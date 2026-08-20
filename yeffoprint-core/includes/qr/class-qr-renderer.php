<?php
/**
 * Renders a YeffoPrint_QrCodeGen_QrCode's module grid to raster/PDF
 * bytes — the two formats a label field's QR value needs: PNG for the
 * live configurator preview and general download, PDF for staff
 * pulling a print-ready file when producing an order (direct request:
 * "so I don't need to use a 3rd party site").
 *
 * Always black-on-white, no color options — a colored or low-contrast
 * QR code is a real scan-reliability risk, and this code exists on a
 * physical printed product where a customer's phone camera has to
 * read it under ordinary lighting, not a design choice worth exposing.
 *
 * The PDF path is a minimal hand-built single-page PDF (one FlateDecode
 * 1-bit DeviceGray image XObject) rather than a pulled-in PDF library —
 * this plugin has zero build-step/dependency infrastructure (see
 * docs/ARCHITECTURE.md's Custom Stickers entries for the same
 * reasoning applied to the QR *encoder* itself) and a single small
 * raster placed on one page is simple enough to construct correctly by
 * hand, unlike the QR encoding algorithm in class-qr-code-gen.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Qr_Renderer {

	private const MARGIN_MODULES = 4; // ISO/IEC 18004's recommended "quiet zone" width, in modules.

	/**
	 * @return string|\WP_Error Raw PNG bytes.
	 */
	public static function render_png( string $text, int $module_px = 10, int $ecl = YeffoPrint_QrCodeGen_Ecc::MEDIUM ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new \WP_Error( 'yeffoprint_qr_no_gd', __( 'QR code rendering is unavailable on this server (the GD image library is missing).', 'yeffoprint-core' ) );
		}

		$qr = self::encode( $text, $ecl );
		if ( is_wp_error( $qr ) ) {
			return $qr;
		}

		$module_px = max( 1, $module_px );
		$modules   = $qr->size + 2 * self::MARGIN_MODULES;
		$px        = $modules * $module_px;

		$im    = imagecreatetruecolor( $px, $px );
		$white = imagecolorallocate( $im, 255, 255, 255 );
		$black = imagecolorallocate( $im, 0, 0, 0 );
		imagefilledrectangle( $im, 0, 0, $px - 1, $px - 1, $white );

		for ( $y = 0; $y < $qr->size; $y++ ) {
			for ( $x = 0; $x < $qr->size; $x++ ) {
				if ( ! $qr->get_module( $x, $y ) ) {
					continue;
				}
				$px0 = ( self::MARGIN_MODULES + $x ) * $module_px;
				$py0 = ( self::MARGIN_MODULES + $y ) * $module_px;
				imagefilledrectangle( $im, $px0, $py0, $px0 + $module_px - 1, $py0 + $module_px - 1, $black );
			}
		}

		ob_start();
		imagepng( $im );
		$data = ob_get_clean();
		imagedestroy( $im );

		return false === $data
			? new \WP_Error( 'yeffoprint_qr_png_failed', __( 'Could not render the QR code image.', 'yeffoprint-core' ) )
			: $data;
	}

	/**
	 * @param float $physical_size_in Width/height of the QR image on the
	 *   PDF page, in inches — the page is sized to exactly this (plus no
	 *   extra margin), so the file is a ready-to-place crop, not a full
	 *   sheet layout.
	 * @return string|\WP_Error Raw PDF bytes.
	 */
	public static function render_pdf( string $text, float $physical_size_in = 2.0, int $ecl = YeffoPrint_QrCodeGen_Ecc::MEDIUM ) {
		$qr = self::encode( $text, $ecl );
		if ( is_wp_error( $qr ) ) {
			return $qr;
		}

		$modules       = $qr->size + 2 * self::MARGIN_MODULES;
		$bytes_per_row = (int) ceil( $modules / 8 );
		$raster        = '';

		for ( $y = 0; $y < $modules; $y++ ) {
			$row_bits = array_fill( 0, $bytes_per_row * 8, 1 ); // 1 = white by default (unset bits at row end past $modules also stay white).
			for ( $x = 0; $x < $modules; $x++ ) {
				$grid_x = $x - self::MARGIN_MODULES;
				$grid_y = $y - self::MARGIN_MODULES;
				$dark   = $grid_x >= 0 && $grid_x < $qr->size && $grid_y >= 0 && $grid_y < $qr->size && $qr->get_module( $grid_x, $grid_y );
				if ( $dark ) {
					$row_bits[ $x ] = 0; // 0 = black in DeviceGray with the default Decode array.
				}
			}
			for ( $byte_i = 0; $byte_i < $bytes_per_row; $byte_i++ ) {
				$byte = 0;
				for ( $bit_i = 0; $bit_i < 8; $bit_i++ ) {
					$byte = ( $byte << 1 ) | $row_bits[ $byte_i * 8 + $bit_i ];
				}
				$raster .= chr( $byte );
			}
		}

		$compressed = gzcompress( $raster, 9 );
		if ( false === $compressed ) {
			return new \WP_Error( 'yeffoprint_qr_pdf_failed', __( 'Could not render the QR code PDF.', 'yeffoprint-core' ) );
		}

		$points = max( 1, round( $physical_size_in * 72 ) ); // 72pt = 1in, PDF's native unit.

		$objects   = [];
		$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$objects[] = sprintf(
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %1$d %1$d] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>',
			$points
		);

		$content    = sprintf( "q %1\$d 0 0 %1\$d 0 0 cm /Im0 Do Q", $points );
		$objects[] = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $content ), $content );

		$objects[] = sprintf(
			"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 1 /Interpolate false /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
			$modules,
			$modules,
			strlen( $compressed ),
			$compressed
		);

		return self::assemble_pdf( $objects );
	}

	/**
	 * @param string[] $objects Each entry is the object body (without "N 0 obj"/"endobj" wrapping), in order — object N is $objects[N-1].
	 */
	private static function assemble_pdf( array $objects ): string {
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; // The high-byte comment line is a standard signal to naive tools that this file contains binary data.

		$offsets = [];
		foreach ( $objects as $i => $body ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$count       = count( $objects ) + 1;

		$pdf .= "xref\n0 {$count}\n";
		$pdf .= "0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}

		$pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
		$pdf .= "startxref\n{$xref_offset}\n%%EOF";

		return $pdf;
	}

	/** @return YeffoPrint_QrCodeGen_QrCode|\WP_Error */
	private static function encode( string $text, int $ecl ) {
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'yeffoprint_qr_empty', __( 'No URL to encode.', 'yeffoprint-core' ) );
		}

		try {
			return YeffoPrint_QrCodeGen_QrCode::encode_text( $text, $ecl );
		} catch ( YeffoPrint_QrCodeGen_Data_Too_Long_Exception $e ) {
			return new \WP_Error( 'yeffoprint_qr_too_long', __( 'That URL is too long to encode as a QR code.', 'yeffoprint-core' ) );
		}
	}
}
