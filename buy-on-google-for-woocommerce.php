<?php
/*
 * Plugin Name: Buy on Google for WooCommerce
 * Description: A WooCommerce extension to sync and manage "Buy on Google" orders using WooCommerce.
 * Version: 0.9.9
 * Author: Machine Pro SEO
 * Author URI: https://www.machineproseo.com
 * Developer: Machine Pro SEO
 * Developer URI: https://www.machineproseo.com
 * Text Domain: mproseo_bogfw
 * Requires at least: 5.7
 * Tested up to: 6.2.2
 * Requires PHP: 7.0
 * WC requires at least: 4.4
 * WC tested up to: 7.8.1
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

if (!defined('ABSPATH')) {
	exit();
}

if (!function_exists('mproseo_bogfw_short_plugin_name')) {
	function mproseo_bogfw_short_plugin_name() {
		return 'Buy on Google';
	}
}

if (!function_exists('mproseo_bogfw_get_accounts_option_name')) {
	function mproseo_bogfw_get_accounts_option_name() {
		return 'mproseo_bogfw_merchants';
	}
}

if (!function_exists('mproseo_bogfw_get_settings_option_name')) {
	function mproseo_bogfw_get_settings_option_name() {
		return 'mproseo_bogfw_settings';
	}
}

if (!function_exists('mproseo_bogfw_get_sync_cron_name')) {
	function mproseo_bogfw_get_sync_cron_name() {
		return 'mproseo_bogfw_sync_cron';
	}
}

if (!function_exists('mproseo_bogfw_get_dismissed_notice_option_name')) {
	function mproseo_bogfw_get_dismissed_notice_option_name() {
		return 'mproseo_bogfw_dismissed_no_accounts_notice';
	}
}

function mproseo_bogfw_is_plugin_active( $plugins ) {
	if (empty($plugins)) {
		return false;
	}
	$plugins = (array) $plugins;
	if (function_exists('is_plugin_active')) {
		foreach ($plugins as $plugin) {
			if (!is_plugin_active($plugin)) {
				return false;
			}
		}
	} else {
		$active_plugins = (array) get_option('active_plugins', array());
		if (function_exists('is_multisite') && is_multisite()) {
			$active_plugins = array_merge($active_plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
		}
		if (function_exists('apply_filters')) {
			$active_plugins = apply_filters('active_plugins', $active_plugins);
		}
		if (empty($active_plugins)) {
			return false;
		} else {
			foreach ($plugins as $plugin) {
				if (!in_array($plugin, $active_plugins)) {
					return false;
				}
			}
		}
	}
	return true;
}

if (!function_exists('mproseo_bogfw_schedule_cron_sync')) {
	function mproseo_bogfw_schedule_cron_sync( $settings = array(), $reschedule = true, $args = array() ) {
		$scheduled = wp_next_scheduled(mproseo_bogfw_get_sync_cron_name(), $args);
		if (false === $scheduled || $reschedule) {
			if (empty($settings)) {
				$settings = get_option(mproseo_bogfw_get_settings_option_name());
			}
			$cron_interval = ( false !== $settings && !empty($settings['cron_interval']) && is_numeric($settings['cron_interval']) && absint($settings['cron_interval']) >= 2 ? absint($settings['cron_interval']) : 4 );
			if ($cron_interval !== absint($settings['cron_interval'])) {
				$settings['cron_interval'] = $cron_interval;
				update_option(mproseo_bogfw_get_settings_option_name(), $settings, false);
			}
			$current_time = (int) current_time('timestamp');
			$timezone_diff = (int) ( $current_time - time() );
			$next_hour = (int) strtotime('+1 hour', ( $current_time - ( $current_time % 3600 ) ));
			$cron_start = (int) ( $next_hour + ( ( $cron_interval * 3600 ) - ( ( ( (int) gmdate('H', $next_hour) ) * 3600 ) % ( $cron_interval * 3600 ) ) ) - $timezone_diff );
			if (false === $scheduled || ( (int) $scheduled ) > $cron_start || ( ( $cron_start - ( (int) $scheduled ) ) % ( $cron_interval * 3600 ) ) !== 0) {
				if (false !== $scheduled) {
					wp_clear_scheduled_hook(mproseo_bogfw_get_sync_cron_name(), $args);
				}
				wp_schedule_event($cron_start, 'mproseo_bogfw_cron_interval', mproseo_bogfw_get_sync_cron_name(), $args);
			}
		}
	}
}

register_activation_hook(__FILE__, function() {
	if (mproseo_bogfw_is_plugin_active('woocommerce/woocommerce.php')) {
		$default_settings = array('match_products' => true, 'monitor_term' => 30, 'cron_interval' => 4);
		$settings_option = mproseo_bogfw_get_settings_option_name();
		$saved_settings = get_option($settings_option);
		$all_settings = array_merge($default_settings, ( false !== $saved_settings && is_array($saved_settings) ? $saved_settings : array() ));
		update_option($settings_option, $all_settings, false);
		update_option(mproseo_bogfw_get_dismissed_notice_option_name(), false, false);
		mproseo_bogfw_schedule_cron_sync($all_settings);
	} elseif (!is_admin() && function_exists('deactivate_plugins')) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(esc_html__('Please install and activate WooCommerce to use this extension.', 'mproseo_bogfw'));
	}
});

register_deactivation_hook(__FILE__, function() {
	if (false !== wp_next_scheduled(mproseo_bogfw_get_sync_cron_name())) {
		wp_clear_scheduled_hook(mproseo_bogfw_get_sync_cron_name());
	}
	delete_option(mproseo_bogfw_get_dismissed_notice_option_name());
});

add_action('before_woocommerce_init', function() {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class) && method_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class, 'declare_compatibility')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

if (!function_exists('mproseo_bogfw_get_admin_path')) {
	function mproseo_bogfw_get_admin_path() {
		$default_path = 'mproseo-sync-google-orders';
		$custom_path = sanitize_key(apply_filters('mproseo_bogfw_admin_path', $default_path));
		return ( !empty($custom_path) ? $custom_path : $default_path );
	}
}

add_filter('extra_plugin_headers', function($headers) {
	if (!in_array('Portal URI', $headers)) {
		$headers[] = 'Portal URI';
	}
	return $headers;
});

add_action('admin_init', function() {
	$plugin_name = mproseo_bogfw_short_plugin_name();
	$plugin_data = ( function_exists('get_plugin_data') ? get_plugin_data(__FILE__, false, false) : array() );
	if (!empty($plugin_data['Name'])) {
		$plugin_name = $plugin_data['Name'];
	}
	$has_woo = mproseo_bogfw_is_plugin_active('woocommerce/woocommerce.php');
	$can_manage_woo = current_user_can('manage_woocommerce');
	add_action('admin_notices', function() use( $plugin_name, $has_woo, $can_manage_woo ) {
		if (!$has_woo) {
			if (current_user_can('activate_plugins')) {
				echo '<div class="notice mproseo-bogfw-notice notice-error is-dismissible"><p>Sorry, but ' . esc_html__($plugin_name, 'mproseo_bogfw') . ' requires the WooCommerce plugin to be installed and active.</p></div>';
			}
		} elseif ($can_manage_woo) {
			$users = get_option(mproseo_bogfw_get_accounts_option_name(), array());
			if (empty($users) || empty(array_filter(array_column($users, 'refresh_token')))) {
				if (empty(get_option(mproseo_bogfw_get_dismissed_notice_option_name())) && ( empty($_GET['page']) || mproseo_bogfw_get_admin_path() !== sanitize_key($_GET['page']) )) {
					echo '<div id="mproseo_bogfw_no_accounts_notice" class="notice mproseo-bogfw-notice notice-error is-dismissible"><p>' . esc_html__($plugin_name, 'mproseo_bogfw') . ' is not connected to any Google accounts. Please <a href="' . esc_url(add_query_arg('page', urlencode(mproseo_bogfw_get_admin_path()), admin_url('admin.php'))) . '" target="_self">connect an account</a> to begin syncing orders.</p></div>';
				}
			}
		}
	});
	if (!$has_woo) {
		deactivate_plugins(plugin_basename(__FILE__));
		unset($_GET['activate']);
	}
	add_action('wp_ajax_mproseo_bogfw_dismiss_no_accounts_notice_handler', function() {
		check_ajax_referer('dismiss_no_accounts_notice', 'security');
		if (current_user_can('manage_woocommerce')) {
			update_option(mproseo_bogfw_get_dismissed_notice_option_name(), true, false);
			wp_die();
		}
		wp_die(-1);
	});
	add_action('admin_enqueue_scripts', function( $hook ) use( $plugin_data ) {
		$edit_order = false;
		$hpos_enabled = ( class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class) && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled() );
		if (( $hpos_enabled && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id('shop-order') === $hook && !empty($_GET['id']) : ( 'post.php' === $hook && !empty($_GET['post']) ) )) {
			$post_id = ( $hpos_enabled ? intval($_GET['id']) : intval($_GET['post']) );
			if ($post_id > 1 && 'shop_order' === ( $hpos_enabled && method_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class, 'get_order_type') ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type($post_id) : get_post_type($post_id) )) {
				$order = wc_get_order($post_id);
				if (false !== $order && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
					$edit_order = true;
				}
			}
		}
		$users = get_option(mproseo_bogfw_get_accounts_option_name(), array());
		$admin_notice = ( ( empty($users) || empty(array_filter(array_column($users, 'refresh_token'))) ) && empty(get_option(mproseo_bogfw_get_dismissed_notice_option_name())) );
		if ($edit_order || $admin_notice || $app_settings) {
			$version = ( !empty($plugin_data['Version']) ? $plugin_data['Version'] : null );
			wp_enqueue_script('jquery');
			if ($edit_order) {
				wp_enqueue_script('mproseo-bogfw-edit-order-script', plugin_dir_url(__FILE__) . '/assets/js/edit-order.js', array('jquery'), $version, true);
			}
			if ($admin_notice) {
				wp_enqueue_script('mproseo-bogfw-admin-notice-script', plugin_dir_url(__FILE__) . '/assets/js/admin-notice.js', array('jquery'), $version, true);
				wp_localize_script('mproseo-bogfw-admin-notice-script', 'mproseo_bogfw_security', array('dismiss_notice_nonce' => wp_create_nonce('dismiss_no_accounts_notice')));
			}
		}
	});
	global $pagenow;
	if ('admin.php' === $pagenow && $can_manage_woo && !empty($_GET['page']) && mproseo_bogfw_get_admin_path() === sanitize_key($_GET['page'])) {
		add_filter('admin_footer_text', function($text) use( $plugin_data, $plugin_name ) {
			if (!empty($plugin_name)) {
				$text = '<span id="footer-thankyou" style="position: fixed; bottom: 5px;">Thank you for using ' . esc_html__($plugin_name, 'mproseo_bogfw') . ( !empty($plugin_data['Author']) ? ' by ' . esc_html($plugin_data['Author']) : '' ) . '!</span>';
			}
			return $text;
		});
		add_filter('update_footer', function($version) use( $plugin_data ) {
			if (!empty($plugin_data['Version'])) {
				$version = '<span style="position: fixed; right: 20px; bottom: 5px;">Version ' . esc_html($plugin_data['Version']) . '</span>';
			}
			return $version;
		});
	} elseif ('plugins.php' === $pagenow && $can_manage_woo) {
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), function( $actions ) {
			$actions[] = '<a href="' . esc_url(add_query_arg('page', urlencode(mproseo_bogfw_get_admin_path()), admin_url('admin.php'))) . '" target="_self">Settings</a>';
			return $actions;
		});
	}
});

add_filter('cron_schedules', function( $schedules ) {
	$settings = get_option(mproseo_bogfw_get_settings_option_name());
	$cron_interval = ( false !== $settings && !empty($settings['cron_interval']) && is_numeric($settings['cron_interval']) && absint($settings['cron_interval']) >= 2 ? absint($settings['cron_interval']) : 4 );
	if (false === $settings || empty($settings['cron_interval']) || $cron_interval !== absint($settings['cron_interval'])) {
		if (false === $settings || !is_array($settings)) {
			$settings = array();
		}
		$settings['cron_interval'] = $cron_interval;
		update_option(mproseo_bogfw_get_settings_option_name(), $settings, false);
	}
	$plugin_name = mproseo_bogfw_short_plugin_name();
	if (function_exists('get_plugin_data')) {
		$plugin_data = get_plugin_data(__FILE__, false, false);
		if (!empty($plugin_data['Name'])) {
			$plugin_name = $plugin_data['Name'];
		}
	}
	$schedules['mproseo_bogfw_cron_interval'] = array('interval' => ( $cron_interval * 3600 ), 'display' => esc_html__($plugin_name . ' Cron', 'mproseo_bogfw'));
	return $schedules;
});

add_filter('woocommerce_payment_complete_order_status', function( $status, $order_id, $order = null ) {
	if (!$order) {
		$order = wc_get_order($order_id);
	}
	if (false !== $order && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
		$status = 'processing';
	}
	return $status;
}, 20, 3);

if (!function_exists('mproseo_bogfw_woo_customer_refunded_email_enabled')) {
	function mproseo_bogfw_woo_customer_refunded_email_enabled( $enabled, $order = null ) {
		if (!is_null($order) && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
			$enabled = false;
		}
		return $enabled;
	}
}
add_filter('woocommerce_email_enabled_customer_refunded_order', 'mproseo_bogfw_woo_customer_refunded_email_enabled', 10, 2);
add_filter('woocommerce_email_enabled_customer_partially_refunded_order', 'mproseo_bogfw_woo_customer_refunded_email_enabled', 10, 2);

add_filter('woocommerce_order_number', function( $order_id, $order = null ) {
	if (!$order) {
		$order = wc_get_order($order_id);
	}
	if (false !== $order) {
		$google_order_id = $order->get_meta('_mproseo_bogfw_google_order_id');
		if (!empty($google_order_id)) {
			$order_id .= ' ' . ( $order->get_meta('_mproseo_bogfw_google_sandbox_order') ? 'TEST-' : '' ) . $google_order_id;
		}
	}
	return $order_id;
}, 20, 2);

add_action('init', function() {
	if (mproseo_bogfw_is_plugin_active('woocommerce/woocommerce.php')) {
		add_action('mproseo_bogfw_order_shipments', function( $order_id = 0, $shipments = array(), $shipment_country = 'US' ) {
			if (mproseo_bogfw_is_plugin_active('woocommerce-shipment-tracking/woocommerce-shipment-tracking.php')) {
				if (is_numeric($order_id) && $order_id > 0) {
					$supported_providers = apply_filters('mproseo_bogfw_supported_shipping_countries', array('US' => 'United States', 'FR' => 'France'));
					if (class_exists('WC_Shipment_Tracking_Actions') && method_exists('WC_Shipment_Tracking_Actions', 'add_tracking_item')) {
						$track_shipment = new WC_Shipment_Tracking_Actions();
						$all_providers = ( method_exists('WC_Shipment_Tracking_Actions', 'get_providers') ? $track_shipment->get_providers() : array() );
						$translate_providers = apply_filters('mproseo_bogfw_translate_providers', array('DHL US' => 'DHL'));
						$providers = array();
						if (isset($supported_providers[$shipment_country]) && !empty($all_providers[$supported_providers[$shipment_country]])) {
							foreach (array_keys($all_providers[$supported_providers[$shipment_country]]) as $provider) {
								$providers[strtolower(( isset($translate_providers[$provider]) ? $translate_providers[$provider] : $provider ))] = $provider;
							}
						}
						$custom_provider_links = apply_filters('mproseo_bogfw_custom_provider_links', array('dhl express' => 'https://www.logistics.dhl/us-en/home/tracking/tracking-ecommerce.html?tracking-id=%1$s'));
						$saved_tracking_ids = ( method_exists('WC_Shipment_Tracking_Actions', 'get_tracking_items') ? $track_shipment->get_tracking_items($order_id, true) : array() );
						if (!empty($saved_tracking_ids) && method_exists('WC_Shipment_Tracking_Actions', 'delete_tracking_item')) {
							foreach ($saved_tracking_ids as $saved_tracking_id) {
								if (!empty($saved_tracking_id['tracking_number'])) {
									$key = array_search($saved_tracking_id['tracking_number'], array_column($shipments, 'trackingId'));
									if (false === $key || ( empty($shipments[$key]['carrier']) || ( empty(( isset($saved_tracking_id['tracking_provider']) ? $saved_tracking_id['tracking_provider'] : $saved_tracking_id['custom_tracking_provider'] )) || ( ( isset($saved_tracking_id['tracking_provider']) ? $saved_tracking_id['tracking_provider'] : $saved_tracking_id['custom_tracking_provider'] ) ) !== $shipments[$key]['carrier'] ) )) {
										$track_shipment->delete_tracking_item($order_id, $saved_tracking_id['tracking_id']);
									}
								}
							}
						}
						if (!empty($shipments)) {
							foreach ((array) $shipments as $shipment) {
								if (!empty($shipment['carrier']) && !empty($shipment['trackingId'])) {
									$key = array_search($shipment['trackingId'], array_column($saved_tracking_ids, 'tracking_number'));
									if (false === $key) {
										$args = array();
										$custom_provider = ( empty($providers[strtolower($shipment['carrier'])]) );
										$args[( $custom_provider ? 'custom_tracking_provider' : 'tracking_provider' )] = ( $custom_provider ? $shipment['carrier'] : $providers[strtolower($shipment['carrier'])] );
										if ($custom_provider && !empty($shipment['carrier']) && !empty($custom_provider_links[$shipment['carrier']])) {
											$args['custom_tracking_link'] = $custom_provider_links[$shipment['carrier']];
										}
										$args['tracking_number'] = $shipment['trackingId'];
										$args['date_shipped'] = $shipment['creationDate'];
										$track_shipment->add_tracking_item($order_id, $args);
									}
								}
							}
						}
					}
				}
			}
		}, 20, 3);

		if (!function_exists('mproseo_bogfw_accounts')) {
			function mproseo_bogfw_accounts( $opt = 'get', $value = array() ) {
				$accounts_key = mproseo_bogfw_get_accounts_option_name();
				if ('set' === $opt) {
					return update_option($accounts_key, $value, false);
				} else {
					return (array) get_option($accounts_key, array());
				}
			}
		}

		if (!function_exists('mproseo_bogfw_has_valid_authorized_response')) {
			function mproseo_bogfw_has_valid_authorized_response( $response, $account_id = null, $location_id = null) {
				if (!is_wp_error($response)) {
					$response_code = wp_remote_retrieve_response_code($response);
					if (429 === $response_code) {
						return array('error' => 'rate_limited');
					} else {
						$response_body = wp_remote_retrieve_body($response);
						$parsed_response = json_decode($response_body, true);
						if (empty($parsed_response['error']) && 200 === $response_code) {
							return $parsed_response;
						} elseif (!empty($account_id) && !is_null($parsed_response) && ( 'invalid_grant' === $parsed_response['error'] || ( !empty($parsed_response['error']['status']) && 'UNAUTHENTICATED' === $parsed_response['error']['status'] ) )) {
							$users = mproseo_bogfw_accounts();
							if (!empty($location_id)) {
								$location_key = array_search($location_id, $users[$account_id]['locations']);
								if (false !== $location_key) {
									unset($users[$account_id]['locations'][$location_key]);
								}
							} else {
								unset($users[$account_id]['refresh_token']);
							}
							mproseo_bogfw_accounts('set', $users);
						}
					}
				}
				return false;
			}
		}

		if (!function_exists('mproseo_bogfw_settings')) {
			function mproseo_bogfw_settings( $opt = 'get', $value = array() ) {
				$settings_key = mproseo_bogfw_get_settings_option_name();
				if ('set' === $opt) {
					return update_option($settings_key, $value, false);
				} else {
					return get_option($settings_key, array());
				}
			}
		}

		if (!function_exists('mproseo_bogfw_application_api_request')) {
			function mproseo_bogfw_application_api_request( $api, $args = array(), $url_args = array() ) {
				$args['headers'] = apply_filters('mproseo_bogfw_request_headers', $args['headers'], $api);
				if (false === $args || !is_array($args) || empty($args) || ( 'authorize' === $api && empty($url_args['redirect_uri']) )) {
					if (false === $args && 'authorize' === $api && !empty($url_args['redirect_uri'])) {
						$redirect_uri = parse_url($url_args['redirect_uri']);
						if (false !== $redirect_uri && wp_safe_redirect(esc_url_raw(add_query_arg(array('add_account' => false, 'add_account_error' => 'no_auth'), $url_args['redirect_uri'])))) {
							exit();
						}
					}
					return false;
				} else {
					$url = "https://sync-google-orders.merchantordermanagement.com/${api}" . ( !empty($url_args) ? '?' . http_build_query((array) $url_args) : '' );
					return mproseo_bogfw_has_valid_authorized_response(wp_safe_remote_request(esc_url_raw($url), $args), null, null);
				}
			}
		}

		if (!function_exists('mproseo_bogfw_google_api_request')) {
			function mproseo_bogfw_google_api_request( $api, $path, $args, $account_id = null, $location_id = null, $content_sandbox = null ) {
				if ('content' === $api) {
					$args['timeout'] = 30;
					if (!is_bool($content_sandbox)) {
						$content_sandbox = (bool) apply_filters('mproseo_bogfw_enable_google_content_sandbox', false);
					}
					$no_sandbox_endpoints = array('accounts', 'supportedCarriers');
					$api_sandbox = ( $content_sandbox && !in_array($path, $no_sandbox_endpoints) && ( false === strpos($path, '/') || empty(array_intersect($no_sandbox_endpoints, explode('/', explode('?', $path)[0]))) ) );
					$api_ver = apply_filters('mproseo_bogfw_google_content_api_version', '2.1');
					$api_mode = 'v' . $api_ver . ( $api_sandbox ? 'sandbox' : '' );
				} elseif ('oauth2' === $api) {
					$api_mode = 'v1';
				} else {
					return false;
				}
				$endpoint = "${api}/${api_mode}/${path}";
				$headers = apply_filters('mproseo_bogfw_request_headers', array(), $endpoint);
				$args['headers'] = array_merge(( !empty($args['headers']) && is_array($args['headers']) ? $args['headers'] : array() ), ( !empty($headers) && is_array($headers) ? $headers : array() ));
				$url = "https://www.googleapis.com/${endpoint}";
				return mproseo_bogfw_has_valid_authorized_response(wp_safe_remote_request(esc_url_raw($url), $args), $account_id, $location_id);
			}
		}

		if (!function_exists('mproseo_bogfw_site_id')) {
			function mproseo_bogfw_site_id( $opt = 'get', $value = null ) {
				$settings = mproseo_bogfw_settings();
				if ('set' === $opt) {
					if (!empty($value)) {
						$settings['site_id'] = $value;
						return mproseo_bogfw_settings('set', $settings);
					}
				} else {
					return ( !empty($settings['site_id']) ? $settings['site_id'] : false );
				}
			}
		}

		if (!function_exists('mproseo_bogfw_token_cache')) {
			function mproseo_bogfw_token_cache( $opt = 'get', $value = array(), $expiration = ( 1 * HOUR_IN_SECONDS ) ) {
				$token_cache_key = '_mproseo_bogfw_api_tokens';
				if ('set' === $opt) {
					if (is_array($value)) {
						return set_transient($token_cache_key, $value, $expiration);
					} else {
						return false;
					}
				} else {
					$tokens = get_transient($token_cache_key);
					return ( !empty($tokens) ? (array) $tokens : array() );
				}
			}
		}

		if (!function_exists('mproseo_bogfw_monitor_list')) {
			function mproseo_bogfw_monitor_list( $opt = 'get', $value = array(), $expiration = 0 ) {
				$list_cache_key = '_mproseo_bogfw_monitor_list';
				if ('set' === $opt) {
					if (is_array($value)) {
						return set_transient($list_cache_key, $value, $expiration);
					} else {
						return false;
					}
				} else {
					$list = get_transient($list_cache_key);
					return ( !empty($list) ? (array) $list : array() );
				}
			}
		}

		if (!function_exists('mproseo_bogfw_get_google_complete_statuses')) {
			function mproseo_bogfw_get_google_complete_statuses() {
				return array('shipped', 'delivered', 'returned', 'partiallyDelivered', 'partiallyReturned');
			}
		}

		if (!function_exists('mproseo_bogfw_get_google_order_woo_status')) {
			function mproseo_bogfw_get_google_order_woo_status( $order, $sandbox_mode ) {
				return ( in_array($order['status'], array('inProgress', 'pendingShipment', 'partiallyShipped')) ? ( empty($order['paymentStatus']) || in_array($order['paymentStatus'], array_merge(array('paymentCaptured'), ( $sandbox_mode ? array('paymentSecured') : array() ))) ? 'processing' : 'on-hold' ) : ( in_array($order['status'], mproseo_bogfw_get_google_complete_statuses()) ? 'completed' : ( 'canceled' === $order['status'] ? 'cancelled': 'failed' ) ) );
			}
		}

		if (!function_exists('mproseo_bogfw_parse_google_date')) {
			function mproseo_bogfw_parse_google_date( $date ) {
				if (!empty($date) && preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])-(0[1-9]|1[0-9]|2[0-9]|3[0-1])T(0[0-9]|1[0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(Z|[\+\-](0[0-9]|1[0-4]):[0-5][0-9])$/', $date)) {
					$date = gmdate('M j, Y \a\t H:i:s T', strtotime($date));
				}
				return $date;
			}
		}

		if (!function_exists('mproseo_bogfw_get_item_delivery_type')) {
			function mproseo_bogfw_get_item_delivery_type( $product ) {
				return ( 'delivery' === $product['shippingDetails']['type'] ? 'Ship' . ( !empty($product['shippingDetails']['shipByDate']) ? ' by ' . mproseo_bogfw_parse_google_date($product['shippingDetails']['shipByDate']) : '' ) . ' (Method: ' . $product['shippingDetails']['method']['methodName'] . ( !empty($product['shippingDetails']['method']['carrier']) ? ' via ' . $product['shippingDetails']['method']['carrier'] : '' ) . ')' : 'Pickup (see order notes for details)' );
			}
		}

		if (!function_exists('mproseo_bogfw_get_order_notes_by_id')) {
			function mproseo_bogfw_get_order_notes_by_id( $notes ) {
				$notes_arr = array();
				if (!empty($notes)) {
					foreach ((array) $notes as $note) {
						$note_parts = explode(':::', $note->content, 2);
						if (count($note_parts) > 1) {
							$notes_arr[trim($note_parts[0])][$note->id] = trim(explode('::', $note_parts[1], 2)[0]);
						}
					}
				}
				return $notes_arr;
			}
		}

		if (!function_exists('mproseo_bogfw_get_cancellation_or_return_note')) {
			function mproseo_bogfw_get_cancellation_or_return_note( $action, $details = array(), $product = array() ) {
				return ( in_array($action, array('cancelled', 'returned')) && !empty($details) && !empty($product) ? ( "${product['id']}:::${details['creationDate']}:: Item with ID \"${product['id']}\" had quantity of ${details['quantity']} marked as ${action} by ${details['actor']} for reason: ${details['reason']}" . ( !empty($details['reasonText']) ? ' (' . $details['reasonText'] . ')' : '' ) . ' on ' . mproseo_bogfw_parse_google_date($details['creationDate']) . '.' ) : '' );
			}
		}

		if (!function_exists('mproseo_bogfw_get_fee_note')) {
			function mproseo_bogfw_get_fee_note( $fee = array(), $product = array() ) {
				return ( !empty($fee) && !empty($product) ? ( "${product['id']}:::${fee['name']}:: Item with ID \"${product['id']}\" had an associated fee (${fee['name']}) of " . $fee['amount']['value'] . ' ' . $fee['amount']['currency'] . ' assessed on the product.' ) : '' );
			}
		}

		if (!function_exists('mproseo_bogfw_get_pickup_collectors')) {
			function mproseo_bogfw_get_pickup_collectors( $collectors ) {
				$pickup_collectors = '';
				$collectors = (array) $collectors;
				$collector_num = 0;
				$num_collectors = count($collectors);
				if (!empty($collectors)) {
					foreach ($collectors as $collector_num => $collector) {
						if (!empty($collector['name'])) {
							$pickup_collectors .= ( 0 !== $collector_num ? ( $num_collectors > 2 ? ', ' : ' ' ) . ( ( $num_collectors - 1 ) === $collector_num ? 'or ' : '' ) : ' by ' ) . $collector['name'] . ( !empty($collector['phoneNumber']) ? ' (Tel: ' . $collector['phoneNumber'] . ')' : '' );
						}
					}
				}
				return $pickup_collectors;
			}
		}

		if (!function_exists('mproseo_bogfw_get_pickup_note')) {
			function mproseo_bogfw_get_pickup_note( $order = array() ) {
				echo ( !empty($order) ? 'Pickup' . ( !empty($order['pickupDetails']['address']['fullAddress']) ? ' from ' . esc_html($order['pickupDetails']['address']['fullAddress']) : '' ) . ( !empty($order['pickupDetails']['locationId']) ? ' (' . esc_html($order['pickupDetails']['locationId']) . ')' : '' ) . esc_html(mproseo_bogfw_get_pickup_collectors(( isset($order['pickupDetails']['collectors']) ? $order['pickupDetails']['collectors'] : array() ))) . ( !empty($order['pickupDetails']['pickupType']) ? ' at "' . esc_html($order['pickupDetails']['pickupType']) . '"' : '' ) . '.' : '' );
			}
		}

		if (!function_exists('mproseo_bogfw_get_shipment_note')) {
			function mproseo_bogfw_get_shipment_note( $shipment ) {
				$shipment_note = '';
				$line_item_note = '';
				$line_items = ( !empty($shipment['lineItems']) ? (array) $shipment['lineItems'] : array() );
				$first_line = true;
				$item_num = 0;
				$item_count = count($line_items);
				if (!empty($line_items)) {
					foreach ($line_items as $line_item) {
						++$item_num;
						$line_item_note .= ( !$first_line ? ( $item_count > 2 ? ', ' . ( $item_num === $item_count ? 'and ' : '' ) : ' and ' ) : '' ) . "${line_item['quantity']} of item with ID \"${line_item['lineItemId']}\"";
						$first_line = false;
					}
					$shipment_note = "Shipment containing ${line_item_note} was " . ( 'readyForPickup' === $shipment['status'] ? 'ready for pickup' : ( in_array($shipment['status'], array('shipped', 'undeliverable')) ? 'shipped via' : 'delivered by' ) . " ${shipment['carrier']}" ) . ( 'delivered' === $shipment['status'] ? ( !empty($shipment['deliveryDate']) ? ' on ' . mproseo_bogfw_parse_google_date($shipment['deliveryDate']) : '' ) . ' (Shipped on ' . mproseo_bogfw_parse_google_date($shipment['creationDate']) . ')' : ' on ' . mproseo_bogfw_parse_google_date($shipment['creationDate']) . ( 'undeliverable' === $shipment['status'] ? ', and was undeliverable' : '' ) ) . '.' . ( !empty($shipment['trackingId']) ? " Tracking number: ${shipment['trackingId']}" : '' );
				}
				return $shipment_note;
			}
		}

		if (!function_exists('mproseo_bogfw_update_dynamic_item_meta')) {
			function mproseo_bogfw_update_dynamic_item_meta( $woo_order = null, $item_id = null, $product = null, $parsed_notes = null ) {
				if (empty($item_id) || empty($product)) {
					return;
				}
				if (!$woo_order) {
					$order_id = get_order_id_by_order_item_id($item_id);
					$woo_order = wc_get_order($order_id);
				}
				wc_update_order_item_meta($item_id, 'Type', mproseo_bogfw_get_item_delivery_type($product));
				$google_order_item_cancel_count = intval($product['quantityCanceled']);
				if ($google_order_item_cancel_count > 0 && $woo_order) {
					$wc_order_item_cancel_count = intval(wc_get_order_item_meta($item_id, 'Cancelled'));
					if ($google_order_item_cancel_count > $wc_order_item_cancel_count) {
						if (is_null($parsed_notes)) {
							$order_notes = wc_get_order_notes(array('order_id' => $woo_order->get_id()));
							$parsed_notes = mproseo_bogfw_get_order_notes_by_id($order_notes);
						}
						foreach ($product['cancellations'] as $cancellation) {
							if (empty($parsed_notes) || !isset($parsed_notes[$product['id']]) || !in_array($cancellation['creationDate'], $parsed_notes[$product['id']])) {
								$cancel_item_args = array(
									'amount' => 0,
									'reason' => 'Synced cancellations with Google: ' . $cancellation['reason'] . ( !empty($cancellation['reasonText']) ? " (${cancellation['reasonText']})" : '' ),
									'order_id' => $woo_order->get_id(),
									'line_items' => array($item_id => array('qty' => $cancellation['quantity'])),
									'restock_items' => (bool) ( 'noInventory' !== $cancellation['reason'] ),
									'google_refund' => true
								);
								$refund = wc_create_refund($cancel_item_args);
								if (!is_wp_error($refund)) {
									$cancellation_note = mproseo_bogfw_get_cancellation_or_return_note('cancelled', $cancellation, $product);
									if (!empty($cancellation_note)) {
										$note_id = $woo_order->add_order_note(__($cancellation_note, 'mproseo_bogfw'));
										$cancellation_notes = $woo_order->get_meta('_mproseo_bogfw_refund_cancellation_notes');
										if (empty($cancellation_notes)) {
											$cancellation_notes = array();
										}
										$cancellation_notes[$refund->get_id()] = $note_id;
										$woo_order->update_meta_data('_mproseo_bogfw_refund_cancellation_notes', $cancellation_notes);
									}
								} else {
									$google_order_item_cancel_count -= $cancellation['quantity'];
								}
							}
						}
					}
				} else {
					$wc_order_item_cancel_count = $google_order_item_cancel_count;
				}
				if ($google_order_item_cancel_count > 0) {
					if (!isset($wc_order_item_cancel_count) || $google_order_item_cancel_count !== $wc_order_item_cancel_count) {
						wc_update_order_item_meta($item_id, 'Cancelled', $google_order_item_cancel_count);
					}
				} else {
					wc_delete_order_item_meta($item_id, 'Cancelled');
				}
				if ((int) $product['quantityPending'] > 0) {
					wc_update_order_item_meta($item_id, 'Pending', $product['quantityPending']);
				} else {
					wc_delete_order_item_meta($item_id, 'Pending');
				}
				if ('delivery' === $product['shippingDetails']['type']) {
					if ((int) $product['quantityShipped'] > 0) {
						wc_update_order_item_meta($item_id, 'Shipped', $product['quantityShipped']);
					} else {
						wc_delete_order_item_meta($item_id, 'Shipped');
					}
					if ((int) $product['quantityUndeliverable'] > 0) {
						wc_update_order_item_meta($item_id, 'Undeliverable', $product['quantityUndeliverable']);
					} else {
						wc_delete_order_item_meta($item_id, 'Undeliverable');
					}
				} else {
					if ((int) $product['quantityReadyForPickup'] > 0) {
						wc_update_order_item_meta($item_id, 'Ready for Pickup', $product['quantityReadyForPickup']);
					} else {
						wc_delete_order_item_meta($item_id, 'Ready for Pickup');
					}
				}
				if ((int) $product['quantityDelivered'] > 0) {
					wc_update_order_item_meta($item_id, 'Delivered', $product['quantityDelivered']);
				} else {
					wc_delete_order_item_meta($item_id, 'Delivered');
				}
				if ((int) $product['quantityReturned'] > 0) {
					wc_update_order_item_meta($item_id, 'Returned', $product['quantityReturned']);
				} else {
					wc_delete_order_item_meta($item_id, 'Returned');
				}
			}
		}

		if (!function_exists('mproseo_bogfw_account_tokens_exist')) {
			function mproseo_bogfw_account_tokens_exist( $accounts, $tokens ) {
				if (empty($accounts) || empty($tokens)) {
					return false;
				} else {
					foreach ($accounts as $account_id => $account_settings) {
						if (!empty($account_settings['refresh_token']) && !isset($tokens[$account_id])) {
							return false;
						}
					}
				}
				return true;
			}
		}

		if (!function_exists('mproseo_bogfw_get_google_order_creds')) {
			function mproseo_bogfw_get_google_order_creds( $order, $users = null, $creds = null, $sandbox_mode = null, $site_id = null ) {
				$order_creds = null;
				if (empty($order['merchant_id'])) {
					return $order_creds;
				}
				if (is_null($users)) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($creds)) {
					$creds = mproseo_bogfw_get_google_access_tokens();
				}
				if (!empty($creds)) {
					if (!empty($users)) {
						foreach ($users as $user_id => $settings) {
							if (!empty($settings['locations']) && in_array($order['merchant_id'], (array) $settings['locations']) && !empty($creds[$user_id]['access_token'])) {
								$order_creds = array($user_id, $creds[$user_id]);
								break;
							}
						}
					}
					if (is_null($order_creds)) {
						foreach ($creds as $user_id => $user_creds) {
							if (!empty($user_creds['access_token'])) {
								if (is_null($site_id)) {
									$site_id = mproseo_bogfw_site_id();
								}
								$locations = mproseo_bogfw_get_google_locations($user_id, null, $user_creds, array($order['merchant_id']), $site_id);
								if (!empty($locations) && array_key_exists($order['merchant_id'], $locations)) {
									$order_creds = array($user_id, $user_creds);
									break;
								}
							}
						}
					}
				}
				return $order_creds;
			}
		}

		if (!function_exists('mproseo_bogfw_get_google_access_tokens')) {
			function mproseo_bogfw_get_google_access_tokens( $accounts = array(), $force_update = false, $data = array() ) {
				$cached_token_data = mproseo_bogfw_token_cache();
				$reset_ttl = !empty($data);
				if (!$force_update && !empty($cached_token_data) && empty($data) && ( !is_array($accounts) || empty($accounts) || mproseo_bogfw_account_tokens_exist($accounts, $cached_token_data) )) {
					return $cached_token_data;
				} else {
					if (empty($accounts)) {
						$accounts = mproseo_bogfw_accounts();
					}
					if (!empty($accounts)) {
						$refresh_accounts = array();
						foreach ($accounts as $account_id => $account_settings) {
							if (!isset($data[$account_id]) && !empty($account_settings['refresh_token'])) {
								$refresh_accounts[$account_id] = array('token' => $account_settings['refresh_token']);
							}
						}
						if (!empty($refresh_accounts)) {
							$token_args = array(
								'method' => 'POST',
								'body' => json_encode($refresh_accounts),
								'headers' => array(									
									'Cache-Control' => 'no-cache',
									'Content-Type' => 'application/json',
								)
							);
							$response = mproseo_bogfw_application_api_request('token', $token_args);
							if (!empty($response)) {
								if (!empty($response['error']) && 'rate_limited' === $response['error']) {
									if (empty($data)) {
										$data = false;
									}
								} else {
									$users = mproseo_bogfw_accounts();
									foreach (array_keys($accounts) as $returned_account) {
										if (!empty($response[$returned_account])) {
											if (empty($response[$returned_account]['error']) && !empty($response[$returned_account]['access_token'])) {
												$data[$returned_account] = array('access_token' => $response[$returned_account]['access_token']);
												if (!$reset_ttl) {
													$reset_ttl = true;
												}
											} elseif ('invalid_grant' === $response[$returned_account]['error'] && !empty($users[$returned_account])) {
												unset($users[$returned_account]['refresh_token']);
												if (!$users_updated) {
													$users_updated = true;
												}
											}
										}
									}
									if ($users_updated) {
										mproseo_bogfw_accounts('set', $users);
									}
								}
							}
						}
					}
				}
				if (!empty($data) || $force_update) {
					mproseo_bogfw_token_cache('set', $data, ( $reset_ttl ? ( ( 1 * HOUR_IN_SECONDS ) - ( 1 * MINUTE_IN_SECONDS ) ) : ( 15 * MINUTE_IN_SECONDS ) ));
				}
				return $data;
			}
		}

		if (!function_exists('mproseo_bogfw_clear_tokens')) {
			function mproseo_bogfw_clear_tokens() {
				$users = mproseo_bogfw_accounts();
				if (!empty($users)) {
					foreach ($users as &$user) {
						unset($user['refresh_token']);
					}
					mproseo_bogfw_accounts('set', $users);
				}
				mproseo_bogfw_get_google_access_tokens($users, true);
			}
		}

		if (!function_exists('mproseo_bogfw_get_google_userinfo')) {
			function mproseo_bogfw_get_google_userinfo( $user_id, $user_creds = null, $site_id = null ) {
				$user_info = false;
				if (!empty($user_id)) {
					if (empty($user_creds)) {
						$creds = mproseo_bogfw_get_google_access_tokens();
						if (isset($creds[$user_id])) {
							$user_creds = $creds[$user_id];
						}
					}
					if (!empty($user_creds['access_token'])) {
						if (is_null($site_id)) {
							$site_id = mproseo_bogfw_site_id();
						}
						$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
						$info_args = array('headers' => array('Authorization' => "Bearer ${user_creds['access_token']}"));
						$info_response = mproseo_bogfw_google_api_request('oauth2', "userinfo${quota_user}", $info_args);
						if (!empty($info_response)) {
							if (!empty($info_response['id'])) {
								$user_info = array(
									'id' => $info_response['id'],
									'name' => $info_response['name'],
									'email' => $info_response['email']
								);
							}
						}
					}
				}
				return $user_info;
			}
		}

		if (!function_exists('mproseo_bogfw_get_google_locations')) {
			function mproseo_bogfw_get_google_locations( $user_id, $user_info = null, $user_creds = null, $check_locations = array(), $site_id = null ) {
				$locations = false;
				if (empty($user_id)) {
					return $locations;
				}
				if (empty($user_creds)) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					if (is_null($site_id)) {
						$site_id = mproseo_bogfw_site_id();
					}
					if (empty($user_info)) {
						$user_info = mproseo_bogfw_get_google_userinfo($user_id, $user_creds, $site_id);
					}
					if (!empty($user_info['email'])) {
						$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
						$authinfo_endpoint = "accounts/authinfo${quota_user}";
						$content_args = array('headers' => array('Authorization' => "Bearer ${user_creds['access_token']}"));
						$authinfo_response = mproseo_bogfw_google_api_request('content', $authinfo_endpoint, $content_args, $user_id, null, false);
						if (!empty($authinfo_response['accountIdentifiers'])) {
							$locations = array();
							foreach ((array) $authinfo_response['accountIdentifiers'] as $merchant) {
								if (!empty($merchant['merchantId']) && ( empty($check_locations) || in_array($merchant['merchantId'], (array) $check_locations) )) {
									$merchant_id = $merchant['merchantId'];
									$accounts_endpoint = "${merchant_id}/accounts/${merchant_id}${quota_user}";
									$accounts_response = mproseo_bogfw_google_api_request('content', $accounts_endpoint, $content_args, null, null, false);
									if (!empty($accounts_response)) {
										$location_id = $accounts_response['id'];
										$location_name = $accounts_response['name'];
										$user_is_order_manager = false;
										if (!empty($accounts_response['users'])) {
											$account_key = array_search($user_info['email'], array_column($accounts_response['users'], 'emailAddress'));
											if (false !== $account_key) {
												if (!empty($accounts_response['users'][$account_key]['orderManager'])) {
													$user_is_order_manager = true;
												}
											}
										}
										if (!$user_is_order_manager) {
											$location_id = "UNAUTHORIZED-${location_id}";
											$location_name .= ' (No Order Access)';
										}
										$locations[$location_id] = $location_name;
									}
								} elseif (!empty($merchant['aggregatorId']) && empty($check_locations)) {
									$aggregator_id = $merchant['aggregatorId'];
									$accounts_endpoint = "${aggregator_id}/accounts/${aggregator_id}${quota_user}";
									$accounts_response = mproseo_bogfw_google_api_request('content', $accounts_endpoint, $content_args, null, null, false);
									if (!empty($accounts_response)) {
										$location_id = "UNAUTHORIZED-${accounts_response['id']}";
										$location_name = $accounts_response['name'] . ' (MCA Not Supported)';
										$locations[$location_id] = $location_name;
									}
								}
							}
						}
					}
				}
				return $locations;
			}
		}

		if (!function_exists('mproseo_bogfw_get_google_orders')) {
			function mproseo_bogfw_get_google_orders( $user_id, $user_info = null, $user_creds = null, $sync_locations = array(), $available_locations = array(), $get_all = false, $sandbox_mode = null, $site_id = null, $separate = false, $acknowledged = 'false', $check_locations = true ) {
				$orders = array();
				if (empty($user_id)) {
					return $orders;
				}
				if (!empty($sync_locations)) {
					if (empty($user_creds)) {
						$creds = mproseo_bogfw_get_google_access_tokens();
						if (isset($creds[$user_id])) {
							$user_creds = $creds[$user_id];
						}
					}
					if (!empty($user_creds['access_token'])) {
						if (is_null($site_id)) {
							$site_id = mproseo_bogfw_site_id();
						}
						if ($check_locations && empty($user_info)) {
							$user_info = mproseo_bogfw_get_google_userinfo($user_id, $user_creds, $site_id);
						}
						if (!$check_locations || !empty($user_info)) {
							$orders_args = array('headers' => array('Authorization' => "Bearer ${user_creds['access_token']}"));
							$locations_updated = false;
							$locations = ( array() !== $sync_locations && array_keys($sync_locations) !== range(0, count($sync_locations) - 1) ? array_keys($sync_locations) : $sync_locations );
							if ($check_locations && empty($available_locations) && !empty($locations)) {
								$available_locations = mproseo_bogfw_get_google_locations($user_id, $user_info, $user_creds, $locations, $site_id);
							}
							foreach ($locations as $location_id) {
								if ($sync_locations === $locations) {
									$sync_orders = array(null);
								} else {
									$sync_orders = $sync_locations[$location_id];
								}
								if ('UNAUTHORIZED' !== substr($location_id, 0, strlen('UNAUTHORIZED')) && ( !$check_locations || ( !empty($available_locations) && isset($available_locations[$location_id]) ) )) {
									foreach ($sync_orders as $order_id) {
										$page_token = false;
										while (!is_null($page_token)) {
											$endpoint = "${location_id}/orders/" . ( !is_null($order_id) ? $order_id : '' ) . ( !empty($page_token) ? "?pageToken=${page_token}" : '' ) . ( !$get_all ? ( !empty($page_token) ? '&' : '?' ) . "acknowledged=${acknowledged}" : '' ) . ( !empty($site_id) ? ( !empty($page_token) || !$get_all ? '&' : '?' ) . "quotaUser=${site_id}" : '' );
											$response = mproseo_bogfw_google_api_request('content', $endpoint, $orders_args, $user_id, $location_id, $sandbox_mode);
											if (!empty($response)) {
												$page_token = ( !empty($response['nextPageToken']) ? $response['nextPageToken'] : null );
												if (is_null($order_id)) {
													if (!empty($response['resources'])) {
														if ($separate) {
															$orders[$location_id] = array_merge($orders[$location_id], $response['resources']);
														} else {
															$orders = array_merge($orders, $response['resources']);
														}
													}
												} else {
													if ($separate) {
														$orders[$location_id] = array_merge($orders[$location_id], array($response));
													} else {
														$orders = array_merge($orders, array($response));
													}
												}
											} else {
												$page_token = null;
											}
										}
									}
								} elseif ($check_locations && false !== $available_locations) {
									if (empty($users)) {
										$users = mproseo_bogfw_accounts();
									}
									if (!empty($users) && !empty($users[$user_id]['locations'])) {
										$saved_location = array_search($location_id, $saved_locations);
										if (false !== $saved_location) {
											unset($saved_locations[$saved_location]);
											if (!$locations_updated) {
												$locations_updated = true;
											}
										}
									}
								}
							}
							if ($locations_updated) {
								mproseo_bogfw_accounts('set', $users);
							}
						}
					}
				}
				return $orders;
			}
		}

		if (!function_exists('mproseo_bogfw_advance_google_orders')) {
			function mproseo_bogfw_advance_google_orders( $orders = array(), $user_id = null, $user_creds = null, $site_id = null ) {
				$result = array();
				if (empty($orders)) {
					return $result;
				}
				if (empty($user_creds['access_token'])) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (!empty($user_id) && isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					$order_creds = array($user_id, $user_creds);
				} else {
					$order_creds = null;
				}
				$creds_per_order = ( !isset($order_creds) );
				if (true === $creds_per_order) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
				foreach ($orders as $order) {
					if (!empty($order['merchant_id']) && !empty($order['google_id'])) {
						$result[$order['google_id']] = array('advanced' => false);
						$sandbox_mode = null;
						if (!isset($order['sandbox'])) {
							if (!empty($order['order_id'])) {
								$wc_order = wc_get_order($order['order_id']);
								if (false !== $wc_order) {
									$sandbox_mode = (bool) $wc_order->get_meta('_mproseo_bogfw_google_sandbox_order');
								}
							}
						} else {
							$sandbox_mode = (bool) $order['sandbox'];
						}
						if (isset($sandbox_mode)) {
							if ($creds_per_order) {
								$order_creds = mproseo_bogfw_get_google_order_creds($order, $users, $creds, $sandbox_mode, $site_id);
							}
							if (!is_null($order_creds) && !empty($order_creds[1]['access_token'])) {
								$content_args = array('headers' => array('Authorization' => 'Bearer ' . $order_creds[1]['access_token']));
								$advance_args = $content_args;
								$advance_args['method'] = 'POST';
								$advance_endpoint = "${order['merchant_id']}/testorders/${order['google_id']}/advance${quota_user}";
								$result[$order['google_id']]['advanced'] = false;
								if (!empty(mproseo_bogfw_google_api_request('content', $advance_endpoint, $advance_args, $user_id, $order['merchant_id'], $sandbox_mode))) {
									$order_endpoint = "${order['merchant_id']}/orders/${order['google_id']}${quota_user}";
									$google_order = mproseo_bogfw_google_api_request('content', $order_endpoint, $content_args, $sandbox_mode);
									if (!empty($google_order)) {
										if ('inProgress' !== $google_order['status']) {
											$result[$order['google_id']]['advanced'] = true;
										}
									}
								}
							}
						}
					}
				}
				return $result;
			}
		}

		if (!function_exists('mproseo_bogfw_acknowledge_google_orders')) {
			function mproseo_bogfw_acknowledge_google_orders( $orders = array(), $user_id = null, $user_creds = null, $site_id = null, $update_id_only = false ) {
				$result = array();
				if (empty($orders)) {
					return $result;
				}
				$timestamp = time();
				if (empty($user_creds['access_token'])) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (!empty($user_id) && isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					$order_creds = array($user_id, $user_creds);
				} else {
					$order_creds = null;
				}
				$creds_per_order = ( !isset($order_creds) );
				if (true === $creds_per_order) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
				foreach ($orders as $key => $order) {
					if (!empty($order['merchant_id']) && !empty($order['google_id'])) {
						$result[$order['google_id']] = array('acknowledged' => false, 'assigned' => false);
						$sandbox_mode = null;
						if (!isset($order['sandbox'])) {
							if (!empty($order['order_id'])) {
								$wc_order = wc_get_order($order['order_id']);
								if (false !== $wc_order) {
									$sandbox_mode = (bool) $wc_order->get_meta('_mproseo_bogfw_google_sandbox_order');
								}
							}
						} else {
							$sandbox_mode = (bool) $order['sandbox'];
						}
						if (isset($sandbox_mode)) {
							if ($creds_per_order) {
								$order_creds = mproseo_bogfw_get_google_order_creds($order, $users, $creds, $sandbox_mode, $site_id);
							}
							if (!is_null($order_creds) && !empty($order_creds[1]['access_token'])) {
								$content_args = array(
									'method' => 'POST',
									'headers' => array(
										'Authorization' => 'Bearer ' . $order_creds[1]['access_token'],
										'Content-Type' => 'application/json'
									)
								);
								$order_endpoint = "${order['merchant_id']}/orders/${order['google_id']}";
								$content_args['body'] = json_encode(array(
									'operationId' => "acknowledge_${timestamp}${key}" . ( !empty($order['order_id']) ? "_${order['order_id']}" : '' )
								));
								if (!$update_id_only) {
									$acknowledge_endpoint = "${order_endpoint}/acknowledge${quota_user}";
									if (!empty(mproseo_bogfw_google_api_request('content', $acknowledge_endpoint, $content_args, ( $creds_per_order ? $order_creds[0] : $user_id ), $order['merchant_id'], $sandbox_mode))) {
										$result[$order['google_id']]['acknowledged'] = true;
									}
								}
								if (!empty($order['order_id'])) {
									$content_args['body'] = json_encode(array(
										'operationId' => "assign_${timestamp}${key}_${order['order_id']}",
										'merchantOrderId' => $order['order_id']
									));
									$assign_endpoint = "${order_endpoint}/updateMerchantOrderId${quota_user}";
									if (!empty(mproseo_bogfw_google_api_request('content', $assign_endpoint, $content_args, ( $creds_per_order ? $order_creds[0] : $user_id ), $order['merchant_id'], $sandbox_mode))) {
										$result[$order['google_id']]['assigned'] = true;
									}
								}
							}
						}
					}
				}
				return $result;
			}
		}

		if (!function_exists('mproseo_bogfw_charge_google_orders')) {
			function mproseo_bogfw_charge_google_orders( $orders = array(), $user_id = null, $user_creds = null, $site_id = null ) {
				$result = array();
				if (empty($orders)) {
					return $result;
				}
				$timestamp = time();
				if (empty($user_creds['access_token'])) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (!empty($user_id) && isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					$order_creds = array($user_id, $user_creds);
				} else {
					$order_creds = null;
				}
				$creds_per_order = ( !isset($order_creds) );
				if (true === $creds_per_order) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
				foreach ($orders as $order) {
					if (!empty($order['merchant_id']) && !empty($order['google_id'])) {
						$result[$order['google_id']] = array('charged' => false);
						$sandbox_mode = null;
						if (!isset($order['sandbox'])) {
							if (!empty($order['order_id'])) {
								$wc_order = wc_get_order($order['order_id']);
								if (false !== $wc_order) {
									$sandbox_mode = (bool) $wc_order->get_meta('_mproseo_bogfw_google_sandbox_order');
								}
							}
						} else {
							$sandbox_mode = (bool) $order['sandbox'];
						}
						if (isset($sandbox_mode)) {
							if ($creds_per_order) {
								$order_creds = mproseo_bogfw_get_google_order_creds($order, $users, $creds, $sandbox_mode, $site_id);
							}
							if (!is_null($order_creds) && !empty($order_creds[1]['access_token'])) {
								$content_args = array('headers' => array('Authorization' => 'Bearer ' . $order_creds[1]['access_token']));
								$order_endpoint = "${order['merchant_id']}/orders/${order['google_id']}";
								$charge_args = $content_args;
								$charge_args['method'] = 'POST';
								$charge_endpoint = "${order_endpoint}/captureOrder${quota_user}";
								if (!empty(mproseo_bogfw_google_api_request('content', $charge_endpoint, $charge_args, null, null, $sandbox_mode))) {
									if (!$sandbox_mode) {
										$order_endpoint .= $quota_user;
										$charged_order = mproseo_bogfw_google_api_request('content', $order_endpoint, $content_args, null, null, $sandbox_mode);
									}
									if ($sandbox_mode || ( !empty($charged_order['paymentStatus']) && 'paymentCaptured' === $charged_order['paymentStatus'] )) {
										$result[$order['google_id']]['charged'] = true;
									}
								}
							}
						}
					}
				}
				return $result;
			}
		}

		if (!function_exists('mproseo_bogfw_google_payment_complete')) {
			function mproseo_bogfw_google_payment_complete( $order, $sandbox_mode = null ) {
				if ($order) {
					if (is_null($sandbox_mode)) {
						$sandbox_mode = (bool) $order->get_meta('_mproseo_bogfw_google_sandbox_order');
					}
					$order->payment_complete();
					$order->add_order_note(__('The payment method was charged successfully. ' . ( !$sandbox_mode ? 'You may proceed with fulfilling the order. ' : '' ) . 'Please make sure to create shipments, cancellations, and refunds to bring an order to its resolution. If shipments are not submitted, the order will be cancelled by Google automatically.', 'mproseo_bogfw'));
				}
			}
		}

		if (!function_exists('mproseo_bogfw_google_pending_payment')) {
			function mproseo_bogfw_google_pending_payment( $order = null ) {
				if ($order) {
					$order->add_order_note(__('Payment has not yet been captured. We do not recommend fulfilling this order until it has been. Please try updating order status to "Processing" to attempt a charge before proceeding.', 'mproseo_bogfw'));
				}
			}
		}

		if (!function_exists('mproseo_bogfw_sync_google_orders_array')) {
			function mproseo_bogfw_sync_google_orders_array( $orders = array(), $creds = array(), $order = array(), $timestamp = null, $sandbox_mode = null ) {
				if (empty($orders)) {
					$orders = array();
				}
				if (!empty($creds) && !empty($order['google_id']) && !empty($order['merchant_id'])) {
					if (!isset($orders['accounts'])) {
						$orders['accounts'] = array($creds[0] => array('locations' => array($order['merchant_id'])));
					} elseif (!isset($orders['accounts'][$creds[0]])) {
						$orders['accounts'][$creds[0]] = array('locations' => array($order['merchant_id']));
					} elseif (!in_array($order['merchant_id'], $orders['accounts'][$creds[0]]['locations'])) {
						$orders['accounts'][$creds[0]]['locations'][] = $order['merchant_id'];
					}
					if (!isset($orders['user_creds'])) {
						$orders['user_creds'] = array($creds[0] => $creds[1]);
					} elseif (!isset($orders['user_creds'][$creds[0]])) {
						$orders['user_creds'][$creds[0]] = $creds[1];
					}
					if (is_null($timestamp)) {
						$timestamp = time();
					}
					if (!isset($orders['orders'])) {
						$orders['orders'] = array(( $sandbox_mode ? 'sandbox' : 'live' ) => array($timestamp => array($order['merchant_id'] => array($order['google_id']))));
					} elseif (!isset($orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )])) {
						$orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )] = array($timestamp => array($order['merchant_id'] => array($order['google_id'])));
					} elseif (!isset($orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )][$timestamp])) {
						$orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )][$timestamp] = array($order['merchant_id'] => array($order['google_id']));
					} elseif (!isset($orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )][$timestamp][$order['merchant_id']])) {
						$orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )][$timestamp][$order['merchant_id']] = array($order['google_id']);
					} elseif (!in_array($order['google_id'], $orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )][$timestamp][$order['merchant_id']])) {
						$orders['orders'][( $sandbox_mode ? 'sandbox' : 'live' )][$timestamp][$order['merchant_id']][] = array($order['google_id']);
					}
				}
				return $orders;
			}
		}

		if (!function_exists('mproseo_bogfw_ship_google_orders_func')) {
			function mproseo_bogfw_ship_google_orders_func( $shipments = array(), $user_id = null, $user_creds = null, $site_id = null ) {
				$result = array();
				if (empty($shipments)) {
					return $result;
				}
				$timestamp = time();
				if (isset($shipments['order_id'])) {
					$shipments = array($shipments);
				}
				if (empty($user_creds['access_token'])) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (!empty($user_id) && isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					$order_creds = array($user_id, $user_creds);
				} else {
					$order_creds = null;
				}
				$creds_per_order = ( !isset($order_creds) );
				if (true === $creds_per_order) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
				$sync_orders = array();
				foreach ((array) $shipments as $key => $shipment) {
					$result[$key] = array('shipped' => false);
					if (!empty($shipment['line_items']) && !empty($shipment['shipments']) && ( count($shipment['line_items']) === 1 || count($shipment['shipments']) === 1 ) && !empty($shipment['order_id'])) {
						$order = wc_get_order($shipment['order_id']);
						if (false !== $order) {
							$google_order_id = ( !empty($shipment['google_id']) ? $shipment['google_id'] : $order->get_meta('_mproseo_bogfw_google_order_id') );
							$google_merchant_id = ( !empty($shipment['merchant_id']) ? $shipment['merchant_id'] : $order->get_meta('_mproseo_bogfw_google_merchant_id') );
							if (!empty($google_order_id) && !empty($google_merchant_id)) {
								if (empty($shipment['merchant_id'])) {
									$shipment['merchant_id'] = $google_merchant_id;
								}
								if (empty($shipment['google_id'])) {
									$shipment['google_id'] = $google_order_id;
								}
								$sandbox_mode = (bool) $order->get_meta('_mproseo_bogfw_google_sandbox_order');
								if ($creds_per_order) {
									$order_creds = mproseo_bogfw_get_google_order_creds($shipment, $users, $creds, $sandbox_mode, $site_id);
								}
								if (!is_null($order_creds) && !empty($order_creds[1]['access_token'])) {
									$content_args = array('headers' => array('Authorization' => 'Bearer ' . $order_creds[1]['access_token']));
									$order_endpoint = "${google_merchant_id}/orders/${google_order_id}";
									$ship_args = $content_args;
									$ship_args['method'] = 'POST';
									$ship_args['headers']['Content-Type'] = 'application/json';
									$ship_endpoint = "${order_endpoint}/shipLineItems${quota_user}";
									$content_body = array('operationId' => "ship_${timestamp}${key}_${shipment['order_id']}");
									$content_body['lineItems'] = array();
									foreach ($shipment['line_items'] as $item_id => $item) {
										$google_item_id = wc_get_order_item_meta($item_id, 'Item ID');
										if (!empty($google_item_id)) {
											$content_body['lineItems'][] = array('lineItemId' => $google_item_id, 'quantity' => intval($item['qty']));
										}
									}
									foreach ($shipment['shipments'] as $id => $ship) {
										$content_body['shipmentInfos'][] = array(
											'shipmentId' => "${timestamp}${key}${id}",
											'carrier' => $ship['carrier'],
											'trackingId' => $ship['id']
										);
									}
									$ship_args['body'] = json_encode($content_body);
									if (!empty(mproseo_bogfw_google_api_request('content', $ship_endpoint, $ship_args, null, null, $sandbox_mode))) {
										$order_endpoint .= $quota_user;
										$google_order = mproseo_bogfw_google_api_request('content', $order_endpoint, $content_args, null, null, $sandbox_mode);
										if (!empty($google_order)) {
											mproseo_bogfw_update_woo_order($google_order, $order, $sandbox_mode);
										} else {
											$sync_orders = mproseo_bogfw_sync_google_orders_array($sync_orders, $order_creds, $refund, $sandbox_mode);
										}
										$result[$key]['shipped'] = true;
									}
								}
							}
						}
					}
				}
				if (!empty($sync_orders)) {
					mproseo_bogfw_sync_google_orders($sync_orders['accounts'], false, $sync_orders['user_creds'], $sync_orders['orders']);
				}
				return $result;
			}
		}

		add_action('mproseo_bogfw_ship_google_orders', 'mproseo_bogfw_ship_google_orders_func', 10, 3);

		if (!function_exists('mproseo_bogfw_ship_google_items')) {
			function mproseo_bogfw_ship_google_items() {
				if (!empty($_POST['order_id']) && is_numeric($_POST['order_id']) && intval($_POST['order_id']) > 1 && !empty($_POST['line_items']) && !empty($_POST['shipments'])) {
					$order_id = intval($_POST['order_id']);
					if (!empty($_POST['security']) && wp_verify_nonce(sanitize_key($_POST['security']), 'order-item') && current_user_can('edit_shop_orders', $order_id)) {
						$ship = array('order_id' => $order_id, 'line_items' => array(), 'shipments' => array());
						foreach ((array) map_deep($_POST['line_items'], 'sanitize_text_field') as $line_item) {
							if (!empty($line_item['id']) && !empty($line_item['qty']) && is_numeric($line_item['qty']) && intval($line_item['qty']) > 0) {
								$ship['line_items'][$line_item['id']] = array('qty' => intval($line_item['qty']));
							}
						}
						foreach ((array) map_deep($_POST['shipments'], 'sanitize_text_field') as $shipment) {
							if (!empty($shipment['carrier']) && !empty($shipment['id'])) {
								$ship['shipments'][] = $shipment;
							}
						}
						if (!empty($ship['line_items']) && !empty($ship['shipments']) && ( count($ship['line_items']) === 1 || count($ship['shipments']) === 1 )) {
							if (!empty(mproseo_bogfw_ship_google_orders_func(array($ship))[0]['shipped'])) {
								wp_die();
							} else {
								wp_send_json_error(array('error' => 'Shipment not accepted by Google. Please contact support if issue continues.', 'code' => 400), 400);
							}
						}
					} else {
						wp_send_json_error(array('error' => 'Access denied', 'code' => 401), 401);
					}
				}
				wp_send_json_error(array('error' => 'Shipment request invalid. Please try again.', 'code' => 400), 400);
			}
		}

		add_action('wp_ajax_mproseo_bogfw_google_shipment_create_handler', 'mproseo_bogfw_ship_google_items');

		if (!function_exists('mproseo_bogfw_update_google_shipments_func')) {
			function mproseo_bogfw_update_google_shipments_func( $shipments = array(), $user_id = null, $user_creds = null, $site_id = null ) {
				$result = array();
				if (empty($shipments)) {
					return $result;
				}
				$timestamp = time();
				if (isset($shipments['order_id'])) {
					$shipments = array($shipments);
				}
				if (empty($user_creds['access_token'])) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (!empty($user_id) && isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					$order_creds = array($user_id, $user_creds);
				} else {
					$order_creds = null;
				}
				$creds_per_order = ( !isset($order_creds) );
				if (true === $creds_per_order) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
				$sync_orders = array();
				foreach ((array) $shipments as $key => $shipment) {
					$result[$key] = array('updated' => false);
					$order_id = ( !empty($shipment['order_id']) && is_numeric($shipment['order_id']) ? intval($shipment['order_id']) : 0 );
					if (!empty($shipment['id']) && $order_id > 1 && ( ( !empty($shipment['carrier']) && !empty($shipment['tracking_id']) ) || !empty($shipment['status']) )) {
						$order = wc_get_order($order_id);
						if (false !== $order) {
							$google_order_id = ( !empty($shipment['google_id']) ? $shipment['google_id'] : $order->get_meta('_mproseo_bogfw_google_order_id') );
							$google_merchant_id = ( !empty($shipment['merchant_id']) ? $shipment['merchant_id'] : $order->get_meta('_mproseo_bogfw_google_merchant_id') );
							if (!empty($google_order_id) && !empty($google_merchant_id)) {
								if (empty($shipment['merchant_id'])) {
									$shipment['merchant_id'] = $google_merchant_id;
								}
								if (empty($shipment['google_id'])) {
									$shipment['google_id'] = $google_order_id;
								}
								$sandbox_mode = (bool) $order->get_meta('_mproseo_bogfw_google_sandbox_order');
								if ($creds_per_order) {
									$order_creds = mproseo_bogfw_get_google_order_creds($shipment, $users, $creds, $sandbox_mode, $site_id);
								}
								if (!is_null($order_creds) && !empty($order_creds[1]['access_token'])) {
									$content_args = array('headers' => array('Authorization' => 'Bearer ' . $order_creds[1]['access_token']));
									$order_endpoint = "${google_merchant_id}/orders/${google_order_id}";
									$update_args = $content_args;
									$update_args['method'] = 'POST';
									$update_args['headers']['Content-Type'] = 'application/json';
									$update_endpoint = "${order_endpoint}/updateShipment${quota_user}";
									$content_body = array('operationId' => "update_${timestamp}${key}_${shipment['order_id']}");
									$content_body['shipmentId'] = $shipment['id'];
									if (!empty($shipment['carrier']) && !empty($shipment['tracking_id'])) {
										$content_body['carrier'] = $shipment['carrier'];
										$content_body['trackingId'] = $shipment['tracking_id'];
									}
									if (!empty($shipment['status'])) {
										$content_body['status'] = $shipment['status'];
									}
									$update_args['body'] = json_encode($content_body);
									if (!empty(mproseo_bogfw_google_api_request('content', $update_endpoint, $update_args, null, null, $sandbox_mode))) {
										$order_endpoint .= $quota_user;
										$google_order = mproseo_bogfw_google_api_request('content', $order_endpoint, $content_args, null, null, $sandbox_mode);
										if (!empty($google_order)) {
											mproseo_bogfw_woo_import_shipments($google_order, $order);
											mproseo_bogfw_update_woo_order($google_order, $order, $sandbox_mode);
										} else {
											$sync_orders = mproseo_bogfw_sync_google_orders_array($sync_orders, $order_creds, $refund, $sandbox_mode);
										}
										$result[$key]['updated'] = true;
									}
								}
							}
						}
					}
				}
				if (!empty($sync_orders)) {
					mproseo_bogfw_sync_google_orders($sync_orders['accounts'], false, $sync_orders['user_creds'], $sync_orders['orders']);
				}
				return $result;
			}
		}

		add_action('mproseo_bogfw_update_google_shipments', 'mproseo_bogfw_update_google_shipments_func', 10, 3);

		if (!function_exists('mproseo_bogfw_update_google_shipment_item')) {
			function mproseo_bogfw_update_google_shipment_item() {
				if (!empty($_POST['order_id']) && is_numeric($_POST['order_id']) && intval($_POST['order_id']) > 1 && !empty($_POST['id']) && !empty($_POST['carrier']) && !empty($_POST['tracking_id'])) {
					$order_id = intval($_POST['order_id']);
					if (!empty($_POST['security']) && wp_verify_nonce(sanitize_key($_POST['security']), 'order-item') && current_user_can('edit_shop_orders', $order_id)) {
						$update = array(
							'order_id' => $order_id,
							'id' => sanitize_text_field($_POST['id']),
							'carrier' => sanitize_text_field($_POST['carrier']),
							'tracking_id' => sanitize_text_field($_POST['tracking_id'])
						);
						if (!empty($_POST['status'])) {
							$update['status'] = sanitize_text_field($_POST['status']);
						}
						if (!empty(mproseo_bogfw_update_google_shipments_func(array($update))[0]['updated'])) {
							wp_die();
						} else {
							wp_send_json_error(array('error' => 'Shipment update not accepted by Google. Please contact support if issue continues.', 'code' => 400), 400);
						}
					} else {
						wp_send_json_error(array('error' => 'Access denied', 'code' => 401), 401);
					}
				}
				wp_send_json_error(array('error' => 'Shipment update request invalid. Please try again.', 'code' => 400), 400);
			}
		}

		add_action('wp_ajax_mproseo_bogfw_google_shipment_update_handler', 'mproseo_bogfw_update_google_shipment_item');

		if (!function_exists('mproseo_bogfw_get_google_supported_carriers')) {
			function mproseo_bogfw_get_google_supported_carriers( $merchant_id = null, $creds = null, $sandbox_mode = null, $site_id = null ) {
				$carriers = array();
				if (is_null($merchant_id)) {
					return $carriers;
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				if (is_null($creds)) {
					$creds = mproseo_bogfw_get_google_order_creds(array('merchant_id' => $merchant_id), null, null, $sandbox_mode, $site_id);
				}
				if (!is_null($creds) && !empty($creds[1]['access_token'])) {
					$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
					$carriers_endpoint = "${merchant_id}/supportedCarriers${quota_user}";
					$content_args = array('headers' => array('Authorization' => 'Bearer ' . $creds[1]['access_token']));
					$supported_carriers = mproseo_bogfw_google_api_request('content', $carriers_endpoint, $content_args, null, null, $sandbox_mode);
					if (!empty($supported_carriers['carriers'])) {
							$carriers = array_column($supported_carriers['carriers'], 'name');
					}
				}
				return $carriers;
			}
		}

		add_action('add_meta_boxes', function() {
			global $thepostid, $post;
			$hpos_enabled = ( class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class) && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled() );
			$post_id = ( $hpos_enabled && !empty($_GET['id']) ? intval($_GET['id']) : ( empty($thepostid) ? ( !empty($post) ? $post->ID : ( !empty($_GET['post']) ? intval($_GET['post']) : 0 ) ) : $thepostid ) );
			if (0 === $post_id && !empty($_GET['rest_route'])) {
				$rest_route = explode('/', sanitize_text_field($_GET['rest_route']));
				$orders_key = array_search('orders', $rest_route);
				if (false !== $orders_key && !empty($rest_route[$orders_key + 1]) && is_numeric($rest_route[$orders_key + 1])) {
					$post_id = intval($rest_route[$orders_key + 1]);
				}
			}
			if ($post_id > 1 && current_user_can('edit_shop_orders', $post_id)) {
				$order = wc_get_order($post_id);
				if (false !== $order) {
					$google_order_id = $order->get_meta('_mproseo_bogfw_google_order_id');
					if (!empty($google_order_id)) {
						$screen = ( $hpos_enabled && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id('shop-order') : 'shop_order' );
						remove_meta_box('woocommerce-shipment-tracking', $screen, 'side');
						remove_meta_box('woocommerce-order-shipment-tracking', $screen, 'side');
						$sandbox_mode = (bool) $order->get_meta('_mproseo_bogfw_google_sandbox_order');
						$google_merchant_id = $order->get_meta('_mproseo_bogfw_google_merchant_id');
						$can_manage_order = false;
						if (!empty($google_merchant_id)) {
							$site_id = mproseo_bogfw_site_id();
							$creds = mproseo_bogfw_get_google_order_creds(array('merchant_id' => $google_merchant_id), null, null, $sandbox_mode, $site_id);
							if (!is_null($creds) && !empty($creds[1]['access_token'])) {
								$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
								$content_args = array('headers' => array('Authorization' => 'Bearer ' . $creds[1]['access_token']));
								$order_endpoint = "${google_merchant_id}/orders/${google_order_id}${quota_user}";
								$google_order = mproseo_bogfw_google_api_request('content', $order_endpoint, $content_args, null, null, $sandbox_mode);
								if (!empty($google_order)) {
									$can_manage_order = true;
									mproseo_bogfw_update_woo_order($google_order, $order, $sandbox_mode);
									$supported_carriers = mproseo_bogfw_get_google_supported_carriers($google_merchant_id, $creds, $sandbox_mode, $site_id);
									if (!in_array($google_order['status'], array_merge(array('canceled'), mproseo_bogfw_get_google_complete_statuses()))) {
										add_meta_box('mproseo-bogfw-create-shipment', __('Create Shipment', 'mproseo_bogfw'), function( $wc_order ) use( $order, $supported_carriers ) {
											if (false === $order) {
												if ($wc_order) {
													$order = ( $wc_order instanceof WP_Post ? wc_get_order($wc_order->ID) : $wc_order );
												} else {
													echo 'There was an error loading your order.<br /><br />Please try refreshing the page and contact support if the issue persists.';
												}
											}
											if (false !== $order) {
												echo '<noscript>Javascript is required to use this feature.<br /><br />Please enable and refresh this page.</noscript>';
												echo '<div id="mproseo_bogfw_create_shipments" class="mproseo_bogfw_create_shipments" style="display: none;">';
												if (!empty($supported_carriers)) {
													$line_items = array();
													$order_items = $order->get_items();
													if (!empty($order_items)) {
														foreach ($order_items as $order_item_id => $item) {
															if (false !== wc_get_order_item_meta($order_item_id, 'Item ID')) {
																$pending = wc_get_order_item_meta($order_item_id, 'Pending');
																if (!empty($pending) && is_numeric($pending) && intval($pending) > 0) {
																	$line_items[$order_item_id] = array(
																		'name' => $item->get_name(),
																		'pending' => intval($pending)
																	);
																}
															}
														}
													}
													if (!empty($line_items)) {
														echo '<strong>Choose type of shipment:</strong><br /><br /><div id="mproseo_bogfw_ship" class="mproseo_bogfw_ship"><label for="mproseo_bogfw_ship_multi_type"><input type="radio" id="mproseo_bogfw_ship_multi_type" class="mproseo_bogfw_ship_type" name="ship_item_type" value="MULTI" checked="checked"/>Ship one or more items together</label><br /><br /><label for="mproseo_bogfw_multi_ship_type"><input type="radio" id="mproseo_bogfw_multi_ship_type" class="mproseo_bogfw_ship_type" name="ship_item_type" value="SINGLE"/>Split item into one or more shipments</label><br /><br /><div id="mproseo_bogfw_ship_multi" class="mproseo_bogfw_ship"><table width="100%" style="text-align: left; width: 100%; table-layout: fixed;"><colgroup><col style="width: 75%;"/><col style="width: 25%"/></colgroup><thead><tr><th>Items</th><th>Quantity</th></tr></thead><tbody>';
														foreach ($line_items as $item_id => $item) {
															echo '<tr><td><div style="overflow: auto;">' . esc_html($item['name']) . '</div></td><td><input class="mproseo_bogfw_ship mproseo_bogfw_ship_qty" type="number" name="ship_items[]" data-item-id="' . esc_attr($item_id) . '" min="0" max="' . esc_attr($item['pending']) . '" placeholder="0"/></td></tr>';
														}
														echo '</tbody></table></div><div style="display: none;" id="mproseo_bogfw_multi_ship" class="mproseo_bogfw_ship"><table style="text-align: left; width: 100%; table-layout: fixed;"><colgroup><col style="width: 75%"/><col style="width: 25%"/></colgroup><thead><tr><th>Item</th><th>Quantity</th></tr></thead><tbody><tr><td><select id="mproseo_bogfw_multi_ship_item">';
														foreach ($line_items as $item_id => $item) {
															echo '<option value="' . esc_attr($item_id) . '" data-qty-pending="' . esc_attr($item['pending']) . '">' . esc_html($item['name']) . '</option>';
														}
														echo '</select></td><td><input class="mproseo_bogfw_ship mproseo_bogfw_ship_qty" type="number" name="ship_items[]" id="mproseo_bogfw_multi_ship_item_qty" min="0" max="0" placeholder="0"/></td></tr></tbody></table></div><br /><div id="mproseo_bogfw_ship_tracking" class="mproseo_bogfw_ship"><table width="100%" style="width: 100%; table-layout: fixed;"><colgroup><col style="width: 30%;"/><col style="width: 22%;"/><col style="width: 40%;"/><col style="width: 8%"/></colgroup><thead><tr style="text-align: left;"><th>Carrier</th><th style="display: none;" class="mproseo_bogfw_ship_custom_carrier_column">Custom</th><th>Tracking</th><th></th></tr></thead><tbody><tr id="mproseo_bogfw_ship_tracking_line"><td><select class="mproseo_bogfw_ship mproseo_bogfw_ship_tracking_carriers" name="mproseo_bogfw_ship_tracking_carriers[]">';
														foreach ($supported_carriers as $carrier) {
															echo '<option value="' . esc_attr($carrier) . '">' . esc_html__($carrier, 'mproseo_bogfw') . '</option>';
														}
														echo '<option value="">Other</option></select></td><td style="display: none;" class="mproseo_bogfw_ship mproseo_bogfw_ship_custom_carrier_column custom_carrier"><input style="display: none;" class="mproseo_bogfw_ship custom_carrier" type="text" size="1" name="mproseo_bogfw_ship_custom_carriers[]"/></td><td><input class="mproseo_bogfw_ship mproseo_bogfw_ship_tracking" type="text" size="6" name="mproseo_bogfw_ship_tracking_numbers[]"/></td><td style="text-align: right;" class="delete_tracker"></td></tr></tbody></table><br /><button type="button" style="display: none;" id="mproseo_bogfw_ship_add_tracking" class="button button-primary" disabled="disabled">Add Shipment</button></div><div style="text-align: right;" id="mproseo_bogfw_ship_button" class="mproseo_bogfw_ship"><button type="button" class="button button-primary ship_button" disabled="disabled">Submit</button></div></div>';
													} else {
														echo 'There are no pending items remaining.';
													}
												} else {
													echo 'No supported carriers were found. Please try refreshing the page.';
												}
												echo '</div><div id="mproseo_bogfw_create_shipments_no_load" style="display: none;">Unable to load required asset for this feature to work properly.<br /><br />Please try refreshing the page and contact support if the issue persists.</div><script>document.addEventListener("DOMContentLoaded", function() { const no_load_msg = document.getElementById("mproseo_bogfw_create_shipments_no_load"); if ( no_load_msg != null) { no_load_msg.style.display = "block"; } });</script>';
											}
										}, $screen, 'side', 'high');
									}
									if (!empty($google_order['shipments'])) {
										add_meta_box('mproseo-bogfw-update-shipments', __('Manage Shipments', 'mproseo_bogfw'), function() use( $google_order, $supported_carriers ) {
											$statuses = array('delivered', 'undeliverable');
											echo '<noscript>Javascript is required to use this feature.<br /><br />Please enable and refresh this page.</noscript>';
											echo '<div id="mproseo_bogfw_update_shipments" class="mproseo_bogfw_update_shipments" style="display: none;">';
											$product_names = array();
											foreach ($google_order['shipments'] as $key => $shipment) {
												echo '<div ' . ( 0 !== $key ? 'style="border-top: 2px solid black;" ' : '' ) . 'width="100%" style="width: 100%;" class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_tracking" data-ship-id="' . esc_attr($shipment['id']) . '">' . ( 0 !== $key ? '<br />' : '' );
												$shipment_carriers = $supported_carriers;
												if (!empty($shipment['lineItems'])) {
													echo "<strong>Shipment contains:</strong><br />";
													foreach ($shipment['lineItems'] as $line_item) {
														if (array_key_exists($line_item['lineItemId'], $product_names)) {
															$product_name = $product_names[$line_item['lineItemId']];
														} else {
															$order_item_key = array_search($line_item['lineItemId'], array_column($google_order['lineItems'], 'id'));
															if (false !== $order_item_key) {
																if (!empty($google_order['lineItems'][$order_item_key]['product']['title'])) {
																	$product_name = $google_order['lineItems'][$order_item_key]['product']['title'];
																} elseif (!empty($google_order['lineItems'][$order_item_key]['product']['mpn'])) {
																	$product_brand = ( !empty($google_order['lineItems'][$order_item_key]['product']['brand']) ? $google_order['lineItems'][$order_item_key]['product']['brand'] . ' ' : '' );
																	$product_name = $product_brand . $google_order['lineItems'][$order_item_key]['product']['mpn'];
																} else {
																	$product_name = ( !empty($google_order['lineItems'][$order_item_key]['product']['offerId']) ? $google_order['lineItems'][$order_item_key]['product']['offerId'] : ( !empty($google_order['lineItems'][$order_item_key]['product']['id']) ? $google_order['lineItems'][$order_item_key]['product']['id'] : $line_item['lineItemId'] ) );
																}
															} else {
																$product_name = $line_item['lineItemId'];
															}
															$product_names[$line_item['lineItemId']] = $product_name;
														}
														echo esc_html($product_name) . ' x ' . esc_html($line_item['quantity']) . '<br />';
													}
												}
												echo '<table class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_tracking" width="100%" style="width: 100%; table-layout: fixed;"><colgroup><col style="width: 30%;"/><col style="width: 22%;"/><col style="width: 40%;"/><col style="width: 8%;"/></colgroup><thead><tr style="text-align: left;"><th>Carrier</th><th style="display: none;" class="mproseo_bogfw_update_shipments_custom_carrier_column">Custom</th><th>Tracking</th><th></th></tr></thead><tbody>';

												echo '<tr class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_tracking"><td><select class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_tracking_carriers" name="mproseo_bogfw_update_shipments_tracking_carriers[]" data-set-value="' . esc_attr($shipment['carrier']) . '" disabled="disabled">';
												if (!in_array($shipment['carrier'], $shipment_carriers)) {
													$shipment_carriers[] = $shipment['carrier'];
												}
												foreach ($shipment_carriers as $carrier) {
													$selected = ( $carrier === $shipment['carrier'] );
													echo '<option value="' . esc_attr($carrier) . '"' . ( $selected ? ' selected="selected"' : '' ) . '>' . esc_html__($carrier, 'mproseo_bogfw') . '</option>';
												}
												$new_statuses = ( !in_array($shipment['status'], $statuses) ? $statuses : array() );
												echo '<option value="">Other</option></select></td><td style="display: none;" class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_custom_carrier_column custom_carrier"><input style="display: none;" class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_custom_carrier custom_carrier" type="text" size="1" name="mproseo_bogfw_manage_custom_carriers[]" data-set-value="" disabled="disabled"/></td><td><input type="text" class="mproseo_bogfw_update_shipments_tracking_numbers" size="6" name="mproseo_bogfw_update_shipments_tracking_numbers[]" value="' . esc_attr($shipment['trackingId']) . '" data-set-value="' . esc_attr($shipment['trackingId']) . '" disabled="disabled"/></td><td style="text-align: right;" class="edit_tracker"><button type="button" class="button button-secondary edit_tracker">Edit</button><span style="display: none;"><button type="button" class="button button-secondary cancel_edit_tracker">X</button></span></td></tr></tbody></table><div style="display: none;" class="mproseo_bogfw_update_shipments update_tracker"><button type="button" class="button button-secondary update_tracker">Update</button><br /></div><br /><table class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_status" width="100%" style="border: 1px solid black; width: 100%; table-layout: fixed;"><colgroup><col style="width: 40%;"/>' . ( !empty($new_statuses) ? '<col class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_new_status" style="width: 60%;"/>' : '' ) . '</colgroup><thead><tr><th>Current Status</th>' . ( !empty($new_statuses) ? '<th class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_new_status">New Status</th>' : '' ) . '</tr></thead><tbody><tr><td class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_current_status" style="text-align: center;"><strong>' . esc_html__(ucfirst($shipment['status']), 'mproseo_bogfw') . '</strong></td>';
												if (!empty($new_statuses)) {
													echo '<td class="mproseo_bogfw_update_shipments mproseo_bogfw_update_shipments_new_status" style="text-align: center;">';
													$first_status = true;
													foreach ($new_statuses as $status) {
														if (!$first_status) {
															echo '<br /><br />';
														} else {
															$first_status = false;
														}
														echo '<button type="button" class="button button-primary status_button" data-new-status="' . esc_attr($status) . '">' . esc_html__(ucfirst($status), 'mproseo_bogfw') . '</button>';
													}
													echo '</td>';
												}
												echo '</tr></tbody></table></div>' . ( ( count($google_order['shipments']) - 1 ) !== $key ? '<br />' : '' );
											}
											echo '</div><div id="mproseo_bogfw_update_shipments_no_load" style="display: none;">Unable to load required asset for this feature to work properly.<br /><br />Please try refreshing the page and contact support if the issue persists.</div><script>document.addEventListener("DOMContentLoaded", function() { const no_load_msg = document.getElementById("mproseo_bogfw_update_shipments_no_load"); if ( no_load_msg != null) { no_load_msg.style.display = "block"; } });</script>';
										}, $screen, 'side', 'high');
									}
								}
							}
						}
						if (!$can_manage_order) {
							add_meta_box('mproseo-bogfw-manage', __('Manage Google Order', 'mproseo_bogfw'), function() {
								echo 'Unable to manage Google order. This could be caused by an issue with your account, your server, or a Google outage. Please <a href="' . esc_url(add_query_arg('page', urlencode(mproseo_bogfw_get_admin_path()), admin_url('admin.php'))) . '" target="_blank">ensure your account is still connected</a> and try refreshing the page.';
							}, $screen, 'side', 'high');
						}
					}
				}
			}
		}, 90);

		if (!function_exists('mproseo_bogfw_cancel_google_orders')) {
			function mproseo_bogfw_cancel_google_orders( $cancellations = array(), $user_id = null, $user_creds = null, $site_id = null ) {
				if (empty($cancellations)) {
					return;
				}
				$timestamp = time();
				if (isset($cancellations['google_id'])) {
					$cancellations = array($cancellations);
				}
				if (empty($user_creds['access_token'])) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (!empty($user_id) && isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					$order_creds = array($user_id, $user_creds);
				} else {
					$order_creds = null;
				}
				$creds_per_order = ( !isset($order_creds) );
				if (true === $creds_per_order) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
				$sync_orders = array();
				foreach ((array) $cancellations as $key => $cancellation) {
					if (!empty($cancellation['order_id'])) {
						$order = wc_get_order($cancellation['order_id']);
						if (false !== $order) {
							$google_order_id = ( !empty($cancellation['google_id']) ? $cancellation['google_id'] : $order->get_meta('_mproseo_bogfw_google_order_id') );
							$google_merchant_id = ( !empty($cancellation['merchant_id']) ? $cancellation['merchant_id'] : $order->get_meta('_mproseo_bogfw_google_merchant_id') );
							if (!empty($google_order_id) && !empty($google_merchant_id)) {
								if (empty($cancellation['merchant_id'])) {
									$cancellation['merchant_id'] = $google_merchant_id;
								}
								if (empty($cancellation['google_id'])) {
									$cancellation['google_id'] = $google_order_id;
								}
								$sandbox_mode = (bool) $order->get_meta('_mproseo_bogfw_google_sandbox_order');
								if ($creds_per_order) {
									$order_creds = mproseo_bogfw_get_google_order_creds($cancellation, $users, $creds, $sandbox_mode, $site_id);
								}
								if (!is_null($order_creds) && !empty($order_creds[1]['access_token'])) {
									$content_args = array('headers' => array('Authorization' => 'Bearer ' . $order_creds[1]['access_token']));
									$order_endpoint = "${cancellation['merchant_id']}/orders/${cancellation['google_id']}";
									$cancel_args = $content_args;
									$cancel_args['method'] = 'POST';
									$cancel_args['headers']['Content-Type'] = 'application/json';
									$cancel_body = array('operationId' => "cancel_${timestamp}${key}_${cancellation['order_id']}");
									$cancel_body['reason'] = ( !empty($cancellation['general_reason']) ? $cancellation['general_reason'] : 'other' );
									$cancel_body['reasonText'] = ( !empty($cancellation['reason']) ? $cancellation['reason'] : 'No explanation provided' );
									$cancel_endpoint = "${order_endpoint}/cancel${quota_user}";
									$cancel_args['body'] = json_encode($cancel_body);
									if (!empty(mproseo_bogfw_google_api_request('content', $cancel_endpoint, $cancel_args, null, null, $sandbox_mode))) {
										$order_endpoint .= $quota_user;
										$google_order = mproseo_bogfw_google_api_request('content', $order_endpoint, $content_args, null, null, $sandbox_mode);
										if (!empty($google_order)) {
											mproseo_bogfw_update_woo_order($google_order, $order, $sandbox_mode);
										} else {
											$sync_orders = mproseo_bogfw_sync_google_orders_array($sync_orders, $order_creds, $cancellation, $sandbox_mode);
										}
									}
								}
							}
						}
					}
				}
				if (!empty($sync_orders)) {
					mproseo_bogfw_sync_google_orders($sync_orders['accounts'], false, $sync_orders['user_creds'], $sync_orders['orders']);
				}
			}
		}

		if (!function_exists('mproseo_bogfw_woo_order_cancelled')) {
			function mproseo_bogfw_woo_order_cancelled( $woo_order_id ) {
				if (!empty($woo_order_id)) {
					$woo_order = wc_get_order($woo_order_id);
					if ($woo_order) {
						$google_order_id = $woo_order->get_meta('_mproseo_bogfw_google_order_id');
						if (!empty($google_order_id)) {
							if (!empty($woo_order->get_meta('_mproseo_bogfw_google_order_shipped'))) {
								mproseo_bogfw_woo_order_status_update($woo_order, $old_status, 'Unable to cancel order because fulfillments have been made.');
								$woo_order->save();
							} else {
								$google_merchant_id = $woo_order->get_meta('_mproseo_bogfw_google_merchant_id');
								if (!empty($google_merchant_id)) {
									$cancel_args = array(array('order_id' => $woo_order_id, 'google_id' => $google_order_id, 'merchant_id' => $google_merchant_id));
									mproseo_bogfw_cancel_google_orders($cancel_args);
								} else {
									mproseo_bogfw_woo_order_status_update($woo_order, $old_status, 'Unable to cancel order because order meta may be missing, malformed, or corrupt. We recommend resyncing the order.');
									$woo_order->save();
								}
							}
						}
					}
				}
			}
		}

		add_action('woocommerce_order_status_cancelled', 'mproseo_bogfw_woo_order_cancelled', 10, 1);

		if (!function_exists('mproseo_bogfw_woo_import_shipments')) {
			function mproseo_bogfw_woo_import_shipments( $google_order, $woo_order, $parsed_notes = null ) {
				if (!empty($google_order['shipments']) && $woo_order) {
					if (is_null($parsed_notes)) {
						$order_notes = wc_get_order_notes(array('order_id' => $woo_order->get_id()));
						$parsed_notes = mproseo_bogfw_get_order_notes_by_id($order_notes);
					}
					$notes_exist = (bool) ( !empty($parsed_notes) );
					foreach ($google_order['shipments'] as $order_shipment) {
						if (!empty(trim($order_shipment['id']))) {
							$shipment_details = trim(mproseo_bogfw_get_shipment_note($order_shipment));
							if (!empty($shipment_details)) {
								$add_note = true;
								if ($notes_exist && !empty($parsed_notes[trim($order_shipment['id'])])) {
									foreach ((array) $parsed_notes[trim($order_shipment['id'])] as $note_id => $shipment_note) {
										if ($shipment_details !== trim($shipment_note)) {
											wc_delete_order_note($note_id);
										} else {
											$add_note = false;
										}
									}
								}
								if ($add_note) {
									$woo_order->add_order_note(__(trim($order_shipment['id']) . "::: ${shipment_details}", 'mproseo_bogfw'));
								}
							}
						}
					}
					$woo_order->update_meta_data('_mproseo_bogfw_google_order_shipped', true);
					$woo_order->save_meta_data();
					do_action('mproseo_bogfw_order_shipments', $woo_order->get_id(), $google_order['shipments'], ( !empty($google_order['deliveryDetails']['address']['country']) ? $google_order['deliveryDetails']['address']['country'] : 'US' ));
				}
			}
		}

		if (!function_exists('mproseo_bogfw_update_woo_order')) {
			function mproseo_bogfw_update_woo_order( $google_order, $woo_order, $sandbox_mode = null, $update_items = true ) {
				/*$refund = wc_get_order(44);
				if (false !== $refund) {
					$refund->delete(true);
				}*/
				if (empty($google_order) || false === $woo_order) {
					return;
				}
				if (!is_bool($sandbox_mode)) {
					$sandbox_mode = (bool) $woo_order->get_meta('_mproseo_bogfw_google_sandbox_order');
				}
				$woo_order_status = $woo_order->get_status();
				$google_order_status = mproseo_bogfw_get_google_order_woo_status($google_order, $sandbox_mode);
				if ('on-hold' === $woo_order_status && !in_array($google_order_status, array('on-hold', 'cancelled'))) {
					mproseo_bogfw_google_payment_complete($woo_order, $sandbox_mode);
				}
				$current_order_status = $google_order['status'];
				$woo_order->update_meta_data('_mproseo_bogfw_google_order_id', $google_order['id']);
				$woo_order->update_meta_data('_mproseo_bogfw_google_merchant_id', $google_order['merchantId']);
				$order_notes = wc_get_order_notes(array('order_id' => $woo_order->get_id()));
				$parsed_notes = mproseo_bogfw_get_order_notes_by_id($order_notes);
				$notes_exist = (bool) ( !empty($parsed_notes) );
				$order_items = $woo_order->get_items();
				if (!empty($order_items)) {
					$google_order_item_ids = array_column($google_order['lineItems'], 'id');
					foreach ($order_items as $order_item_id => $item) {
						$google_item_id = wc_get_order_item_meta($order_item_id, 'Item ID');
						if (!empty($google_item_id)) {
							$google_order_item_key = array_search($google_item_id, $google_order_item_ids);
							if (false !== $google_order_item_key && isset($google_order['lineItems'][$google_order_item_key])) {
								$product = $google_order['lineItems'][$google_order_item_key];
								mproseo_bogfw_update_dynamic_item_meta($woo_order, $order_item_id, $product);
								if (!empty($product['returns'])) {
									foreach ($product['returns'] as $return) {
										if (!$notes_exist || !isset($parsed_notes[$product['id']]) || !in_array($return['creationDate'], $parsed_notes[$product['id']])) {
											$return_note = mproseo_bogfw_get_cancellation_or_return_note('returned', $return, $product);
											$woo_order->add_order_note(__($return_note, 'mproseo_bogfw'));
											if (empty($parsed_notes[$product['id']])) {
												$parsed_notes[$product['id']] = array();
											}
											$parsed_notes[$product['id']][] = $return['creationDate'];
										}
									}
								}
							}
						}
					}
				}
				$woo_order->save_meta_data();
				if (!empty($google_order['pickupDetails'])) {
					$pickup_details = trim(mproseo_bogfw_get_pickup_note($google_order));
					if (!empty($pickup_details)) {
						$add_note = true;
						if ($notes_exist && !empty($parsed_notes['Pickup Details'])) {
							foreach ((array) $parsed_notes['Pickup Details'] as $note_id => $pickup_note) {
								if ($pickup_details !== trim($pickup_note)) {
									wc_delete_order_note($note_id);
								} else {
									$add_note = false;
								}
							}
						}
						if ($add_note) {
							$woo_order->add_order_note(__("Pickup Details::: ${pickup_details}", 'mproseo_bogfw'));
						}
					}
				}
				if (!empty($google_order['annotations'])) {
					foreach ($google_order['annotations'] as $order_annotation) {
						if (!empty(trim($order_annotation['key']))) {
							if (!empty(trim($order_annotation['value']))) {
								$add_note = true;
								if ($notes_exist && !empty($parsed_notes[trim($order_annotation['key'])])) {
									foreach ((array) $parsed_notes[trim($order_annotation['key'])] as $note_id => $annotation_note) {
										if (trim($order_annotation['value']) !== trim($annotation_note)) {
											wc_delete_order_note($note_id);
										} else {
											$add_note = false;
										}
									}
								}
								if ($add_note) {
									$woo_order->add_order_note(__(trim($order_annotation['key']) . '::: ' . trim($order_annotation['value']), 'mproseo_bogfw'));
								}
							}
						}
					}
				}
				if (!empty($google_order['shipments'])) {
					mproseo_bogfw_woo_import_shipments($google_order, $woo_order, ( $notes_exist ? $parsed_notes : array() ));
				}
				if (!empty($google_order['refunds'])) {
					$woo_max_refund = $woo_order->get_remaining_refund_amount();
					if ($woo_max_refund > 0) {
						$woo_current_net_total = number_format(($woo_order->get_total() - $woo_order->get_total_refunded()), 2);
						$google_current_net_total = number_format($google_order['netPriceAmount']['value'], 2);
						if ($woo_current_net_total > $google_current_net_total) {
							$proposed_refund = $woo_current_net_total - $google_current_net_total;
							remove_filter('woocommerce_email_enabled_customer_refunded_order', 'mproseo_bogfw_woo_customer_refunded_email_enabled', 10);
							remove_filter('woocommerce_email_enabled_customer_partially_refunded_order', 'mproseo_bogfw_woo_customer_refunded_email_enabled', 10);
							$refund_order_args = array(
								'amount' => number_format(min($proposed_refund, $woo_max_refund), 2),
								'reason' => ('Synced refunds with Google on ' . mproseo_bogfw_parse_google_date(date('Y-m-d\Th:i:s\Z')) . '.'),
								'order_id' => $woo_order->get_id(),
								'google_refund' => true
							);
							if (!is_wp_error(wc_create_refund($refund_order_args))) {
								$woo_max_refund = $woo_order->get_remaining_refund_amount();
								$woo_past_refund = $woo_order->get_total_refunded();
								if ($woo_max_refund <= 0 && $woo_past_refund > 0 && !in_array($google_order_status, array('on-hold', 'cancelled'))) {
									$google_order_status = 'refunded';
								}
							}
						}
					}
				}
				if ($woo_order_status !== $google_order_status) {
					mproseo_bogfw_woo_order_status_update($woo_order, $google_order_status, 'Updating order status to match Google.');
				}
				$woo_order->save();
			}
		}

		if (!function_exists('mproseo_bogfw_refund_google_orders_func')) {
			function mproseo_bogfw_refund_google_orders_func( $woo_refunds = array(), $user_id = null, $user_creds = null, $site_id = null ) {
				if (empty($woo_refunds)) {
					return;
				}
				if (isset($woo_refunds['order_id'])) {
					$woo_refunds = array($woo_refunds);
				}
				if (empty($user_creds['access_token'])) {
					$creds = mproseo_bogfw_get_google_access_tokens();
					if (!empty($user_id) && isset($creds[$user_id])) {
						$user_creds = $creds[$user_id];
					}
				}
				if (!empty($user_creds['access_token'])) {
					$order_creds = array($user_id, $user_creds);
				} else {
					$order_creds = null;
				}
				$creds_per_order = ( !isset($order_creds) );
				if (true === $creds_per_order) {
					$users = mproseo_bogfw_accounts();
				}
				if (is_null($site_id)) {
					$site_id = mproseo_bogfw_site_id();
				}
				$quota_user = ( !empty($site_id) ? "?quotaUser=${site_id}" : '' );
				$sync_orders = array();
				foreach ((array) $woo_refunds as $key => $refund) {
					if (!empty($refund['refund_id'])) {
						$wc_refund = wc_get_order($refund['refund_id']);
						if (false !== $wc_refund) {
							$wc_refund->delete(true);
						}
						unset($refund['refund_id']);
					}
					if (!empty($refund['order_id'])) {
						$order = wc_get_order($refund['order_id']);
						if (false !== $order) {
							$google_order_id = ( !empty($refund['google_id']) ? $refund['google_id'] : $order->get_meta('_mproseo_bogfw_google_order_id') );
							$google_merchant_id = ( !empty($refund['merchant_id']) ? $refund['merchant_id'] : $order->get_meta('_mproseo_bogfw_google_merchant_id') );
							if (!empty($google_order_id) && !empty($google_merchant_id)) {
								if (empty($refund['merchant_id'])) {
									$refund['merchant_id'] = $google_merchant_id;
								}
								if (empty($refund['google_id'])) {
									$refund['google_id'] = $google_order_id;
								}
								$sandbox_mode = (bool) $order->get_meta('_mproseo_bogfw_google_sandbox_order');
								if ($creds_per_order) {
									$order_creds = mproseo_bogfw_get_google_order_creds($refund, $users, $creds, $sandbox_mode, $site_id);
								}
								if (!is_null($order_creds) && !empty($order_creds[1]['access_token'])) {
									if ($refund['restock_items']) {
										$type = 'cancel';
									} else {
										$type = 'refund';
										$currency = $order->get_currency();
									}
									$content_args = array('headers' => array('Authorization' => 'Bearer ' . $order_creds[1]['access_token']));
									$order_endpoint = "${google_merchant_id}/orders/${google_order_id}";
									$timestamp = ( !empty($refund['timestamp']) ? $refund['timestamp'] : time() );
									$content_body = array('operationId' => "${type}_${timestamp}${key}");
									if (!empty($refund['general_reason'])) {
										$content_body['reason'] = $refund['general_reason'];
									}
									$content_body['reasonText'] = ( !empty($refund['reason']) ? $refund['reason'] : 'No explanation provided' );
									$refund_submitted = false;
									if ('refund' === $type) {
										$content_body['operationId'] .= "_${refund['order_id']}";
										$refund_args = $content_args;
										$refund_args['method'] = 'POST';
										$refund_args['headers']['Content-Type'] = 'application/json';
										if (!empty($refund['line_items'])) {
											$items_refunded = false;
											$tax_refunded = false;
											$shipping_refunded = false;
											$refund_items = array();
											$shipping_item = array();
											foreach ($refund['line_items'] as $item_id => $item) {
												unset($quantity, $cancelled);
												$item_total = number_format(( !empty($item['refund_total']) && is_numeric($item['refund_total']) ? (float) $item['refund_total'] : 0 ), 2);
												if ($item_total > 0) {
													$item_amount = array(
														'value' => (string) $item_total,
														'currency' => $currency
													);
													$google_item_id = wc_get_order_item_meta($item_id, 'Item ID');
													if (!empty($google_item_id)) {
														$item_amount = array('priceAmount' => $item_amount);
														if (!empty($item['refund_tax'])) {
															$refund_taxes = (float) array_values((array) $item['refund_tax']);
															if (isset($refund_taxes[0]) && $refund_taxes[0] > 0) {
																$item_amount['taxAmount'] = array(
																	'value' => (string) number_format($refund_taxes[0], 2),
																	'currency' => $currency
																);
																if (!$tax_refunded) {
																	$tax_refunded = true;
																}
															}
														}
														$order_item = $order->get_item($item_id);
														if (false !== $order_item) {
															$quantity = intval($order_item->get_quantity());
														} else {
															$quantity = 1;
														}
														$item_cancel_count = wc_get_order_item_meta($item_id, 'Cancelled');
														if (!empty($item_cancel_count) && is_numeric($item_cancel_count) && intval($item_cancel_count) > 0) {
															$cancelled = intval($item_cancel_count);
														} else {
															$cancelled = 0;
														}
														$pending = ( $quantity - $cancelled );
														if ($pending <= 0) {
															continue;
														}
														$refund_items[] = array(
															'lineItemId' => $google_item_id,
															'quantity' => min(( !empty($item['qty']) ? intval($item['qty']) : 1 ), $pending),
															'fullRefund' => false,
															'amount' => $item_amount
														);
														if (!$items_refunded) {
															$items_refunded = true;
														}
													} elseif (isset($item['refund_total'])) {
														if (!$shipping_refunded) {
															$shipping_refunded = true;
														}
														$shipping_item = array(
															'fullRefund' => false,
															'amount' => $item_amount
														);
													}
												}
											}
											if (!empty($refund_items)) {
												$content_body['items'] = $refund_items;
											}
											if (!empty($shipping_item)) {
												$content_body['shipping'] = $shipping_item;
											}
											if (empty($content_body['reason'])) {
												$content_body['reason'] = ( $items_refunded ?
												( $shipping_refunded || $tax_refunded ? 'adjustment' : 'priceAdjustment' ) :
												( $shipping_refunded ? ( $tax_refunded ? 'adjustment' : 'shippingCostAdjustment' ) :
												( $tax_refunded ? 'taxAdjustment' : 'adjustment' ) ) );
											}
										} else {
											$content_body['fullRefund'] = false;
											$content_body['amount'] = array(
												'priceAmount' => array(
													'value' => (string) number_format((float) $refund['amount'], 2),
													'currency' => $currency
												),
											);
											if (empty($content_body['reason'])) {
												$content_body['reason'] = 'other';
											}
										}
										$refund_args['body'] = json_encode($content_body);
										$type_endpoint = "${order_endpoint}/refund" . ( !empty($content_body['amount']) ? 'order' : 'item' ) . $quota_user;
										if (!empty(mproseo_bogfw_google_api_request('content', $type_endpoint, $refund_args, null, null, $sandbox_mode))) {
											$refund_submitted = true;
										}
									} elseif (!empty($refund['line_items'])) {
										$cancel_args = $content_args;
										$cancel_args['method'] = 'POST';
										$cancel_args['headers']['Content-Type'] = 'application/json';
										$operation_id = $content_body['operationId'];
										if (empty($content_body['reason'])) {
											$content_body['reason'] = 'other';
										}
										foreach ($refund['line_items'] as $item_id => $item) {
											if (!empty($item['qty']) && is_numeric($item['qty']) && intval($item['qty']) > 0) {
												$item_pending_count = wc_get_order_item_meta($item_id, 'Pending');
												if (!empty($item_pending_count) && is_numeric($item_pending_count) && intval($item_pending_count) > 0) {
													$pending = intval($item_pending_count);
												} else {
													$pending = 0;
												}
												if ($pending <= 0) {
													continue;
												}
												$google_item_id = wc_get_order_item_meta($item_id, 'Item ID');
												if (!empty($google_item_id)) {
													$content_body['operationId'] = $operation_id . "_${item_id}";
													$content_body['lineItemId'] = $google_item_id;
													$content_body['quantity'] = min(intval($item['qty']), $pending);
													$cancel_args['body'] = json_encode($content_body);
													$type_endpoint = "${order_endpoint}/cancelLineItem${quota_user}";
													if (!empty(mproseo_bogfw_google_api_request('content', $type_endpoint, $cancel_args, null, null, $sandbox_mode))) {
														$refund_submitted = true;
													}
												}
											}
										}
									}
									if ($refund_submitted) {
										$order_endpoint .= $quota_user;
										$google_order = mproseo_bogfw_google_api_request('content', $order_endpoint, $content_args, null, null, $sandbox_mode);
										if (!empty($google_order)) {
											mproseo_bogfw_update_woo_order($google_order, $order, $sandbox_mode);
										} else {
											$sync_orders = mproseo_bogfw_sync_google_orders_array($sync_orders, $order_creds, $refund, $sandbox_mode);
										}
									}
								}
							}
						}
					}
				}
				if (!empty($sync_orders)) {
					mproseo_bogfw_sync_google_orders($sync_orders['accounts'], false, $sync_orders['user_creds'], $sync_orders['orders']);
				}
			}
		}
		
		add_action('mproseo_bogfw_refund_google_orders', 'mproseo_bogfw_refund_google_orders_func', 10, 3);

		add_action('woocommerce_refund_created', function( $refund_id, $args ) {
			if (!empty($refund_id) && empty($args['google_refund'])) {
				$refund = wc_get_order($refund_id);
				if (false !== $refund) {
					$order_id = $refund->get_parent_id();
					if (!empty($order_id)) {
						$order = wc_get_order($order_id);
						if (false !== $order) {
							$google_order_id = $order->get_meta('_mproseo_bogfw_google_order_id');
							$google_merchant_id = $order->get_meta('_mproseo_bogfw_google_merchant_id');
							if (!empty($google_order_id)) {
								$wc_refund = wc_get_order($refund_id);
								if (false !== $wc_refund) {
									$wc_refund->delete(true);
								}
								if (!empty($google_merchant_id)) {
									$args['google_id'] = $google_order_id;
									$args['merchant_id'] = $google_merchant_id;
									$args['timestamp'] = time();
									mproseo_bogfw_refund_google_orders_func(array($args));
								}
							}
						}
					}
				}
			}
		}, 20, 2);

		add_action('woocommerce_refund_deleted', function( $refund_id, $order_id ) {
			if (!empty($order_id)) {
				$order = wc_get_order($order_id);
				if (false !== $order && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
					$cancelled_items = array();
					$refunds = $order->get_refunds();
					if ($refunds) {
						foreach ($refunds as $refund) {
							foreach ($refund->get_items() as $refund_item_id => $item) {
								if (!isset($cancelled_items[$refund_item_id])) {
									$cancelled_items[$refund_item_id] = 0;
								}
								$cancelled_items[$refund_item_id] += abs($item->get_quantity());
							}
						}
					}
					$order_items = $order->get_items();
					if (!empty($order_items)) {
						foreach (array_keys($order_items) as $order_item_id) {
							if (array_key_exists($order_item_id, $cancelled_items)) {
								if ($cancelled_items[$order_item_id] > 0) {
									wc_update_order_item_meta($order_item_id, 'Cancelled', $cancelled_items[$order_item_id]);
								} else {
									wc_delete_order_item_meta($order_item_id, 'Cancelled');
								}
							} else {
								wc_delete_order_item_meta($order_item_id, 'Cancelled');
							}
						}
					}
					$cancellation_notes = $order->get_meta('_mproseo_bogfw_refund_cancellation_notes');
					if (!empty($cancellation_notes)) {
						if (!empty($cancellation_notes[$refund_id])) {
							wc_delete_order_note($cancellation_notes[$refund_id]);
							unset($cancellation_notes[$refund_id]);
							$order->update_meta_data('_mproseo_bogfw_refund_cancellation_notes', $cancellation_notes);
						}
					}
				}
			}
		}, 20, 2);

		add_filter('woocommerce_get_order_item_totals', function( $total_rows, $order = null ) {
			if ($order) {
				$order_refunds = $order->get_refunds();
				if (!empty($order_refunds)) {
					foreach ($order_refunds as $id => $refund) {
						unset($total_rows['refund_' . $id]);
					}
				}
			}
			return $total_rows;
		}, 20, 2);

		add_filter('woocommerce_order_fully_refunded_status', function( $status, $order_id, $refund_id ) {
			if (!empty($order_id)) {
				$order = wc_get_order($order_id);
				if (false !== $order && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
					$status = $order->get_status();
				}
			}
			return $status;
		}, 20, 3);

		add_filter('woocommerce_restock_refunded_items', function( $checked ) {
			if ($checked) {
				global $thepostid, $post;
				$hpos_enabled = ( class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && method_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class, 'custom_orders_table_usage_is_enabled') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
				$post_id = ( $hpos_enabled && !empty($_GET['id']) ? intval($_GET['id']) : ( empty($thepostid) ? ( !empty($post) ? $post->ID : ( !empty($_GET['post']) ? intval($_GET['post']) : 0 ) ) : $thepostid ) );
				if ($post_id > 1) {
					$order = wc_get_order($post_id);
					if (false !== $order && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
						$checked = false;
					}
				}
			}
			return $checked;
		});

		if (!function_exists('mproseo_bogfw_woo_order_statuses')) {	
			function mproseo_bogfw_woo_order_statuses( $statuses ) {
				if (!empty($statuses)) {
					global $thepostid, $post;
					$hpos_enabled = ( class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && method_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class, 'custom_orders_table_usage_is_enabled') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
					$post_id = intval($hpos_enabled && !empty($_GET['id']) ? intval($_GET['id']) : ( empty($thepostid) ? ( !empty($post) ? $post->ID : ( !empty($_GET['post']) ? intval($_GET['post']) : 0 ) ) : $thepostid ));
					if (0 === $post_id && !empty($_GET['rest_route'])) {
						$rest_route = explode('/', sanitize_text_field($_GET['rest_route']));
						$orders_key = array_search('orders', $rest_route);
						if (false !== $orders_key && !empty($rest_route[$orders_key + 1]) && is_numeric($rest_route[$orders_key + 1])) {
							$post_id = intval($rest_route[$orders_key + 1]);
						}
					}
					if ($post_id > 1) {
						$order = wc_get_order($post_id);
						if (false !== $order && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
							$order_status = $order->get_status();
							$valid_statuses = array('wc-' . $order_status);
							if (!empty($order->get_meta('_mproseo_bogfw_google_order_refunded'))) {
								$valid_statuses[] = 'wc-refunded';
							}
							if (empty($order->get_meta('_mproseo_bogfw_google_order_shipped'))) {
								$valid_statuses[] = 'wc-cancelled';
							}
							if (in_array($order_status, array('pending', 'on-hold'))) {
								$valid_statuses[] = 'wc-processing';
							}
							foreach ((array) array_keys($statuses) as $status) {
								if (!in_array($status, $valid_statuses)) {
									unset($statuses[$status]);
								}
							}
						}
					}
				}
				return $statuses;
			}
		}

		add_filter('wc_order_statuses', 'mproseo_bogfw_woo_order_statuses', 10);

		if (!function_exists('mproseo_bogfw_remove_status_update_hooks')) {
			function mproseo_bogfw_remove_status_update_hooks() {
				remove_filter('woocommerce_order_status_cancelled', 'mproseo_bogfw_woo_order_cancelled', 10);
				remove_filter('wc_order_statuses', 'mproseo_bogfw_woo_order_statuses', 10);
			}
		}

		add_filter('wc_order_is_editable', function( $editable, $order = null ) {
			if ($editable) {
				if (!$order) {
					global $thepostid, $post;
					$hpos_enabled = ( class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && method_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class, 'custom_orders_table_usage_is_enabled') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
					$post_id = ( $hpos_enabled && !empty($_GET['id']) ? intval($_GET['id']) : ( empty($thepostid) ? ( !empty($post) ? $post->ID : ( !empty($_GET['post']) ? intval($_GET['post']) : 0 ) ) : $thepostid ) );
					if (0 === $post_id && !empty($_GET['rest_route'])) {
						$rest_route = explode('/', sanitize_text_field($_GET['rest_route']));
						$orders_key = array_search('orders', $rest_route);
						if (false !== $orders_key && !empty($rest_route[$orders_key + 1]) && is_numeric($rest_route[$orders_key + 1])) {
							$post_id = intval($rest_route[$orders_key + 1]);
						}
					}
					if ($post_id > 1) {
						$order = wc_get_order($post_id);
					}
				}
				if (!is_null($order) && !empty($order->get_meta('_mproseo_bogfw_google_order_id'))) {
					$editable = false;
				}
			}
			return $editable;
		}, 20, 2);

		if (!function_exists('mproseo_bogfw_woo_order_status_update')) {
			function mproseo_bogfw_woo_order_status_update( $order, $status, $note = '', $manual = false ) {
				if (!$order) {
					return;
				}
				mproseo_bogfw_remove_status_update_hooks();
				if ('refunded' === $status) {
					$order->update_meta_data('_mproseo_bogfw_google_order_refunded', true);
					$order->save_meta_data();
				}
				$order->update_status($status, $note, $manual);
			}
		}

		add_action('woocommerce_order_status_changed', function( $order_id, $old_status, $new_status, $order = null ) {
			if (in_array($old_status, array('pending', 'on-hold')) && 'processing' === $new_status) {
				if (!$order) {
					$order = wc_get_order($order_id);
				}
				if (false !== $order) {
					$google_order_id = $order->get_meta('_mproseo_bogfw_google_order_id');
					if (!empty($google_order_id)) {
						$google_merchant_id = $order->get_meta('_mproseo_bogfw_google_merchant_id');
						if (!empty($google_merchant_id)) {
							$sandbox_mode = (bool) $order->get_meta('_mproseo_bogfw_google_sandbox_order');
							$google_order = array(
								'order_id' => $order_id,
								'google_id' => $google_order_id,
								'merchant_id' => $google_merchant_id,
								'sandbox' => $sandbox_mode
							);
							if (!empty(mproseo_bogfw_charge_google_orders(array($google_order))[$google_order_id]['charged'])) {
								mproseo_bogfw_google_payment_complete($order, $sandbox_mode);
							} else {
								mproseo_bogfw_woo_order_status_update($order, $old_status, 'Attempt to charge the payment method failed. We do not recommend fulfilling the order. Please try changing order status to Processing again later to retry the charge.');
							}
						} else {
							mproseo_bogfw_woo_order_status_update($order, $old_status, 'Unable to change order status to processing because order meta may be missing, malformed, or corrupt. We recommend resyncing the order.');
						}
						$order->save();
					}
				}
			}
		}, 20, 4);

		if (!function_exists('mproseo_bogfw_temporary_sync_add')) {
			function mproseo_bogfw_temporary_sync_add( $synced_orders = array(), $dont_monitor = array(), $list = null ) {
				if (!empty($synced_orders)) {
					$timestamp = time();
					if (is_null($list)) {
						$list = mproseo_bogfw_monitor_list();
					}
					foreach ($synced_orders as $env => $merchants) {
						if (!empty($merchants)) {
							foreach ($merchants as $merchant_id => $order_ids) {
								if (!empty($order_ids)) {
									if (!empty($dont_monitor[$env][$merchant_id])) {
										foreach ($dont_monitor[$env][$merchant_id] as $order_id) {
											$order_key = array_search($order_id, $order_ids);
											if (false !== $order_key) {
												unset($order_ids[$order_key]);
											}
										}
									}
									if (!empty($order_ids)) {
										if (!isset($list[$env])) {
											$list[$env] = array();
										}
										if (!isset($list[$env][$timestamp])) {
											$list[$env][$timestamp] = array();
										}
										$wc_order_ids =  array_map('intval', array_keys($order_ids));
										array_multisort($wc_order_ids, SORT_DESC, SORT_NUMERIC, $order_ids);
										$order_ids = array_unique(array_combine($wc_order_ids, array_values($order_ids)));
										if (!empty($list[$env][$timestamp][$merchant_id])) {
											$order_ids += $list[$env][$timestamp][$merchant_id];
										}
										$list[$env][$timestamp][$merchant_id] = $order_ids;
									}
								}
							}
						}
					}
				} elseif (is_null($list)) {
					return;
				}
				mproseo_bogfw_monitor_list('set', $list);
			}
		}

		if (!function_exists('mproseo_bogfw_sync_google_orders')) {
			function mproseo_bogfw_sync_google_orders( $accounts = null, $get_all = false, $user_creds = array(), $orders = array() ) {
				if (is_null($accounts)) {
					$accounts = mproseo_bogfw_accounts();
				}
				if (!empty($accounts)) {
					if (empty($user_creds)) {
						$user_creds = mproseo_bogfw_get_google_access_tokens($accounts);
					}
					if (!empty($user_creds)) {
						$monitor_list = mproseo_bogfw_monitor_list();
						if (!empty($orders)) {
							$sync_lists = $orders;
						} else {
							if (!empty($monitor_list)) {
								$sync_lists = &$monitor_list;
								$sync_lists = array_merge(array('sandbox' => array(), 'live' => array()), $sync_lists);
							} else {
								$sync_lists = array('sandbox' => array(), 'live' => array());
							}
						}
						$settings = mproseo_bogfw_settings();
						$site_id = ( !empty($settings['site_id']) ? $settings['site_id'] : false );
						$monitor_term = ( ( !empty($settings['monitor_term']) && is_numeric($settings['monitor_term']) && absint($settings['monitor_term']) >= 0 ? absint($settings['monitor_term']) : 30 ) * DAY_IN_SECONDS );
						$earliest_time = time() - $monitor_term;
						$updated_orders = array();
						$synced_orders = array();
						$dont_monitor = array();
						$sandbox_mode = (bool) apply_filters('mproseo_bogfw_enable_google_content_sandbox', false);
						foreach ($accounts as $account_id => $account_settings) {
							if (!empty($user_creds[$account_id]['access_token'])) {
								$sync_locations = ( !empty($account_settings['locations']) ? $account_settings['locations'] : array() );
								if (!empty($sync_locations)) {
									$user_info = mproseo_bogfw_get_google_userinfo($account_id, $user_creds[$account_id], $site_id);
									if (!empty($user_info)) {
										$available_locations = mproseo_bogfw_get_google_locations($account_id, $user_info, $user_creds[$account_id], $sync_locations, $site_id);
										foreach ($sync_lists as $env => &$sync_list) {
											if (empty($sync_list)) {
												if ( ( $sandbox_mode ? 'sandbox' : 'live' ) !== $env) {
													continue;
												}
											} else {
												krsort($sync_list, SORT_NUMERIC);
												$recent_list = array();
												foreach ($sync_list as $sync_time => $monitor_locations) {
													if (!empty($monitor_locations)) {
														foreach ($monitor_locations as $location_id => $google_order_ids) {
															if (!empty($google_order_ids)) {
																if (!isset($recent_list[$location_id])) {
																	$recent_list[$location_id] = array();
																}
																foreach ($google_order_ids as $wc_order_id => $google_order_id) {
																	if (( is_numeric($wc_order_id) && ( 0 === intval($wc_order_id) || false === wc_get_order(intval($wc_order_id)) ) ) || in_array($google_order_id, $recent_list[$location_id])) {
																		unset($sync_list[$sync_time][$location_id][$wc_order_id]);
																	} else {
																		$recent_list[$location_id][] = $google_order_id;
																	}
																}
																if (empty($sync_list[$sync_time][$location_id])) {
																	unset($sync_list[$sync_time][$location_id]);
																}
															} else {
																unset($sync_list[$sync_time][$location_id]);
															}
														}
														if (empty($sync_list[$sync_time])) {
															unset($sync_list[$sync_time]);
														}
													} else {
														unset($sync_list[$sync_time]);
													}
												}
												if (!empty($sync_list)) {
													foreach ($sync_list as $sync_time => $monitor_locations) {
														if ($sync_time < $earliest_time) {
															unset($sync_list[$sync_time]);
														} elseif (!empty($monitor_locations)) {
															$monitor_orders = mproseo_bogfw_get_google_orders($account_id, $user_info, $user_creds[$account_id], $monitor_locations, $available_locations, true, ( 'sandbox' === $env ), $site_id);
															if (!empty($monitor_orders)) {
																$monitor_order_ids = array_column($monitor_orders, 'id');
																foreach ($monitor_locations as $location_id => $google_order_ids) {
																	if (!empty($google_order_ids)) {
																		if (!isset($updated_orders[$location_id])) {
																			$updated_orders[$location_id] = array();
																		}
																		foreach ($google_order_ids as $wc_order_id => $google_order_id) {
																			$monitor_order_key = array_search($google_order_id, $monitor_order_ids);
																			if (false !== $monitor_order_key) {
																				$monitor_order = $monitor_orders[$monitor_order_key];
																				if (!empty($monitor_order['merchantOrderId']) || false !== get_post_status($wc_order_id)) {
																					$update_order = wc_get_order(( !empty($monitor_order['merchantOrderId']) ? $monitor_order['merchantOrderId'] : $wc_order_id ));
																					if (false !== $update_order) {
																						mproseo_bogfw_update_woo_order($monitor_order, $update_order, ( 'sandbox' === $env ));
																						if ('canceled' === $monitor_order['status'] || $update_order->get_status() === 'refunded') {
																							unset($sync_list[$sync_time][$location_id][$wc_order_id]);
																						}
																						$updated_orders[$location_id][] = $google_order_id;
																					}
																				}
																			}
																		}
																		if (empty($sync_list[$sync_time][$location_id])) {
																			unset($sync_list[$sync_time][$location_id]);
																		}
																	} else {
																		unset($sync_list[$sync_time][$location_id]);
																	}
																}
																if (empty($sync_list[$sync_time])) {
																	unset($sync_list[$sync_time]);
																}
															}
														} else {
															unset($sync_list[$sync_time]);
														}
													}
													$sync_lists[$env] = $sync_list;
												}
												if (!empty($orders) || ( $sandbox_mode ? 'sandbox' : 'live' ) !== $env) {
													continue;
												}
											}
											$account_orders = mproseo_bogfw_get_google_orders($account_id, $user_info, $user_creds[$account_id], $sync_locations, $available_locations, $get_all, ( 'sandbox' === $env ), $site_id);
											if (!empty($account_orders)) {
												$account_order_ids = array_column($account_orders, 'id');
												array_multisort($account_order_ids, SORT_ASC, $account_orders);
												$synced_orders[$env] = array();
												$dont_monitor[$env] = array();
												$order_ids = array();
												$no_sync_completed = array_merge(array('canceled'), mproseo_bogfw_get_google_complete_statuses());
												$no_sync_statuses = (array) apply_filters('mproseo_bogfw_no_sync_statuses', ( !$sandbox_mode ? array('inProgress') : array() ));
												$admin_email = get_option('admin_email');
												foreach ($account_orders as $sync_order) {
													unset($order_id);
													$order_status = ( isset($sync_order['status']) ? $sync_order['status'] : false );
													$order_acknowledged = ( isset($sync_order['acknowledged']) ? $sync_order['acknowledged'] : false );
													if (false !== $order_status && ( false === $order_acknowledged || ( ( empty($sync_order['merchantOrderId']) || false === wc_get_order($sync_order['merchantOrderId']) ) && ( $get_all || !in_array($order_status, $no_sync_completed) ) ) ) && ( empty($synced_orders[$env][$sync_order['merchantId']]) || !in_array($sync_order['id'], $synced_orders[$env][$sync_order['merchantId']]) ) && ( empty($updated_orders[$sync_order['merchantId']]) || !in_array($sync_order['id'], $updated_orders[$sync_order['merchantId']]) ) && !in_array($order_status, $no_sync_statuses) && ( 'inProgress' !== $order_status || !empty(mproseo_bogfw_advance_google_orders(array('merchant_id' => $sync_order['merchantId'], 'google_id' => $sync_order['id'], 'sandbox' => $sandbox_mode), $account_id, $user_creds[$account_id], $site_id)[$sync_order['id']]['advanced']) )) {
														$order_customer_id = 0;
														$marketing_email = ( $sandbox_mode && !empty($admin_email) ? $admin_email : ( !empty($sync_order['customer']['marketingRightsInfo']['marketingEmailAddress']) ? $sync_order['customer']['marketingRightsInfo']['marketingEmailAddress'] : null ) );
														if (!empty($marketing_email)) {
															$customer = get_user_by('email', $marketing_email);
															if (false !== $customer) {
																$customer_id = $customer->ID;
																if (is_numeric($customer_id) && intval($customer_id) > 1) {
																	$order_customer_id = intval($customer_id);
																}
															}
														}
														$order = wc_create_order(array('customer_id' => $order_customer_id, 'created_via' => 'google'));
														if (!is_wp_error($order)) {
															$order->add_order_note(__("Order imported from Google with status of ${order_status}.", 'mproseo_bogfw'));
															if ($sandbox_mode) {
																$order->add_order_note(__('This is a test order. DO NOT FULFILL!', 'mproseo_bogfw'));
															}
															$order->update_meta_data('_mproseo_bogfw_google_sandbox_order', $sandbox_mode);
															$order->add_order_note(__('You may also manage this order on Google Merchant Center by <a href="' . esc_url('https://merchants.google.com/mc/orders/orderdetails?a=' . $sync_order['merchantId'] . '&orderid=' . $sync_order['id'] . ( $sandbox_mode ? '&sandbox=true' : '' )) . '" target="_blank">clicking here</a>.', 'mproseo_bogfw'));
															$billing_address = array();
															$invoice_email = ( $sandbox_mode && !empty($admin_email) ? $admin_email : ( !empty($sync_order['customer']['invoiceReceivingEmail']) ? $sync_order['customer']['invoiceReceivingEmail'] : null ) );
															if (!is_null($invoice_email)) {
																$billing_address['email'] = $invoice_email;
															}
															$billing_city = ( !empty($sync_order['billingAddress']['locality']) ? $sync_order['billingAddress']['locality'] : null );
															if (!is_null($billing_city)) {
																$billing_address['city'] = $billing_city;
															}
															$billing_state = ( !empty($sync_order['billingAddress']['region']) ? $sync_order['billingAddress']['region'] : null );
															if (!is_null($billing_state)) {
																$billing_address['state'] = $billing_state;
															}
															$billing_zip = ( !empty($sync_order['billingAddress']['postalCode']) ? $sync_order['billingAddress']['postalCode'] : null );
															if (!is_null($billing_zip)) {
																$billing_address['postcode'] = $billing_zip;
															}
															$billing_country = ( !empty($sync_order['billingAddress']['country']) ? $sync_order['billingAddress']['country'] : null );
															if (!is_null($billing_country)) {
																$billing_address['country'] = $billing_country;
															}
															$billing_name = explode(' ', ( !empty($sync_order['billingAddress']['recipientName']) ? $sync_order['billingAddress']['recipientName'] : ( !empty($sync_order['deliveryDetails']['address']['recipientName']) ? $sync_order['deliveryDetails']['address']['recipientName'] : '' ) ), 2);
															if (!empty($billing_name[0])) {
																$billing_address['first_name'] = $billing_name[0];
																if (count($billing_name) > 1 && !empty($billing_name[1])) {
																	$billing_address['last_name'] = $billing_name[1];
																}
															}
															$phone = ( !empty($sync_order['deliveryDetails']['phoneNumber']) ? $sync_order['deliveryDetails']['phoneNumber'] : null );
															if (!is_null($phone)) {
																$billing_address['phone'] = $phone;
															}
															$bill_street = (array) ( !empty($sync_order['billingAddress']['streetAddress']) ? $sync_order['billingAddress']['streetAddress'] : ( !empty($sync_order['deliveryDetails']['address']['streetAddress']) ? $sync_order['deliveryDetails']['address']['streetAddress'] : array() ) );
															if (!empty($bill_street)) {
																$billing_address['address_1'] = $bill_street[0];
																if (count($bill_street) > 1) {
																	unset($bill_street[0]);
																	$bill_street_2 = implode(', ', $bill_street);
																	if (!empty($bill_street_2)) {
																		$billing_address['address_2'] = $bill_street_2;
																	}
																}
															}
															if (!empty($phone)) {
																$shipping_address['phone'] = $phone;
															}
															$order->set_address($billing_address, 'billing');
															if (!empty($sync_order['netPriceAmount']['currency'])) {
																$order->set_currency($sync_order['netPriceAmount']['currency']);
															}
															$merchant_tax = ( isset($sync_order['taxCollector']) && 'merchant' === $sync_order['taxCollector'] );
															if (!$merchant_tax) {
																$order->add_order_note(__('Taxes for this order were collected by Google. For this reason, tax information for this order has not been included to maintain accurate accounting for reporting purposes. Added note: Please consult a tax profession before attempting to remit sales tax for this order, as Google has already done so.', 'mproseo_bogfw'));
															}
															if (!empty($sync_order['lineItems'])) {
																foreach ($sync_order['lineItems'] as $product) {
																	$wc_product = false;
																	if (!empty($product['product']['offerId'])) {
																		$offer_id = $product['product']['offerId'];
																		if (!empty($settings['match_products']) && !empty($product['product'])) {
																			$id_parts = array_filter(preg_split('/[-_]/', $offer_id));
																			$proposed_id = intval($id_parts[count($id_parts) - 1]);
																			if (!empty($proposed_id) && is_numeric($proposed_id) && $proposed_id > 1) {
																				$wc_product = wc_get_product($proposed_id);
																			}
																		}
																	}
																	$quantity = ( !empty($product['quantityOrdered']) && is_numeric($product['quantityOrdered']) ? intval($product['quantityOrdered']) : 1 );
																	$product_args = array('quantity' => $quantity);
																	if ($wc_product) {
																		$product_args['name'] = $wc_product->get_name();
																	} else {
																		if (!empty($product['product']['title'])) {
																			$product_args['name'] = $product['product']['title'];
																		} elseif (!empty($product['product']['mpn'])) {
																			$product_brand = ( !empty($product['product']['brand']) ? $product['product']['brand'] . ' ' : '' );
																			$product_args['name'] = $product_brand . $product['product']['mpn'];
																		} else {
																			$product_args['name'] = ( !empty($offer_id) ? $offer_id : ( !empty($product['product']['id']) ? $product['product']['id'] : $product['id'] ) );
																		}
																	}
																	if (!empty($product['variantAttributes'])) {
																		$product_args['variation'] = array();
																		foreach ($product['variantAttributes'] as $attribute) {
																			$product_args['variation']['attribute_' . $attribute['dimension']] = $attribute['value'];
																		}
																	}
																	$promotionTotal = ( empty($product['adjustments']) ? 0 : array_sum(array_column(array_column($product['adjustments'], 'priceAdjustment'), 'value')) );
																	$promotionTax = ( empty($product['adjustments']) ? 0 : array_sum(array_column(array_column($product['adjustments'], 'taxAdjustment'), 'value')) );
																	$product_args['subtotal'] = ( !empty($product['price']['value']) && is_numeric($product['price']['value']) ? (float) $product['price']['value'] : 0 );
																	if ($promotionTotal > 0) {
																		$product_args['subtotal'] = max(( $product_args['subtotal'] - $promotionTotal ), 0);
																	}
																	$product_args['total_tax'] = ( $merchant_tax && !empty($product['tax']['value']) && is_numeric($product['tax']['value']) ? (float) $product['tax']['value'] : 0 );
																	if ($merchant_tax && $promotionTax > 0) {
																		$product_args['total_tax'] = max(( $product_args['total_tax'] - $promotionTax ), 0);
																	}
																	$product_args['total'] = $product_args['subtotal'] + $product_args['total_tax'];
																	$order_item_id = $order->add_product($wc_product, $quantity, $product_args);
																	wc_update_order_item_meta($order_item_id, 'Item ID', $product['id']);
																	if (!empty($product['product']['brand'])) {
																		wc_update_order_item_meta($order_item_id, 'Brand', $product['product']['brand']);
																	}
																	if (!empty($product['product']['mpn'])) {
																		wc_update_order_item_meta($order_item_id, 'MPN', $product['product']['mpn']);
																	}
																	if (!empty($product['product']['gtin'])) {
																		wc_update_order_item_meta($order_item_id, 'GTIN', $product['product']['gtin']);
																	}
																	if (!empty($product['product']['condition'])) {
																		wc_update_order_item_meta($order_item_id, 'Condition', $product['product']['condition']);
																	}
																	if (!empty($product['product']['contentLanguage'])) {
																		wc_update_order_item_meta($order_item_id, 'Language', $product['product']['contentLanguage']);
																	}
																}
																if (!empty($sync_order['deliveryDetails'])) {
																	$shipping_address = array(
																		'city' => $sync_order['deliveryDetails']['address']['locality'],
																		'state' => $sync_order['deliveryDetails']['address']['region'],
																		'postcode' => $sync_order['deliveryDetails']['address']['postalCode'],
																		'country' => $sync_order['deliveryDetails']['address']['country']
																	);
																	if (!empty($sync_order['deliveryDetails']['address']['recipientName'])) {
																		$shipping_name = explode(' ', $sync_order['deliveryDetails']['address']['recipientName']);
																		$shipping_address['first_name'] = $shipping_name[0];
																		if (count($shipping_name) > 1) {
																			$shipping_address['last_name'] = $shipping_name[1];
																		}
																	} else {
																		$shipping_address['first_name'] = '';
																	}
																	if (!empty($sync_order['deliveryDetails']['address']['streetAddress'])) {
																		$ship_street = $sync_order['deliveryDetails']['address']['streetAddress'];
																		if (is_array($ship_street)) {
																			$shipping_address['address_1'] = $ship_street[0];
																			if (count($ship_street)) {
																				unset($ship_street[0]);
																				$shipping_address['address_2'] = implode(' ', $ship_street);
																			}
																		}
																	}
																	$shipping_cost = ( !empty($sync_order['shippingCost']['value']) && is_numeric($sync_order['shippingCost']['value']) ? (float) $sync_order['shippingCost']['value'] : 0 );
																	$shipping_tax = ( $merchant_tax && !empty($sync_order['shippingCostTax']['value']) && is_numeric($sync_order['shippingCostTax']['value']) ? (float) $sync_order['shippingCostTax']['value'] : 0 );
																	$shipping_item = new WC_Order_Item_Shipping();
																	$shipping_item->set_method_title('Methods selected in Google Shopping (See delivery type by item)');
																	$shipping_item->set_total($shipping_cost);
																	$shipping_item->set_taxes(array('total' => array($shipping_tax)));
																	$order->add_item($shipping_item);
																	if (!empty($phone)) {
																		$shipping_address['phone'] = $phone;
																	}
																	if (!empty($email)) {
																		$shipping_address['email'] = $email;
																	}
																	$order->set_address($shipping_address, 'shipping');
																}
																if (!empty($sync_order['pickupDetails'])) {
																	$order->add_order_note(__(mproseo_bogfw_get_pickup_note($sync_order), 'mproseo_bogfw'));
																}
															}
															$order->calculate_totals();
															$order_id = $order->get_id();
															if (!isset($synced_orders[$env][$sync_order['merchantId']])) {
																$synced_orders[$env][$sync_order['merchantId']] = array();
															}
															if (!empty($order_id)) {
																$order_ids[] = $order_id;
																$synced_orders[$env][$sync_order['merchantId']][strval($order_id)] = $sync_order['id'];
															} else {
																$synced_orders[$env][$sync_order['merchantId']][] = strval($sync_order['id']);
															}
															if (in_array($order_status, array('canceled', 'returned'))) {
																if (!isset($dont_monitor[$env][$sync_order['merchantId']])) {
																	$dont_monitor[$env][$sync_order['merchantId']] = array();
																}
																$dont_monitor[$env][$sync_order['merchantId']][] = $sync_order['id'];
															}
															if ($order->get_status() === 'on-hold') {
																mproseo_bogfw_google_pending_payment($order);
															}
															mproseo_bogfw_update_woo_order($sync_order, $order, $sandbox_mode);
															if (!empty($marketing_email) && !empty($sync_order['customer']['marketingRightsInfo']['explicitMarketingPreference'])) {
																$allow_marketing = $sync_order['customer']['marketingRightsInfo']['explicitMarketingPreference'];
																$customer_info = array('email' => $marketing_email);
																if (!empty($sync_order['customer']['fullName'])) {
																	$customer_info['name'] = $sync_order['customer']['fullName'];
																}
																if (!empty($sync_order['customer']['marketingRightsInfo']['lastUpdatedTimestamp'])) {
																	$customer_info['last_updated'] = $sync_order['customer']['marketingRightsInfo']['lastUpdatedTimestamp'];
																}
																if ('granted' === $allow_marketing) {
																	do_action('mproseo_bogfw_customer_optin', $customer_info, ( $order_id ? $order_id : null ));
																} elseif ('denied' === $allow_marketing) {
																	do_action('mproseo_bogfw_customer_optout', $customer_info, ( $order_id ? $order_id : null ));
																}
															}
															do_action('mproseo_bogfw_order_imported', $order_id);
														}
													}
													if (!$order_acknowledged || !empty($order_id)) {
														$order_info = array('merchant_id' => $sync_order['merchantId'], 'google_id' => $sync_order['id'], 'sandbox' => $sandbox_mode);
														if (!empty($order_id)) {
															$order_info['order_id'] = $order_id;
														}
														$acknowledge_response = mproseo_bogfw_acknowledge_google_orders(array($order_info), $account_id, $user_creds[$account_id], $site_id, $order_acknowledged);
													}
												}
												do_action('mproseo_bogfw_imported_orders', $order_ids);
											}
										}
									}
								}
							}
						}
						mproseo_bogfw_temporary_sync_add($synced_orders, $dont_monitor, $monitor_list);
						do_action('mproseo_bogfw_sync_complete', $synced_orders);
					} elseif (false === $user_creds) {
						wp_schedule_single_event(( time() + ( 15 * MINUTE_IN_SECONDS ) ), mproseo_bogfw_get_sync_cron_name(), array($accounts, $get_all));
					}
				}
			}
		}

		add_action(mproseo_bogfw_get_sync_cron_name(), 'mproseo_bogfw_sync_google_orders', 10, 2);

		if (is_admin()) {

			if (!function_exists('mproseo_bogfw_google_authorize_redirect')) {
				function mproseo_bogfw_google_authorize_redirect() {
					$redirect_uri = add_query_arg(array('page' => urlencode(mproseo_bogfw_get_admin_path()), 'add_account' => urlencode(wp_create_nonce('mproseo_bogfw_add_account')), 'import_all' => ( isset($_GET['import_all']) ? true : false ), 'add_account_error' => false), admin_url('admin.php'));
					$response = mproseo_bogfw_application_api_request('authorize', array('headers' => array('Cache-Control' => 'no-cache')), array('redirect_uri' => $redirect_uri));
					if (false !== $response && !empty($response['site_id'])) {
						mproseo_bogfw_site_id('set', $response['site_id']);
					}
					nocache_headers();
					if (wp_safe_redirect(esc_url_raw(( false !== $response && !empty($response['url']) && empty($response['error']) ? $response['url'] : ( add_query_arg(array('add_account' => false, 'add_account_error' => ( false === $response || empty($response['error']) || 'rate_limited' !== $response['error'] ? 'access_denied' : 'try_again' )), $redirect_uri) ) )))) {
						exit();
					}
				}
			}

			if (!function_exists('mproseo_bogfw_option_starts_with')) {
				function mproseo_bogfw_option_starts_with( $key, $disabled_options ) {
					if ('string' !== gettype($key)) {
						return false;
					}
					foreach ($disabled_options as $disabled_option) {
						if (substr($key, 0, strlen((string) $disabled_option)) === (string) $disabled_option) {
							return true;
						}
					}
					return false;
				}
			}

			if (!function_exists('mproseo_bogfw_woo_wp_select_multiple')) {
				function mproseo_bogfw_woo_wp_select_multiple( $field ) {
					global $thepostid, $post, $woocommerce;

					$thepostid = ( empty($thepostid) ? ( $post ? $post->ID : 0 ) : $thepostid );
					$field['class'] = ( isset($field['class']) ? $field['class'] : 'select short' );
					$field['wrapper_class'] = ( isset($field['wrapper_class']) ? $field['wrapper_class'] : '' );
					$field['name'] = ( isset($field['name']) ? $field['name'] : $field['id'] );
					$field['value'] = ( isset($field['value']) ? $field['value'] : ( get_post_meta($thepostid, $field['id'], true) ? get_post_meta($thepostid, $field['id'], true) : array() ) );
	
					echo '<p class="form-field ' . esc_attr($field['id']) . '_field ' . esc_attr($field['wrapper_class']) . '">';
					echo '<label for="' . esc_attr($field['id']) . '">' . wp_kses_post($field['label']) . '</label>';
					echo '<select id="' . esc_attr($field['id']) . '" name="' . esc_attr($field['name']) . '[]" class="' . esc_attr($field['class']) . '" multiple="multiple">';
					$all_disabled = true;
					foreach ($field['options'] as $key => $value) {
						$disabled_options = ( isset($field['disabled']) ? (array) $field['disabled'] : array() );
						$field_disabled = ( !empty($disabled_options) && ( in_array($key, $disabled_options) || mproseo_bogfw_option_starts_with($key, $disabled_options) ) );
						echo '<option value="' . esc_attr($key) . '" ' . ( in_array($key, $field['value']) ? 'selected="selected"' : '' ) . ' ' . ( $field_disabled ? 'disabled="disabled"' : '' ) . '>' . esc_html__($value, 'mproseo_bogfw') . '</option>';
						if ($all_disabled && !$field_disabled) {
							$all_disabled = false;
						}
					}
					echo '</select> ';

					if (!empty($field['description'])) {
						if (isset($field['desc_tip']) && false !== $field['desc_tip']) {
							echo '<img class="help_tip" data-tip="' . esc_attr($field['description']) . '" src="' . esc_url(WC()->plugin_url()) . '/assets/images/help.png" height="16" width="16" />';
						} else {
							echo '<span class="description">' . wp_kses_post($field['description']) . '</span>';
						}
					}
					echo '</p>';
					return $all_disabled;
				}
			}
			
			global $pagenow;
			if ('admin.php' === $pagenow && current_user_can('manage_woocommerce') && isset($_GET['page']) && mproseo_bogfw_get_admin_path() === sanitize_key($_GET['page'])) {
				if (isset($_GET['add_account_error'])) {
					$add_error = sanitize_text_field($_GET['add_account_error']);
					$auth_error = in_array($add_error, array('access_denied', 'no_auth'));
					if ($auth_error) {
						mproseo_bogfw_clear_tokens();
					}
					add_action('admin_notices', function() use( $add_error, $auth_error ) {
						if (!$auth_error) {
							$uri_domain = false;
							$plugin_data = ( function_exists('get_plugin_data') ? get_plugin_data(__FILE__, false, false) : array() );
							if (!empty($plugin_data['PluginURI'])) {
								$author_uri = parse_url($plugin_data['PluginURI']);
								if (false !== $author_uri && !empty($author_uri['host'])) {
									$domain_parts = explode('.', $author_uri['host']);
									$part_count = count($domain_parts);
									$uri_domain = $domain_parts[$part_count - 2] . '.' . $domain_parts[$part_count - 1];
								}
							}
							echo '<div class="notice mproseo-bogfw-notice notice-error is-dismissible"><p>' . ( 'try_again' === $add_error ? 'We are receiving an abnormal number of requests at the moment. Please try again in a minute.' : 'There was an issue linking the account.' ) . ( false !== $uri_domain ? ' If the issue persists, please <a href="' . esc_url('mailto:support@' . $uri_domain) . '">contact support</a>' : '' ) . '</p></div>';
						}
					});
				}
				add_filter('allowed_redirect_hosts', function( $hosts ) {
					$google_accounts = 'accounts.google.com';
					if (!in_array($google_accounts, $hosts)) {
						$hosts[] = $google_accounts;
					}
					return $hosts;
				});
			}
			
			add_action('admin_menu', function() {
				add_action('admin_head', function() {
					if (isset($_GET['page']) && mproseo_bogfw_get_admin_path() === sanitize_key($_GET['page'])) {
						echo '<style>';
						echo 'a.bogfw_add_account_button:hover, a.bogfw_add_account_button:focus {border: 0 none !important; outline: none !important; box-shadow: none !important;} ';
						echo 'a.bogfw_add_account_button[disabled] {pointer-events: none !important;} ';
						echo '.mproseo-bogfw-app-settings {position: relative;} ';
						echo '.mproseo-bogfw-app-settings .close-button {position: absolute; top: 5px; right: 5px; width: 30px; height: 30px; border-radius: 50%; background-color: #ccc; color: #fff; text-align: center; line-height: 30px; text-decoration: none; font-weight: bold;} ';
						echo '.mproseo-bogfw-app-settings .close-button:hover {background-color: #aaa;} ';
						echo '.mproseo-bogfw-app-settings .mproseo-bogfw-accounts {padding-bottom: 10px;} ';
						echo '.mproseo-bogfw-app-settings label.link-account {display: block; margin-top: 10px; margin-bottom: 10px; font-size: 16px;} ';
						echo '.mproseo-bogfw-app-settings input[type=submit]:hover, .mproseo-bogfw-app-settings input[type=submit]:focus {background-color: #329632 !important; color: #FFF !important;} ';
						echo '.mproseo-bogfw-app-settings input.delete-account[type=submit]:hover, .mproseo-bogfw-app-settings input.delete-account[type=submit]:focus {background-color: #D2042D !important; color: #FFF !important;} ';
						echo '</style>';
					}
				});
				$plugin_name = mproseo_bogfw_short_plugin_name();
				if (function_exists('get_plugin_data')) {
					$plugin_data = get_plugin_data(__FILE__, false, false);
					if (!empty($plugin_data['Name'])) {
						$plugin_name = $plugin_data['Name'];
					}
				}
				add_submenu_page('woocommerce', $plugin_name, mproseo_bogfw_short_plugin_name(), 'manage_woocommerce', mproseo_bogfw_get_admin_path(), function() use( $plugin_data, $plugin_name ) {
					echo '<div class="mproseo-bogfw-app-settings" style="background-color: #FFF; padding-top: 1px; padding-bottom: 20px; border-radius: 10px; max-width: 100%; min-width: 30%; min-height: calc(20vw); margin: 0 auto; margin-top: 10px; margin-right: 1%;"><center><h1 style="color: #1A73E8; font-size: 28px; margin-top: 30px; margin-bottom: 20px; line-height: normal;">' . esc_html__($plugin_name, 'mproseo_bogfw') . '</h1>';
					$settings = mproseo_bogfw_settings();
					if (!empty($_GET['add_account']) && wp_verify_nonce(sanitize_key($_GET['add_account']), 'mproseo_bogfw_add_account')) {
						if (empty($_GET['auth'])) {
							mproseo_bogfw_google_authorize_redirect();
						} else {
							$account_info_query = base64_decode(sanitize_text_field($_GET['auth']));
							if (false !== $account_info_query) {
								parse_str($account_info_query, $account_info);
								if (!empty($account_info['site_id']) && ( empty($settings['site_id']) || $settings['site_id'] !== $account_info['site_id'] )) {
									$settings['site_id'] = sanitize_key($account_info['site_id']);
									mproseo_bogfw_settings('set', $settings);
								}
								$user_id = ( !empty($account_info['account_id']) ? sanitize_key($account_info['account_id']) : null );
								if (!empty($user_id) && !empty($account_info['access_token']) && !empty($account_info['refresh_token'])) {
									$users = mproseo_bogfw_accounts();
									unset($users[$user_id]['refresh_token']);
									$new_token = array($user_id => array('access_token' => $account_info['access_token']));
									$user_creds = mproseo_bogfw_get_google_access_tokens($users, true, $new_token);
									$users[$user_id] = array_merge(
										array('locations' => array()),
										( !empty($users[$user_id]) ? $users[$user_id] : array() ),
										array('refresh_token' => $account_info['refresh_token'])
									);
									mproseo_bogfw_accounts('set', $users);
									if (!empty(get_option(mproseo_bogfw_get_dismissed_notice_option_name()))) {
										delete_option(mproseo_bogfw_get_dismissed_notice_option_name());
									}
									if (!empty($users[$user_id]['locations'])) {
										$add_cron_args = array(array($user_id => $users[$user_id]), isset($_GET['import_all']));
										$timestamp = time();
										$scheduled = wp_next_scheduled(mproseo_bogfw_get_sync_cron_name(), $add_cron_args);
										if (false === $scheduled || $scheduled > $timestamp) {
											if (false !== $scheduled) {
												wp_clear_scheduled_hook(mproseo_bogfw_get_sync_cron_name(), $add_cron_args);
											}
											wp_schedule_single_event($timestamp, mproseo_bogfw_get_sync_cron_name(), $add_cron_args);
										}
									}
									nocache_headers();
									if (wp_safe_redirect(esc_url_raw(add_query_arg(array('add_account' => false, 'auth' => false))))) {
										exit();
									}
								}
							}
						}
					}
					echo '<div id="mproseo_bogfw_admin"><h2 style="font-size: 23px; font-weight: 400; margin: 0; padding: 9px 0 4px 0; line-height: 1.3;">Linked Merchant Accounts</h2>';
					if (!isset($users)) {
						$users = mproseo_bogfw_accounts();
					}
					if (!empty($_POST['_mproseo_bogfw_delete_nonce']) && wp_verify_nonce(sanitize_key($_POST['_mproseo_bogfw_delete_nonce']), 'mproseo_bogfw_delete_account') && !empty($_POST['delete_account'])) {
						$delete_account = sanitize_key($_POST['delete_account']);
						if (isset($users[$delete_account])) {
							unset($users[$delete_account]);
							mproseo_bogfw_accounts('set', $users);
						}
					}
					if (!isset($user_creds)) {
						$user_creds = mproseo_bogfw_get_google_access_tokens($users);
					}
					$user_list = array();
					if (!empty($users)) {
						$update_nonce = ( !empty($_POST['_mproseo_bogfw_update_nonce']) && wp_verify_nonce(sanitize_key($_POST['_mproseo_bogfw_update_nonce']), 'mproseo_bogfw_update_account') );
						$timestamp = time();
						$users_updated = false;
						foreach ($users as $user_id => &$user_settings) {
							if ($update_nonce && isset($_POST[$user_id . '_account_update'])) {
								$user_settings['locations'] = ( !empty($_POST[$user_id . '_locations']) ? array_map('sanitize_key', (array) $_POST[$user_id . '_locations']) : array() );
								if (!$users_updated) {
									$users_updated = true;
								}
								if (!empty($user_settings['locations'])) {
									$sync_cron_args = array(array($user_id => $user_settings), isset($_GET['import_all']));
									$scheduled = wp_next_scheduled(mproseo_bogfw_get_sync_cron_name(), $sync_cron_args);
									if (false === $scheduled || $scheduled > $timestamp) {
										if (false !== $scheduled) {
											wp_clear_scheduled_hook(mproseo_bogfw_get_sync_cron_name(), $sync_cron_args);
										}
										wp_schedule_single_event($timestamp, mproseo_bogfw_get_sync_cron_name(), $sync_cron_args);
									}
								}
							}
							if (!empty($user_creds[$user_id]) && !empty($user_settings['refresh_token'])) {
								$user = mproseo_bogfw_get_google_userinfo($user_id, $user_creds[$user_id]);
								if (!empty($user)) {
									$user_list[$user_id] = $user;
								}
							}
						}
						if ($users_updated) {
							mproseo_bogfw_accounts('set', $users);
						}
					}
					if (!empty($user_list)) {
						foreach ($user_list as $user_id => $user_info) {
							if (!empty($user_info['name'])) {
								echo '<div class="mproseo-bogfw-accounts"><form method="post" style="display: inline;"><p><strong>' . esc_html($user_info['name']) . ' (' . esc_html($user_info['email']) . ')</strong></p>';
								$locations = mproseo_bogfw_get_google_locations($user_id, $user_info, $user_creds[$user_id], null, ( !empty($settings['site_id']) ? $settings['site_id'] : null ));
								if (false !== $locations) {
									$saved_locations = &$users[$user_id]['locations'];
									if (!empty($saved_locations)) {
										$locations_updated = false;
										foreach ($saved_locations as $location_key => $saved_location) {
											if (!isset($locations[$saved_location])) {
												unset($saved_locations[$location_key]);
												if (!$locations_updated) {
													$locations_updated = true;
												}
											}
										}
										if ($locations_updated) {
											mproseo_bogfw_accounts('set', $users);
										}
									}
									if (!empty($locations)) {
										$all_disabled = mproseo_bogfw_woo_wp_select_multiple(array(
											'label' => __('Select Accounts ', 'mproseo_bogfw'),
											'id' => $user_id . '_locations',
											'options' => $locations,
											'value' => ( !empty($users[$user_id]['locations']) ? $users[$user_id]['locations'] : array() ),
											'disabled' => array('UNAUTHORIZED')
										));
										if (!wp_is_mobile()) {
											echo '<p>Hold Ctrl/Command key to select multiple accounts.</p>';
										}
										if (!$all_disabled) {
											woocommerce_wp_hidden_input(array(
												'id' => ( $user_id . '_account_update' ),
												'value' => 1,
											));
											wp_nonce_field('mproseo_bogfw_update_account', '_mproseo_bogfw_update_nonce');
											echo '<input class="button" type="submit" style="margin: 2px;" value="Sync"/>';
										}
									} else {
										echo '<p>No merchant accounts are linked to this Google account</p>';
									}
								} else {
									echo '<p>We were unable to get account information.<br />This could be due to a connectivity issue or because this is an invalid account.<br />(Like if it does not have a linked Merchant Account)<br />Please wait a minute and try again if you know this account has a linked Merchant Account, as it could be a temporary issue with Google\'s servers.</p>';
								}
								echo '</form> <form method="post" style="display: inline;">';
								woocommerce_wp_hidden_input(array(
									'id' => 'delete_account',
									'value' => $user_id,
								));
								wp_nonce_field('mproseo_bogfw_delete_account', '_mproseo_bogfw_delete_nonce');
								echo '<input class="button delete-account" type="submit" style="margin: 2px;" value="Delete" onclick=\'return confirm("Are you sure you want to delete this account?\n\nNote, it does not revoke your account credentials, meaning your account will remain connected to other sites using the application.\n\nIf you would like to revoke all access to your account, please search your Google account email for an account connection notice sent from ' . esc_html__(mproseo_bogfw_short_plugin_name(), 'mproseo_bogfw') . '.")\'/></form></div>';
							}
						}
					} else {
						echo '<p>No accounts are currently linked.<br />Click "Sign in with Google" below to link an account.</p>';
					}
					$image_assets_path = untrailingslashit(plugins_url('/', __FILE__)) . '/assets/images/';
					echo '<label class="link-account" for="add_account"><h4 style="font-weight: bold; text-decoration: underline; margin-bottom: 10px;">Link another account</h4><a id="add_account" class="bogfw_add_account_button" href="' . esc_url(add_query_arg('add_account', urlencode(wp_create_nonce('mproseo_bogfw_add_account')))) . '" target="_self"><img alt="Sign in with Google" src="' . esc_url($image_assets_path . 'add_account_btn_google_signin_dark_normal_web.png') . '" onmouseover="this.src=\'' . esc_url($image_assets_path . 'add_account_btn_google_signin_dark_focus_web.png') . '\';" onmouseout="this.src=\'' . esc_url($image_assets_path . 'add_account_btn_google_signin_dark_normal_web.png') . '\';" onclick="this.parentElement.setAttribute(\'disabled\', true);this.src=\'' . esc_url($image_assets_path . 'add_account_btn_google_signin_dark_pressed_web.png') . '\';this.onmouseout = function() {};document.documentElement.addEventListener(\'click\', function (e) {e.stopImmediatePropagation();e.preventDefault();}, true);" /><a></label></center></div><div style="position: relative; margin-right: 65px;" id="mproseo_bogfw_settings"><h3><noscript><span style="text-decoration: underline;">Advanced Settings</span></noscript><button id="mproseo_bogfw_advanced_settings_button" style="position: absolute; top: 0; right: 0; background-color: #D2042D; color: #FFF; display: none; z-index: 2;" onclick="var s = document.getElementById(\'mproseo_bogfw_settings_form\');if (\'none\' === s.style.display) {const buttonOffsetRight = document.documentElement.clientWidth - this.getBoundingClientRect().right;const buttonMargin = Math.max(buttonOffsetRight, 0);this.style.marginRight = `${buttonMargin}px`;s.style.display = \'block\';} else {s.style.display = \'none\';this.style.marginRight = \'0px\';};">Advanced Settings</button></h3><form id="mproseo_bogfw_settings_form" method="post" style="position: absolute; top: 100%; right: 0; text-align: center; margin-top: 30px; margin-right: 2px; z-index: 1;">';
					if (!empty($_POST['_mproseo_bogfw_settings_nonce']) && wp_verify_nonce(sanitize_key($_POST['_mproseo_bogfw_settings_nonce']), mproseo_bogfw_get_settings_option_name())) {
						$settings = array_merge(array(
							'match_products' => true,
							'monitor_term' => 30,
							'cron_interval' => 4
						), $settings);
						if (isset($_POST['match_products_enabled']) && intval($_POST['match_products_enabled'])) {
							$settings['match_products'] = true;
						}
						if (isset($_POST['monitor_imports_term'])) {
							$monitor_term = intval($_POST['monitor_imports_term']);
							if ($monitor_term >= 0) {
								$settings['monitor_term'] = $monitor_term;
							} else {
								echo '<p class="error" style="color: red;">Please enter a valid number of days to monitor imported orders, greater than or equal to 0.</p>';
							}
						}
						if (isset($_POST['sync_cron_interval'])) {
							$cron_interval = intval($_POST['sync_cron_interval']);
							if ($cron_interval >= 2) {
								$settings['cron_interval'] = $cron_interval;
								mproseo_bogfw_schedule_cron_sync($settings);
							} else {
								echo '<p class="error" style="color: red;">Please enter a valid sync interval in whole hours, greater than or equal to 2.</p>';
							}
						}
						mproseo_bogfw_settings('set', $settings);
					}
					woocommerce_wp_checkbox(array(
						'label' => __('Attempt to match products: ', 'mproseo_bogfw'),
						'id' => 'match_products_enabled',
						'desc_tip' => true,
						'description' => __('Attempt to match imported order items with products on this site using the number at the end of the Google offer ID. Disabling this option will also disable stock updating, if managed on this site.', 'mproseo_bogfw'),
						'cbvalue' => 1,
						'value' => (int) !empty($settings['match_products'])
					));
					woocommerce_wp_text_input(array(
						'label' => __('Sync Interval (in hours): ', 'mproseo_bogfw'),
						'id' => 'sync_cron_interval',
						'desc_tip' => true,
						'description' => __('Set the interval at which your site will sync orders from Google, in hours, greater than or equal to 2.', 'mproseo_bogfw'),
						'style' => 'width: 80px;',
						'value' => ( !empty($settings['cron_interval']) && is_numeric($settings['cron_interval']) && absint($settings['cron_interval']) >= 2 ? absint($settings['cron_interval']) : 4 ),
						'type' => 'number',
						'placeholder' => 4,
						'custom_attributes' => array('step' => 1, 'min' => 2)
					));
					woocommerce_wp_text_input(array(
						'label' => __('Days to monitor incomplete orders: ', 'mproseo_bogfw'),
						'id' => 'monitor_imports_term',
						'desc_tip' => true,
						'description' => __('Set the number of days to check Google for updates to already imported orders for events like cancellations or chargebacks. We recommend setting this to your maximum return policy length.', 'mproseo_bogfw'),
						'style' => 'width: 80px;',
						'value' => ( !empty($settings['monitor_term']) && is_numeric($settings['monitor_term']) && absint($settings['monitor_term']) >= 0 ? absint($settings['monitor_term']) : 30 ),
						'type' => 'number',
						'placeholder' => 30,
						'custom_attributes' => array('step' => 1, 'min' => 0)
					));
					wp_nonce_field(mproseo_bogfw_get_settings_option_name(), '_mproseo_bogfw_settings_nonce');
					echo '<input class="button" type="submit" value="Save"/></form></div><script>document.onreadystatechange = () => {if (\'complete\' === document.readyState) {document.getElementById(\'mproseo_bogfw_advanced_settings_button\').style.display = \'block\';document.getElementById(\'mproseo_bogfw_settings_form\').style.display = \'none\';}};</script>';
					echo '</div>';
				});
			});
		}
	}
});
