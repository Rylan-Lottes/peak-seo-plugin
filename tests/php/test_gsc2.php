<?php
// Phase 2 tests: pull job + snapshot store, with a fake client and an in-memory $wpdb.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { public $c, $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function number_format_i18n( $n ) { return number_format( $n ); }
$OPT = array(); $TR = array(); $CRON = array();
function get_option( $k, $d = false ) { global $OPT; return array_key_exists( $k, $OPT ) ? $OPT[ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $OPT; $OPT[ $k ] = $v; return true; }
function delete_option( $k ) { global $OPT; unset( $OPT[ $k ] ); return true; }
function get_transient( $k ) { global $TR; return $TR[ $k ] ?? false; }
function set_transient( $k, $v, $t ) { global $TR; $TR[ $k ] = $v; return true; }
function delete_transient( $k ) { global $TR; unset( $TR[ $k ] ); return true; }
function wp_next_scheduled( $h ) { global $CRON; return $CRON[ $h ]['at'] ?? false; }
function wp_schedule_event( $t, $r, $h ) { global $CRON; $CRON[ $h ] = array( 'at' => $t, 'every' => $r ); return true; }
function wp_clear_scheduled_hook( $h ) { global $CRON; unset( $CRON[ $h ] ); return 0; }
$DBDELTA = array();
// dbDelta lives in wp-admin/includes/upgrade.php: provide that file for the require.
@mkdir( __DIR__ . '/wp-admin/includes', 0777, true );
file_put_contents( __DIR__ . '/wp-admin/includes/upgrade.php', '<?php function dbDelta( $q ) { global $DBDELTA; $DBDELTA[] = $q; return array(); }' );

/** Just enough of $wpdb for Peak_SEO_GSC_Store. */
class FakeWpdb {
	public $prefix = 'wp_', $insert_id = 0, $t = array(), $fail_insert = false;
	function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	function insert( $table, $data ) { if ( $this->fail_insert ) { return false; } $this->insert_id = count( $this->t[ $table ] ?? array() ) + 1 + ( $this->ids[ $table ] ?? 0 ); $this->ids[ $table ] = ( $this->ids[ $table ] ?? 0 ); $data['id'] = $this->next( $table ); $this->t[ $table ][ $data['id'] ] = $data; $this->insert_id = $data['id']; return 1; }
	private $seq = array();
	private function next( $table ) { $this->seq[ $table ] = ( $this->seq[ $table ] ?? 0 ) + 1; return $this->seq[ $table ]; }
	function prepare( $q, ...$a ) { $i = 0; return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return '%d' === $m[0] ? (int) $v : "'" . addslashes( $v ) . "'"; }, $q ); }
	private function rows( $t ) { return array_values( $this->t[ $t ] ?? array() ); }
	function get_col( $q ) { if ( preg_match( "/SELECT id FROM (\\w+) WHERE pulled_at < '([^']+)'/", $q, $m ) ) { return array_column( array_filter( $this->rows( $m[1] ), function ( $r ) use ( $m ) { return $r['pulled_at'] < $m[2]; } ), 'id' ); } throw new Exception( "get_col: $q" ); }
	function query( $q ) {
		if ( preg_match( '/DELETE FROM (\\w+) WHERE (pull_id|id) IN \\(([\\d,]+)\\)/', $q, $m ) ) { $ids = explode( ',', $m[3] ); foreach ( $this->t[ $m[1] ] ?? array() as $k => $r ) { if ( in_array( (string) $r[ $m[2] ], $ids, true ) ) { unset( $this->t[ $m[1] ][ $k ] ); } } return 1; }
		if ( preg_match( '/DROP TABLE IF EXISTS (\\w+)/', $q, $m ) ) { unset( $this->t[ $m[1] ] ); return 1; }
		throw new Exception( "query: $q" );
	}
	function get_row( $q, $o ) {
		if ( preg_match( "/FROM (\\w+) WHERE property = '([^']*)'( AND status = 'ok')? ORDER BY pulled_at DESC, id DESC LIMIT 1/", $q, $m ) ) {
			$r = array_filter( $this->rows( $m[1] ), function ( $r ) use ( $m ) { return $r['property'] === stripslashes( $m[2] ) && ( empty( $m[3] ) || 'ok' === $r['status'] ); } );
			usort( $r, function ( $a, $b ) { return array( $b['pulled_at'], $b['id'] ) <=> array( $a['pulled_at'], $a['id'] ); } );
			return $r ? $r[0] : null;
		}
		if ( preg_match( "/COUNT\\(\\*\\) AS n, MIN\\(pulled_at\\) AS oldest FROM (\\w+) WHERE property = '([^']*)' AND status = 'ok'/", $q, $m ) ) {
			$r = array_filter( $this->rows( $m[1] ), function ( $r ) use ( $m ) { return $r['property'] === $m[2] && 'ok' === $r['status']; } );
			return array( 'n' => count( $r ), 'oldest' => $r ? min( array_column( $r, 'pulled_at' ) ) : null );
		}
		throw new Exception( "get_row: $q" );
	}
	function get_var( $q ) {
		if ( preg_match( "/SUM\\(LENGTH\\(d.data\\)\\).*FROM (\\w+) d JOIN (\\w+) p .*property = '([^']*)'/", $q, $m ) ) {
			$ids = array_column( array_filter( $this->rows( $m[2] ), function ( $r ) use ( $m ) { return $r['property'] === $m[3]; } ), 'id' );
			$s   = 0; foreach ( $this->rows( $m[1] ) as $d ) { if ( in_array( $d['pull_id'], $ids, true ) ) { $s += strlen( $d['data'] ); } } return $s;
		}
		throw new Exception( "get_var: $q" );
	}
	function get_results( $q, $o ) {
		if ( preg_match( '/FROM (\\w+) WHERE pull_id = (\\d+)/', $q, $m ) ) { return array_values( array_filter( $this->rows( $m[1] ), function ( $r ) use ( $m ) { return (int) $r['pull_id'] === (int) $m[2]; } ) ); }
		throw new Exception( "get_results: $q" );
	}
}
$wpdb = new FakeWpdb();

/** Fake Search Console client: answers per (period by startDate, dimensions). */
class FakeClient {
	public $calls = array(), $fail_on = null, $big = false;
	function query( $prop, $body ) {
		$this->calls[] = $body;
		$dims = implode( '+', $body['dimensions'] ?? array() );
		if ( $this->fail_on === $dims ) { return new WP_Error( 'peak_gsc_quota', 'Search Console’s usage limit was reached.' ); }
		$prev = '2026-09-15' === $body['startDate'];
		if ( '' === $dims ) { return array( array( 'clicks' => $prev ? 3 : 1, 'impressions' => $prev ? 4100 : 5027, 'ctr' => 0.000198926, 'position' => 18.3456 ) ); }
		$n    = $this->big ? 1000 : ( 'query+page' === $dims ? 3 : 2 );
		$rows = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$keys   = 'query' === $dims ? array( "plumber $i" ) : ( 'page' === $dims ? array( "https://peakvisibility.net/p$i/" ) : array( "plumber $i", "https://peakvisibility.net/p$i/" ) );
			$rows[] = array( 'keys' => $keys, 'clicks' => $i, 'impressions' => 100 * ( $i + 1 ), 'ctr' => 0.0123456, 'position' => 7.891 );
		}
		return $rows;
	}
}

$dir = $argv[1];
foreach ( array( 'class-crypto.php', 'gsc/interface-gsc-credentials.php', 'gsc/class-gsc-service-account.php', 'gsc/class-gsc-client.php', 'gsc/class-gsc.php', 'gsc/class-gsc-store.php', 'gsc/class-gsc-pull.php' ) as $f ) {
	require $dir . '/' . $f;
}
$fails = 0;
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }
$NOW = strtotime( '2026-11-12 15:00:00 UTC' );
$S   = 'Peak_SEO_GSC_Store';
$P   = 'Peak_SEO_GSC_Pull';

// ---- Install ------------------------------------------------------------------------
$S::maybe_install();
ok( 2 === count( $DBDELTA ) && false !== strpos( $DBDELTA[0], 'CREATE TABLE wp_peak_seo_gsc_pulls (' ) && false !== strpos( $DBDELTA[1], 'CREATE TABLE wp_peak_seo_gsc_data (' ), 'install: both tables via dbDelta' );
ok( false !== strpos( $DBDELTA[0], 'PRIMARY KEY  (id)' ) && false !== strpos( $DBDELTA[1], 'data longtext NOT NULL' ), 'dbDelta format: two spaces after PRIMARY KEY, longtext data' );
ok( '1' === get_option( 'peak_seo_db_version' ), 'db version recorded' );
$S::maybe_install();
ok( 2 === count( $DBDELTA ), 'maybe_install does nothing when up to date' );

// ---- Compact rows -------------------------------------------------------------------
$c = $S::compact( array( 'keys' => array( 'a', 'b' ), 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.0750049, 'position' => 4.56789 ) );
ok( $c === array( array( 'a', 'b' ), 3, 40, 0.075, 4.57 ), 'compact row: keys, ints, rounded ctr/position' );
ok( $S::expand( $c ) === array( 'keys' => array( 'a', 'b' ), 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.075, 'position' => 4.57 ), 'expand round trip' );

// ---- Scheduling ---------------------------------------------------------------------
$P::schedule();
ok( ! wp_next_scheduled( $P::HOOK ), 'not configured → no weekly event' );
$OPT['peak_seo_gsc_key'] = 'pkv1:x'; $OPT['peak_seo_gsc_property'] = 'sc-domain:peakvisibility.net';
$P::schedule();
ok( wp_next_scheduled( $P::HOOK ) && 'weekly' === $CRON[ $P::HOOK ]['every'], 'configured → weekly event' );
$before = $CRON[ $P::HOOK ]['at'];
$P::schedule();
ok( $before === $CRON[ $P::HOOK ]['at'], 'not scheduled twice' );
unset( $OPT['peak_seo_gsc_key'] );
$P::schedule();
ok( ! wp_next_scheduled( $P::HOOK ), 'key removed → weekly event removed' );
$OPT['peak_seo_gsc_key'] = 'pkv1:x';

// ---- A good pull --------------------------------------------------------------------
$client = new FakeClient();
$r      = $P::run( 'manual', $client, $NOW );
ok( is_array( $r ) && $r['pull_id'] > 0, 'pull saved: ' . ( is_array( $r ) ? $r['message'] : $r->m ) );
ok( 8 === count( $client->calls ), '8 requests: 4 views × 2 periods' );
$cur = array_values( array_filter( $client->calls, function ( $b ) { return '2026-10-13' === $b['startDate']; } ) );
$prv = array_values( array_filter( $client->calls, function ( $b ) { return '2026-09-15' === $b['startDate'] && '2026-10-12' === $b['endDate']; } ) );
ok( 4 === count( $cur ) && '2026-11-09' === $cur[0]['endDate'] && 4 === count( $prv ), 'ranges: last 28 days ending 3 days ago, and the 28 before' );
ok( array( null, array( 'query' ), array( 'page' ), array( 'query', 'page' ) ) === array_map( function ( $b ) { return $b['dimensions'] ?? null; }, $cur ), 'datasets: totals, query, page, query+page' );
ok( 1 === $cur[0]['rowLimit'] && 1000 === $cur[1]['rowLimit'] && 1000 === $cur[3]['rowLimit'] && 'web' === $cur[1]['type'], 'rowLimit 1000 (1 for totals), web search' );
ok( false !== strpos( $r['message'], '5,027 impressions' ) && false !== strpos( $r['message'], '2 queries, 2 pages, 3 query+page pairs' ), 'summary message' );
$last = $S::latest( 'sc-domain:peakvisibility.net' );
ok( 'ok' === $last['status'] && 'manual' === $last['source'] && '2026-11-12 15:00:00' === $last['pulled_at'] && '2026-10-13' === $last['current_start'] && '2026-09-15' === $last['previous_start'], 'pull row: status, source, time, ranges' );
$d = $S::data( $r['pull_id'] );
ok( array( 'current', 'previous' ) === array_keys( $d ) && array( 'totals', 'query', 'page', 'query_page' ) === array_keys( $d['current'] ), 'data: both periods, four datasets' );
ok( 5027 === $d['current']['totals']['rows'][0]['impressions'] && 4100 === $d['previous']['totals']['rows'][0]['impressions'], 'totals stored per period' );
ok( array( 'plumber 1', 'https://peakvisibility.net/p1/' ) === $d['current']['query_page']['rows'][1]['keys'] && 7.89 === $d['current']['query_page']['rows'][1]['position'], 'query+page rows keep both keys' );
ok( false === $d['current']['query']['truncated'], 'small result not marked truncated' );
ok( empty( $TR[ $P::LOCK ] ), 'lock released' );

// ---- Truncation, stats ----------------------------------------------------------------
$big = new FakeClient(); $big->big = true;
$r2  = $P::run( 'cron', $big, $NOW + WEEK_IN_SECONDS );
$d2  = $S::data( $r2['pull_id'] );
ok( true === $d2['current']['query']['truncated'] && 1000 === count( $d2['current']['query']['rows'] ), '1000 rows → marked truncated (more exist)' );
$st = $S::stats( 'sc-domain:peakvisibility.net' );
ok( 2 === $st['pulls'] && '2026-11-12 15:00:00' === $st['oldest'] && $st['bytes'] > 50000, 'stats: 2 snapshots, oldest, size ' . $st['bytes'] . ' bytes' );
ok( 'cron' === $S::latest( 'sc-domain:peakvisibility.net' )['source'], 'latest = newest pull' );

// ---- Failure is all-or-nothing --------------------------------------------------------
$bad = new FakeClient(); $bad->fail_on = 'page';
$dataRows = count( $wpdb->t['wp_peak_seo_gsc_data'] );
$r3  = $P::run( 'cron', $bad, $NOW + 2 * WEEK_IN_SECONDS );
ok( is_wp_error( $r3 ) && false !== strpos( $r3->m, 'Pulling page for the current 28 days failed' ), 'failure message names the step: ' . $r3->m );
ok( count( $wpdb->t['wp_peak_seo_gsc_data'] ) === $dataRows, 'failed pull stores no partial data' );
$lf = $S::latest( 'sc-domain:peakvisibility.net' );
ok( 'error' === $lf['status'] && $S::latest( 'sc-domain:peakvisibility.net', true )['id'] === $r2['pull_id'], 'failure recorded; latest OK pull is still the previous one' );
ok( empty( $TR[ $P::LOCK ] ), 'lock released after failure' );

// ---- Lock -----------------------------------------------------------------------------
set_transient( $P::LOCK, 1, 600 );
$c4 = new FakeClient();
$r4 = $P::run( 'manual', $c4, $NOW );
ok( is_wp_error( $r4 ) && 'peak_gsc_busy' === $r4->c && 0 === count( $c4->calls ), 'second pull while one runs: refused, no requests' );
delete_transient( $P::LOCK );

// ---- Client error (e.g. unreadable key) ---------------------------------------------
$r5 = $P::run( 'cron', null, $NOW + 3 * WEEK_IN_SECONDS ); // Real Peak_SEO_GSC::client(): key "pkv1:x" can't be decrypted.
ok( is_wp_error( $r5 ) && 'error' === $S::latest( 'sc-domain:peakvisibility.net' )['status'], 'broken key: failure recorded, nothing sent: ' . $r5->m );

// ---- Retention ------------------------------------------------------------------------
$wpdb->fail_insert = false;
$old = $S::save_pull( array( 'property' => 'sc-domain:peakvisibility.net', 'pulled_at' => '2024-11-01 00:00:00', 'source' => 'cron', 'status' => 'ok', 'message' => 'old' ), array( 'current' => array( 'totals' => array( 'rows' => array( array( array(), 1, 2, 0.5, 3 ) ), 'truncated' => false ) ) ) );
$keep = $S::save_pull( array( 'property' => 'sc-domain:peakvisibility.net', 'pulled_at' => '2024-11-20 00:00:00', 'source' => 'cron', 'status' => 'ok', 'message' => 'keep' ) );
$n    = $S::prune( $NOW );
ok( 1 === $n && ! isset( $wpdb->t['wp_peak_seo_gsc_pulls'][ $old ] ) && isset( $wpdb->t['wp_peak_seo_gsc_pulls'][ $keep ] ), 'prune: older than 2 years deleted, newer kept' );
ok( ! array_filter( $wpdb->t['wp_peak_seo_gsc_data'], function ( $d ) use ( $old ) { return $d['pull_id'] === $old; } ), 'prune: its data deleted too' );

// ---- Save failure -------------------------------------------------------------------
$wpdb->fail_insert = true;
$r6 = $P::run( 'manual', new FakeClient(), $NOW );
ok( is_wp_error( $r6 ) && 'peak_gsc_save' === $r6->c, 'database insert failure reported' );
$wpdb->fail_insert = false;

// ---- Drop ---------------------------------------------------------------------------
$S::drop();
ok( ! isset( $wpdb->t['wp_peak_seo_gsc_pulls'] ) && ! isset( $wpdb->t['wp_peak_seo_gsc_data'] ) && false === get_option( 'peak_seo_db_version' ), 'drop: tables and version option removed' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
