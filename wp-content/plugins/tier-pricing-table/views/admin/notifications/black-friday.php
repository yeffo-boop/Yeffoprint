<?php defined( 'WPINC' ) || die;

	use TierPricingTable\Admin\Notifications\Notifications\TwoMonthsUsingDiscount;
	use TierPricingTable\Core\ServiceContainer;
	use TierPricingTable\TierPricingTablePlugin;

	$fileManager = ServiceContainer::getInstance()->getFileManager();

	/**
	 * Available variables
	 *
	 * @var TwoMonthsUsingDiscount $notification
	 */

	// Updated, more marketing-friendly feature names
	$premiumFeatures = array(
			'Dynamic, Role-Based Pricing Rules',
			'Percentage-Based Bulk Discounts',
			'Smart In-Cart Upsells',
			'Customizable Pricing Table Columns',
			'Interactive, Clickable Tables',
			'Set Min/Max Order Quantities',
			'Instant Price Totals',
			'Hide Prices for Guest Users',
			'Show "As Low As" Price in Lists',
	);

	$upgradeUrl = add_query_arg( array(
			'coupon' => 'BF25OFF',
	), tpt_fs_activation_url() );

?>

<style>
	/* Scope everything to avoid conflicts */
	.tpt-bf-light-banner {
		box-sizing: border-box;
		/* Updated background: White to very light primary color gradient */
		background: linear-gradient(135deg, #ffffff 0%, #fdf2f8 100%);
		border: 1px solid #dcdcde;
		border-left: 5px solid #96598a; /* Primary color */
		border-radius: 8px;
		color: #3c434a;
		margin: 20px 0 15px;
		padding: 0;
		position: relative;
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
		overflow: hidden;
		box-shadow: 0 4px 10px rgba(0,0,0,0.05);
	}

	/* Close button */
	.tpt-bf-light-banner__close {
		position: absolute;
		top: 10px;
		right: 15px;
		color: #a7aaad;
		text-decoration: none;
		font-size: 20px;
		line-height: 1;
		transition: color 0.2s;
		z-index: 10;
	}
	.tpt-bf-light-banner__close:hover {
		color: #3c434a;
	}

	.tpt-bf-light-banner__inner {
		display: flex;
		flex-wrap: wrap;
		padding: 20px;
		gap: 30px;
	}

	/* Left Side: Call to Action */
	.tpt-bf-light-banner__cta-column {
		flex: 1;
		min-width: 300px;
		display: flex;
		flex-direction: column;
		justify-content: center;
	}

	/* Plugin Name Badge Style */
	.tpt-bf-light-banner__plugin-badge {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		background-color: #fdf2f8; /* Very light primary bg */
		color: #96598a;
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		padding: 5px 12px;
		border-radius: 20px;
		margin-bottom: 10px;
		border: 1px solid rgba(150, 89, 138, 0.2);
		box-shadow: 0 2px 4px rgba(150, 89, 138, 0.05);
	}

	.tpt-bf-light-banner__plugin-badge::before {
		content: '';
		display: block;
		width: 6px;
		height: 6px;
		background-color: #96598a;
		border-radius: 50%;
	}

	.tpt-bf-light-banner__headline {
		font-size: 1.4em;
		font-weight: 600;
		margin-bottom: 15px;
		line-height: 1.3;
	}

	.tpt-bf-light-banner__highlight {
		color: #96598a; /* Primary color */
		font-weight: 700;
		display: inline-block;
		animation: tpt-pulse 2s infinite;
	}

	.tpt-bf-light-banner__subtext {
		font-size: 15px;
		margin-top: 0;
		margin-bottom: 25px;
		color: #646970;
		line-height: 1.5;
	}

	.tpt-bf-light-banner__coupon-box {
		background-color: #fff;
		border: 2px dashed #96598a;
		padding: 12px 24px;
		border-radius: 8px;
		display: inline-flex;
		align-items: center;
		gap: 15px;
		margin-bottom: 25px;
		box-shadow: 0 4px 10px rgba(150, 89, 138, 0.1);
	}

	.tpt-bf-light-banner__code-label {
		font-weight: 600;
		color: #646970;
		font-size: 14px;
	}

	.tpt-bf-light-banner__code {
		font-family: monospace;
		font-size: 1.4em;
		font-weight: 700;
		color: #96598a;
		letter-spacing: 1px;
		background-color: #fdf2f8;
		padding: 6px 12px;
		border-radius: 6px;
	}

	.tpt-bf-light-banner__actions {
		display: flex;
		align-items: center;
		gap: 20px;
	}

	/* Animated Button */
	.tpt-bf-light-button {
		background: linear-gradient(to right, #96598a, #b06ba3);
		color: white !important;
		text-decoration: none;
		padding: 12px 30px;
		border-radius: 50px;
		font-weight: 600;
		font-size: 15px;
		border: none;
		cursor: pointer;
		position: relative;
		overflow: hidden;
		transition: transform 0.2s, box-shadow 0.2s;
		display: inline-flex;
		align-items: center;
		gap: 8px;
		box-shadow: 0 4px 6px rgba(150, 89, 138, 0.2);
	}

	.tpt-bf-light-button:hover {
		transform: translateY(-2px);
		box-shadow: 0 6px 12px rgba(150, 89, 138, 0.3);
		color: white !important;
	}

	/* Button Shimmer Animation */
	.tpt-bf-light-button::after {
		content: '';
		position: absolute;
		top: 0;
		left: -100%;
		width: 100%;
		height: 100%;
		background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
		animation: tpt-shimmer 3s infinite;
	}

	.tpt-bf-light-banner__dismiss {
		font-size: 13px;
		color: #646970;
		text-decoration: underline;
		cursor: pointer;
	}
	.tpt-bf-light-banner__dismiss:hover {
		color: #3c434a;
	}

	/* Right Side: Features List */
	.tpt-bf-light-banner__features-column {
		flex: 1.2;
		min-width: 320px;
		border-left: 1px solid rgba(0,0,0,0.05);
		padding-left: 30px;
		display: flex;
		flex-direction: column;
		justify-content: center;
		background: rgba(255,255,255,0.4);
	}

	.tpt-bf-light-banner__features-title {
		font-weight: 700;
		font-size: 14px;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		margin-bottom: 15px;
		color: #96598a;
		opacity: 0.9;
	}

	.tpt-bf-light-banner__features-list {
		list-style: none;
		padding: 0;
		margin: 0;
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 10px;
	}

	/* Modern Card Design for Features */
	.tpt-bf-light-feature {
		font-size: 13px;
		font-weight: 500;
		display: flex;
		align-items: center;
		gap: 10px;
		color: #3c434a;
		background: #fff;
		padding: 10px 12px;
		border-radius: 8px;
		border: 1px solid rgba(0,0,0,0.05);
		box-shadow: 0 2px 4px rgba(0,0,0,0.02);
		transition: all 0.2s ease;
	}

	.tpt-bf-light-feature:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 12px rgba(150, 89, 138, 0.15);
		border-color: rgba(150, 89, 138, 0.2);
		color: #96598a;
	}

	.tpt-bf-light-feature i {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 20px;
		height: 20px;
		background-color: #eafaf1; /* Light green bg */
		color: #2ed573; /* Green check */
		border-radius: 50%;
		font-style: normal;
		font-size: 10px;
		flex-shrink: 0;
	}

	/* Animations */
	@keyframes tpt-pulse {
		0% { transform: scale(1); }
		50% { transform: scale(1.05); }
		100% { transform: scale(1); }
	}

	@keyframes tpt-shimmer {
		0% { left: -100%; }
		20% { left: 100%; }
		100% { left: 100%; }
	}

	/* Mobile Responsiveness */
	@media screen and (max-width: 960px) {
		.tpt-bf-light-banner__inner {
			flex-direction: column;
			gap: 25px;
			padding: 25px;
		}
		.tpt-bf-light-banner__features-column {
			border-left: none;
			padding-left: 0;
			border-top: 1px solid #dcdcde;
			padding-top: 25px;
		}
	}

	@media screen and (max-width: 600px) {
		.tpt-bf-light-banner__coupon-box {
			width: 100%;
			justify-content: center;
			box-sizing: border-box;
		}
		.tpt-bf-light-banner__actions {
			flex-direction: column;
			align-items: stretch;
			gap: 15px;
		}
		.tpt-bf-light-button {
			justify-content: center;
			width: 100%;
			box-sizing: border-box;
		}
		.tpt-bf-light-banner__dismiss {
			text-align: center;
			display: block;
		}
		.tpt-bf-light-banner__features-list {
			grid-template-columns: 1fr;
		}
	}
</style>

<div class="tpt-bf-light-banner notice">
	<a href="<?php echo esc_attr( $notification->getCloseURL() ); ?>" class="tpt-bf-light-banner__close" title="Dismiss">&times;</a>

	<div class="tpt-bf-light-banner__inner">

		<!-- Left Column: CTA -->
		<div class="tpt-bf-light-banner__cta-column">
			<div class="tpt-bf-light-banner__headline">
                <span class="tpt-bf-light-banner__plugin-badge">
                    Tiered Pricing Table for WooCommerce
                </span>
				<br>
				🎉 Boost Your Sales this Black Friday!
				<br>
				<span class="tpt-bf-light-banner__highlight">Save 25% on Premium</span>
			</div>

			<p class="tpt-bf-light-banner__subtext">
				Unlock powerful features to maximize revenue and offer dynamic pricing to your customers.
			</p>

			<div>
				<div class="tpt-bf-light-banner__coupon-box">
					<span class="tpt-bf-light-banner__code-label">Use Code:</span>
					<span class="tpt-bf-light-banner__code">BF25OFF</span>
				</div>
			</div>

			<div class="tpt-bf-light-banner__actions">
				<a href="<?php echo esc_attr( $upgradeUrl ); ?>" class="tpt-bf-light-button">
					Get the Offer & Grow 🚀
				</a>
				<a href="<?php echo esc_attr( $notification->getCloseURL() ); ?>" class="tpt-bf-light-banner__dismiss">
					No thanks, I'll miss out on this one
				</a>
			</div>
		</div>

		<!-- Right Column: Features -->
		<div class="tpt-bf-light-banner__features-column">
			<div class="tpt-bf-light-banner__features-title">What's Included in Premium:</div>
			<ul class="tpt-bf-light-banner__features-list">
				<?php foreach ( $premiumFeatures as $feature ) : ?>
					<li class="tpt-bf-light-feature">
						<i>✔</i> <?php echo esc_html( $feature ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div style="margin-top: 15px; font-size: 13px; color: #646970;">
				Have questions? <a href="<?php echo esc_attr( TierPricingTablePlugin::getContactUsURL() ); ?>" target="_blank" style="color: #96598a; text-decoration: underline;">Contact our team</a>.
			</div>
		</div>

	</div>
</div>