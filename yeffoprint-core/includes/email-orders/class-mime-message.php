<?php
/**
 * Turns a raw email (what YeffoPrint_Imap_Client::fetch() returns) into
 * the few things the email order reader needs: who sent it, the subject,
 * and the readable text, with any forwarded message folded in.
 *
 * Handles multipart bodies, base64 and quoted-printable parts, other
 * character sets, HTML-only mail, and forwards sent "as attachment"
 * (message/rfc822), whose own From/Date/Subject go into the text so the
 * customer's address is still there to read.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Mime_Message {

	/** Longest text kept; real order emails are a small fraction of this. */
	private const MAX_TEXT = 40000;

	/** @return array{from_email:string, from_name:string, subject:string, message_id:string, date:string, text:string} */
	public static function parse( string $raw ): array {
		[ $headers, $body ] = self::split( $raw );

		[ $from_name, $from_email ] = self::address( $headers['from'] ?? '' );

		$text = trim( self::body_text( $headers, $body, 0 ) );
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT ) . "\n[…cut]";
		}

		return [
			'from_email' => $from_email,
			'from_name'  => $from_name,
			'subject'    => self::decode_header( $headers['subject'] ?? '' ),
			'message_id' => trim( $headers['message-id'] ?? '', " \t<>" ),
			'date'       => trim( $headers['date'] ?? '' ),
			'text'       => $text,
		];
	}

	/** @return array{0:string, 1:string} [ name, lowercase email ] */
	public static function address( string $header ): array {
		$header = self::decode_header( $header );
		if ( preg_match( '/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $header, $m ) ) {
			return [ trim( $m[1] ), strtolower( trim( $m[2] ) ) ];
		}
		if ( preg_match( '/[^\s<>,;"]+@[^\s<>,;"]+/', $header, $m ) ) {
			return [ '', strtolower( $m[0] ) ];
		}
		return [ '', '' ];
	}

	/** @return array{0:array<string,string>, 1:string} lowercase header names => unfolded values, and the body. */
	private static function split( string $raw ): array {
		$raw   = str_replace( "\r\n", "\n", $raw );
		$parts = explode( "\n\n", $raw, 2 );
		$head  = preg_replace( "/\n[ \t]+/", ' ', $parts[0] );

		$headers = [];
		foreach ( explode( "\n", (string) $head ) as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$name = strtolower( trim( substr( $line, 0, $colon ) ) );
			// The first one wins (a forward can repeat headers further down).
			if ( ! isset( $headers[ $name ] ) ) {
				$headers[ $name ] = trim( substr( $line, $colon + 1 ) );
			}
		}

		return [ $headers, $parts[1] ?? '' ];
	}

	private static function body_text( array $headers, string $body, int $depth ): string {
		if ( $depth > 8 ) {
			return '';
		}

		[ $type, $params ] = self::content_type( $headers['content-type'] ?? 'text/plain' );
		$disposition       = strtolower( $headers['content-disposition'] ?? '' );

		if ( 0 === strpos( $type, 'multipart/' ) && ! empty( $params['boundary'] ) ) {
			$parts = self::multipart( $body, $params['boundary'] );

			if ( 'multipart/alternative' === $type ) {
				// Same message twice: keep the plain one, or the HTML one if that's all there is.
				$html = '';
				foreach ( $parts as [ $part_headers, $part_body ] ) {
					[ $part_type ] = self::content_type( $part_headers['content-type'] ?? 'text/plain' );
					if ( 'text/plain' === $part_type ) {
						$plain = trim( self::body_text( $part_headers, $part_body, $depth + 1 ) );
						if ( '' !== $plain ) {
							return $plain;
						}
					} elseif ( '' === $html ) {
						$html = self::body_text( $part_headers, $part_body, $depth + 1 );
					}
				}
				return $html;
			}

			$texts = [];
			foreach ( $parts as [ $part_headers, $part_body ] ) {
				$text = trim( self::body_text( $part_headers, $part_body, $depth + 1 ) );
				if ( '' !== $text ) {
					$texts[] = $text;
				}
			}
			return implode( "\n\n", $texts );
		}

		if ( 'message/rfc822' === $type ) {
			[ $inner_headers, $inner_body ] = self::split( self::decode_body( $body, $headers ) );
			$intro = "---------- Forwarded message ----------\n";
			foreach ( [ 'from' => 'From', 'date' => 'Date', 'subject' => 'Subject', 'to' => 'To' ] as $key => $label ) {
				if ( ! empty( $inner_headers[ $key ] ) ) {
					$intro .= $label . ': ' . self::decode_header( $inner_headers[ $key ] ) . "\n";
				}
			}
			return $intro . "\n" . self::body_text( $inner_headers, $inner_body, $depth + 1 );
		}

		// Files (photos, PDFs, logos) aren't read; a text file attachment is.
		if ( 0 === strpos( $disposition, 'attachment' ) && 'text/plain' !== $type ) {
			return '';
		}

		if ( 'text/plain' === $type || 'text/html' === $type ) {
			$text = self::to_utf8( self::decode_body( $body, $headers ), $params['charset'] ?? '' );
			return 'text/html' === $type ? self::html_to_text( $text ) : $text;
		}

		return '';
	}

	/** @return array<int, array{0:array<string,string>, 1:string}> */
	private static function multipart( string $body, string $boundary ): array {
		$body   = str_replace( "\r\n", "\n", $body );
		$chunks = explode( '--' . $boundary, $body );
		array_shift( $chunks ); // Preamble.

		$parts = [];
		foreach ( $chunks as $chunk ) {
			if ( 0 === strpos( $chunk, '--' ) ) {
				break; // Closing boundary.
			}
			$chunk = preg_replace( '/^[ \t]*\n/', '', $chunk );
			$chunk = preg_replace( '/\n$/', '', (string) $chunk );
			// A part with no headers starts with a blank line.
			$parts[] = 0 === strpos( (string) $chunk, "\n" ) ? [ [], substr( (string) $chunk, 1 ) ] : self::split( (string) $chunk );
		}
		return $parts;
	}

	/** @return array{0:string, 1:array<string,string>} */
	private static function content_type( string $value ): array {
		$pieces = explode( ';', $value );
		$type   = strtolower( trim( (string) array_shift( $pieces ) ) );
		$params = [];
		foreach ( $pieces as $piece ) {
			if ( preg_match( '/^\s*([\w\-*]+)\s*=\s*"?([^"]*)"?\s*$/', $piece, $m ) ) {
				$params[ strtolower( $m[1] ) ] = $m[2];
			}
		}
		return [ '' !== $type ? $type : 'text/plain', $params ];
	}

	private static function decode_body( string $body, array $headers ): string {
		$encoding = strtolower( trim( $headers['content-transfer-encoding'] ?? '' ) );
		if ( 'base64' === $encoding ) {
			$decoded = base64_decode( preg_replace( '/\s+/', '', $body ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MIME transfer encoding.
			return false === $decoded ? '' : $decoded;
		}
		if ( 'quoted-printable' === $encoding ) {
			return quoted_printable_decode( $body );
		}
		return $body;
	}

	private static function to_utf8( string $text, string $charset ): string {
		$charset = strtolower( trim( $charset ) );
		if ( '' !== $charset && 'utf-8' !== $charset && 'us-ascii' !== $charset ) {
			$converted = @mb_convert_encoding( $text, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unknown charsets fall through to the check below.
			if ( is_string( $converted ) ) {
				$text = $converted;
			}
		}
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return str_replace( "\r\n", "\n", $text );
	}

	private static function html_to_text( string $html ): string {
		$html = preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '#</(p|div|tr|li|h[1-6]|table|blockquote)>#i', "\n", (string) $html );
		$html = preg_replace( '#<(td|th)[^>]*>#i', ' ', (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		return trim( (string) preg_replace( "/\n\s*\n\s*\n+/", "\n\n", (string) $text ) );
	}

	private static function decode_header( string $value ): string {
		if ( false === strpos( $value, '=?' ) ) {
			return trim( $value );
		}
		$decoded = function_exists( 'iconv_mime_decode' ) ? @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falls back below.
		if ( false === $decoded ) {
			$decoded = mb_decode_mimeheader( $value );
		}
		return trim( (string) $decoded );
	}
}
