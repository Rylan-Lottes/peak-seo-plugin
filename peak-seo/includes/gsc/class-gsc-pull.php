<?php
/**
 * The weekly Search Console pull (and the "Pull now" button).
 *
 * One pull = the last 28 days (ending 3 days ago, since Search Console data
 * lags) and the 28 days before that, each as: totals, top queries, top
 * pages, and query+page pairs (up to 1000 rows each). Saved as a snapshot
 * only if every request succeeds; otherwise the failure is recorded.
 *
 * The weekly event exists only while Search Console is configured.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_GSC_Pull {

	const HOOK      = 'peak_seo_gsc_weekly_pull';
	const LOCK      = 'peak_seo_gsc_pull_lock';
	const ROW_LIMIT = 1000;

	/** Dataset name => dimensions. */
	const DATASETS = array(
		'totals'     => array(),
		'query'      => array( 'query' ),
		'page'       => array( 'page' ),
		'query_page' => array( 'query', 'page' ),
	);

	/* ------------------------------------------------------------------ */
	/* Scheduling                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Keep the weekly event in step with the settings (runs on init).
	 */
	public static function schedule() {
		$next = wp_next_scheduled( self::HOOK );
		if ( Peak_SEO_GSC::configured() ) {
			if ( ! $next ) {
				wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'weekly', self::HOOK );
			}
		} elseif ( $next ) {
			self::unschedule();
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function cron() {
		if ( Peak_SEO_GSC::configured() ) {
			self::run( 'cron' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The pull                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string                   $source 'cron' or 'manual'.
	 * @param Peak_SEO_GSC_Client|null $client For tests.
	 * @param int|null                 $now    For tests.
	 * @return array|WP_Error Summary: pull_id, message, counts.
	 */
	public static function run( $source, $client = null, $now = null ) {
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'peak_gsc_busy', 'A pull is already running. Wait a minute and refresh.' );
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		try {
			return self::pull( $source, $client, null === $now ? time() : (int) $now );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	private static function pull( $source, $client, $now ) {
		$property = Peak_SEO_GSC::property();
		$client   = $client ? $client : Peak_SEO_GSC::client();
		$ranges   = array(
			'current'  => Peak_SEO_GSC::date_range( 28, 0, $now ),
			'previous' => Peak_SEO_GSC::date_range( 28, 28, $now ),
		);
		$pull     = array(
			'property'       => $property,
			'pulled_at'      => gmdate( 'Y-m-d H:i:s', $now ),
			'source'         => $source,
			'current_start'  => $ranges['current']['startDate'],
			'current_end'    => $ranges['current']['endDate'],
			'previous_start' => $ranges['previous']['startDate'],
			'previous_end'   => $ranges['previous']['endDate'],
		);

		if ( is_wp_error( $client ) ) {
			return self::fail( $pull, $client->get_error_message() );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- some hosts disable it.
		}

		$datasets = array();
		foreach ( $ranges as $period => $range ) {
			foreach ( self::DATASETS as $name => $dims ) {
				$body = $range + array(
					'type'     => 'web',
					'rowLimit' => $dims ? self::ROW_LIMIT : 1,
				);
				if ( $dims ) {
					$body['dimensions'] = $dims;
				}
				$rows = $client->query( $property, $body );
				if ( is_wp_error( $rows ) ) {
					return self::fail( $pull, sprintf( 'Pulling %s for the %s 28 days failed: %s', str_replace( '_', '+', $name ), $period, $rows->get_error_message() ) );
				}
				$datasets[ $period ][ $name ] = array(
					'rows'      => array_map( array( 'Peak_SEO_GSC_Store', 'compact' ), $rows ),
					'truncated' => $dims && count( $rows ) >= self::ROW_LIMIT,
				);
			}
		}

		$totals          = $datasets['current']['totals']['rows'][0] ?? array( array(), 0, 0, 0, 0 );
		$pull['status']  = 'ok';
		$pull['message'] = sprintf(
			'%s–%s: %s %s, %s %s; %d queries, %d pages, %d query+page pairs.',
			$ranges['current']['startDate'],
			$ranges['current']['endDate'],
			number_format_i18n( $totals[1] ),
			1 === $totals[1] ? 'click' : 'clicks',
			number_format_i18n( $totals[2] ),
			1 === $totals[2] ? 'impression' : 'impressions',
			count( $datasets['current']['query']['rows'] ),
			count( $datasets['current']['page']['rows'] ),
			count( $datasets['current']['query_page']['rows'] )
		);
		$id = Peak_SEO_GSC_Store::save_pull( $pull, $datasets );
		if ( ! $id ) {
			return new WP_Error( 'peak_gsc_save', 'The data was downloaded but could not be saved to the database.' );
		}
		$pruned = Peak_SEO_GSC_Store::prune( $now );

		return array(
			'pull_id' => $id,
			'message' => $pull['message'],
			'pruned'  => $pruned,
		);
	}

	private static function fail( $pull, $message ) {
		$pull['status']  = 'error';
		$pull['message'] = $message;
		Peak_SEO_GSC_Store::save_pull( $pull );
		return new WP_Error( 'peak_gsc_pull_failed', $message );
	}
}
