<?php
/**
 * Contract every carrier's live-tracking client implements — one method,
 * because that's all the tracking page actually needs from a carrier:
 * turn a tracking number into a normalized event timeline.
 *
 * Kept as a real interface (not just "any object with this method") so
 * a third carrier — this store only ships USPS/UPS today, but the
 * tracking page and the registry below don't otherwise care which
 * carrier they're talking to — has a single, explicit shape to match.
 */

defined( 'ABSPATH' ) || exit;

interface YeffoPrint_Tracking_Provider {

	/**
	 * @return array{status:string,description:string,location:string,timestamp:string}[]
	 *   Newest event first. Throws YeffoPrint_Tracking_Exception on any
	 *   failure (missing/invalid credentials, the carrier's API being
	 *   down, an unrecognized tracking number, a network error) — the
	 *   caller (class-order-tracking-controller.php) always has the
	 *   carrier's own direct tracking link to fall back to, so a thrown
	 *   exception here is never fatal to the page, just to the live
	 *   in-page timeline.
	 */
	public function get_events( string $tracking_number ): array;

	/** Whether this provider has what it needs (API credentials) to actually attempt a lookup. */
	public function is_configured(): bool;
}
