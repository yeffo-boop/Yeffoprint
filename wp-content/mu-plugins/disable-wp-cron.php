<?php
/**
 * Disables WordPress's default page-load-triggered cron spawning —
 * direct concern: "I'm concerned the site is getting a bit slower with
 * these new features being added." No dedicated system cron runs
 * wp-cron.php on this self-hosted server — only the separate git-pull
 * deploy cron (deploy/pull-and-deploy.sh, unrelated) — so WP-Cron has
 * been running in its default mode: any visitor's page load past a
 * scheduled job's due time spawns a loopback HTTP request that ties up
 * a full PHP worker for however long that job takes. Two hourly sweeps
 * run today (class-proof-reminder-scheduler.php,
 * class-order-delivery-status.php) — the delivery-tracking one makes a
 * real outbound HTTP call per shipped order, the slowest job on this
 * site, and on a small self-hosted worker pool that's genuine
 * contention with real visitor requests, not just theoretical.
 *
 * A must-use plugin, not a wp-config.php constant — wp-config.php is
 * deliberately untracked (.gitignore, holds DB credentials), so it
 * can't carry a change like this through the normal git-pull deploy
 * the rest of this repo relies on. mu-plugins load early enough in
 * WordPress's own bootstrap (well before the `init` hook that would
 * otherwise spawn a cron run) for this constant to take effect exactly
 * as if it were set in wp-config.php.
 *
 * This alone does not run scheduled jobs anymore — it only stops them
 * running on a visitor's dime. Something now has to trigger
 * wp-cron.php on a real schedule instead: see docs/deploy-setup.md's
 * "Move WP-Cron off page loads" step for the crontab line — that part
 * has to be added directly on the server, outside this repo, same as
 * the deploy cron itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
