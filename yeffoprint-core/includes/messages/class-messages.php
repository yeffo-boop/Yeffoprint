<?php
/**
 * Messages inbox for the admin app (direct request: run the business
 * from the dashboard without wp-admin). Contact form messages and web
 * design quote requests used to exist only as an email and a Telegram
 * alert; this keeps a copy of each so the dashboard can list them, mark
 * them done, and reply by email.
 *
 * Stored as a private post type (yp_message), one post per message:
 * post_content is the message (or the quote's formatted answers), and
 * meta holds the kind, sender and New/Done state. Listens to the same
 * actions the Telegram alerts do (class-contact-controller.php,
 * class-web-design-quote-controller.php), so neither form changed.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Messages {

	public const POST_TYPE = 'yp_message';

	private const META_KIND   = '_yp_message_kind';
	private const META_NAME   = '_yp_message_name';
	private const META_EMAIL  = '_yp_message_email';
	private const META_REPLY  = '_yp_message_reply_via';
	private const META_STATE  = '_yp_message_state';
	private const META_DONE   = '_yp_message_done_at';

	public const KINDS = [
		'contact' => 'Contact form',
		'quote'   => 'Web design quote',
	];

	private const REPLY_LABELS = [
		'email'    => 'Email',
		'whatsapp' => 'WhatsApp',
		'telegram' => 'Telegram',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'yeffoprint_contact_form_submitted', [ $this, 'on_contact' ], 5, 5 );
		add_action( 'yeffoprint_web_design_quote_submitted', [ $this, 'on_quote' ], 5 );
	}

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'label'           => __( 'Messages', 'yeffoprint-core' ),
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'supports'        => [ 'title', 'editor' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		] );
	}

	public function on_contact( string $name, string $email, string $method, string $handle, string $message ): void {
		$reply = 'email' === $method || '' === $handle
			? ''
			: sprintf( '%s: %s', self::REPLY_LABELS[ $method ] ?? ucfirst( $method ), $handle );

		self::store( 'contact', $name, $email, $message, $reply );
	}

	/** @param array<string,string> $answers */
	public function on_quote( array $answers ): void {
		$body = implode( "\n", YeffoPrint_Web_Design_Quote_Controller::format_answers( $answers ) );
		$name = (string) ( $answers['name'] ?? '' );
		if ( ! empty( $answers['business_name'] ) ) {
			$name .= ' (' . $answers['business_name'] . ')';
		}
		self::store( 'quote', $name, (string) ( $answers['email'] ?? '' ), $body, '' );
	}

	public static function store( string $kind, string $name, string $email, string $body, string $reply_via ): int {
		$id = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'private',
			'post_title'   => mb_substr( $name, 0, 190 ),
			'post_content' => $body,
		], true );

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, self::META_KIND, $kind );
		update_post_meta( $id, self::META_NAME, $name );
		update_post_meta( $id, self::META_EMAIL, $email );
		update_post_meta( $id, self::META_REPLY, $reply_via );
		update_post_meta( $id, self::META_STATE, 'new' );
		return (int) $id;
	}

	/** @param string $filter new | done | contact | quote | all */
	public static function list( string $filter, int $page = 1 ): array {
		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 30,
			'paged'          => max( 1, $page ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( 'new' === $filter || 'done' === $filter ) {
			$args['meta_query'] = [ [ 'key' => self::META_STATE, 'value' => $filter ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} elseif ( isset( self::KINDS[ $filter ] ) ) {
			$args['meta_query'] = [ [ 'key' => self::META_KIND, 'value' => $filter ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new \WP_Query( $args );

		return [
			'messages'      => array_map( [ self::class, 'format' ], $query->posts ),
			'total'         => (int) $query->found_posts,
			'max_num_pages' => (int) $query->max_num_pages,
			'page'          => max( 1, $page ),
		];
	}

	public static function count_new(): int {
		$query = new \WP_Query( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => self::META_STATE, 'value' => 'new' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		] );
		return (int) $query->found_posts;
	}

	public static function format( \WP_Post $post ): array {
		$kind = (string) get_post_meta( $post->ID, self::META_KIND, true );
		return [
			'id'         => $post->ID,
			'kind'       => $kind,
			'kind_label' => self::KINDS[ $kind ] ?? $kind,
			'name'       => (string) get_post_meta( $post->ID, self::META_NAME, true ),
			'email'      => (string) get_post_meta( $post->ID, self::META_EMAIL, true ),
			'reply_via'  => (string) get_post_meta( $post->ID, self::META_REPLY, true ),
			'message'    => (string) $post->post_content,
			'status'     => 'done' === get_post_meta( $post->ID, self::META_STATE, true ) ? 'done' : 'new',
			'date'       => get_post_datetime( $post ) ? get_post_datetime( $post )->format( 'c' ) : null,
		];
	}

	public static function exists( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type;
	}

	public static function set_state( int $id, string $state ): void {
		update_post_meta( $id, self::META_STATE, 'done' === $state ? 'done' : 'new' );
		if ( 'done' === $state ) {
			update_post_meta( $id, self::META_DONE, time() );
		} else {
			delete_post_meta( $id, self::META_DONE );
		}
	}

	public static function delete( int $id ): void {
		wp_delete_post( $id, true );
	}
}
