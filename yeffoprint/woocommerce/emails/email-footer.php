<?php
/**
 * Email Footer — theme override of woocommerce/templates/emails/email-footer.php.
 *
 * Closes the body markup email-header.php opens, then renders the
 * footer band. WC core's own footer just echoes the single "Email
 * footer text" option (Settings → Emails); this replaces that with the
 * same tagline/social/legal links the site's own footer carries
 * (parts/footer.html), so a customer opening the email recognizes it as
 * the same site instead of a generic "powered by WooCommerce" block.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

$email = $email ?? null;

?>
																		</div>
																	</td>
																</tr>
															</table>
															<!-- End Content -->
														</td>
													</tr>
												</table>
												<!-- End Body -->
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<tr>
								<td align="center" valign="top">
									<!-- Footer -->
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_footer" role="presentation">
										<tr>
											<td valign="top">
												<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
													<tr>
														<td colspan="2" valign="middle" id="credit">
															<p><?php esc_html_e( 'Premium custom vial labels and stickers, designed live, printed to order.', 'yeffoprint' ); ?></p>
															<p>
																<a href="https://t.me/senoryeffo"><?php esc_html_e( 'Telegram', 'yeffoprint' ); ?></a>
																<a href="https://wa.me/12538304840"><?php esc_html_e( 'WhatsApp', 'yeffoprint' ); ?></a>
																<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>"><?php esc_html_e( 'Privacy Policy', 'yeffoprint' ); ?></a>
																<a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>"><?php esc_html_e( 'Terms of Service', 'yeffoprint' ); ?></a>
															</p>
															<?php
															/**
															 * Still fired so a store-specific note added in
															 * Settings → Emails → "Email footer text" keeps
															 * showing up (e.g. a business address some payment
															 * processors require) — it just renders under the
															 * brand footer above instead of replacing it.
															 */
															$email_footer_text = get_option( 'woocommerce_email_footer_text' );
															if ( $email_footer_text ) {
																echo wp_kses_post( wpautop( wptexturize( apply_filters( 'woocommerce_email_footer_text', $email_footer_text, $email ) ) ) );
															}
															?>
															<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name', 'display' ) ); ?>. <?php esc_html_e( 'All rights reserved.', 'yeffoprint' ); ?></p>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
									<!-- End Footer -->
								</td>
							</tr>
						</table>
					</div>
				</td>
				<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
			</tr>
		</table>
	</body>
</html>
