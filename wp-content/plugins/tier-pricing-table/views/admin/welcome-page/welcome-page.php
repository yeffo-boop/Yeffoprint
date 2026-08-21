<?php use TierPricingTable\Core\ServiceContainer;

	defined( 'ABSPATH' ) || die();

	$fileManager = ServiceContainer::getInstance()->getFileManager();
?>
<style>
	/**
	  * General styles
	 */
	.notice, .error {
		display: none;
	}

	.tpt-checkmark {
		display: flex;
		align-items: center;
		font-weight: 600;
		background: #f1f5f9;
		border: 1px solid #e2e8f0;
		padding: 6px 10px;
		border-radius: 6px;
		color: #0f172a;
		transition: all 0.2s ease;
		margin: 0;
		font-size: 0.95em;
	}

	.tpt-checkmark:hover {
		background: #fff;
		border-color: #cbd5e1;
		box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
		transform: translateX(4px);
	}

	.tpt-checkmark::before {
		content: '';
		background-image: url(<?php echo esc_attr( $fileManager->locateAsset( 'admin/welcome-page/checkmark.svg' ) ); ?>);
		background-size: 1.2em;
		background-repeat: no-repeat;
		background-position: center;
		width: 1.2em;
		height: 1.2em;
		display: inline-block;
		flex-shrink: 0;
		margin-right: 8px;
	}

	/**
	  * Button styles
	  */
	.tpt-welcome-page-button {
		display: inline-block;
		padding: 14px 28px;
		font-size: 15px;
		font-weight: 600;
		text-decoration: none;
		border-radius: 8px;
		transition: all 0.2s ease;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	}

	.tpt-welcome-page-button-primary {
		background: #96598a;
		color: #fff;
	}

	.tpt-welcome-page-button-primary--border {
		border: 2px solid rgba(255, 255, 255, 0.3);
		box-shadow: none;
	}

	.tpt-welcome-page-button-primary:hover {
		color: #fff;
		background: #7b3f6f;
		transform: translateY(-2px);
		box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
	}

	.tpt-welcome-page-button-primary--border:hover {
		border-color: #fff;
	}

	.tpt-welcome-page-button-secondary {
		background: #79ab3f;
		color: #fff;
	}

	.tpt-welcome-page-button-secondary:hover {
		color: #fff;
		background: #5f8a2f;
		transform: translateY(-2px);
		box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
	}

	.tpt-welcome-page {
		margin-left: -20px;
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
	}

	.tpt-welcome-page-hero {
		background: linear-gradient(135deg, #7b3f6f 0%, #96598a 100%);
		display: flex;
		justify-content: space-between;
		align-items: center;
		color: #fff;
		padding: 40px 50px;
		box-shadow: 0 4px 12px rgba(150, 89, 138, 0.2);
	}

	.tpt-welcome-page-hero__content {
		width: 40%;
	}

	.tpt-browser-mockup {
		background: #fff;
		border-radius: 12px;
		box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
		width: 100%;
		max-width: 750px;
		min-width: 600px;
		transform: perspective(1000px) rotateY(-5deg) rotateX(5deg);
		transition: transform 0.5s ease;
		margin-left: auto;
		overflow: hidden;
	}

	.tpt-browser-mockup:hover {
		transform: perspective(1000px) rotateY(0deg) rotateX(0deg) scale(1.02);
	}

	.tpt-browser-mockup-header {
		background: #f1f5f9;
		padding: 12px 16px;
		display: flex;
		align-items: center;
		border-bottom: 1px solid #e2e8f0;
	}

	.tpt-browser-mockup-header .dot {
		display: inline-block;
		width: 12px;
		height: 12px;
		border-radius: 50%;
		margin-right: 8px;
	}

	.tpt-browser-mockup-header .dot-red {
		background: #ef4444;
	}

	.tpt-browser-mockup-header .dot-yellow {
		background: #eab308;
	}

	.tpt-browser-mockup-header .dot-green {
		background: #22c55e;
	}

	.tpt-browser-mockup-address {
		background: #fff;
		border: 1px solid #cbd5e1;
		border-radius: 4px;
		padding: 4px 12px;
		font-size: 0.8rem;
		color: #94a3b8;
		margin-left: 20px;
		flex: 1;
		text-align: center;
	}

	.tpt-interactive-preview {
		display: flex;
		flex-direction: column;
		background: #fff;
		color: #333;
		padding: 30px;
		gap: 24px;
		box-sizing: border-box;
	}

	.tpt-interactive-preview * {
		box-sizing: border-box;
	}

	.tpt-ip-top {
		display: flex;
		gap: 24px;
		align-items: flex-start;
	}

	.tpt-ip-left {
		flex: 0 0 120px;
		background: #eef2f6;
		border-radius: 8px;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 20px;
		min-width: 0;
		aspect-ratio: 1 / 1;
	}

	.tpt-ip-left img {
		width: 100%;
		max-height: 250px;
		object-fit: contain;
	}

	.tpt-ip-top-info {
		flex: 1;
		display: flex;
		flex-direction: column;
		text-align: left;
		min-width: 0;
	}

	.tpt-ip-title {
		font-size: 2rem;
		font-weight: 300;
		margin: 0 0 10px 0;
		color: #1e293b;
		line-height: 1;
	}

	.tpt-ip-main-price {
		font-size: 1.5rem;
		margin-bottom: 15px;
		color: #475569;
	}

	.tpt-ip-main-price del {
		color: #9ca3af;
		margin-right: 8px;
	}

	.tpt-ip-you-save {
		display: block;
		font-size: 0.9rem;
		color: #64748b;
		font-weight: 500;
		margin-top: 4px;
	}

	.tpt-ip-desc {
		color: #64748b;
		margin-top: 0;
		margin-bottom: 20px;
		font-size: 0.95em;
	}

	.tpt-ip-table-container {
		margin-bottom: 20px;
		background: #fff;
		border: 1px solid #cbd5e1;
		border-radius: 6px;
		overflow: hidden;
	}

	.tpt-ip-table {
		width: 100%;
		border-collapse: collapse;
		font-size: 14px;
	}

	.tpt-ip-table th {
		background: #f8fafc;
		padding: 10px 15px;
		text-align: left;
		font-weight: 600;
		color: #475569;
		border-bottom: 1px solid #e2e8f0;
	}

	.tpt-ip-block {
		cursor: pointer;
		transition: background 0.2s ease;
		border-bottom: 1px solid #f1f5f9;
		color: #334155;
	}

	.tpt-ip-block:last-child {
		border-bottom: none;
	}

	.tpt-ip-block:hover {
		background: #f8fafc;
	}

	.tpt-ip-block.is-selected {
		background: #faf5f8;
	}

	.tpt-ip-block.is-selected td {
		position: relative;
	}

	.tpt-ip-block.is-selected td:first-child::before {
		content: '';
		position: absolute;
		left: 0;
		top: 0;
		bottom: 0;
		width: 3px;
		background: #96598a;
	}

	.tpt-hero-preview-qty {
		padding: 12px 15px;
		font-size: 0.95em;
	}

	.tpt-hero-preview-price {
		padding: 12px 15px;
		font-size: 1.05em;
	}

	.tpt-hero-preview-discount {
		padding: 12px 15px;
		font-size: 1.05em;
	}

	.tpt-hero-preview-badge {
		font-size: 0.75em;
		padding: 3px 6px;
		border-radius: 4px;
		margin-left: 8px;
		font-weight: 600;
		text-transform: uppercase;
		display: inline-flex;
		align-items: center;
		vertical-align: middle;
		gap: 4px;
	}

	.tpt-hero-preview-badge .dashicons {
		font-size: 14px;
		width: 14px;
		height: 14px;
		line-height: 14px;
	}

	.tpt-hero-preview-badge--popular {
		background: #96598a;
		color: #fff;
	}

	.tpt-hero-preview-badge--value {
		background: #eab308;
		color: #fff;
	}

	.tpt-ip-add-to-cart {
		display: flex;
		gap: 12px;
		margin-bottom: 25px;
	}

	.tpt-ip-qty-input {
		width: 70px;
		padding: 8px;
		border: 1px solid #cbd5e1;
		border-radius: 4px;
		font-size: 1.05rem;
		text-align: center;
		background: #f1f5f9;
		color: #334155;
	}

	.tpt-ip-btn {
		background: #334155;
		color: #fff;
		border: none;
		padding: 8px 20px;
		border-radius: 4px;
		font-size: 1rem;
		font-weight: 600;
		cursor: pointer;
		transition: background 0.2s ease;
	}

	.tpt-ip-btn:hover {
		background: #1e293b;
	}

	.tpt-ip-summary {
		border-top: 1px solid #e2e8f0;
		padding-top: 15px;
	}

	.tpt-ip-summary-row {
		display: flex;
		justify-content: space-between;
		margin-bottom: 8px;
		font-size: 1.05rem;
		color: #475569;
		font-weight: 600;
	}

	.tpt-ip-summary-row--total {
		font-size: 1.3rem;
		color: #64748b;
		margin-bottom: 0;
	}

	.tpt-ip-summary-row--total strong {
		color: #334155;
	}

	.tpt-welcome-page-hero__title {
		font-size: 3.5rem;
		line-height: 1.1;
		margin-bottom: 20px;
		font-weight: 700;
	}

	.tpt-welcome-page-hero__description {
		font-size: 1.1rem;
		line-height: 1.6;
		margin-bottom: 30px;
		opacity: 0.9;
	}

	.tpt-welcome-page-hero__actions {
		display: flex;
		gap: 15px;
	}

	.tpt-welcome-page-features {
		column-count: 2;
		column-gap: 40px;
		padding: 0 50px;
		margin: 50px 0;
	}

	.tpt-welcome-page-feature {
		margin-bottom: 40px;
		break-inside: avoid;
	}

	.tpt-welcome-page-feature__image-description {
		text-align: center;
		margin-bottom: 20px;
		font-style: italic;
		color: #64748b;
		font-size: 0.9em;
	}

	.tpt-welcome-page-features--templates {
		column-count: 2;
		column-gap: 30px;
	}

	.tpt-welcome-page-feature__inner {
		background: #fff;
		padding: 30px;
		border-radius: 12px;
		border: 1px solid #f1f5f9;
		box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
		transition: transform 0.2s ease, box-shadow 0.2s ease;
	}

	.tpt-welcome-page-feature__inner:hover {
		transform: translateY(-4px);
		box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -5px rgba(0, 0, 0, 0.04);
	}

	.tpt-welcome-page-feature--template .tpt-welcome-page-feature__inner {
		padding: 15px;
	}

	.tpt-welcome-page-feature__title {
		font-size: 1.4rem;
		font-weight: 700;
		margin-bottom: 18px;
		line-height: 1.4;
		color: #0f172a;
	}

	.tpt-welcome-page-feature__description {
		font-size: 1.05em;
		color: #1e293b;
		line-height: 1.5;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.tpt-welcome-page-feature img {
		width: 100%;
		border-radius: 6px;
	}

	.tpt-welcome-page-section-title {
		font-size: 2.5em;
		font-weight: 700;
		line-height: 1.2;
		padding: 0 50px;
		margin-top: 60px;
		color: #0f172a;
		display: flex;
		align-items: center;
	}

	.tpt-welcome-page-section-title span {
		margin-right: 15px;
		background: linear-gradient(135deg, #7b3f6f 0%, #96598a 100%);
		font-size: 1.5rem;
		color: #fff;
		display: inline-block;
		padding: 6px 16px;
		border-radius: 8px;
		box-shadow: 0 4px 6px rgba(150, 89, 138, 0.3);
	}

	.tpt-welcome-page-install-notice {
		background: #dcfce7;
		color: #166534;
		padding: 12px 24px;
		border-radius: 8px;
		border: 1px solid #bbf7d0;
		display: flex;
		align-items: center;
		font-weight: 500;
		font-size: 1.05em;
	}

	.tpt-welcome-page-install-notice .dashicons {
		margin-right: 8px;
		color: #15803d;
	}

	.tpt-welcome-page-side-features {
		padding: 0 50px;
		margin: 40px 0;
		display: flex;
		gap: 24px;
		flex-wrap: wrap;
	}

	.tpt-welcome-page-side-feature {
		padding: 20px 25px;
		background: #fff;
		font-weight: 500;
		font-size: 1.25em;
		color: #334155;
		border: 1px solid #f1f5f9;
		border-radius: 12px;
		box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
		transition: transform 0.2s ease, box-shadow 0.2s ease;
		display: flex;
		align-items: center;
		gap: 12px;
	}

	.tpt-welcome-page-side-feature:hover {
		transform: translateY(-4px);
		box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
		border-color: #e2e8f0;
	}

</style>
<main class="tpt-welcome-page">

	<div class="tpt-welcome-page-install-notice">
		<span class="dashicons dashicons-plugins-checked"></span> <?php esc_html_e( 'Thanks for installing the plugin! Below you will find a quick overview of the main features.',
				'tier-pricing-table' ); ?>
	</div>

	<header class="tpt-welcome-page-hero">

		<div class="tpt-welcome-page-hero__content">
			<div class="tpt-welcome-page-hero__title">
				<div><?php esc_html_e( 'Welcome to', 'tier-pricing-table' ); ?></div>
				<div><b><?php esc_html_e( 'Tiered Pricing Table', 'tier-pricing-table' ); ?></b></div>
			</div>

			<div class="tpt-welcome-page-hero__description">
				<p>
					<?php
						esc_html_e( 'Tiered Pricing Table is a powerful tool that allows you to create quantity-based pricing for your WooCommerce products.',
								'tier-pricing-table' );
					?>
				</p>
				<p>
					<?php
						esc_html_e( 'With intuitive templates, flexible pricing rules, and advanced features, this plugin is a perfect fit for any type of store.',
								'tier-pricing-table' );
					?>
				</p>
			</div>
			<div class="tpt-welcome-page-hero__actions">
				<a href="<?php echo esc_attr( ServiceContainer::getInstance()->getSettings()->getLink() ); ?>"
				   class="tpt-welcome-page-button tpt-welcome-page-button-secondary">
					<?php esc_html_e( 'Settings', 'tier-pricing-table' ); ?>
				</a>

				<a href="<?php echo esc_attr( \TierPricingTable\TierPricingTablePlugin::getDocumentationURL() ); ?>"
				   target="_blank"
				   class="tpt-welcome-page-button tpt-welcome-page-button-primary tpt-welcome-page-button-primary--border">
					<?php esc_html_e( 'Documentation', 'tier-pricing-table' ); ?>
				</a>
			</div>

			<div class="tpt-welcome-page-hero__additional" style="font-size: 1.2em; margin-top: 20px;">
				<?php esc_html_e( 'Questions? We\'re here to help.', 'tier-pricing-table' ); ?>
				<a style="color: #fff"
				   href="<?php echo esc_attr( \TierPricingTable\TierPricingTablePlugin::getContactUsURL() ); ?>"
				   target="_blank"><?php esc_html_e( 'Contact Us', 'tier-pricing-table' ); ?></a>
			</div>
		</div>

		<div class="tpt-welcome-page-hero__image">
			<div class="tpt-browser-mockup">
				<div class="tpt-browser-mockup-header">
					<span class="dot dot-red"></span>
					<span class="dot dot-yellow"></span>
					<span class="dot dot-green"></span>
					<div class="tpt-browser-mockup-address">yoursite.com/product/t-shirt</div>
				</div>
				<div class="tpt-interactive-preview" id="tpt-interactive-preview">
					<div class="tpt-ip-top">
						<div class="tpt-ip-left">
							<svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"
							     stroke-linecap="round"
							     stroke-linejoin="round" style="width: 80%; height: auto; max-height: 200px;">
								<path d="M20.38 3.46L16 2a4 4 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/>
							</svg>
						</div>
						<div class="tpt-ip-top-info">
							<h2 class="tpt-ip-title">T-Shirt</h2>
							<div class="tpt-ip-main-price" id="tpt-ip-main-price">
								<del>$100.00</del>
								<span>$75.00</span></div>
							<p class="tpt-ip-desc"><?php esc_html_e( 'This is a product example.',
										'tier-pricing-table' ); ?></p>
						</div>
					</div>

					<div class="tpt-ip-bottom">
						<div class="tpt-ip-table-container">
							<table class="tpt-ip-table">
								<thead>
								<tr>
									<th><?php esc_html_e( 'Quantity', 'tier-pricing-table' ); ?></th>
									<th><?php esc_html_e( 'Discount', 'tier-pricing-table' ); ?></th>
									<th><?php esc_html_e( 'Price', 'tier-pricing-table' ); ?></th>
								</tr>
								</thead>
								<tbody>
								<tr class="tpt-ip-block" data-qty="1" data-price="100.00">
									<td class="tpt-hero-preview-qty">1 - 9 pieces</td>
									<td class="tpt-hero-preview-discount">-</td>
									<td class="tpt-hero-preview-price"><strong>$100.00</strong></td>
								</tr>
								<tr class="tpt-ip-block" data-qty="10" data-price="90.00">
									<td class="tpt-hero-preview-qty">10 - 19 pieces</td>
									<td class="tpt-hero-preview-discount">10%</td>
									<td class="tpt-hero-preview-price"><strong>$90.00</strong></td>
								</tr>
								<tr class="tpt-ip-block" data-qty="20" data-price="85.00">
									<td class="tpt-hero-preview-qty">20 - 49 pieces <span
												class="tpt-hero-preview-badge tpt-hero-preview-badge--popular"><span
													class="dashicons dashicons-star-filled"></span>Most popular</span>
									</td>
									<td class="tpt-hero-preview-discount">15%</td>
									<td class="tpt-hero-preview-price"><strong>$85.00</strong></td>
								</tr>
								<tr class="tpt-ip-block is-selected" data-qty="50" data-price="80.00">
									<td class="tpt-hero-preview-qty">50+ pieces</td>
									<td class="tpt-hero-preview-discount">20%</td>
									<td class="tpt-hero-preview-price"><strong>$80.00</strong></td>
								</tr>
								</tbody>
							</table>
						</div>

						<div class="tpt-ip-add-to-cart">
							<input type="number" id="tpt-ip-qty" class="tpt-ip-qty-input" value="50" min="1">
							<button class="tpt-ip-btn"><?php esc_html_e( 'Add to cart',
										'tier-pricing-table' ); ?></button>
						</div>

						<div class="tpt-ip-summary">
							<div class="tpt-ip-summary-row">
								<span id="tpt-ip-summary-qty">50x</span>
								<span id="tpt-ip-summary-each">$80.00</span>
							</div>
							<div class="tpt-ip-summary-row tpt-ip-summary-row--total">
								<span>T-Shirt</span>
								<strong id="tpt-ip-summary-total">$4,000.00</strong>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const blocks = document.querySelectorAll('.tpt-ip-block');
				const qtyInput = document.getElementById('tpt-ip-qty');
				const mainPrice = document.getElementById('tpt-ip-main-price');
				const summaryQty = document.getElementById('tpt-ip-summary-qty');
				const summaryEach = document.getElementById('tpt-ip-summary-each');
				const summaryTotal = document.getElementById('tpt-ip-summary-total');

				function getPriceForQty(qty) {
					if (qty >= 50) return 80.00;
					if (qty >= 20) return 85.00;
					if (qty >= 10) return 90.00;
					return 100.00;
				}

				function updateUI(qty) {
					qty = parseInt(qty) || 1;
					if (qty < 1) qty = 1;
					if (qtyInput.value != qty) qtyInput.value = qty;

					const price = getPriceForQty(qty);

					blocks.forEach(b => b.classList.remove('is-selected'));

					let selectedIndex = 0;
					if (qty >= 50) selectedIndex = 3;
					else if (qty >= 20) selectedIndex = 2;
					else if (qty >= 10) selectedIndex = 1;

					if (blocks[selectedIndex]) blocks[selectedIndex].classList.add('is-selected');

					if (price < 100) {
						const originalPrice = 100.00;
						const savedTotal = (originalPrice - price) * qty;
						const savedPercent = Math.round((originalPrice - price) / originalPrice * 100);
						mainPrice.innerHTML = `<del>$100.00</del> <span>$${price.toFixed(2)}</span> <span class="tpt-ip-you-save"><?php esc_html_e( 'You save',
								'tier-pricing-table' ); ?> $${savedTotal.toLocaleString('en-US', {
							minimumFractionDigits: 2,
							maximumFractionDigits: 2
						})} (${savedPercent}%)</span>`;
					} else {
						mainPrice.innerHTML = `<span>$100.00</span>`;
					}

					summaryQty.innerText = `${qty}x`;
					summaryEach.innerText = `$${price.toFixed(2)}`;
					summaryTotal.innerText = `$${(price * qty).toLocaleString('en-US', {
						minimumFractionDigits: 2,
						maximumFractionDigits: 2
					})}`;
				}

				blocks.forEach(block => {
					block.addEventListener('click', function () {
						const qty = parseInt(this.getAttribute('data-qty'));
						updateUI(qty);
					});
				});

				if (qtyInput) {
					qtyInput.addEventListener('input', function () {
						updateUI(this.value);
					});
				}

				updateUI(100);
			});
		</script>
	</header>

	<div class="tpt-welcome-page-section-title">
		<span>#1</span>
		<?php esc_html_e( 'Easy Setup', 'tier-pricing-table' ); ?>
	</div>

	<section class="tpt-welcome-page-features">

		<div class="tpt-welcome-page-feature">

			<div class="tpt-welcome-page-feature__title">
				<?php esc_html_e( 'Add tiered pricing to products', 'tier-pricing-table' ); ?>:
			</div>

			<div class="tpt-welcome-page-feature__inner">
				<div class="tpt-welcome-page-feature__image">
					<img src="<?php echo esc_attr( $fileManager->locateAsset( 'admin/welcome-page/product-level-rules.png' ) ); ?>">
					<div class="tpt-welcome-page-feature__image-description">Product edit page</div>
				</div>
				<div class="tpt-welcome-page-feature__description">
					<span class="tpt-checkmark"> <?php esc_html_e( 'Add unlimited quantity-based prices.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Fixed prices or percentage discounts.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Works great with variable products.',
								'tier-pricing-table' ); ?></span>
				</div>
			</div>

		</div>

		<div class="tpt-welcome-page-feature">

			<div class="tpt-welcome-page-feature__title">
				<?php esc_html_e( 'Prices automatically displayed on the product page:', 'tier-pricing-table' ); ?>
			</div>

			<div class="tpt-welcome-page-feature__inner">
				<div class="tpt-welcome-page-feature__image">
					<img src="<?php echo esc_attr( $fileManager->locateAsset( 'admin/welcome-page/product-page.png' ) ); ?>">
					<div class="tpt-welcome-page-feature__image-description">Product page</div>
				</div>

			</div>
		</div>

	</section>

	<div class="tpt-welcome-page-section-title">
		<span>#2</span>
		<?php esc_html_e( 'Various Pricing Templates', 'tier-pricing-table' ); ?>
	</div>

	<?php
		$templates = array(
				array(
						'title'    => __( 'Pricing Table', 'tier-pricing-table' ),
						'image'    => 'table.png',
						'features' => array(
								__( 'Ability to add custom columns.', 'tier-pricing-table' ),
						),
				),

				array(
						'title'    => __( 'Pricing Blocks #3', 'tier-pricing-table' ),
						'image'    => 'blocks-1.png',
						'features' => array(),
				),
				array(
						'title'    => __( 'Pricing Blocks', 'tier-pricing-table' ),
						'image'    => 'blocks-2.png',
						'features' => array(),
				),
				array(
						'title'    => __( 'Pricing Blocks #2', 'tier-pricing-table' ),
						'image'    => 'blocks-3.png',
						'features' => array(),
				),
				array(
						'title'    => __( 'Pricing Options', 'tier-pricing-table' ),
						'image'    => 'options.png',
						'features' => array(),
				),
				array(
						'title'    => __( 'Pricing Options #2', 'tier-pricing-table' ),
						'image'    => 'options-2.png',
						'features' => array(),
				),

				array(
						'title'    => __( 'Horizontal table', 'tier-pricing-table' ),
						'image'    => 'horizontal-table.png',
						'features' => array(),
				),
				array(
						'title'    => __( 'Plain text', 'tier-pricing-table' ),
						'image'    => 'plain-text.png',
						'features' => array(),
				),
				array(
						'title'    => __( 'Dropdown', 'tier-pricing-table' ),
						'image'    => 'dropdown.png',
						'features' => array(),
				),
		);
	?>
	<section class="tpt-welcome-page-features tpt-welcome-page-features--templates">

		<?php foreach ( $templates as $template ) : ?>

			<div class="tpt-welcome-page-feature tpt-welcome-page-feature--template">
				<div class="tpt-welcome-page-feature__title">
					<?php echo esc_html( $template['title'] ); ?>
				</div>

				<div class="tpt-welcome-page-feature__inner">
					<div class="tpt-welcome-page-feature__image">
						<img src="<?php echo esc_attr( $fileManager->locateAsset( 'admin/welcome-page/templates/' . $template['image'] ) ); ?>">
					</div>
					<div class="tpt-welcome-page-feature__description">

						<?php foreach ( $template['features'] as $feature ) : ?>
							<span class="tpt-checkmark"><?php echo esc_html( $feature ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>

			</div>

		<?php endforeach; ?>
	</section>

	<div class="tpt-welcome-page-section-title">
		<span>#3</span>
		<?php esc_html_e( 'Flexible Pricing', 'tier-pricing-table' ); ?>
	</div>

	<section class="tpt-welcome-page-features tpt-welcome-page-features--flexible-pricing">
		<div class="tpt-welcome-page-feature">

			<div class="tpt-welcome-page-feature__title">
				<?php esc_html_e( 'Apply custom prices to any user role', 'tier-pricing-table' ); ?>:
			</div>

			<div class="tpt-welcome-page-feature__inner">
				<div class="tpt-welcome-page-feature__image">
					<img src="<?php echo esc_attr( $fileManager->locateAsset( 'admin/welcome-page/role-based.png' ) ); ?>">
				</div>
				<div class="tpt-welcome-page-feature__description">
					<span class="tpt-checkmark"> <?php esc_html_e( 'Add unlimited role-based pricing.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Control regular & sale price or provide a percentage discount.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Control minimum, maximum and quantity step.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Works great with variable products.',
								'tier-pricing-table' ); ?></span>
				</div>
			</div>

		</div>

		<div class="tpt-welcome-page-feature">

			<div class="tpt-welcome-page-feature__title">
				<?php
					esc_html_e( 'Apply custom prices in bulk for selected categories and users:',
							'tier-pricing-table' );
				?>
			</div>

			<div class="tpt-welcome-page-feature__inner">
				<div class="tpt-welcome-page-feature__image">
					<img src="<?php echo esc_attr( $fileManager->locateAsset( 'admin/welcome-page/global-rules	.png' ) ); ?>">
				</div>
				<div class="tpt-welcome-page-feature__description">
					<span class="tpt-checkmark"> <?php esc_html_e( 'Control regular prices, tiered pricing and quantity limits in one place.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Apply tiered pricing across multiple products.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Select products or product categories that the rule applies to.',
								'tier-pricing-table' ); ?></span>
					<span class="tpt-checkmark"> <?php esc_html_e( 'Select users or user roles that the rule applies to.',
								'tier-pricing-table' ); ?></span>
				</div>
			</div>
		</div>
	</section>

	<div class="tpt-welcome-page-section-title">
		<span>#4</span>
		<?php esc_html_e( 'Advanced Features', 'tier-pricing-table' ); ?>
	</div>

	<section class="tpt-welcome-page-features tpt-welcome-page-features--plugin-features">
		<?php
			$mainFeatures = array(
					array(
							'title'    => __( 'Request a Quote', 'tier-pricing-table' ),
							'image'    => 'request-a-quote.png',
							'features' => array(
									__( 'Allow customers to request custom quotes via a sleek customizable popup.',
											'tier-pricing-table' ),
							),
					),
					array(
							'title'    => __( 'Manage user roles', 'tier-pricing-table' ),
							'image'    => 'user-roles.png',
							'features' => array(
									__( 'Create and update user roles', 'tier-pricing-table' ),
							),
					),
					array(
							'title'    => __( 'Labels', 'tier-pricing-table' ),
							'image'    => 'tiered-pricing-labels.png',
							'features' => array(
									__( 'Create customizable labels and attach them to a pricing tier',
											'tier-pricing-table' ),
							),
					),
					array(
							'title'    => __( 'Cart', 'tier-pricing-table' ),
							'image'    => 'cart.png',
							'features' => array(
									__( 'Cart upsells to motivate customers to purchase more.', 'tier-pricing-table' ),
									__( 'Customize cart upsells template.', 'tier-pricing-table' ),
									__( 'Tiered price in the cart appears as a discount.', 'tier-pricing-table' ),
							),
					),
					array(
							'title'    => __( 'Catalog prices', 'tier-pricing-table' ),
							'image'    => 'catalog.png',
							'features' => array(
									__( 'Show the lowest price.', 'tier-pricing-table' ),
									__( 'Customize the lowest price prefix: “from $10.00”, “as low as $10.00” or whatever you want.',
											'tier-pricing-table' ),
									__( 'Show the price range based on tiered pricing.', 'tier-pricing-table' ),
							),
					),
					array(
							'title'    => __( 'Product catalog (Category page)', 'tier-pricing-table' ),
							'image'    => 'catalog-render.png',
							'features' => array(
									__( 'Customize template (can be different from product page).',
											'tier-pricing-table' ),
									__( 'Show quantity field.', 'tier-pricing-table' ),
							),
					),
			);
		?>

		<?php foreach ( $mainFeatures as $feature ) : ?>
			<div class="tpt-welcome-page-feature">

				<div class="tpt-welcome-page-feature__title">
					<?php echo esc_html( $feature['title'] ); ?>
				</div>

				<div class="tpt-welcome-page-feature__inner">

					<div class="tpt-welcome-page-feature__image">
						<img src="<?php echo esc_attr( $fileManager->locateAsset( 'admin/welcome-page/' . $feature['image'] ) ); ?>">
						<div class="tpt-welcome-page-feature__image-description">Product catalog</div>
					</div>

					<div class="tpt-welcome-page-feature__description">
						<?php foreach ( $feature['features'] as $featureItem ) : ?>
							<span class="tpt-checkmark"><?php echo esc_html( $featureItem ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>

			</div>
		<?php endforeach; ?>
	</section>

	<div class="tpt-welcome-page-section-title">
		<span>#5</span>
		<?php esc_html_e( 'Other Features That Make The Plugin Unique', 'tier-pricing-table' ); ?>
	</div>

	<?php
		$otherFeatures = array(
				array(
						'title' => __( 'Tier Labels & Badges', 'tier-pricing-table' ),
						'icon'  => '🏷️',
				),
				array(
						'title' => __( 'Data Cleanup & Role Management Tools', 'tier-pricing-table' ),
						'icon'  => '🛠️',
				),
				array(
						'title' => __( 'Import \ Export', 'tier-pricing-table' ),
						'icon'  => '🔁',
				),
				array(
						'title' => __( 'REST API', 'tier-pricing-table' ),
						'icon'  => '⚙️',
				),
				array(
						'title' => __( 'Admin-made orders supported', 'tier-pricing-table' ),
						'icon'  => '✅',
				),
				array(
						'title' => __( 'Built-in cache', 'tier-pricing-table' ),
						'icon'  => '🚀',
				),
				array(
						'title' => __( 'Coupons management', 'tier-pricing-table' ),
						'icon'  => '🎫',
				),
				array(
						'title' => __( 'Shortcode \ Gutenberg \ Elementor', 'tier-pricing-table' ),
						'icon'  => '🧱',
				),
				array(
						'title' => __( 'Hide prices for logged-out users', 'tier-pricing-table' ),
						'icon'  => '🔑',
				),
				array(
						'title' => __( 'Works with any theme', 'tier-pricing-table' ),
						'icon'  => '✨',
				),
				array(
						'title' => __( 'Debug mode', 'tier-pricing-table' ),
						'icon'  => '⚙️',
				),
		)
	?>

	<section class="tpt-welcome-page-side-features">
		<?php foreach ( $otherFeatures as $feature ) : ?>
			<div class="tpt-welcome-page-side-feature">
				<?php echo esc_html( $feature['icon'] ); ?><?php echo esc_html( ' ' . $feature['title'] ); ?>
			</div>
		<?php endforeach; ?>
	</section>


	<div class="tpt-welcome-page-section-title">
		<span>#6</span>
		<?php esc_html_e( 'Integrations with 3rd party plugins', 'tier-pricing-table' ); ?>
	</div>

	<section class="tpt-welcome-page-integrations">
		<?php
			$integrations = array(
					array(
							'title' => 'WP All Import',
							'image' => 'wpallimport-icon.png',
					),
					array(
							'title' => 'WPML',
							'image' => 'wpml-multicurrency-icon.png',
					),
					array(
							'title' => 'Elementor',
							'image' => 'elementor-icon.svg',
					),
					array(
							'title' => 'WooCommerce Product Add-ons',
							'image' => 'woocommerce-develop.jpeg',
					),
					array(
							'title' => 'Yith Request a Quote',
							'image' => 'yith-raq-icon.jpeg',
					),
					array(
							'title' => 'Addify Request a Quote',
							'image' => 'addify-raq-icon.png',
					),
					array(
							'title' => 'Aelia Multicurrency',
							'image' => 'aelia-icon.svg',
					),
					array(
							'title' => 'WooCommerce Bundles',
							'image' => 'woocommerce-develop.jpeg',
					),
					array(
							'title' => 'Fox Multicurrency',
							'image' => 'fox-icon.png',
					),
					array(
							'title' => 'Mix & Match Products',
							'image' => 'mix-match-icon.png',
					),
					array(
							'title' => 'Currency Switcher by "WP Experts"',
							'image' => 'wccs-icon.png',
					),
					array(
							'title' => 'WooCommerce Deposits',
							'image' => 'woocommerce-develop.jpeg',
					),
					array(
							'title' => 'WPML Multicurrency',
							'image' => 'wpml-multicurrency-icon.png',
					),
					array(
							'title' => 'WooCommerce Custom Product Addons',
							'image' => 'wcpa-icon.png',
					),
			);
		?>
		<style>
			.tpt-welcome-page-integrations {
				padding: 40px 50px;
				display: flex;
				flex-wrap: wrap;
				gap: 30px;
				align-items: center;
				justify-content: flex-start;
			}

			.tpt-welcome-page-integration {
				width: 140px;
				text-align: center;
				transition: transform 0.2s ease;
			}

			.tpt-welcome-page-integration:hover {
				transform: translateY(-6px) scale(1.05);
			}

			.tpt-welcome-page-integrations__image img {
				width: 65%;
				border-radius: 16px;
				box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
			}

			.tpt-welcome-page-integrations__name {
				text-align: center;
				margin-top: 12px;
				font-size: 0.95em;
				color: #475569;
			}

		</style>
		<?php foreach ( $integrations as $integration ) : ?>
			<div class="tpt-welcome-page-integration">
				<div class="tpt-welcome-page-integrations__image">
					<img src="<?php echo esc_attr( $fileManager->locateAsset( 'admin/integrations/' . $integration['image'] ) ); ?>">
				</div>
				<div class="tpt-welcome-page-integrations__name">
					<b><?php echo esc_html( $integration['title'] ); ?></b>
				</div>
			</div>
		<?php endforeach; ?>
	</section>

	<style>
		.tpt-welcome-page-contact-us {
			background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
			padding: 80px 40px;
			text-align: center;
			border-radius: 16px;
			margin: 20px 50px 60px;
			box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
		}

		.tpt-welcome-page-contact-us__title {
			font-size: 2.5em;
			font-weight: 700;
			color: #fff;
			line-height: 1.2;
			margin-bottom: 30px;
		}

		.tpt-welcome-page-feature__image img {
			cursor: zoom-in;
		}

		.tpt-lightbox {
			position: fixed;
			top: 0;
			left: 0;
			width: 100vw;
			height: 100vh;
			background: rgba(15, 23, 42, 0.85);
			display: flex;
			align-items: center;
			justify-content: center;
			z-index: 999999;
			opacity: 0;
			pointer-events: none;
			transition: opacity 0.25s ease;
			backdrop-filter: blur(4px);
		}

		.tpt-lightbox.is-active {
			opacity: 1;
			pointer-events: auto;
		}

		.tpt-lightbox img {
			max-width: 90%;
			max-height: 90vh;
			border-radius: 8px;
			box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
			transform: scale(0.95);
			transition: transform 0.25s ease;
		}

		.tpt-lightbox.is-active img {
			transform: scale(1);
		}

		.tpt-lightbox-close {
			position: absolute;
			top: 20px;
			right: 30px;
			color: #fff;
			font-size: 40px;
			cursor: pointer;
			opacity: 0.7;
			transition: opacity 0.2s;
			line-height: 1;
		}

		.tpt-lightbox-close:hover {
			opacity: 1;
		}
	</style>

	<section class="tpt-welcome-page-contact-us">
		<div class="tpt-welcome-page-contact-us__title"><?php esc_html_e( 'Have a question?',
					'tier-pricing-table' ); ?></div>
		<div class="tpt-welcome-page-contact-us__button">
			<a href="<?php echo esc_attr( \TierPricingTable\TierPricingTablePlugin::getContactUsURL() ); ?>"
			   target="_blank"
			   class="tpt-welcome-page-button tpt-welcome-page-button-primary">Contact Us</a>
		</div>
	</section>

	<div id="tpt-lightbox" class="tpt-lightbox">
		<span class="tpt-lightbox-close">&times;</span>
		<img id="tpt-lightbox-img" src="">
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			const lightbox = document.getElementById('tpt-lightbox');
			const lightboxImg = document.getElementById('tpt-lightbox-img');

			const images = document.querySelectorAll('.tpt-welcome-page-feature__image img');

			images.forEach(img => {
				img.addEventListener('click', function () {
					lightboxImg.src = this.src;
					lightbox.classList.add('is-active');
				});
			});

			lightbox.addEventListener('click', function (e) {
				if (e.target !== lightboxImg) {
					lightbox.classList.remove('is-active');
				}
			});
		});
	</script>

</main>

<style>
	@media screen and (max-width: 900px) {

		.tpt-welcome-page-hero__content {
			width: 100%;
		}

		.tpt-welcome-page-hero__image {
			display: none
		}

		.tpt-welcome-page-hero__additional {
			font-size: 1em;
		}

		.tpt-welcome-page-features {
			column-count: 1;
		}

		.tpt-welcome-page-features--templates {
			column-count: 2;
		}

		.tpt-welcome-page-feature__image-description {
			font-size: 0.8em;
		}

		.tpt-welcome-page-feature__title {
			font-size: 1.4em;
		}

		.tpt-welcome-page-feature__description {
			font-size: 1em;
		}

		.tpt-welcome-page-section-title {
			font-size: 2em;
		}

		.tpt-welcome-page-side-features {
			gap: 10px;
		}

		.tpt-welcome-page-side-feature {
			font-size: 1.2em;
			padding: 10px;
		}

		.tpt-welcome-page-integrations {
			padding: 40px 20px;
		}

		.tpt-welcome-page-integration {
			width: 120px;
		}

		.tpt-welcome-page-contact-us {
			padding: 40px 20px;
		}

		.tpt-welcome-page-contact-us__title {
			font-size: 2em;
		}
	}
</style>