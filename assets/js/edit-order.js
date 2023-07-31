jQuery($ => {
	const $orderItems = $('#woocommerce-order-items');
	const $restockItems = $orderItems.find('#restock_refunded_items');
	const $restockItemsSection = $restockItems.closest('tr');
	$restockItemsSection.hide().find('label').text('Cancel item(s) (refund and restock)');
	var $refundHtml = null;
	if($refundHtml == null) {
		$refundHtml = $('.wc-order-refund-amount').clone().prepend('Refund ').append(' via Google');
	}
	const $refundBtn = $('.do-manual-refund');
	$refundBtn.html($refundHtml).prop('disabled', true).on('mouseenter', function() {$('#tiptip_content').text('This will send the request to Google for processing.');});

	function updateItemQuantity(index, item) {
		let $possibleItemGroup = $(item).closest('tr');
		if($possibleItemGroup.data('order_item_id')) {
			let $itemName = $possibleItemGroup.find('td.name');
			let $itemMeta = $itemName.find('div.view table.display_meta').find('tr');
			if($itemMeta != null) {
				let $cancelledItemQty = parseInt('0');
				let $pendingItemQty = parseInt('0');
				$itemMeta.each(function(index, meta) {
					const $metaText = $(meta).find('th').first().text();
					if($metaText != null) {
						if($metaText === 'Cancelled:') {
							$cancelledItemQty = parseInt($(meta).find('td').first().text());
						} else if($metaText === 'Pending:') {
							$pendingItemQty = parseInt($(meta).find('td').first().text());
						}
					}
				});
				let $itemRefundQty = $possibleItemGroup.find('td.quantity div.refund input.refund_order_item_qty');
				if($itemRefundQty != null) {
					let $itemRefundQtyMax = ($itemRefundQty.data('max-qty') ? $itemRefundQty.data('max-qty') : parseInt($itemRefundQty.attr('max')));
					if(!$itemRefundQty.data('max-qty')) $itemRefundQty.data('max-qty', $itemRefundQtyMax);
					if($itemRefundQtyMax <= $cancelledItemQty && !$restockItems.prop('checked')) {
						$itemRefundQty.val(0).prop('disabled', true);
					} else {
						if($restockItems.prop('checked')) {
							$itemRefundQtyMax = $pendingItemQty;
						} else {
							$itemRefundQtyMax -= $cancelledItemQty;
						}
						$itemRefundQty.attr({
							'min': 0,
							'max': $itemRefundQtyMax
						});
					}
				}
			}
		}
	}

	const $itemRefundQtys = $('.refund input.refund_order_item_qty');
	$itemRefundQtys.each(function(index, item) {updateItemQuantity(index, item);});

	$orderItems.on('change keyup', '.wc-order-refund-items #refund_amount', function() {
		var total = accounting.unformat($(this).val(), woocommerce_admin.mon_decimal_point);
		const $disableRefund = (total <= 0 && !$restockItems.prop('checked'));
		if($refundBtn.prop('disabled') !== $disableRefund) {
			$refundBtn.prop('disabled', $disableRefund);
		}
	});

	function removeRefundItemDelete(index, item) {
		if($(item).closest('tr').data('order_refund_id')) {
			$(item).remove();
		}
	}

	const $orderRefunds = $('#order_refunds');
	$orderRefunds.on('change', function() {$(this).find('tr.refund td.wc-order-edit-line-item div.wc-order-edit-line-item-actions a.delete_refund').each(function(index, item) {removeRefundItemDelete(index, item);});}).change();

	var $refundBtnBackup = null;
	$restockItems.on('change', function() {
		$('.refund input.refund_line_total').each(function(index, item) {
			if($(item).closest('tr').data('order_item_id')) {
				if($restockItems.prop('checked')) $(item).val(0).change();
				$(item).prop('disabled', (!$restockItems.is(':hidden') && $restockItems.prop('checked')));
			}
		});

		$('.refund input.refund_line_tax').each(function(index, item) {
			if($(item).closest('tr').data('order_item_id')) {
				if($restockItems.prop('checked')) $(item).val(0).change();
				$(item).prop('disabled', (!$restockItems.is(':hidden') && $restockItems.prop('checked')));
			}
		});

		if($itemRefundQtys != null) $itemRefundQtys.each(function(index, item) {
			updateItemQuantity(index, item);
			if($(item).closest('tr').data('order_item_id')) {
				if($(item).val() == null || ($(item).val() !== 0 && !$(item).val())) {
					$(item).val(0);
				}
			}
		}).change();

		if($(this).prop('checked')) {
			if(!$(this).is(':hidden')) {
				if($refundBtnBackup == null) $refundBtnBackup = $refundBtn.html();
				$refundBtn.html('Cancel via Google');
			}
		} else if($refundBtnBackup != null) {
			$refundBtn.html($refundBtnBackup);
		}
	});

	$itemRefundQtys.on('change keyup', function() {
		let $changeRestock = true;
		$itemRefundQtys.each(function(index, item) {
			if($(item).closest('tr').data('order_item_id')) {
				let $qtyMin = parseInt($(item).attr('min'));
				let $qtyMax = parseInt($(item).attr('max'));
				if(parseInt($(item).val()) > $qtyMax) {
					$(item).val($qtyMax).change();
				} else if(parseInt($(item).val()) < $qtyMin) {
					$(item).val($qtyMin).change();
				}
				if($restockItems.is(':hidden') || !$restockItems.prop('checked') || $(item).val() > 0) {
					$changeRestock = false;
				}
			}
		});
		if($restockItems.prop('checked') && $changeRestock) {
			$restockItems.prop('checked', false).change();
		}
	});

	const $refundAmount = $('#refund_amount');
	$refundAmount.on('change', function() {
		if($restockItems.prop('checked')) {
			const $orderTotals = $('.wc-order-refund-items table.wc-order-totals').find('tr');
			if($orderTotals != null) $orderTotals.each(function(index, total) {
				const $totalLabel = $(total).find('td.label').first();
				if($totalLabel != null && $totalLabel.text() === 'Total available to refund:') {
					const $maxRefundable = $(total).find('td.total').first();
					if($maxRefundable != null) {
						let $maxTotal = parseFloat(accounting.unformat($maxRefundable.text(), woocommerce_admin.mon_decimal_point));
						if($maxTotal < $refundAmount.val()) {
							$refundAmount.val($maxTotal);
						}
					}
					return false;
				}
			});
		}
	});

	const $itemShip = $('#mproseo_bogfw_ship');
	const $itemShipTracking = $('#mproseo_bogfw_ship_tracking');
	const $itemAddTracker = $('#mproseo_bogfw_ship_add_tracking');
	const $itemShipBtn = $('#mproseo_bogfw_ship_button button.ship_button');
	function shipItemsUpdate() {
		const $itemShipType = $itemShip.find('input.mproseo_bogfw_ship_type:checked');
		if($itemShipType != null) {
			if($itemShipType.val() === 'SINGLE') {
				$itemShip.find('#mproseo_bogfw_ship_multi').hide();
				$itemShip.find('#mproseo_bogfw_multi_ship').show();
				$itemAddTracker.show();
			} else {
				$itemShip.find('#mproseo_bogfw_multi_ship').hide();
				$itemShip.find('#mproseo_bogfw_ship_multi').show();
				$itemShipTracking.find('td.delete_tracker button.delete_tracker').each(function(index, tracker) {$(tracker).closest('tr').remove();});
				$itemAddTracker.hide();
			}
		}
		let $itemShipBtnDisabled = true;
		const $itemShipQtys = $itemShip.find('input.mproseo_bogfw_ship_qty');
		if($itemShipQtys != null) $itemShipQtys.each(function(index, item) {
			let $qtyMin = parseInt($(item).attr('min'));
			const $optSel = $(this).closest('div').find('#mproseo_bogfw_multi_ship_item option:selected');
			if($optSel != null) {
				const $optMax = $optSel.data('qty-pending');
				if($optMax != null) $(item).attr('max', $optMax);
				const $inputName = $optSel.val();
				if($inputName != null) $(item).data('item-id', $inputName);
			}
			let $qtyMax = parseInt($(item).attr('max'));
			if(parseInt($(item).val()) > $qtyMax) {
				$(item).val($qtyMax).change();
			} else if(parseInt($(item).val()) < $qtyMin) {
				$(item).val($qtyMin).change();
			}
			if($itemShipBtnDisabled && $(item).val() > 0 && !$(item).is(':hidden')) $itemShipBtnDisabled = false;
		});
		let $itemAddTrackerDisabled = false;
		const $itemShipCustomCarrierColumn = $itemShipTracking.find('.mproseo_bogfw_ship_custom_carrier_column');
		const $itemShipCarriers = $itemShipTracking.find('select.mproseo_bogfw_ship_tracking_carriers');
		if($itemShipCarriers != null) {
			let $hideShipCustomCarrierColumn = true;
			$itemShipCarriers.each(function(index, carrier) {
				if(!$(carrier).val()) {
					const $lineCustomCarrier = $(carrier).closest('tr').find('td.custom_carrier input.custom_carrier');
					if($lineCustomCarrier != null) {
						$lineCustomCarrier.show();
						$hideShipCustomCarrierColumn = false;
					}
					if($lineCustomCarrier == null || !$lineCustomCarrier.val()) {
						if(!$itemAddTrackerDisabled) $itemAddTrackerDisabled = true;
						if(!$itemShipBtnDisabled) $itemShipBtnDisabled = true;
					}
				} else {
					$(carrier).closest('tr').find('td.custom_carrier input.custom_carrier').hide().val('');
				}
			});
			if($itemShipCustomCarrierColumn != null) {
				if($hideShipCustomCarrierColumn) {
					$itemShipCustomCarrierColumn.each(function(index, row) {$(row).hide();});
				} else $itemShipCustomCarrierColumn.each(function(index, row) {$(row).show();});
			}
		}

		const $itemShipTrackers = $itemShipTracking.find('input.mproseo_bogfw_ship_tracking');
		if($itemShipTrackers != null) {
			$itemShipTrackers.each(function(index, tracker) {
				if(!$(tracker).val()) {
					if($itemAddTracker != null && !$itemAddTrackerDisabled) $itemAddTrackerDisabled = true;
					if(!$itemShipBtnDisabled) $itemShipBtnDisabled = true;
				}
			});
			$itemAddTracker.prop('disabled', $itemAddTrackerDisabled);
		}
		$itemShipBtn.prop('disabled', $itemShipBtnDisabled);
	}
	if($itemShip != null) shipItemsUpdate();

	$itemAddTracker.on('click', function() {
		const $trackerLine = $('#mproseo_bogfw_ship_tracking_line');
		if($trackerLine != null) {
			$trackerLineHtml = $('<tr>' + $trackerLine.html() + '</tr>');
			$trackerLineHtml.find('td.delete_tracker').html('<button type="button" class="button button-secondary delete_tracker">X</button>');
			$trackerLine.closest('table').append($trackerLineHtml).change();
		}
	});

	$itemShipTracking.on('click', 'td.delete_tracker button.delete_tracker', function() {
		$(this).closest('tr').remove();
		$itemShipTracking.change();
	});

	$itemShip.on('change keyup', function() {shipItemsUpdate();});

	$itemShipBtn.on('click', function() {
		$itemShip.block({
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6,
			}
		});
		if(window.confirm('Are you sure you want to create this shipment?')) {

			var line_items = [];
			var shipments = [];

			$('#mproseo_bogfw_ship input.mproseo_bogfw_ship_qty').each(function(index, item) {
				if(!$(item).is(':hidden') && $(item).val()) {
					var item_id = $(item).data('item-id');
					if(typeof item_id !== 'undefined' && item_id !== false) {
						line_items.push({id: item_id, qty: parseInt($(item).val())});
					}
				}
			});

			$('#mproseo_bogfw_ship input.mproseo_bogfw_ship_tracking').each(function(index, tracker) {
				if($(tracker).val()) {
					const $trackerRow = $(tracker).closest('tr');
					if($trackerRow != null) {
						let $trackerCarrier = null;
						const $selectedCarrier = $trackerRow.find('td select.mproseo_bogfw_ship_tracking_carriers option:selected');
						if($selectedCarrier != null) if($selectedCarrier.val()) {
							$trackerCarrier = $selectedCarrier.val();
						} else {
							const $customCarrier = $trackerRow.find('td.mproseo_bogfw_ship.custom_carrier input.mproseo_bogfw_ship.custom_carrier');
							if($customCarrier != null) if($customCarrier.val()) {
								$trackerCarrier = $customCarrier.val();
							}
						}
						if($trackerCarrier != null) shipments.push({carrier: $trackerCarrier, id: $(tracker).val()});
					}
				}
			});

			var data = {
				action: 'mproseo_bogfw_google_shipment_create_handler',
				order_id: woocommerce_admin_meta_boxes.post_id,
				line_items: line_items,
				shipments: shipments,
				security: woocommerce_admin_meta_boxes.order_item_nonce,
			};

			$.ajax({
				url: woocommerce_admin_meta_boxes.ajax_url,
				data: data,
				type: 'POST',
				success: function(response) {
					$('#wpwrap').block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6,
						}
					});
					alert('Shipment submitted. The order will now refresh.');
					window.location.reload();
				},
				error: function(jqXHR, textStatus, errorThrown) {
					var message = $.parseJSON(jqXHR.responseText);
					let reason = 'No error details provided.';
					if(message != null && message.hasOwnProperty('data') && message.data != null && message.data.hasOwnProperty('error') && message.data.error != null) {
						reason = message.data.error;
						if(message.data.hasOwnProperty('code') && message.data.code != null) reason += (' (Code: ' + message.data.code + ')');
					}
					alert("Failed to submit shipment.\r\nError: " + reason);
				},
				complete: function() {
					$itemShip.unblock();
				},
			});

		} else $itemShip.unblock();
	});
	$('#mproseo_bogfw_create_shipments_no_load').hide();
	$('#mproseo_bogfw_update_shipments_no_load').hide();
	$('#mproseo_bogfw_create_shipments').show();
	const $updateShip = $('#mproseo_bogfw_update_shipments');
	$updateShip.show();
	$updateShip.find('div.mproseo_bogfw_update_shipments_tracking table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td.edit_tracker button.edit_tracker').on('click', function() {
		if($(this).closest('div.mproseo_bogfw_update_shipments_tracking').data('ship-id')) $(this).hide().closest('td.edit_tracker').find('span').show().closest('div.mproseo_bogfw_update_shipments_tracking').find('table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td select, table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td input, div.update_tracker, table.mproseo_bogfw_update_shipments_status tr td.mproseo_bogfw_update_shipments_new_status button.status_button').each(function(index, item) {$(item).prop('disabled', ($(item).prop('nodeName') === 'BUTTON')).show();});
	});
	$updateShip.find('div.mproseo_bogfw_update_shipments_tracking table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td.edit_tracker button.cancel_edit_tracker').on('click', function() {
		if($(this).closest('div.mproseo_bogfw_update_shipments_tracking').data('ship-id')) $(this).closest('span').hide().closest('td.edit_tracker').find('button.edit_tracker').show().closest('div.mproseo_bogfw_update_shipments_tracking').find('div.update_tracker').hide().closest('div.mproseo_bogfw_update_shipments_tracking').find('table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td select, table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td input, table.mproseo_bogfw_update_shipments_status tr td.mproseo_bogfw_update_shipments_new_status button.status_button').each(function(index, item) {if($(item).data('set-value')) $(item).val($(item).data('set-value')).change(); $(item).prop('disabled', ($(item).prop('nodeName') !== 'BUTTON'));});
	});
	$updateShip.on('change keyup', 'table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td select, table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking td input', function() {
		let $itemUpdateTrackerDisabled = false;
		const $manageLine = $(this).closest('tr');
		if($manageLine != null) {
			const $itemManageCustomCarrierColumn = $manageLine.closest('table.mproseo_bogfw_update_shipments_tracking').find('.mproseo_bogfw_update_shipments_custom_carrier_column');
			const $itemManageCarrier = $manageLine.find('td select.mproseo_bogfw_update_shipments_tracking_carriers').first();
			if($itemManageCarrier != null) {
				let $hideManageCustomCarrierColumn = true;
				if(!$itemManageCarrier.val()) {
					const $lineCustomCarrier = $manageLine.find('td.custom_carrier input.custom_carrier');
					if($lineCustomCarrier != null) {
						$lineCustomCarrier.show();
						$hideManageCustomCarrierColumn = false;
					}
					if($lineCustomCarrier == null || !$lineCustomCarrier.val()) {
						if(!$itemUpdateTrackerDisabled) $itemUpdateTrackerDisabled = true;
					}
				} else {
					$itemManageCarrier.closest('tr').find('td.custom_carrier input.custom_carrier').hide().val('');
				}
				if($itemManageCustomCarrierColumn != null) {
					if($hideManageCustomCarrierColumn) {
						$itemManageCustomCarrierColumn.each(function(index, row) {$(row).hide();});
					} else $itemManageCustomCarrierColumn.each(function(index, row) {$(row).show();});
				}
			}
			if(!$manageLine.find('input.mproseo_bogfw_update_shipments_tracking_numbers').first().val() && !$itemUpdateTrackerDisabled) $itemUpdateTrackerDisabled = true;
		}
		$manageLine.closest('div.mproseo_bogfw_update_shipments_tracking').find('div.update_tracker button.update_tracker').prop('disabled', $itemUpdateTrackerDisabled);
	});

	$updateShip.find('div.mproseo_bogfw_update_shipments_tracking').on('click', 'div.update_tracker button.update_tracker, table.mproseo_bogfw_update_shipments_status td.mproseo_bogfw_update_shipments_new_status button.status_button', function() {
		const $shipItem = $(this).closest('div.mproseo_bogfw_update_shipments_tracking');
		if($shipItem != null) {
			$shipItem.block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6,
				}
			});
			if(window.confirm('Are you sure you want to update this shipment?')) {
				const $shipItemId = $shipItem.data('ship-id');
				if($shipItemId != null && $shipItemId) {
					var shipmentId = $shipItemId;
					var shipStatus = null;
					var shipCarrier = null;
					var shipTracking = null;

					const $shipItemLine = $shipItem.find('table.mproseo_bogfw_update_shipments_tracking tr.mproseo_bogfw_update_shipments_tracking');
					if($shipItemLine != null) {
						$shipLineCarrier = $shipItemLine.find('td select.mproseo_bogfw_update_shipments_tracking_carriers').first();
						if($shipLineCarrier != null && $shipLineCarrier.val()) {
							shipCarrier = $shipLineCarrier.val();
						} else {
							$shipLineCustomCarrier = $shipItemLine.find('td.custom_carrier input.custom_carrier').first();
							if($shipLineCustomCarrier != null && $shipLineCustomCarrier.val()) {
								shipCarrier = $shipLineCustomCarrier.val();
							}
						}
						$shipLineTracking = $shipItemLine.find('td input.mproseo_bogfw_update_shipments_tracking_numbers').first();
						if($shipLineTracking != null && $shipLineTracking.val()) {
							shipTracking = $shipLineTracking.val();
						}
					}

					if($(this).data('new-status')) shipStatus = $(this).data('new-status');

					if(shipCarrier == null || shipTracking == null) {
						alert('Invalid request (missing carrier or tracking number). Please try again.');
					} else {
						var data = {
							action: 'mproseo_bogfw_google_shipment_update_handler',
							order_id: woocommerce_admin_meta_boxes.post_id,
							id: shipmentId,
							carrier: shipCarrier,
							tracking_id: shipTracking,
							security: woocommerce_admin_meta_boxes.order_item_nonce,
						};
						if(shipStatus != null) data.status = shipStatus;

						$.ajax({
							url: woocommerce_admin_meta_boxes.ajax_url,
							data: data,
							type: 'POST',
							success: function(response) {
								$shipItemLine.find('td select, td input').each(function(index, item) {$(item).data('set-value', $(item).val());});
								$shipItemLine.find('td.edit_tracker button.cancel_edit_tracker').first().click();
								if(data.status) {
									const $shipItemTracking = $shipItem.find('table.mproseo_bogfw_update_shipments_status');
									if($shipItemTracking != null) {
										$shipItemTracking.find('.mproseo_bogfw_update_shipments_current_status strong').text(data.status.charAt(0).toUpperCase() + data.status.slice(1));
										$shipItemTracking.find('.mproseo_bogfw_update_shipments_new_status').remove();
									}
								}
								alert('Shipment update submitted successfully.');
							},
							error: function(jqXHR, textStatus, errorThrown) {
								let reason = 'No error details provided.';
								var message = $.parseJSON(jqXHR.responseText);
								if(message != null && message.hasOwnProperty('data') && message.data != null && message.data.hasOwnProperty('error') && message.data.error != null) {
									reason = message.data.error;
									if(message.data.hasOwnProperty('code') && message.data.code != null) reason += (' (Code: ' + message.data.code + ')');
								}
								alert("Failed to submit shipment update.\r\nError: " + reason);
								$shipItemLine.find('td select, td input').each(function(index, item) {if($(item).data('set-value')) $(item).val($(item).data('set-value')).change();});
							},
							complete: function() {
								$shipItem.unblock();
							},
						});
					}
				}
			} else $shipItem.unblock();
		}
	});
});
