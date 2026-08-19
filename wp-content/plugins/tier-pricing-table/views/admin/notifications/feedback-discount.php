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

	// Kept the same feature list, just formatted for the new layout
	$premiumFeatures = array(
			'Dynamic, Role-Based Pricing',
			'Percentage-Based Bulk Discounts',
			'Smart In-Cart Upsells',
			'Customizable Table Columns',
			'Interactive, Clickable Tables',
			'Min/Max Order Quantities',
			'Instant Price Totals',
			'Hide Prices for Guests',
			'Show "As Low As" Prices',
	);

	$upgradeUrl = tpt_fs_activation_url();
	$feedbackUrl = 'https://forms.gle/yWTt3aWuvZVQhbRZ7';

?>

<style>
	/* Scope everything to avoid conflicts - using 'tpt-feedback-banner' prefix */
	.tpt-feedback-banner {
		box-sizing: border-box;
		/* Modern light gradient background */
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
	.tpt-feedback-banner__close {
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
	.tpt-feedback-banner__close:hover {
		color: #3c434a;
	}

	.tpt-feedback-banner__inner {
		display: flex;
		flex-wrap: wrap;
		padding: 25px;
		gap: 30px;
	}

	/* Left Side: Call to Action */
	.tpt-feedback-banner__cta-column {
		flex: 1;
		min-width: 300px;
		display: flex;
		flex-direction: column;
		justify-content: center;
	}

	/* Plugin Name Badge */
	.tpt-feedback-banner__plugin-badge {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		background-color: #fdf2f8;
		color: #96598a;
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		padding: 5px 12px;
		border-radius: 20px;
		margin-bottom: 12px;
		border: 1px solid rgba(150, 89, 138, 0.2);
		box-shadow: 0 2px 4px rgba(150, 89, 138, 0.05);
		align-self: flex-start;
	}

	.tpt-feedback-banner__plugin-badge::before {
		content: '';
		display: block;
		width: 6px;
		height: 6px;
		background-color: #96598a;
		border-radius: 50%;
	}

	.tpt-feedback-banner__headline {
		font-size: 1.5em;
		font-weight: 600;
		margin-bottom: 15px;
		line-height: 1.3;
		color: #2c3338;
	}

	.tpt-feedback-banner__highlight {
		color: #96598a;
		font-weight: 700;
		position: relative;
		display: inline-block;
	}

	/* Underline effect for highlight */
	.tpt-feedback-banner__highlight::after {
		content: '';
		position: absolute;
		bottom: 2px;
		left: 0;
		width: 100%;
		height: 8px;
		background: rgba(150, 89, 138, 0.15);
		z-index: -1;
		transform: rotate(-1deg);
	}

	.tpt-feedback-banner__subtext {
		font-size: 15px;
		margin-top: 0;
		margin-bottom: 25px;
		color: #646970;
		line-height: 1.6;
	}

	.tpt-feedback-banner__actions {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 15px;
	}

	/* Primary Button (Gradient) */
	.tpt-feedback-banner__btn-primary {
		background: linear-gradient(to right, #96598a, #b06ba3);
		color: white !important;
		text-decoration: none;
		padding: 12px 24px;
		border-radius: 50px;
		font-weight: 600;
		font-size: 14px;
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

	.tpt-feedback-banner__btn-primary:hover {
		transform: translateY(-2px);
		box-shadow: 0 6px 12px rgba(150, 89, 138, 0.3);
		color: white !important;
	}

	/* Shimmer Effect */
	.tpt-feedback-banner__btn-primary::after {
		content: '';
		position: absolute;
		top: 0;
		left: -100%;
		width: 100%;
		height: 100%;
		background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
		animation: tpt-shimmer 3s infinite;
	}

	/* Secondary Button (Outline) */
	.tpt-feedback-banner__btn-secondary {
		background: transparent;
		color: #96598a !important;
		text-decoration: none;
		padding: 11px 20px;
		border-radius: 50px;
		font-weight: 600;
		font-size: 14px;
		border: 1px solid #96598a;
		cursor: pointer;
		transition: all 0.2s;
		display: inline-flex;
		align-items: center;
		gap: 6px;
	}

	.tpt-feedback-banner__btn-secondary:hover {
		background: rgba(150, 89, 138, 0.05);
		color: #7d4a73 !important;
	}

	.tpt-feedback-banner__dismiss {
		font-size: 13px;
		color: #8c8f94;
		text-decoration: underline;
		cursor: pointer;
		margin-left: 5px;
	}
	.tpt-feedback-banner__dismiss:hover {
		color: #3c434a;
	}

	/* Right Side: Features List */
	.tpt-feedback-banner__features-column {
		flex: 1.2;
		min-width: 320px;
		border-left: 1px solid rgba(0,0,0,0.05);
		padding-left: 30px;
		display: flex;
		flex-direction: column;
		justify-content: center;
		background: rgba(255,255,255,0.4);
		border-radius: 0 8px 8px 0;
	}

	.tpt-feedback-banner__features-title {
		font-weight: 700;
		font-size: 13px;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		margin-bottom: 15px;
		color: #96598a;
		opacity: 0.9;
	}

	.tpt-feedback-banner__features-list {
		list-style: none;
		padding: 0;
		margin: 0;
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 10px;
	}

	.tpt-feedback-banner__feature {
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

	.tpt-feedback-banner__feature:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 12px rgba(150, 89, 138, 0.15);
		border-color: rgba(150, 89, 138, 0.2);
		color: #96598a;
	}

	.tpt-feedback-banner__feature i {
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

	@keyframes tpt-shimmer {
		0% { left: -100%; }
		20% { left: 100%; }
		100% { left: 100%; }
	}

	/* Mobile Responsiveness */
	@media screen and (max-width: 960px) {
		.tpt-feedback-banner__inner {
			flex-direction: column;
			gap: 25px;
			padding: 20px;
		}
		.tpt-feedback-banner__features-column {
			border-left: none;
			padding-left: 0;
			border-top: 1px solid #dcdcde;
			padding-top: 25px;
			background: transparent;
		}
	}

	@media screen and (max-width: 600px) {
		.tpt-feedback-banner__actions {
			flex-direction: column;
			align-items: stretch;
		}
		.tpt-feedback-banner__btn-primary,
		.tpt-feedback-banner__btn-secondary {
			justify-content: center;
			width: 100%;
			box-sizing: border-box;
		}
		.tpt-feedback-banner__dismiss {
			text-align: center;
			display: block;
			margin-left: 0;
			margin-top: 10px;
		}
	}
</style>

<div class="tpt-feedback-banner notice">
	<a href="<?php echo esc_attr( $notification->getCloseURL() ); ?>" class="tpt-feedback-banner__close" title="Dismiss">&times;</a>

	<div class="tpt-feedback-banner__inner">

		<!-- Left Column: CTA -->
		<div class="tpt-feedback-banner__cta-column">

            <span class="tpt-feedback-banner__plugin-badge">
                Tiered Pricing Table for WooCommerce
            </span>

			<div class="tpt-feedback-banner__headline">
				<span>🎁</span> We value your feedback!<br>
				Help us improve and <span class="tpt-feedback-banner__highlight">Save 20%</span>
			</div>

			<div class="tpt-feedback-banner__subtext">
				<p style="margin: 0 0 10px;">You've been using the plugin for a while now. We'd love to hear your thoughts!</p>
				<p style="margin: 0;">
					Answer 4 quick questions in our form and receive an
					<b>exclusive 20% discount code</b> for the Premium version.
				</p>
			</div>

			<div class="tpt-feedback-banner__actions">
				<!-- Primary Action: Go to Form -->
				<a href="<?php echo esc_url( $feedbackUrl ); ?>" target="_blank" class="tpt-feedback-banner__btn-primary">
					Give Feedback & Get Coupon 📝
				</a>

				<!-- Secondary Action: Direct Upgrade -->
				<a href="<?php echo esc_attr( $upgradeUrl ); ?>" class="tpt-feedback-banner__btn-secondary">
					Upgrade Now 🚀
				</a>

				<a href="<?php echo esc_attr( $notification->getCloseURL() ); ?>" class="tpt-feedback-banner__dismiss">
					No thanks
				</a>
			</div>

		</div>

		<!-- Right Column: Features -->
		<div class="tpt-feedback-banner__features-column">
			<div class="tpt-feedback-banner__features-title">Upgrade to unlock:</div>
			<ul class="tpt-feedback-banner__features-list">
				<?php foreach ( $premiumFeatures as $feature ) : ?>
					<li class="tpt-feedback-banner__feature">
						<i>✔</i> <?php echo esc_html( $feature ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<div style="margin-top: 15px; font-size: 12px; color: #646970;">
				Have questions? <a href="<?php echo esc_attr( TierPricingTablePlugin::getContactUsURL() ); ?>" target="_blank" style="color: #96598a; text-decoration: underline;">Contact our team</a>
			</div>
		</div>

	</div>
</div>