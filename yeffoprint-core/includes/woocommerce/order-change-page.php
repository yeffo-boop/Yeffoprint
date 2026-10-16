<?php
/**
 * Page body for the customer's "Request a label change" page
 * (YeffoPrint_Order_Label_Changes::render_page()).
 *
 * @var \WC_Order|null $order
 * @var array          $view    state (form|sent|locked|not_found), error, note
 * @var array|null     $request The change request already waiting, if any.
 * @var array          $lines   YeffoPrint_Order_Label_Changes::editable_lines()
 * @var string         $back_url
 * @var string         $back_label
 */

defined( 'ABSPATH' ) || exit;

$number  = $order ? YeffoPrint_Order_Label_Changes::order_label( $order ) : '';
$contact = home_url( '/contact/' );

/** One form control for a label field. */
$control = static function ( string $name, array $field ): string {
	$id    = 'f-' . md5( $name );
	$value = (string) $field['value'];
	$max   = $field['max_chars'] > 0 ? ' maxlength="' . (int) $field['max_chars'] . '"' : '';
	$req   = $field['required'] ? ' required' : '';
	$html  = '<label class="fld" for="' . esc_attr( $id ) . '"><span>' . esc_html( $field['label'] ) . ( $field['required'] ? '' : ' <i>optional</i>' ) . '</span>';

	if ( $field['options'] ) {
		$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . '>';
		$found = false;
		if ( ! $field['required'] ) {
			$html .= '<option value="">—</option>';
		}
		foreach ( $field['options'] as $option ) {
			$selected = 0 === strcasecmp( $option['value'], $value );
			$found    = $found || $selected;
			$html    .= '<option value="' . esc_attr( $option['value'] ) . '"' . selected( $selected, true, false ) . '>' . esc_html( $option['label'] ) . '</option>';
		}
		if ( ! $found && '' !== $value ) {
			$html .= '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $field['display'] ?: $value ) . '</option>';
		}
		$html .= '</select>';
	} elseif ( 'color' === $field['type'] ) {
		$html .= '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ?: '#000000' ) . '">';
	} elseif ( 'textarea' === $field['type'] ) {
		$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . $max . $req . '>' . esc_textarea( $value ) . '</textarea>';
	} else {
		$type  = 'qr_code' === $field['type'] ? 'url' : 'text';
		$html .= '<input type="' . $type . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $max . $req . '>';
	}

	return $html . '</label>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $number ? sprintf( 'Request a label change · Order %s', $number ) : 'Request a label change' ); ?></title>
<style>
:root{--ink:#141414;--muted:#5d5d5d;--line:#e4e2dd;--bg:#f7f6f3;--card:#fff;--pink:#e6007e;--good:#1f7a3a;--bad:#b42318}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
a{color:inherit}
.wrap{max-width:680px;margin:0 auto;padding:24px 16px 64px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:24px}
.brand{font-weight:700;font-size:20px;text-decoration:none}
.back{font-size:14px;color:var(--muted)}
h1{font-size:30px;line-height:1.15;margin:0 0 8px;letter-spacing:-.02em}
.lede{color:var(--muted);margin:0 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;margin-bottom:14px}
.card h2{font-size:17px;margin:0 0 4px}
.lab{border-top:1px solid var(--line);padding-top:14px;margin-top:14px}
.lab:first-of-type{border-top:0;padding-top:4px;margin-top:8px}
.lab h3{font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fld{display:flex;flex-direction:column;gap:5px;font-size:14px;font-weight:600}
.fld i{font-style:normal;font-weight:400;color:var(--muted)}
input,select,textarea{font:inherit;font-weight:400;width:100%;padding:10px 12px;border:1px solid #cfccc5;border-radius:10px;background:#fff;color:var(--ink)}
input[type=color]{height:44px;padding:4px}
input:focus,select:focus,textarea:focus{outline:2px solid var(--ink);outline-offset:1px}
.btn{display:inline-block;border:0;border-radius:999px;background:var(--ink);color:#fff;font:600 16px/1 inherit;padding:15px 22px;cursor:pointer;text-decoration:none}
.btn:hover{background:#000}
.msg{border-radius:14px;padding:14px 16px;margin:0 0 16px;font-size:15px}
.msg--wait{background:#fff7e0;border:1px solid #f1dfa6}
.msg--bad{background:#fde4e0;border:1px solid #f4b8ad;color:var(--bad)}
.msg--good{background:#e5f4ea;border:1px solid #b5dcc1}
.small{font-size:14px;color:var(--muted)}
ul.changes{margin:8px 0 0;padding-left:20px}
@media (max-width:560px){.grid{grid-template-columns:1fr}h1{font-size:26px}}
</style>
</head>
<body>
<div class="wrap">
	<div class="top">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">YeffoDesign</a>
		<?php if ( $order ) : ?>
			<a class="back" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( $back_label ); ?></a>
		<?php endif; ?>
	</div>

<?php if ( 'not_found' === $view['state'] ) : ?>
	<h1>We couldn't find that order</h1>
	<p class="lede">The link may be incomplete. Open it again from your order email, or <a href="<?php echo esc_url( $contact ); ?>">message us</a> with your order number.</p>

<?php elseif ( 'sent' === $view['state'] ) : ?>
	<h1>Change request sent</h1>
	<div class="msg msg--good">
		<b>Thanks! We'll look it over before we print order <?php echo esc_html( $number ); ?>.</b>
		You'll get an email once it's done. If you need anything else, use this page again or <a href="<?php echo esc_url( $contact ); ?>">message us</a>.
	</div>
	<p><a class="btn" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( $back_label ); ?></a></p>

<?php elseif ( 'locked' === $view['state'] ) : ?>
	<h1>Order <?php echo esc_html( $number ); ?> can't be changed online</h1>
	<p class="lede">It's already being printed or has shipped. If something is wrong, <a href="<?php echo esc_url( $contact ); ?>">message us</a> with your order number and we'll help.</p>

<?php else : ?>
	<h1>Request a label change</h1>
	<p class="lede">Order <?php echo esc_html( $number ); ?>. Fix a typo, a compound name or a color below. We'll check it and email you once it's made, before we print.</p>

	<?php if ( $request ) : ?>
		<div class="msg msg--wait">
			<b>We already have a change request from you.</b> It's filled in below. Sending again replaces it.
			<?php if ( ! empty( $request['lines'] ) ) : ?>
				<ul class="changes"><?php foreach ( (array) $request['lines'] as $line_text ) : ?><li><?php echo esc_html( (string) $line_text ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $view['error'] ) : ?>
		<div class="msg msg--bad"><?php echo esc_html( $view['error'] ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( YeffoPrint_Order_Label_Changes::url( $order ) ); ?>">
		<input type="hidden" name="order" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
		<input type="hidden" name="key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">

		<?php foreach ( $lines as $line ) : ?>
			<section class="card">
				<h2><?php echo esc_html( $line['name'] ); ?></h2>
				<?php foreach ( $line['labels'] as $label ) : ?>
					<div class="lab">
						<?php if ( count( $line['labels'] ) > 1 ) : ?>
							<h3>Label <?php echo (int) $label['index'] + 1; ?> · qty <?php echo (int) $label['quantity']; ?></h3>
						<?php endif; ?>
						<div class="grid">
							<?php
							foreach ( $label['fields'] as $field ) {
								echo $control( 'labels[' . $line['item_id'] . '][' . $label['index'] . '][' . $field['id'] . ']', $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside $control.
							}
							?>
						</div>
					</div>
				<?php endforeach; ?>
			</section>
		<?php endforeach; ?>

		<section class="card">
			<label class="fld" for="yp-change-note"><span>Anything else we should know? <i>optional</i></span>
				<textarea id="yp-change-note" name="note" rows="3" maxlength="1000"><?php echo esc_textarea( $view['note'] ); ?></textarea>
			</label>
			<p class="small">Size, material and quantity can't be changed here because they change the price. <a href="<?php echo esc_url( $contact ); ?>">Message us</a> for those.</p>
		</section>

		<button type="submit" class="btn">Send change request</button>
	</form>
<?php endif; ?>
</div>
</body>
</html>
