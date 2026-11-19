<?php
// Tests: Peak_SEO_Freshness (touch + sitemap checks).
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $c, $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$OPT = array( 'blog_public' => '1' ); $ACT = array(); $CLEAN = array(); $TRDEL = array();
function get_option( $k, $d = false ) { global $OPT; return array_key_exists( $k, $OPT ) ? $OPT[ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $OPT; $OPT[ $k ] = $v; return true; }
function get_post( $id ) { return in_array( $id, array( 35, 15 ), true ) ? (object) array( 'ID' => $id ) : null; }
function get_post_type( $id ) { return 'page'; }
function current_time( $t, $gmt = false ) { return $gmt ? '2026-11-19 18:00:00' : '2026-11-19 12:00:00'; }
function clean_post_cache( $id ) { global $CLEAN; $CLEAN[] = $id; }
function do_action( $h, ...$a ) { global $ACT; $ACT[] = array( $h, $a ); }
function delete_transient( $k ) { global $TRDEL; $TRDEL[] = $k; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function human_time_diff( $a, $b ) { return round( ( $b - $a ) / DAY_IN_SECONDS ) . ' days'; }
class Peak_SEO_Fields { static $p = 'yoast'; static function provider() { return self::$p; } }
class WPSEO_Sitemaps_Cache { static $hit = array(); static function invalidate_post( $id ) { self::$hit[] = $id; } }
class FakeDb { public $posts = 'wp_posts', $updates = array(); function update( $t, $d, $w ) { $this->updates[] = array( $t, $d, $w ); return 1; } }
$wpdb = new FakeDb();
require $argv[1];
$fails = 0;
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }
$F = 'Peak_SEO_Freshness';
$P = 'sc-domain:example.com';
$NOW = strtotime( '2026-11-19 18:00:00 UTC' );

// ---- touch ----------------------------------------------------------------------------
ok( true === $F::touch( 35 ), 'touch an existing page' );
ok( array( 'wp_posts', array( 'post_modified' => '2026-11-19 12:00:00', 'post_modified_gmt' => '2026-11-19 18:00:00' ), array( 'ID' => 35 ) ) === $wpdb->updates[0], 'only the two date columns change (no re-save of content)' );
ok( array( 35 ) === $CLEAN && array( 35 ) === WPSEO_Sitemaps_Cache::$hit, 'post cache and Yoast sitemap cache cleared' );
ok( array( 'peak_seo_post_touched', array( 35 ) ) === $ACT[0], 'peak_seo_post_touched action fired' );
ok( false === $F::touch( 999 ) && 1 === count( $wpdb->updates ), 'missing post: nothing written' );

// ---- expected sitemap ---------------------------------------------------------------
$m = array( 'yoast' => '/sitemap_index.xml', 'rankmath' => '/sitemap_index.xml', 'seopress' => '/sitemaps.xml', 'none' => '/wp-sitemap.xml' );
foreach ( $m as $prov => $path ) { Peak_SEO_Fields::$p = $prov; ok( 'https://example.com' . $path === $F::expected_sitemap(), "sitemap address for $prov: $path" ); }
Peak_SEO_Fields::$p = 'yoast';

// ---- store sitemaps from Search Console ---------------------------------------------
class C { public $list; function list_sitemaps( $p ) { return $this->list; } }
$c = new C();
$c->list = array( array( 'path' => 'https://example.com/sitemap_index.xml', 'lastSubmitted' => '2026-10-01T10:00:00Z', 'lastDownloaded' => '2026-11-15T08:00:00Z', 'isPending' => false, 'errors' => '0', 'warnings' => '1' ) );
$items = $F::store_sitemaps( $c, $P, $NOW );
ok( 1 === count( $items ) && 0 === $items[0]['errors'] && 1 === $items[0]['warnings'] && '2026-11-15T08:00:00Z' === $items[0]['last_downloaded'], 'sitemaps stored (counts as numbers)' );
ok( null === $F::sitemaps( 'sc-domain:other.com' ), 'stored status belongs to one property' );
$c->list = new WP_Error( 'x', 'quota' );
ok( is_wp_error( $F::store_sitemaps( $c, $P, $NOW ) ) && 1 === count( $F::sitemaps( $P )['items'] ), 'API error: returns the error, keeps the last good status' );

// ---- checks -------------------------------------------------------------------------
$r = $F::checks( $P, $NOW );
ok( 1 === count( $r ) && 'ok' === $r[0]['level'] && false !== strpos( $r[0]['detail'], 'last read by Google 4 days ago' ), 'all good: ' . $r[0]['detail'] );

$OPT['blog_public'] = '0';
$r = $F::checks( $P, $NOW );
ok( 'error' === $r[0]['level'] && false !== strpos( $r[0]['title'], 'discouraged' ) && false !== strpos( $r[0]['link'], 'options-reading.php' ), '"Discourage search engines" ticked → error first, with a link' );
$OPT['blog_public'] = '1';

$F::store_sitemaps( ( function () { $x = new C(); $x->list = array(); return $x; } )(), $P, $NOW );
$r = $F::checks( $P, $NOW );
ok( 'warning' === $r[0]['level'] && 'No sitemap submitted to Google' === $r[0]['title'] && false !== strpos( $r[0]['detail'], 'https://example.com/sitemap_index.xml' ) && false !== strpos( $r[0]['link'], 'sitemaps?resource_id=sc-domain%3Aexample.com' ), 'no sitemap: warning names the address to submit and links to Search Console' );

$c->list = array( array( 'path' => 'https://example.com/sitemap_index.xml', 'lastDownloaded' => '2026-08-01T00:00:00Z', 'errors' => 2 ) );
$F::store_sitemaps( $c, $P, $NOW );
$r = $F::checks( $P, $NOW );
$titles = array_column( $r, 'title' );
ok( array( 'Sitemap has errors', 'Google hasn’t read the sitemap recently' ) === $titles, 'errors + stale download: both warnings: ' . implode( ' | ', $titles ) );
ok( false !== strpos( $r[0]['detail'], '2 errors' ), 'error count in the message' );

$OPT = array( 'blog_public' => '1' );
$r = $F::checks( $P, $NOW );
ok( 'Sitemap status not checked yet' === $r[0]['title'], 'before the first pull: says it will be checked' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
