jQuery($ => {
	$('#mproseo_bogfw_no_accounts_notice').on('click', '.notice-dismiss', function() {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'mproseo_bogfw_dismiss_no_accounts_notice_handler',
				security: mproseo_bogfw_security.dismiss_notice_nonce
			},
		});
	});
});
