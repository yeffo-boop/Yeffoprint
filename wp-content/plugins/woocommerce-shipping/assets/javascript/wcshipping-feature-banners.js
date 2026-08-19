/* global jQuery, wcShippingBanners, ajaxurl */
(function($) {
	$(document).ready(function() {
		// Show banners now that JavaScript has loaded to prevent FOUC
		$('.wcshipping-feature-banner').addClass('wcshipping-feature-banner--loaded');

		$('.wcshipping-feature-banner .notice-dismiss').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation();

			var $button = $(this);
			var $banner = $button.closest('.wcshipping-feature-banner');
			var bannerId = $banner.data('banner-id');

			if (!bannerId) {
				return;
			}

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wcshipping_dismiss_feature_banner',
					banner_id: bannerId,
					nonce: wcShippingBanners.dismissNonce
				},
				success: function(response) {
					if (response.success) {
						$banner.fadeOut(300, function() {
							$banner.remove();
						});
					} else {
						console.error('WCShipping: Failed to dismiss banner', response);
					}
				},
				error: function(xhr, status, error) {
					console.error('WCShipping: AJAX error', error);
				}
			});
		});

		$('.wcshipping-feature-banner__button').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var bannerId = $button.data('banner-id');
			var buttonAction = $button.data('button-action');
			var url = $button.data('url');

			if (!bannerId || !buttonAction || !url) {
				return;
			}

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wcshipping_track_feature_banner_click',
					banner_id: bannerId,
					button_action: buttonAction,
					nonce: wcShippingBanners.trackNonce
				}
			});

			// if the url starts with ?, then we assume that there are new params
			// to be added to the current url.
			if (url.charAt(0) === '?') {
				var currentUrl = window.location.href;
				// Append if url already has params, or just set params if not present
				var separator = currentUrl.indexOf('?') !== -1 ? '&' : '?';
				var finalUrl = currentUrl + separator + url.substring(1);
				// Need to reload to ensure the params are picked up by the state
				window.location.href = finalUrl;
			} else {
				// External URL - navigate normally
				window.location.href = url;
			}
		});

	});
})(jQuery);
