<?php
/**
 * Search Console snapshots, kept in two custom tables so history builds up
 * beyond Google's 16 months:
 *
 *   {prefix}peak_seo_gsc_pulls  one row per pull (when, which property, the
 *                               date ranges, ok/error and a message)
 *   {prefix}peak_seo_gsc_data   the rows Google returned: one record per
 *                               pull × period (current|previous) × dataset
 *                               (totals|query|page|query_page), as JSON
 *
 * Each data row is stored compactly as [ keys[], clicks, impressions, ctr, position ].
 * Pulls older than two years are deleted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_GSC_Store {

	const DB_VERSION     = '1';
	const OPT_DB_VERSION = 'peak_seo_db_version';
	const RETENTION_DAYS = 730;

	public static function pulls_table() {
		global $wpdb;
		return $wpdb->prefix . 'peak_seo_gsc_pulls';
	}

	public static function data_table() {
		global $wpdb;
		return $wpdb->prefix . 'peak_seo_gsc_data';
	}

	/* ------------------------------------------------------------------ */
	/* Install / upgrade / remove                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Runs on every load: replacing the plugin zip doesn't fire activation.
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VERSION ) ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$pulls   = self::pulls_table();
		$data    = self::data_table();

		dbDelta(
			"CREATE TABLE {$pulls} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property varchar(255) NOT NULL DEFAULT '',
  pulled_at datetime NOT NULL,
  source varchar(10) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT '',
  message text NOT NULL,
  current_start date DEFAULT NULL,
  current_end date DEFAULT NULL,
  previous_start date DEFAULT NULL,
  previous_end date DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY property_pulled (property(191),pulled_at)
) {$collate};"
		);
		dbDelta(
			"CREATE TABLE {$data} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pull_id bigint(20) unsigned NOT NULL,
  period varchar(10) NOT NULL DEFAULT '',
  dataset varchar(12) NOT NULL DEFAULT '',
  row_count int(10) unsigned NOT NULL DEFAULT 0,
  truncated tinyint(1) NOT NULL DEFAULT 0,
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY pull_id (pull_id)
) {$collate};"
		);
		update_option( self::OPT_DB_VERSION, self::DB_VERSION, true ); // Autoloaded: checked on every load.
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::data_table() );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::pulls_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		delete_option( self::OPT_DB_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $pull     property, pulled_at (Y-m-d H:i:s UTC), source, status, message, ranges.
	 * @param array $datasets [ period => [ dataset => [ 'rows' => [...], 'truncated' => bool ] ] ].
	 * @return int|false Pull id.
	 */
	public static function save_pull( array $pull, array $datasets = array() ) {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::pulls_table(),
			array(
				'property'       => (string) $pull['property'],
				'pulled_at'      => (string) $pull['pulled_at'],
				'source'         => (string) $pull['source'],
				'status'         => (string) $pull['status'],
				'message'        => (string) $pull['message'],
				'current_start'  => $pull['current_start'] ?? null,
				'current_end'    => $pull['current_end'] ?? null,
				'previous_start' => $pull['previous_start'] ?? null,
				'previous_end'   => $pull['previous_end'] ?? null,
			)
		);
		if ( ! $ok ) {
			return false;
		}
		$id = (int) $wpdb->insert_id;
		foreach ( $datasets as $period => $sets ) {
			foreach ( $sets as $name => $set ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::data_table(),
					array(
						'pull_id'   => $id,
						'period'    => (string) $period,
						'dataset'   => (string) $name,
						'row_count' => count( $set['rows'] ),
						'truncated' => empty( $set['truncated'] ) ? 0 : 1,
						'data'      => wp_json_encode( $set['rows'] ),
					)
				);
			}
		}
		return $id;
	}

	/**
	 * Delete pulls (and their data) older than the retention period.
	 *
	 * @return int Pulls deleted.
	 */
	public static function prune( $now = null ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', ( null === $now ? time() : (int) $now ) - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$pulls  = self::pulls_table();
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$pulls} WHERE pulled_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $ids ) {
			return 0;
		}
		$ids  = array_map( 'intval', $ids );
		$list = implode( ',', $ids );
		$wpdb->query( 'DELETE FROM ' . self::data_table() . " WHERE pull_id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$pulls} WHERE id IN ({$list})" );                     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return count( $ids );
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Most recent pull for a property (any status, or only 'ok').
	 *
	 * @return array|null
	 */
	public static function latest( $property, $only_ok = false ) {
		global $wpdb;
		$pulls = self::pulls_table();
		$sql   = $only_ok
			? $wpdb->prepare( "SELECT * FROM {$pulls} WHERE property = %s AND status = 'ok' ORDER BY pulled_at DESC, id DESC LIMIT 1", $property ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT * FROM {$pulls} WHERE property = %s ORDER BY pulled_at DESC, id DESC LIMIT 1", $property );                   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/**
	 * A pull's data: [ period => [ dataset => [ 'rows' => [...], 'truncated' => bool ] ] ].
	 * Rows are expanded to [ 'keys' => [...], 'clicks', 'impressions', 'ctr', 'position' ].
	 */
	public static function data( $pull_id ) {
		global $wpdb;
		$table = self::data_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT period, dataset, truncated, data FROM {$table} WHERE pull_id = %d", (int) $pull_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$list = json_decode( (string) $r['data'], true );
			$out[ $r['period'] ][ $r['dataset'] ] = array(
				'rows'      => array_map( array( __CLASS__, 'expand' ), is_array( $list ) ? $list : array() ),
				'truncated' => (bool) $r['truncated'],
			);
		}
		return $out;
	}

	/**
	 * Count, oldest pull, total size of stored data.
	 */
	public static function stats( $property ) {
		global $wpdb;
		$pulls = self::pulls_table();
		$data  = self::data_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MIN(pulled_at) AS oldest FROM {$pulls} WHERE property = %s AND status = 'ok'", $property ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$bytes = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(d.data)),0) FROM {$data} d JOIN {$pulls} p ON p.id = d.pull_id WHERE p.property = %s", $property ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array(
			'pulls'  => (int) ( $row['n'] ?? 0 ),
			'oldest' => $row['oldest'] ?? null,
			'bytes'  => (int) $bytes,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Google row → compact [ keys[], clicks, impressions, ctr, position ].
	 */
	public static function compact( array $row ) {
		return array(
			array_map( 'strval', (array) ( $row['keys'] ?? array() ) ),
			(int) ( $row['clicks'] ?? 0 ),
			(int) ( $row['impressions'] ?? 0 ),
			round( (float) ( $row['ctr'] ?? 0 ), 4 ),
			round( (float) ( $row['position'] ?? 0 ), 2 ),
		);
	}

	public static function expand( $c ) {
		return array(
			'keys'        => (array) ( $c[0] ?? array() ),
			'clicks'      => (int) ( $c[1] ?? 0 ),
			'impressions' => (int) ( $c[2] ?? 0 ),
			'ctr'         => (float) ( $c[3] ?? 0 ),
			'position'    => (float) ( $c[4] ?? 0 ),
		);
	}
}
