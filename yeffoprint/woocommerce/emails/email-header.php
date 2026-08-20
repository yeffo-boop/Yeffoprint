<?php
/**
 * Email Header — theme override of woocommerce/templates/emails/email-header.php.
 *
 * Keeps WC core's own table skeleton (the nested outer_wrapper/wrapper/
 * inner_wrapper/template_container structure below is what keeps this
 * rendering correctly across Outlook/Gmail/Apple Mail — no reason to
 * rebuild it) and swaps only the logo area: instead of the admin-
 * uploaded-image-or-site-name fallback WC's own header renders here,
 * this always renders the CMY brand stripe + wordmark from the site's
 * own header/footer (parts/header.html, parts/footer.html) — email
 * clients don't reliably load inline SVG (Outlook desktop never does),
 * so the three color bars are plain colored table cells instead of the
 * site's actual SVG mark, and three small colored dots stand in for it
 * next to the wordmark.
 *
 * All colors/spacing live in email-styles.php, not inline here, same
 * separation WC's own template keeps.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

$store_name = $store_name ?? get_bloginfo( 'name', 'display' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<meta content="width=device-width, initial-scale=1.0" name="viewport">
		<title><?php echo esc_html( $store_name ); ?></title>
	</head>
	<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
		<table width="100%" id="outer_wrapper" role="presentation">
			<tr>
				<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
				<td width="600">
					<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
						<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="inner_wrapper" role="presentation">
							<tr>
								<td align="center" valign="top">

									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header_image" role="presentation">
										<tr class="yp-email-stripe">
											<td style="background-color:#00AEEF;width:33.33%;"></td>
											<td style="background-color:#EC008C;width:33.33%;"></td>
											<td style="background-color:#FFF200;width:33.34%;"></td>
										</tr>
										<tr>
											<td class="yp-email-wordmark" align="center">
												<a href="<?php echo esc_url( home_url() ); ?>" style="text-decoration:none;" target="_blank">
													<span class="yp-dot" style="background-color:#00AEEF;"></span><span class="yp-dot" style="background-color:#EC008C;"></span><span class="yp-dot" style="background-color:#FFF200;"></span><span class="yp-word"><?php echo esc_html( $store_name ); ?></span>
												</a>
											</td>
										</tr>
									</table>

									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation">
										<tr>
											<td align="center" valign="top">
												<!-- Header -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation">
													<tr>
														<td id="header_wrapper">
															<h1><?php echo esc_html( $email_heading ); ?></h1>
														</td>
													</tr>
												</table>
												<!-- End Header -->
											</td>
										</tr>
										<tr>
											<td align="center" valign="top">
												<!-- Body -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation">
													<tr>
														<td valign="top" id="body_content">
															<!-- Content -->
															<table border="0" cellpadding="20" cellspacing="0" width="100%" role="presentation">
																<tr>
																	<td valign="top" id="body_content_inner_cell">
																		<div id="body_content_inner">
