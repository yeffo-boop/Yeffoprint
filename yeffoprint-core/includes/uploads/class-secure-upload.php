<?php
/**
 * Validated file uploads for the Custom Design inspiration files
 * (PROJECT_SPEC §18: "safe/validated uploads (PDF/SVG/PNG/JPG
 * initially, configurable size limits, no arbitrary executables)").
 *
 * Two things core's own upload handling doesn't cover out of the box,
 * both handled here rather than widening what the whole site accepts:
 *
 * 1. SVG isn't in WordPress's default allowed mime list (it can carry
 *    <script>, event-handler attributes, or external references — a
 *    real XSS vector). It's allowed here, scoped to this one upload
 *    flow via wp_handle_upload()'s per-call `mimes` override rather
 *    than the global `upload_mimes` filter, and every accepted SVG is
 *    parsed and stripped of scripts/handlers/foreignObject before
 *    being stored. If it doesn't parse as valid XML, it's rejected —
 *    we can't sanitize what we can't inspect.
 * 2. A configurable max file size and file count, since this is
 *    customer-facing, unauthenticated (guest) upload — the kind of
 *    endpoint abuse tries first.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Secure_Upload {

	public const MAX_FILE_BYTES  = 10 * 1024 * 1024; // 10MB
	public const MAX_FILES       = 5;

	private const ALLOWED_MIMES = [
		'pdf'  => 'application/pdf',
		'svg'  => 'image/svg+xml',
		'png'  => 'image/png',
		'jpg|jpeg' => 'image/jpeg',
	];

	/**
	 * @param array $file One entry from $_FILES (already single-file —
	 *                    caller loops multi-file input entries).
	 * @return int|\WP_Error Attachment ID on success.
	 */
	public static function handle( array $file ) {
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return new \WP_Error( 'yeffoprint_upload_error', __( 'That file failed to upload.', 'yeffoprint-core' ) );
		}

		if ( ( $file['size'] ?? 0 ) > self::MAX_FILE_BYTES ) {
			return new \WP_Error(
				'yeffoprint_file_too_large',
				sprintf(
					/* translators: %s: max file size, e.g. "10MB" */
					__( 'Files must be %s or smaller.', 'yeffoprint-core' ),
					size_format( self::MAX_FILE_BYTES )
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$declared_ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
		$is_svg       = self::looks_like_svg( $file );

		// Content sniffed as SVG but not named .svg: a mismatched
		// extension is exactly how a script-bearing SVG would try to
		// slip past the mimes override under a trusted-looking
		// .png/.jpg name — reject outright rather than accept it under
		// the wrong declared type.
		if ( $is_svg && 'svg' !== $declared_ext ) {
			return new \WP_Error( 'yeffoprint_invalid_file', __( "This file's contents don't match its file type.", 'yeffoprint-core' ) );
		}

		$overrides = [
			'test_form' => false,
			'mimes'     => self::ALLOWED_MIMES,
		];

		$uploaded = wp_handle_upload( $file, $overrides );

		if ( isset( $uploaded['error'] ) ) {
			return new \WP_Error( 'yeffoprint_invalid_file', $uploaded['error'] );
		}

		if ( $is_svg && ! self::sanitize_svg_file( $uploaded['file'] ) ) {
			wp_delete_file( $uploaded['file'] );
			return new \WP_Error( 'yeffoprint_invalid_svg', __( "That SVG couldn't be validated and was rejected.", 'yeffoprint-core' ) );
		}

		$attachment_id = wp_insert_attachment( [
			'post_mime_type' => $uploaded['type'],
			'post_title'     => sanitize_file_name( pathinfo( $uploaded['file'], PATHINFO_FILENAME ) ),
			'post_status'    => 'inherit',
			'post_content'   => '',
		], $uploaded['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $uploaded['file'] );
			return $attachment_id;
		}

		if ( ! $is_svg ) {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		}

		return $attachment_id;
	}

	/**
	 * Checked by content, not just the client-supplied filename
	 * extension — a file renamed to end in .png/.jpg but containing SVG
	 * markup would otherwise skip sanitize_svg_file() entirely and be
	 * stored as-is via the ordinary (non-SVG) upload path.
	 */
	private static function looks_like_svg( array $file ): bool {
		$ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext ) {
			return true;
		}

		$tmp_name = $file['tmp_name'] ?? '';
		if ( '' === $tmp_name || ! is_readable( $tmp_name ) ) {
			return false;
		}

		// A leading chunk is enough to catch the <svg tag near the top
		// of any real SVG (after an optional XML prolog/comments)
		// without reading the whole file for a check this cheap.
		$head = file_get_contents( $tmp_name, false, null, 0, 4096 );
		return false !== $head && (bool) preg_match( '/<svg[\s>]/i', $head );
	}

	/**
	 * Strips <script>, on*="" event handlers, and javascript:/data:
	 * URIs from an SVG in place. Returns false (caller rejects the
	 * upload) if the file isn't parseable, safe XML.
	 */
	private static function sanitize_svg_file( string $path ): bool {
		$contents = file_get_contents( $path );
		if ( false === $contents || '' === trim( $contents ) ) {
			return false;
		}

		// Reject any DOCTYPE outright rather than trying to safely
		// parse it — a legitimate SVG from a design tool doesn't need
		// one, and it closes off XXE/entity-expansion attacks entirely
		// rather than relying solely on libxml's defaults.
		if ( preg_match( '/<!DOCTYPE/i', $contents ) ) {
			return false;
		}

		$previous_setting = libxml_use_internal_errors( true );
		$dom              = new \DOMDocument();
		$loaded           = $dom->loadXML( $contents, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			return false;
		}

		foreach ( iterator_to_array( $dom->getElementsByTagName( 'script' ) ) as $script ) {
			$script->parentNode->removeChild( $script );
		}

		foreach ( iterator_to_array( $dom->getElementsByTagName( 'foreignObject' ) ) as $foreign ) {
			$foreign->parentNode->removeChild( $foreign );
		}

		$xpath = new \DOMXPath( $dom );
		foreach ( $xpath->query( '//*' ) as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$name  = strtolower( $attribute->name );
				$value = trim( $attribute->value );

				$is_event_handler = 0 === strpos( $name, 'on' );
				$is_script_uri    = preg_match( '/^\s*(javascript|data)\s*:/i', $value );

				if ( $is_event_handler || $is_script_uri ) {
					$element->removeAttribute( $attribute->name );
				}
			}
		}

		return false !== $dom->save( $path );
	}
}
