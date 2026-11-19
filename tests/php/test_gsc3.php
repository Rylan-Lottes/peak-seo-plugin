<?php
// Phase 3 tests: report calculations and REST permission.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $c, $m, $d; function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function number_format_i18n( $n ) { return number_format( $n ); }
$FILTER = null;
function apply_filters( $h, $v ) { global $FILTER; return $FILTER ? $FILTER( $v ) : $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url() { return 'https://peakvisibility.net/'; }
function url_to_postid( $u ) { return '/services/' === parse_url( $u, PHP_URL_PATH ) ? 42 : 0; }
function get_the_title( $id ) { return 42 === $id ? 'Services' : ''; }
$dir = $argv[1];
require $dir . '/gsc/class-gsc-report.php';
$fails = 0;
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }
$R = 'Peak_SEO_GSC_Report';

function row( $keys, $clicks, $impr, $pos ) { return array( 'keys' => (array) $keys, 'clicks' => $clicks, 'impressions' => $impr, 'ctr' => $impr ? $clicks / $impr : 0, 'position' => $pos ); }
function set( $rows, $trunc = false ) { return array( 'rows' => $rows, 'truncated' => $trunc ); }
$U = 'https://peakvisibility.net';
$pull = array( 'id' => 7, 'property' => 'sc-domain:peakvisibility.net', 'pulled_at' => '2026-11-12 21:01:00', 'current_start' => '2026-10-13', 'current_end' => '2026-11-09', 'previous_start' => '2026-09-15', 'previous_end' => '2026-10-12' );
$data = array(
	'current'  => array(
		'totals'     => set( array( row( array(), 40, 5027, 18.3 ) ) ),
		'page'       => set(
			array(
				row( "$U/services/", 2, 900, 6.2 ),     // usual ~3.8% → 0.2%: LOW CTR, maps to post 42
				row( "$U/", 30, 600, 3.1 ),             // 5% vs usual ~10%: 0.5 ratio → just at limit (not flagged)
				row( "$U/blog/a/", 1, 80, 4.0 ),        // too few impressions
				row( "$U/deep/", 0, 2000, 35.0 ),       // position too low for CTR check
				row( "$U/about/", 3, 400, 8.5 ),        // usual ~2.5% → 0.75%: LOW CTR
			)
		),
		'query'      => set(
			array(
				row( 'seo agency huntsville', 1, 300, 8.4 ),    // striking
				row( 'local seo', 0, 120, 14.9 ),               // striking
				row( 'web design', 5, 500, 3.2 ),               // too high for striking
				row( 'peak visibility', 20, 60, 1.1 ),          // top
				row( 'what is schema markup', 0, 220, 38.0 ),   // new content
				row( 'seo pricing', 0, 45, 25.0 ),              // new content (≥30)
				row( 'tiny', 0, 10, 50.0 ),                     // too small
				row( 'rank tracker', 0, 90, 22.0 ),             // query avg 22 but best page 12 → not new
				row( 'seo audit', 0, 40, 9.0 ),                 // striking but < 50 impressions
			)
		),
		'query_page' => set(
			array(
				row( array( 'seo agency huntsville', "$U/services/" ), 1, 250, 8.0 ),
				row( array( 'seo agency huntsville', "$U/" ), 0, 50, 10.0 ),
				row( array( 'local seo', "$U/blog/local-seo/" ), 0, 120, 14.9 ),
				row( array( 'what is schema markup', "$U/blog/a/" ), 0, 220, 38.0 ),
				row( array( 'rank tracker', "$U/tools/" ), 0, 60, 12.0 ),
				row( array( 'rank tracker', "$U/" ), 0, 30, 41.0 ),
				row( array( 'web design', "$U/services/" ), 3, 300, 3.0 ),
				row( array( 'peak visibility', "$U/about/" ), 2, 40, 1.0 ),
			),
			true
		),
	),
	'previous' => array(
		'totals' => set( array( row( array(), 50, 4100, 21.0 ) ) ),
		'page'   => set( array( row( "$U/services/", 5, 800, 12.4 ), row( "$U/about/", 3, 300, 8.0 ) ) ),
		'query'  => set(
			array(
				row( 'seo agency huntsville', 2, 280, 15.0 ),  // 15 → 8.4: up 6.6
				row( 'local seo', 0, 110, 9.0 ),               // 9 → 14.9: down 5.9
				row( 'web design', 5, 480, 4.0 ),              // up 0.8: below min change
				row( 'peak visibility', 15, 20, 1.5 ),          // < 50 impressions before
			)
		),
	),
);
$t   = $R::thresholds();
$rep = $R::compute( $pull, $data, $t );
$s   = $rep['sections'];

ok( $rep['enough_data'] && $rep['truncated'] && 5027 === $rep['totals']['current']['impressions'] && 50 === $rep['totals']['previous']['clicks'], 'header: enough data, truncated flag, totals both periods' );
ok( '2026-11-12T21:01:00+00:00' === $rep['pulled_at'] && array( 'start' => '2026-10-13', 'end' => '2026-11-09' ) === $rep['current'], 'pulled_at ISO, date ranges' );

$p = array_column( $s['low_ctr']['items'], 'page' );
ok( array( "$U/services/", "$U/about/" ) === $p, 'low CTR: services and about, biggest missed clicks first: ' . implode( ', ', $p ) );
$svc = $s['low_ctr']['items'][0];
ok( 42 === $svc['post_id'] && 'Services' === $svc['title'], 'low CTR: URL on this site mapped to its post' );
ok( array( 'web design', 'seo agency huntsville' ) === $svc['top_queries'], 'low CTR: main searches for the page, by impressions' );
ok( $svc['expected_ctr'] > 0.035 && $svc['expected_ctr'] < 0.04 && $svc['missed_clicks'] > 25, sprintf( 'low CTR: usual CTR %.1f%%, ~%d missed clicks', $svc['expected_ctr'] * 100, $svc['missed_clicks'] ) );
ok( ! in_array( "$U/deep/", $p, true ) && ! in_array( "$U/blog/a/", $p, true ) && ! in_array( "$U/", $p, true ), 'low CTR skips: too deep, too few impressions, at the 50% line' );

$g = array_column( $s['striking']['items'], 'page' );
ok( array( "$U/services/", "$U/blog/local-seo/", "$U/tools/" ) === $g, 'strengthen: one entry per page, by impressions: ' . implode( ', ', $g ) );
ok( 42 === $s['striking']['items'][0]['post_id'] && 'seo agency huntsville' === $s['striking']['items'][0]['searches'][0]['query'], 'strengthen: page mapped to its post, with its searches' );
ok( "$U/tools/" === $g[2] && 12.0 === $s['striking']['items'][2]['searches'][0]['position'], 'strengthen: uses the best page’s position (12), not the search average (22)' );

$n = array_column( $s['new_content']['items'], 'query' );
ok( array( 'what is schema markup' ) === $n, 'new pages: only searches nothing covers: ' . implode( ', ', $n ) );
ok( ! in_array( 'seo pricing', $n, true ), 'new pages: a search ranking 25 with the page hidden by Google is left alone' );
ok( 38.0 === $s['new_content']['items'][0]['best_position'] && "$U/blog/a/" === $s['new_content']['items'][0]['best_page'], 'new pages: best position and where it shows up' );

// ---- Real-world shape: peakvisibility.net (quality over quantity) --------------------
$pv = array(
	array( 'peak visibility', 1, 84, 8.1, array( array( "$U/", 8.1 ) ) ),
	array( 'local seo huntsville', 0, 60, 11.6, array( array( "$U/blog-what-is-local-seo", 11.6 ) ) ),
	array( 'huntsville custom web design', 0, 51, 18.9, array( array( "$U/web-design", 18.9 ) ) ),
	array( 'web design alabama', 0, 279, 47.5, array( array( "$U/web-design", 47.5 ) ) ),
	array( 'website design huntsville al', 0, 116, 26.6, array( array( "$U/web-design", 26.6 ) ) ),
	array( 'seo huntsville al', 0, 99, 20.2, array( array( "$U/blog", 20.2 ) ) ),
	array( 'best web designer near me', 0, 43, 20.1, array( array( "$U/web-design", 20.1 ) ) ),
	array( 'local seo benefits for small businesses near me', 0, 42, 29.6, array( array( "$U/blog-what-is-local-seo", 29.6 ) ) ),
	array( 'website design alabama', 0, 42, 48.8, array( array( "$U/web-design", 48.8 ) ) ),
	array( 'hyperlocal seo near me', 0, 41, 23.3, array( array( "$U/blog-what-is-local-seo", 23.3 ) ) ),
	array( 'responsive web design near me', 0, 41, 31.2, array( array( "$U/web-design", 31.2 ) ) ),
	array( 'web design firm near me', 0, 38, 20.4, array( array( "$U/web-design", 20.4 ) ) ),
	array( 'graphic design huntsville', 0, 30, 73.7, array( array( "$U/web-design", 73.7 ) ) ),
);
$q = array(); $qp = array();
foreach ( $pv as $x ) {
	$q[] = row( $x[0], $x[1], $x[2], $x[3] );
	foreach ( $x[4] as $p2 ) {
		$qp[] = row( array( $x[0], $p2[0] ), $x[1], $x[2], $p2[1] );
	}
}
$pvdata = array( 'current' => array( 'totals' => set( array( row( array(), 1, 5027, 26.1 ) ) ), 'query' => set( $q ), 'query_page' => set( $qp ), 'page' => set( array() ) ) );
$pvr    = $R::compute( $pull, $pvdata, $R::thresholds() );
$groups = array();
foreach ( $pvr['sections']['striking']['items'] as $i ) {
	$groups[ parse_url( $i['page'], PHP_URL_PATH ) ] = array_column( $i['searches'], 'query' );
}
ok( array( '/web-design', '/blog-what-is-local-seo', '/' ) === array_keys( $groups ), 'pv: 3 pages to strengthen: ' . implode( ', ', array_keys( $groups ) ) );
ok( 7 === count( $groups['/web-design'] ) && in_array( 'web design alabama', $groups['/web-design'], true ) && in_array( 'website design alabama', $groups['/web-design'], true ) && in_array( 'responsive web design near me', $groups['/web-design'], true ), 'pv: /web-design gets its 7 web-design searches, incl. weaker ones on topic: ' . implode( '; ', $groups['/web-design'] ) );
ok( 3 === count( $groups['/blog-what-is-local-seo'] ), 'pv: local SEO article gets its 3 local-SEO searches' );
$newq = array_column( $pvr['sections']['new_content']['items'], 'query' );
ok( array( 'seo huntsville al', 'graphic design huntsville' ) === $newq, 'pv: only 2 new-page ideas (was 16): ' . implode( ', ', $newq ) );
ok( "$U/blog" === $pvr['sections']['new_content']['items'][0]['best_page'], 'pv: "seo huntsville al" only shows the blog index → a real service page is the idea' );

// is_listing
foreach ( array( '/blog' => true, '/blog/' => true, '/category/seo/' => true, '/tag/x' => true, '/blog/page/2/' => true, '/blog/local-seo/' => false, '/blog-what-is-local-seo' => false, '/web-design' => false, '/' => false ) as $path => $want ) {
	ok( $want === $R::is_listing( $U . $path ), "listing? $path → " . ( $want ? 'yes' : 'no' ) );
}

$m = array_map( function ( $i ) { return $i['type'] . ':' . $i['key'] . ':' . $i['direction'] . ':' . $i['change']; }, $s['movers']['items'] );
ok( array( 'query:seo agency huntsville:up:6.6', 'page:' . $U . '/services/:up:6.2', 'query:local seo:down:-5.9' ) === $m, 'movers: biggest changes first, queries and pages: ' . implode( ' | ', $m ) );

// Not enough data.
$small = $data; $small['current']['totals'] = set( array( row( array(), 0, 120, 30 ) ) );
$rep2  = $R::compute( $pull, $small, $t );
ok( ! $rep2['enough_data'] && array() === $rep2['sections'] && false !== strpos( $rep2['message'], '120 impressions' ), 'not enough data: message, no lists: ' . $rep2['message'] );

// Thresholds filter + limit.
$FILTER = function ( $v ) { $v['strengthen_min_impressions'] = 100; $v['limit'] = 1; return $v; };
$rep3   = $R::compute( $pull, $data, $R::thresholds() );
ok( 2 === $rep3['sections']['striking']['total'] && 1 === count( $rep3['sections']['striking']['items'] ), 'filter raises a threshold; limit caps items but total counts all' );
$FILTER = function ( $v ) { return 'junk'; };
ok( 300 === $R::thresholds()['min_total_impressions'], 'bad filter value falls back to defaults' );
$FILTER = null;

// Expected CTR curve.
ok( abs( $R::expected_ctr( 1 ) - 0.28 ) < 1e-9 && abs( $R::expected_ctr( 1.5 ) - 0.215 ) < 1e-9 && $R::expected_ctr( 10 ) > $R::expected_ctr( 11 ) && $R::expected_ctr( 80 ) >= 0.003, 'expected CTR: interpolated on page 1, falls off after, never below 0.3%' );

// Empty periods don't break anything.
$rep4 = $R::compute( $pull, array( 'current' => array( 'totals' => set( array( row( array(), 9, 1000, 9 ) ) ) ) ), $t );
ok( $rep4['enough_data'] && 0 === $rep4['sections']['movers']['total'] && 0 === $rep4['totals']['previous']['impressions'], 'missing datasets: empty lists, no errors' );

// ---- REST permission ----------------------------------------------------------------
$CAN = false; $LOGGED = false; $SIGN_OK = true;
function current_user_can( $c ) { global $CAN; return $CAN; }
function is_user_logged_in() { global $LOGGED; return $LOGGED; }
class Peak_SEO_Auth { static function check( $r ) { global $SIGN_OK; return $SIGN_OK ? true : new WP_Error( 'peak_bad_signature', 'Signature does not match.', array( 'status' => 401 ) ); } }
class Req { public $h; function __construct( $h = array() ) { $this->h = $h; } function get_header( $k ) { return $this->h[ $k ] ?? null; } }
require $dir . '/class-rest.php';
$CAN = true;
ok( true === Peak_SEO_Rest::admin_or_signed( new Req() ), 'administrator (e.g. Application Password): allowed' );
$CAN = false;
$r = Peak_SEO_Rest::admin_or_signed( new Req() );
ok( is_wp_error( $r ) && 401 === $r->d['status'], 'anonymous: 401 with how to sign in' );
$LOGGED = true;
$r = Peak_SEO_Rest::admin_or_signed( new Req() );
ok( is_wp_error( $r ) && 403 === $r->d['status'], 'logged in but not an administrator: 403' );
$LOGGED = false;
ok( true === Peak_SEO_Rest::admin_or_signed( new Req( array( 'x_peak_signature' => 'abc' ) ) ), 'signed Peak SEO request: allowed' );
$SIGN_OK = false;
$r = Peak_SEO_Rest::admin_or_signed( new Req( array( 'x_peak_signature' => 'abc' ) ) );
ok( is_wp_error( $r ) && 'peak_bad_signature' === $r->c, 'bad signature: refused' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
