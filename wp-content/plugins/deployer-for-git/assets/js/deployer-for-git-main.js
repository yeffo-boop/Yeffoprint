'use strict';

var j = jQuery.noConflict();

j(function() {
	init_provider_changed_listener();
	init_private_repo_changed_listener();
	init_dashboard_listenes();
	init_log_textarea_scroll();
});

function init_provider_changed_listener() {
	j('.dfg_install_package_form').on('change', 'select[name="provider_type"]', function(e) {
		const selected_provider_name = e.target.value;

		// Repo URL descriptions
		j(`.dfg_install_package_form .dfg_repo_url_description`).addClass('dfg_hidden');
		j(`.dfg_install_package_form #${selected_provider_name}-repo-url-description`).removeClass('dfg_hidden');

		// Show "is private repository" row
		j('.dfg_is_private_repository_row').removeClass('dfg_hidden');

		// Refresh private repo fields
		refresh_private_repo_fields();

		// Update help link URL
		j('.dfg_help_link_row').removeClass('dfg_hidden');
		let help_url = '';
		switch (selected_provider_name) {
			case 'github':
				help_url = 'https://deployer-for-git.com/knowledge-base/category/faqs/github/';
				break;
			case 'bitbucket':
				help_url = 'https://deployer-for-git.com/knowledge-base/category/faqs/bitbucket/';
				break;
			case 'gitlab':
				help_url = 'https://deployer-for-git.com/knowledge-base/category/faqs/gitlab/';
				break;
			case 'gitea':
				help_url = 'https://deployer-for-git.com/knowledge-base/category/faqs/gitea/';
				break;
			default:
				help_url = 'https://deployer-for-git.com/knowledge-base/';
		}

		j('#dfg_install_package_form_repo_help_link').attr('href', help_url);
	});
}

function init_private_repo_changed_listener() {
	j('.dfg_install_package_form').on('change', 'input[name="is_private_repository"]', function(e) {
		refresh_private_repo_fields();
	});
}

function refresh_private_repo_fields() {
	const is_checked = j('.dfg_install_package_form input[name="is_private_repository"]').is(':checked');
	const provider_type = j('.dfg_install_package_form select[name="provider_type"]').val();

	j(`.dfg_install_package_form .dfg_username_row,
		 .dfg_install_package_form .dfg_password_row,
		 .dfg_install_package_form .dfg_access_token_row,
		 .dfg_install_package_form .dfg_access_token_description`).addClass('dfg_hidden');

	if (is_checked) {
		if (provider_type === 'github') {
			j('.dfg_install_package_form .dfg_access_token_row').removeClass('dfg_hidden');
			j(`.dfg_install_package_form #github-access-token-description`).removeClass('dfg_hidden');
		}

		if (provider_type === 'bitbucket') {
			j(`.dfg_install_package_form .dfg_username_row,
				 .dfg_install_package_form .dfg_password_row`).removeClass('dfg_hidden');
		}

		if (provider_type === 'gitlab') {
			j('.dfg_install_package_form .dfg_access_token_row').removeClass('dfg_hidden');
			j(`.dfg_install_package_form #gitlab-access-token-description`).removeClass('dfg_hidden');
		}

		if (provider_type === 'gitea') {
			j('.dfg_install_package_form .dfg_access_token_row').removeClass('dfg_hidden');
			j(`.dfg_install_package_form #gitea-access-token-description`).removeClass('dfg_hidden');
		}
	}
}

function init_dashboard_listenes() {
	j('.dfg_package_boxes button[data-copy-url-btn]').on('click', function() {
		const url = j(this).data('copy-url-btn');
		let $text_el = j(this).find('.text');
		let $status_el = j(this).closest('.dfg_package_card').find('.dfg_deploy_status');

		copy_dashboard_text(url).done(function() {
			$text_el.text( dfg.copied_url_label );
			$status_el.removeClass('is-error').text( dfg.copied_url_label );
			setTimeout(function() {
				$text_el.text( dfg.copy_url_label );
				$status_el.text('');
			}, 2000);
		}).fail(function() {
			$status_el.addClass('is-error').text( dfg.copy_failed_label );
		});
	});

	j('.dfg_package_boxes button[data-show-ptd-btn]').on('click', function() {
		let $button = j(this);
		let is_expanded = $button.attr('aria-expanded') === 'true';
		let $package_box_action = j('#' + $button.attr('aria-controls'));

		$button.attr('aria-expanded', ! is_expanded);
		$button.find('.text').text( is_expanded ? dfg.show_webhook_label : dfg.hide_webhook_label );
		$package_box_action.prop('hidden', is_expanded);
	});

	j('.dfg_package_boxes button[data-unlink-btn]').on('click', function(e) {
		e.preventDefault();

		if ( ! confirm( dfg.unlink_confirm_label ) ) {
			return;
		}

		var $btn = j(this);
		var slug = $btn.data('unlink-slug');
		var type = $btn.data('unlink-type');
		var $package_box = $btn.closest('.dfg_package_box');
		var $status_el = $package_box.find('.dfg_deploy_status');

		$btn.attr('disabled', true);
		$status_el.removeClass('is-error is-success').text( dfg.unlinking_label );

		j.ajax({
			url: dfg.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'dfg_unlink_package',
				nonce: dfg.unlink_nonce,
				slug: slug,
				type: type
			}
		})
		.done(function(response) {
			if (response.success) {
				$package_box.fadeOut(300, function() {
					var $section = j(this).closest('.dfg_package_section');
					j(this).remove();
					$section.find('.dfg_count_badge').text( $section.find('.dfg_package_box').length );
				});
			} else {
				$status_el.addClass('is-error').text( response.data.message || dfg.error_label );
				$btn.removeAttr('disabled');
			}
		})
		.fail(function() {
			$status_el.addClass('is-error').text( dfg.error_label );
			$btn.removeAttr('disabled');
		});
	});

	j('.dfg_package_boxes button[data-trigger-ptd-btn]').on('click', function(e) {
		e.preventDefault();
		const endpoint_url = j(this).data('trigger-ptd-btn');
		let $parent_el = j(this);
		let $parent_text_el = j(this).find('.text');
		let $status_el = j(this).closest('.dfg_package_card').find('.dfg_deploy_status');
		let default_label = $parent_el.data('package-type') === 'theme' ? dfg.deploy_theme_label : dfg.deploy_plugin_label;

		j.ajax({
			url: endpoint_url,
			type: 'POST',
			dataType: 'json',
			beforeSend: function() {
				$parent_el.addClass( 'loading' );
				$parent_el.attr( 'disabled', true );
				$parent_text_el.text( dfg.deploying_now_label );
				$status_el.removeClass('is-error is-success').text( dfg.deploying_now_label );
			}
		})
		.done(function(response) {
			if (response.success) {
				$parent_text_el.text( dfg.deploy_completed_label );
				$status_el.addClass('is-success').text( response.message || dfg.deploy_completed_label );

				setTimeout(function() {
					$parent_text_el.text( default_label );
				}, 2000);
			} else {
				$parent_text_el.text( default_label );
				$status_el.addClass('is-error').text( response.message || dfg.error_label );
			}
		})
		.fail(function(response) {
			let message = response.responseJSON && response.responseJSON.message ? response.responseJSON.message : dfg.error_label;
			$parent_text_el.text( default_label );
			$status_el.addClass('is-error').text( message );
		})
		.always(function() {
			$parent_el.removeClass( 'loading' ).removeAttr( 'disabled' );
		});
	});
}

function copy_dashboard_text(text) {
	let deferred = j.Deferred();

	if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(text).then(deferred.resolve, deferred.reject);
		return deferred.promise();
	}

	let $textarea = j('<textarea readonly></textarea>');
	$textarea.css({
		position: 'fixed',
		opacity: 0,
		pointerEvents: 'none'
	});
	$textarea.val(text).appendTo('body').trigger('select');

	try {
		if (document.execCommand('copy')) {
			deferred.resolve();
		} else {
			deferred.reject();
		}
	} catch (error) {
		deferred.reject(error);
	}

	$textarea.remove();
	return deferred.promise();
}

function init_log_textarea_scroll() {
	var $textarea = j('.dfg_log_textarea');
	if ($textarea.length) {
		$textarea.each(function() {
			this.scrollTop = this.scrollHeight;
		});
	}
}
