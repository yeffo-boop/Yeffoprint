<?php namespace TierPricingTable\Settings\CustomOptions;

class TPTTableColumnsField {

	const FIELD_TYPE = 'tpt_table_columns_option';

	public function __construct() {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
	}

	public function render( $value ) {
		if ( ! isset( $value['id'] ) ) {
			$value['id'] = '';
		}

		if ( ! isset( $value['title'] ) ) {
			$value['title'] = isset( $value['name'] ) ? $value['name'] : '';
		}

		if ( ! isset( $value['default'] ) ) {
			$value['default'] = array( 'singular' => '', 'plural' => '' );
		}


		if ( ! isset( $value['desc'] ) ) {
			$value['desc'] = '';
		}
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">

				<div style="display: flex; gap:0 20px; align-items: end; flex-wrap: wrap;">
					<?php foreach ( $value['options'] as $option ) : ?>
						<?php
						$option['value'] = get_option( $option['id'], $option['default'] )
						?>
						<div>
							<label for="<?php echo esc_attr( $option['id'] ); ?>-singular">
								<?php echo esc_html( $option['label'] ); ?>:
							</label>
							<br>
							<input type="text" style="width: 190px;"
								   value="<?php echo esc_attr( $option['value'] ); ?>"
								   name="<?php echo esc_attr( $option['id'] ); ?>"
								   id="<?php echo esc_attr( $option['id'] ); ?>"
								   placeholder="">
						</div>
					<?php endforeach; ?>
					<div style="width: 100%; height:0"></div>
					<div>
						<p class="description"><?php echo wp_kses_post( $value['desc'] ); ?></p>
					</div>
					<?php do_action( 'tiered_pricing_table/settings/table_columns/after_fields' ); ?>
				</div>

				<?php do_action( 'tiered_pricing_table/settings/table_columns/end' ); ?>
			</td>
		</tr>
		<?php
	}
}