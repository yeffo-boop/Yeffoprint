jQuery(document).ready(function ($) {

	if (window.tptQuoteFormConfig === undefined || !window.tptQuoteFormConfig.forms) {
		return;
	}

	/**
	 * Represents a single Request a Quote modal form.
	 * 
	 * @param {jQuery} $modal 
	 * @param {int} productId 
	 * @param {object} config 
	 */
	const RequestQuoteForm = function ($modal, productId, config) {
		this.$modal = $modal;
		this.productId = productId;
		this.config = config;
		this.state = {};
		this.previousActiveElement = null;

		this.init = function () {
			this.bindEvents();
		};

		this.handleKeyDown = (e) => {
			if (e.key === 'Escape' && this.isOpen()) {
				this.close();
			}
		};

		this.bindEvents = function () {
			this.$modal.on('click', '.tpt-quote-modal-close', this.close.bind(this));
			
			let isMouseDownOnOverlay = false;

			this.$modal.on('mousedown', (e) => {
				isMouseDownOnOverlay = (e.target === this.$modal[0]);
			});

			this.$modal.on('mouseup', (e) => {
				if (isMouseDownOnOverlay && e.target === this.$modal[0]) {
					this.close();
				}
				isMouseDownOnOverlay = false;
			});

			this.$modal.find('form').on('submit', this.submit.bind(this));
		};

		this.open = function () {
			this.previousActiveElement = document.activeElement;
			this.renderState();
			this.$modal.show();
			
			// Focus first input field for accessibility
			setTimeout(() => {
				this.$modal.find('input:visible, textarea:visible').not('[type="hidden"], .tpt-quote-modal-close').first().focus();
			}, 50);
			
			$(document).on('keydown', this.handleKeyDown);
		};

		this.close = function () {
			this.$modal.hide();
			$(document).off('keydown', this.handleKeyDown);
			
			// Restore focus
			if (this.previousActiveElement) {
				this.previousActiveElement.focus();
			}
		};

		this.updateState = function (newState) {
			this.state = { ...this.state, ...newState };
			if (this.isOpen()) {
				this.renderState();
			}
		};

		this.isOpen = function () {
			return this.$modal.is(':visible');
		};

		this.renderState = function () {
			const $fieldsContainer = this.$modal.find('.tpt-quote-fields-container');

			if (this.state.quantity) {
				const $qtyInputs = $fieldsContainer.find('input.tpt-quote-sync-quantity');
				if ($qtyInputs.length) {
					$qtyInputs.val(this.state.quantity);
				}
			}

			const currentId = (this.state.variationId && this.state.variationId > 0) ? this.state.variationId : this.productId;
			this.$modal.find('.tpt-quote-product-id').val(currentId);

			if (this.state.priceHtml) {
				this.$modal.find('.tpt-quote-product-info .price').html(this.state.priceHtml);
			}
		};

		this.submit = function (e) {
			e.preventDefault();
			const $form = $(e.currentTarget);

			const submitData = (token) => {
				let formData = new FormData($form[0]);
				if (token) {
					formData.append('g-recaptcha-response', token);
				}

				const $submitBtn = $form.find('button[type="submit"]');
				const originalBtnText = $submitBtn.text();

				$.ajax({
					url: window.tptQuoteFormConfig.restUrl,
					method: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					beforeSend: (xhr) => {
						xhr.setRequestHeader('X-WP-Nonce', window.tptQuoteFormConfig.nonce);
						const submittingText = window.tptQuoteFormConfig.i18n?.defaultSubmittingText || 'Submitting...';
						$submitBtn.prop('disabled', true).text(submittingText);
						this.$modal.find('.tpt-quote-form-message').empty();
					},
					success: (response) => {
						const successAction = this.config.success_action || 'message';
						if (successAction === 'redirect' && this.config.success_redirect_url) {
							window.location.href = this.config.success_redirect_url;
						} else {
							const currentUrl = new URL(window.location.href);
							currentUrl.searchParams.set('tier_pricing_table_quote_success', '1');
							currentUrl.searchParams.set('form_id', this.config.id);
							window.location.href = currentUrl.toString();
						}
					},
					error: (xhr) => {
						const msg = xhr.responseJSON?.message || 'An error occurred.';
						this.$modal.find('.tpt-quote-form-message').html(`<div style="color: red; margin-bottom: 10px;">${msg}</div>`);
					},
					complete: () => {
						$submitBtn.prop('disabled', false).text(originalBtnText);
					}
				});
			};

			if (this.config?.recaptcha_site_key && this.config?.recaptcha_secret_key && typeof grecaptcha !== 'undefined') {
				grecaptcha.ready(() => {
					grecaptcha.execute(this.config.recaptcha_site_key, { action: 'submit' }).then((token) => {
						submitData(token);
					});
				});
			} else {
				submitData(null);
			}
		};
	};

	/**
	 * Manages all Request Quote forms on the page.
	 */
	const RequestQuoteManager = function () {
		this.forms = {};
		this.autoOpened = false;

		this.init = function () {
			this.initializeForms();
			this.bindGlobalEvents();
		};

		this.initializeForms = function () {
			$('.tpt-quote-modal').each((index, el) => {
				const $modal = $(el);
				const productId = $modal.find('.tpt-quote-product-id').val();
				const formId = $modal.find('.tpt-quote-form-id').val();
				
				const config = window.tptQuoteFormConfig.forms.find(f => f.id == formId);
				if (!config || !productId) return;

				const quoteForm = new RequestQuoteForm($modal, productId, config);
				quoteForm.init();
				
				this.forms[productId] = quoteForm;
			});
		};

		this.bindGlobalEvents = function () {
			$(document).on('click', '.tpt-request-quote-trigger', (e) => {
				e.preventDefault();
				const productId = $(e.currentTarget).data('product-id');
				
				if (this.forms[productId]) {
					this.forms[productId].open();
				}
			});

			$(document).on('tiered_price_update', (event, data) => {
				if (!data || !data.__instance || !data.__instance.formatting) return;

				const parentId = data.parentId;
				const variationId = data.productId !== data.parentId ? data.productId : null;
				const priceHtml = data.__instance.formatting.formatPrice(data.price);
				
				const stateUpdate = {
					variationId: variationId,
					parentId: parentId,
					quantity: data.quantity,
					priceHtml: priceHtml
				};

				if (parentId && this.forms[parentId]) {
					this.forms[parentId].updateState(stateUpdate);
					if (data.quantity) {
						this.handleAutoOpen(parseInt(data.quantity, 10), parentId);
					}
				}

				if (variationId && this.forms[variationId]) {
					this.forms[variationId].updateState(stateUpdate);
					if (data.quantity) {
						this.handleAutoOpen(parseInt(data.quantity, 10), variationId);
					}
				}
			});
		};

		this.handleAutoOpen = function (qty, productId) {
			if (this.autoOpened || isNaN(qty)) return;

			let $trigger;
			if (productId) {
				$trigger = $(`.tpt-request-quote-trigger[data-product-id="${productId}"]`).first();
			} else {
				$trigger = $('.tpt-request-quote-trigger').first();
			}

			if ($trigger.length) {
				const targetProductId = $trigger.data('product-id');
				const form = this.forms[targetProductId];
				
				if (form) {
					const triggerAttr = $trigger.attr('data-auto-open-quantity');
					let autoOpenQty = null;
					if (triggerAttr !== undefined) {
						// empty string means deactivated, otherwise parse it
						autoOpenQty = triggerAttr === "" ? null : triggerAttr;
					} else {
						// fallback if attribute doesn't exist
						autoOpenQty = form.config?.auto_open_quantity || null;
					}
					
					if (autoOpenQty) {
						const threshold = parseInt(autoOpenQty, 10);
						if (!isNaN(threshold) && threshold > 0 && qty >= threshold) {
							this.autoOpened = true;
							form.open();
						}
					}
				}
			}
		};
	};

	// Initialize Manager
	const manager = new RequestQuoteManager();
	manager.init();
});
