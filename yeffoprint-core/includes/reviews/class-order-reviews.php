<?php
/**
 * Customer reviews, one per delivered order. Direct request: "Can we
 * enable customer reviews for orders? And add a spot on the 'delivered'
 * email for them to leave a quick review? They should have the option
 * to include pictures if they want."
 *
 * A review is a normal WooCommerce product review (a `review` comment
 * with `rating` + `verified` meta) on the order's first product, so
 * WooCommerce's own rating counts and the homepage "What Customers Say"
 * pattern (theme patterns/reviews.php, which already reads approved
 * product reviews) pick it up with no extra wiring. On top of that it
 * carries:
 *
 * - `_yp_review_order_id`: the order it's for (one review per order;
 *   the order stores the comment ID back under `_yp_review_id`).
 * - `_yp_review_photos`: attachment IDs of the customer's photos.
 * - `_yp_review_for`: one row per label template / 3D print in the
 *   order ("template:12", "print:34"), so every product page the order
 *   touched can show it, not just the first line's.
 *
 * Customers reach the form from the Delivered email (a row of stars
 * and a button, render_email_block()), or from My Account. The link is
 * `/leave-a-review/?order=<id>&key=<order_key>`, same guest-access
 * secret the /track-order/ page already trusts, so no login is needed.
 * New reviews wait for approval in the admin app's Reviews screen
 * unless "Publish new reviews right away" is on.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Reviews {

	public const META_ORDER    = '_yp_review_order_id';
	public const META_PHOTOS   = '_yp_review_photos';
	public const META_FOR      = '_yp_review_for';
	public const ORDER_META    = '_yp_review_id';
	public const MAX_PHOTOS    = 5;
	public const MAX_TEXT      = 2000;
	public const MAX_NAME      = 40;
	public const PAGE_SLUG     = 'leave-a-review';
	public const PAGE_TEMPLATE = 'leave-a-review';

	private const SETTINGS_OPTION = 'yeffoprint_review_settings';
	private const PAGE_OPTION     = 'yeffoprint_review_page_id';
	private const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
	private const PHOTO_MIMES     = [
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	];

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'ensure_page' ], 30 );
		add_filter( 'wp_robots', [ $this, 'noindex_review_page' ] );
		add_action( 'woocommerce_email_order_details', [ $this, 'render_email_block' ], 5, 4 );
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'account_order_action' ], 20, 2 );
		add_action( 'woocommerce_order_details_before_order_table', [ $this, 'render_account_prompt' ] );
		add_action( 'delete_comment', [ $this, 'delete_photos' ] );
	}

	/* ---------- Settings ---------- */

	public static function settings(): array {
		$saved = get_option( self::SETTINGS_OPTION, [] );
		$s     = array_merge(
			[
				'enabled'      => true,
				'auto_publish' => false,
			],
			is_array( $saved ) ? $saved : []
		);

		return [
			'enabled'      => (bool) $s['enabled'],
			'auto_publish' => (bool) $s['auto_publish'],
		];
	}

	public static function save_settings( array $input ): array {
		$current = self::settings();
		foreach ( $current as $key => $value ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = (bool) $input[ $key ];
			}
		}
		update_option( self::SETTINGS_OPTION, $current, false );
		return self::settings();
	}

	/* ---------- The review page ---------- */

	/**
	 * Creates the /leave-a-review/ page (theme template
	 * leave-a-review.html) the first time the plugin runs with this
	 * code, so the email link works without anyone setting up a page by
	 * hand. Re-creates it only if it's been deleted outright; a page
	 * that's merely been edited or moved is left alone.
	 */
	public static function ensure_page(): void {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page_id && get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing ) {
			update_option( self::PAGE_OPTION, (int) $existing->ID, false );
			return;
		}

		// add_option() only succeeds for one request, so two visitors
		// arriving at once can't each create a copy of the page. A lock
		// older than a minute is from a request that died; take it over.
		$lock = 'yeffoprint_review_page_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( $lock, time(), false );
		}

		$page_id = wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Leave a Review', 'yeffoprint-core' ),
			'post_name'    => self::PAGE_SLUG,
			'post_content' => '',
			'meta_input'   => [ '_wp_page_template' => self::PAGE_TEMPLATE ],
		] );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::PAGE_OPTION, (int) $page_id, false );
		}
		delete_option( $lock );
	}

	public static function page_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	/** Private per-order links shouldn't end up in search results. */
	public function noindex_review_page( array $robots ): array {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page_id && is_page( $page_id ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function review_url( \WC_Order $order, int $rating = 0 ): string {
		$args = [
			'order' => $order->get_id(),
			'key'   => $order->get_order_key(),
		];
		if ( $rating >= 1 && $rating <= 5 ) {
			$args['rating'] = $rating;
		}
		return add_query_arg( $args, self::page_url() );
	}

	/* ---------- Eligibility ---------- */

	/**
	 * "Completed" on this store means every package shows delivered
	 * (class-order-delivery-status.php), which is exactly when a review
	 * makes sense. Web Design orders also end up Completed but get their
	 * own "your website is live" email, so they're left out.
	 */
	public static function is_reviewable( \WC_Order $order ): bool {
		if ( 'completed' !== $order->get_status() ) {
			return false;
		}
		if ( class_exists( 'YeffoPrint_Web_Design_Project_Meta' ) && YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order ) ) {
			return false;
		}
		return (bool) self::primary_product_id( $order );
	}

	public static function get_review( \WC_Order $order ): ?\WP_Comment {
		$comment_id = (int) $order->get_meta( self::ORDER_META );
		if ( ! $comment_id ) {
			return null;
		}
		$comment = get_comment( $comment_id );
		return $comment instanceof \WP_Comment && 'trash' !== $comment->comment_approved ? $comment : null;
	}

	private static function primary_product_id( \WC_Order $order ): int {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product_id() && get_post( $item->get_product_id() ) ) {
				return (int) $item->get_product_id();
			}
		}
		return 0;
	}

	/**
	 * What the review is "for": each label template and 3D print in the
	 * order, so each of their product pages can show it.
	 *
	 * @return string[] e.g. ["template:12", "print:34"]
	 */
	private static function review_targets( \WC_Order $order ): array {
		$targets = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$snapshot    = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
			$template_id = (int) ( $snapshot['id'] ?? 0 );
			if ( $template_id ) {
				$targets[] = 'template:' . $template_id;
				continue;
			}
			$print_id = class_exists( 'YeffoPrint_Print_Product' ) ? YeffoPrint_Print_Product::get_print_id( (int) $item->get_product_id() ) : 0;
			if ( $print_id ) {
				$targets[] = 'print:' . $print_id;
			}
		}
		return array_values( array_unique( $targets ) );
	}

	/** @return array<int, array{name:string, detail:string, image:string}> */
	public static function order_lines( \WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product     = $item->get_product();
			$snapshot    = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
			$template_id = (int) ( $snapshot['id'] ?? 0 );
			$print_id    = class_exists( 'YeffoPrint_Print_Product' ) ? YeffoPrint_Print_Product::get_print_id( (int) $item->get_product_id() ) : 0;

			$image = '';
			if ( $template_id ) {
				$image = (string) get_the_post_thumbnail_url( $template_id, 'medium' );
			} elseif ( $print_id ) {
				$image = (string) get_the_post_thumbnail_url( $print_id, 'medium' );
			}
			if ( '' === $image && $product instanceof \WC_Product && $product->get_image_id() ) {
				$image = (string) wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
			}

			$lines[] = [
				'name'  => html_entity_decode( wp_strip_all_tags( $item->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'image' => $image,
			];
		}
		return $lines;
	}

	/** "Jane D." from the billing name, which the customer can change on the form. */
	public static function suggested_name( \WC_Order $order ): string {
		$first = trim( $order->get_billing_first_name() );
		$last  = trim( $order->get_billing_last_name() );
		if ( '' === $first ) {
			return '';
		}
		return $last ? $first . ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.' : $first;
	}

	/* ---------- Submitting ---------- */

	/**
	 * @param array $files Normalized single-file entries from $_FILES.
	 * @return \WP_Comment|\WP_Error
	 */
	public static function submit( \WC_Order $order, int $rating, string $text, string $name, array $files ) {
		if ( ! self::is_reviewable( $order ) ) {
			return new \WP_Error( 'yeffoprint_review_not_ready', __( 'You can review this order once it has been delivered.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}
		if ( self::get_review( $order ) ) {
			return new \WP_Error( 'yeffoprint_review_exists', __( 'You have already reviewed this order. Thank you!', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}
		if ( $rating < 1 || $rating > 5 ) {
			return new \WP_Error( 'yeffoprint_review_rating', __( 'Please pick a star rating.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$text = trim( sanitize_textarea_field( $text ) );
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			/* translators: %d: max characters */
			return new \WP_Error( 'yeffoprint_review_long', sprintf( __( 'Please keep your review under %d characters.', 'yeffoprint-core' ), self::MAX_TEXT ), [ 'status' => 400 ] );
		}

		$name = trim( sanitize_text_field( $name ) );
		$name = '' !== $name ? mb_substr( $name, 0, self::MAX_NAME ) : self::suggested_name( $order );
		if ( '' === $name ) {
			$name = __( 'Customer', 'yeffoprint-core' );
		}

		if ( count( $files ) > self::MAX_PHOTOS ) {
			/* translators: %d: max photos */
			return new \WP_Error( 'yeffoprint_review_photos', sprintf( __( 'You can add up to %d photos.', 'yeffoprint-core' ), self::MAX_PHOTOS ), [ 'status' => 400 ] );
		}

		$photo_ids = [];
		foreach ( $files as $file ) {
			$attachment_id = self::store_photo( $file, $order );
			if ( is_wp_error( $attachment_id ) ) {
				foreach ( $photo_ids as $id ) {
					wp_delete_attachment( $id, true );
				}
				return $attachment_id;
			}
			$photo_ids[] = $attachment_id;
		}

		$publish    = self::settings()['auto_publish'];
		$comment_id = wp_insert_comment( [
			'comment_post_ID'      => self::primary_product_id( $order ),
			'comment_type'         => 'review',
			'comment_author'       => $name,
			'comment_author_email' => $order->get_billing_email(),
			'comment_content'      => $text,
			'comment_approved'     => $publish ? 1 : 0,
			'user_id'              => (int) $order->get_customer_id(),
			'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'comment_meta'         => [
				'rating'          => $rating,
				'verified'        => 1,
				self::META_ORDER  => $order->get_id(),
				self::META_PHOTOS => $photo_ids,
			],
		] );

		if ( ! $comment_id ) {
			foreach ( $photo_ids as $id ) {
				wp_delete_attachment( $id, true );
			}
			return new \WP_Error( 'yeffoprint_review_failed', __( "Your review couldn't be saved. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		foreach ( self::review_targets( $order ) as $target ) {
			add_comment_meta( $comment_id, self::META_FOR, $target );
		}

		// Photos belong to the product the review sits on, so they show
		// up alongside it in the media library.
		foreach ( $photo_ids as $id ) {
			wp_update_post( [ 'ID' => $id, 'post_parent' => self::primary_product_id( $order ) ] );
		}

		$order->update_meta_data( self::ORDER_META, $comment_id );
		$order->save();
		$order->add_order_note(
			/* translators: %d: star rating */
			sprintf( __( 'Customer left a %d-star review.', 'yeffoprint-core' ), $rating )
			. ( $photo_ids ? ' ' . sprintf( /* translators: %d: photo count */ _n( '(%d photo)', '(%d photos)', count( $photo_ids ), 'yeffoprint-core' ), count( $photo_ids ) ) : '' )
		);

		// Keeps WooCommerce's own per-product average/count current.
		if ( class_exists( 'WC_Comments' ) ) {
			\WC_Comments::clear_transients( self::primary_product_id( $order ) );
		}

		self::alert_owner( $order, $rating, $text, $name, count( $photo_ids ), $publish );

		return get_comment( $comment_id );
	}

	/** @return int|\WP_Error Attachment ID. */
	private static function store_photo( array $file, \WC_Order $order ) {
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return new \WP_Error( 'yeffoprint_review_upload', __( 'A photo failed to upload. Please try again.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( ( $file['size'] ?? 0 ) > self::MAX_PHOTO_BYTES ) {
			/* translators: %s: max size, e.g. "10 MB" */
			return new \WP_Error( 'yeffoprint_review_upload', sprintf( __( 'Photos must be %s or smaller.', 'yeffoprint-core' ), size_format( self::MAX_PHOTO_BYTES ) ), [ 'status' => 400 ] );
		}

		// Must really be a raster image, whatever the name says.
		$real_mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $file['tmp_name'] ?? '' ) : false;
		if ( ! $real_mime || ! in_array( $real_mime, self::PHOTO_MIMES, true ) ) {
			return new \WP_Error( 'yeffoprint_review_upload', __( 'Photos need to be JPG, PNG or WebP images.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// A random name, so a photo waiting for approval can't be found
		// by guessing the customer's original filename.
		$ext          = array_search( $real_mime, [ 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ], true );
		$file['name'] = 'review-' . $order->get_id() . '-' . wp_generate_password( 10, false ) . '.' . $ext;

		$uploaded = wp_handle_upload( $file, [
			'test_form' => false,
			'mimes'     => self::PHOTO_MIMES,
		] );
		if ( isset( $uploaded['error'] ) ) {
			return new \WP_Error( 'yeffoprint_review_upload', $uploaded['error'], [ 'status' => 400 ] );
		}

		$attachment_id = wp_insert_attachment( [
			'post_mime_type' => $uploaded['type'],
			/* translators: %s: order number */
			'post_title'     => sprintf( __( 'Review photo, order %s', 'yeffoprint-core' ), $order->get_order_number() ),
			'post_status'    => 'inherit',
			'post_content'   => '',
		], $uploaded['file'] );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $uploaded['file'] );
			return new \WP_Error( 'yeffoprint_review_upload', __( 'A photo failed to upload. Please try again.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		update_post_meta( $attachment_id, '_yp_review_photo', 1 );

		return (int) $attachment_id;
	}

	private static function alert_owner( \WC_Order $order, int $rating, string $text, string $name, int $photos, bool $published ): void {
		if ( ! class_exists( 'YeffoPrint_Telegram_Admin_Alerts' ) ) {
			return;
		}

		$lines = [
			str_repeat( '⭐', $rating ) . ' ' . sprintf(
				/* translators: 1: reviewer name, 2: order number */
				__( 'New review from %1$s (order %2$s)', 'yeffoprint-core' ),
				$name,
				$order->get_order_number()
			),
		];
		if ( '' !== $text ) {
			$lines[] = '“' . mb_strimwidth( $text, 0, 400, '…' ) . '”';
		}
		if ( $photos ) {
			/* translators: %d: photo count */
			$lines[] = '📷 ' . sprintf( _n( '%d photo', '%d photos', $photos, 'yeffoprint-core' ), $photos );
		}
		$lines[] = $published
			? __( 'It is live on the site.', 'yeffoprint-core' )
			: __( 'Approve it in YeffoDesign › Reviews.', 'yeffoprint-core' );

		YeffoPrint_Telegram_Admin_Alerts::notify( implode( "\n", $lines ) );
	}

	/** Deleting a review removes its photos too. */
	public function delete_photos( $comment_id ): void {
		$ids = get_comment_meta( (int) $comment_id, self::META_PHOTOS, true );
		if ( ! is_array( $ids ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			if ( get_post_meta( (int) $id, '_yp_review_photo', true ) ) {
				wp_delete_attachment( (int) $id, true );
			}
		}
	}

	/* ---------- Reading ---------- */

	/**
	 * @return array{id:int, rating:int, text:string, name:string, date:string, date_gmt:string, published:bool, photos:array<int, array{thumb:string, full:string}>, order_id:int}
	 */
	public static function format( \WP_Comment $comment ): array {
		$photo_ids = get_comment_meta( $comment->comment_ID, self::META_PHOTOS, true );
		$photos    = [];
		foreach ( is_array( $photo_ids ) ? $photo_ids : [] as $id ) {
			$full = wp_get_attachment_image_url( (int) $id, 'large' );
			if ( ! $full ) {
				continue;
			}
			$photos[] = [
				'thumb' => (string) ( wp_get_attachment_image_url( (int) $id, 'medium' ) ?: $full ),
				'full'  => (string) $full,
			];
		}

		return [
			'id'        => (int) $comment->comment_ID,
			'rating'    => (int) get_comment_meta( $comment->comment_ID, 'rating', true ),
			'text'      => (string) $comment->comment_content,
			'name'      => (string) $comment->comment_author,
			'date'      => mysql2date( get_option( 'date_format' ), $comment->comment_date ),
			'date_gmt'  => (string) $comment->comment_date_gmt,
			'published' => '1' === (string) $comment->comment_approved,
			'photos'    => $photos,
			'order_id'  => (int) get_comment_meta( $comment->comment_ID, self::META_ORDER, true ),
		];
	}

	/**
	 * Published reviews worth showing (a written review or at least one
	 * photo), newest first. `$for` narrows to one template or print
	 * ("template:12"); empty means store-wide. Only order reviews made
	 * here count — older WooCommerce reviews without text are skipped by
	 * the same text-or-photo rule.
	 *
	 * @return array<int, array> format() rows.
	 */
	public static function published( string $for = '', int $limit = 6 ): array {
		$args = [
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => $limit * 3,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		];
		if ( '' !== $for ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => self::META_FOR,
					'value' => $for,
				],
			];
		}

		$rows = [];
		foreach ( get_comments( $args ) as $comment ) {
			$row = self::format( $comment );
			if ( '' === trim( $row['text'] ) && ! $row['photos'] ) {
				continue;
			}
			// Only the first name is shown publicly ("Jane D." -> "Jane").
			$row['name'] = self::first_name( $row['name'] );
			$rows[]      = $row;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}

	public static function first_name( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		return $parts && '' !== $parts[0] ? $parts[0] : __( 'Customer', 'yeffoprint-core' );
	}

	/**
	 * Average and count for every template and print at once, keyed by
	 * "template:12" / "print:34" — one query per page load, so the shop
	 * grid's stars don't cost a query per card.
	 *
	 * @return array<string, array{average:float, count:int}>
	 */
	public static function all_summaries(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.meta_value AS target, AVG( CAST( r.meta_value AS DECIMAL(3,2) ) ) AS avg_rating, COUNT(*) AS total
			FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->commentmeta} r ON r.comment_id = c.comment_ID AND r.meta_key = 'rating'
			INNER JOIN {$wpdb->commentmeta} f ON f.comment_id = c.comment_ID AND f.meta_key = %s
			WHERE c.comment_type = 'review' AND c.comment_approved = '1' AND CAST( r.meta_value AS UNSIGNED ) BETWEEN 1 AND 5
			GROUP BY f.meta_value",
			self::META_FOR
		) );

		$cache = [];
		foreach ( (array) $rows as $row ) {
			$cache[ (string) $row->target ] = [
				'average' => round( (float) $row->avg_rating, 1 ),
				'count'   => (int) $row->total,
			];
		}
		return $cache;
	}

	/** @return array{average:float, count:int} */
	public static function summary_for( string $for ): array {
		return self::all_summaries()[ $for ] ?? [ 'average' => 0.0, 'count' => 0 ];
	}

	/**
	 * Average and count of published, rated reviews — store-wide or for
	 * one template/print.
	 *
	 * @return array{average:float, count:int}
	 */
	public static function summary( string $for = '' ): array {
		global $wpdb;

		$sql = "SELECT AVG( CAST( r.meta_value AS DECIMAL(3,2) ) ) AS avg_rating, COUNT(*) AS total
			FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->commentmeta} r ON r.comment_id = c.comment_ID AND r.meta_key = 'rating'";
		$params = [];
		if ( '' !== $for ) {
			$sql     .= " INNER JOIN {$wpdb->commentmeta} f ON f.comment_id = c.comment_ID AND f.meta_key = %s AND f.meta_value = %s";
			$params[] = self::META_FOR;
			$params[] = $for;
		}
		$sql .= " WHERE c.comment_type = 'review' AND c.comment_approved = '1' AND CAST( r.meta_value AS UNSIGNED ) BETWEEN 1 AND 5";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only present when $params is non-empty.
		$row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_row( $sql );

		return [
			'average' => $row && $row->total ? round( (float) $row->avg_rating, 1 ) : 0.0,
			'count'   => $row ? (int) $row->total : 0,
		];
	}

	/* ---------- Delivered email ---------- */

	/**
	 * "How did we do?" card at the top of the Delivered (Completed)
	 * email, above the order table: five stars that each open the form
	 * with that rating already picked, plus a plain button.
	 */
	public function render_email_block( $order, $sent_to_admin, $plain_text, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! is_object( $email ) || 'customer_completed_order' !== ( $email->id ?? '' ) ) {
			return;
		}
		if ( ! self::settings()['enabled'] || ! self::is_reviewable( $order ) || self::get_review( $order ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'HOW DID WE DO?', 'yeffoprint-core' ) . "\n";
			echo esc_html__( 'Leave a quick review (photos welcome):', 'yeffoprint-core' ) . "\n";
			echo esc_url_raw( self::review_url( $order ) ) . "\n\n";
			return;
		}

		$stars = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$stars .= sprintf(
				'<a class="yp-review-cta-star" href="%1$s" title="%2$s">&#9733;</a>',
				esc_url( self::review_url( $order, $i ) ),
				/* translators: %d: star rating */
				esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'yeffoprint-core' ), $i ) )
			);
		}
		?>
		<table class="yp-review-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
			<tr><td>
				<span class="yp-review-cta-label"><?php esc_html_e( 'How did we do?', 'yeffoprint-core' ); ?></span>
				<span class="yp-review-cta-title"><?php esc_html_e( 'Tap a star to rate your order', 'yeffoprint-core' ); ?></span>
				<div class="yp-review-cta-stars"><?php echo $stars; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></div>
				<a class="yp-review-cta-button" href="<?php echo esc_url( self::review_url( $order ) ); ?>"><?php esc_html_e( 'Leave a quick review →', 'yeffoprint-core' ); ?></a>
				<span class="yp-review-cta-sub"><?php esc_html_e( 'Takes a minute. Add a photo of your labels if you like, we love seeing them!', 'yeffoprint-core' ); ?></span>
			</td></tr>
		</table>
		<?php
	}

	/* ---------- My Account ---------- */

	public function account_order_action( array $actions, \WC_Order $order ): array {
		if ( self::settings()['enabled'] && self::is_reviewable( $order ) && ! self::get_review( $order ) ) {
			$actions['yp-review'] = [
				'url'  => self::review_url( $order ),
				'name' => __( 'Review', 'yeffoprint-core' ),
			];
		}
		return $actions;
	}

	/** On My Account → View order: a nudge to review, or the stars they gave. */
	public function render_account_prompt( $order ): void {
		if ( ! $order instanceof \WC_Order || ! is_account_page() || ! self::is_reviewable( $order ) ) {
			return;
		}

		$review = self::get_review( $order );
		if ( $review ) {
			$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			printf(
				'<p class="yp-account-review yp-account-review--done">%1$s <span aria-hidden="true">%2$s</span></p>',
				esc_html__( 'You reviewed this order. Thank you!', 'yeffoprint-core' ),
				esc_html( str_repeat( '★', max( 0, min( 5, $rating ) ) ) )
			);
			return;
		}

		if ( ! self::settings()['enabled'] ) {
			return;
		}

		printf(
			'<p class="yp-account-review"><span>%1$s</span> <a class="wp-block-button__link is-style-accent" href="%2$s">%3$s</a></p>',
			esc_html__( 'How did we do? Your order has been delivered.', 'yeffoprint-core' ),
			esc_url( self::review_url( $order ) ),
			esc_html__( 'Leave a review', 'yeffoprint-core' )
		);
	}
}
