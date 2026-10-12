<?php
/**
 * Just enough IMAP to read the order mailbox (class-email-orders.php):
 * log in over TLS, list unread messages, download one, mark it read.
 *
 * Written against plain sockets rather than PHP's imap extension, which
 * many hosts no longer ship (it was unbundled in PHP 8.4), so the
 * feature never depends on what the server happens to have installed.
 * Implicit TLS only (port 993, what every mainstream provider offers).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Imap_Client {

	/** @var resource|null */
	private $stream = null;

	private int $tag = 0;

	/** @throws \RuntimeException */
	public function connect( string $host, int $port, string $username, string $password ): void {
		$context = stream_context_create( [
			'ssl' => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
				'SNI_enabled'      => true,
				'peer_name'        => $host,
			],
		] );

		$errno  = 0;
		$errstr = '';
		$stream = @stream_socket_client( 'ssl://' . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the error is reported below.

		if ( ! $stream ) {
			throw new \RuntimeException( sprintf( 'Could not reach %s:%d (%s).', $host, $port, $errstr ?: 'connection failed' ) );
		}

		stream_set_timeout( $stream, 30 );
		$this->stream = $stream;

		$greeting = $this->read_line();
		if ( 0 !== strpos( $greeting, '* OK' ) && 0 !== strpos( $greeting, '* PREAUTH' ) ) {
			throw new \RuntimeException( 'The mail server did not say hello: ' . trim( $greeting ) );
		}

		$this->command( 'LOGIN ' . self::quote( $username ) . ' ' . self::quote( $password ), 'The mailbox login was refused. Check the username and password (Gmail and Outlook need an app password).' );
		$this->command( 'SELECT INBOX' );
	}

	/** @return int[] UIDs of unread messages, oldest first. */
	public function unread_uids(): array {
		$lines = $this->command( 'UID SEARCH UNSEEN' );
		$uids  = [];
		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, '* SEARCH' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $line, 8 ) ) ) as $uid ) {
					if ( ctype_digit( $uid ) ) {
						$uids[] = (int) $uid;
					}
				}
			}
		}
		sort( $uids );
		return $uids;
	}

	/** The whole raw message (headers and body), without marking it read. */
	public function fetch( int $uid ): string {
		$tag = $this->send( 'UID FETCH ' . $uid . ' (BODY.PEEK[])' );
		$raw = '';

		while ( true ) {
			$line = $this->read_line();
			if ( preg_match( '/\{(\d+)\}\r\n$/', $line, $m ) ) {
				$raw = $this->read_bytes( (int) $m[1] );
				continue;
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				if ( ! preg_match( '/^' . preg_quote( $tag, '/' ) . ' OK/i', $line ) ) {
					throw new \RuntimeException( 'Could not download a message: ' . trim( $line ) );
				}
				break;
			}
		}

		return $raw;
	}

	public function mark_read( int $uid ): void {
		$this->command( 'UID STORE ' . $uid . ' +FLAGS.SILENT (\\Seen)' );
	}

	public function close(): void {
		if ( $this->stream ) {
			try {
				$this->command( 'LOGOUT' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- closing anyway.
			}
			fclose( $this->stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->stream = null;
		}
	}

	/** @return string[] the untagged lines; throws unless the server answers OK. */
	private function command( string $command, string $failure = '' ): array {
		$tag   = $this->send( $command );
		$lines = [];

		while ( true ) {
			$line = $this->read_line();
			// A literal inside an untagged reply (rare outside FETCH): skip its bytes.
			if ( preg_match( '/\{(\d+)\}\r\n$/', $line, $m ) ) {
				$this->read_bytes( (int) $m[1] );
				continue;
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				if ( ! preg_match( '/^' . preg_quote( $tag, '/' ) . ' OK/i', $line ) ) {
					throw new \RuntimeException( '' !== $failure ? $failure : 'The mail server refused a request: ' . trim( substr( $line, strlen( $tag ) + 1 ) ) );
				}
				return $lines;
			}
			$lines[] = rtrim( $line, "\r\n" );
		}
	}

	private function send( string $command ): string {
		$tag = 'A' . ( ++$this->tag );
		if ( false === fwrite( $this->stream, $tag . ' ' . $command . "\r\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			throw new \RuntimeException( 'The mail server connection dropped.' );
		}
		return $tag;
	}

	private function read_line(): string {
		$line = fgets( $this->stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		if ( false === $line ) {
			throw new \RuntimeException( 'The mail server stopped answering.' );
		}
		return $line;
	}

	private function read_bytes( int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$chunk = fread( $this->stream, min( 65536, $length - strlen( $data ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				if ( feof( $this->stream ) || ! empty( stream_get_meta_data( $this->stream )['timed_out'] ) ) {
					throw new \RuntimeException( 'The mail server stopped mid-message.' );
				}
				continue;
			}
			$data .= $chunk;
		}
		return $data;
	}

	/** IMAP quoted string. */
	private static function quote( string $value ): string {
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
	}
}
