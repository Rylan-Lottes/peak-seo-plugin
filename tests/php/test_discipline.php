<?php
// Page Discipline tests (pure calculations + noindex detection + settings).
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$OPT = array(); $META = array();
function get_option( $k, $d = false ) { global $OPT; return array_key_exists( $k, $OPT ) ? $OPT[ $k ] : $d; }
function get_post_meta( $id, $k, $s ) { global $META; return $META[ $id ][ $k ] ?? ''; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
class Peak_SEO_Fields { static $p = 'yoast'; static function provider() { return self::$p; } }
class Peak_SEO_GSC_Report { static function is_listing( $u ) { $p = '/' . trim( (string) parse_url( $u, PHP_URL_PATH ), '/' ); return (bool) preg_match( '#^/(blog|category)(/|$)#', $p ) && ! preg_match( '#^/blog/.+#', $p ); } }
require $argv[1];
$fails = 0;
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }
$D   = 'Peak_SEO_Discipline';
$S   = $D::defaults();
$U   = 'https://acme.com';
$NOW = strtotime( '2026-11-19 12:00:00 UTC' );
function pg( $id, $path, $extra = array() ) { global $U; return array_merge( array( 'id' => $id, 'type' => 'page', 'title' => ucfirst( trim( $path, '/' ) ) ?: 'Home', 'url' => $U . $path, 'path' => '/' . trim( $path, '/' ), 'published' => '2026-01-01T00:00:00', 'indexable' => true, 'noindex_reason' => '', 'is_front' => false, 'is_contact' => false, 'protected' => false, 'has_backlinks' => false ), $extra ); }
function r( $keys, $impr, $pos, $clicks = 0 ) { return array( 'keys' => (array) $keys, 'impressions' => $impr, 'position' => $pos, 'clicks' => $clicks, 'ctr' => 0 ); }

// ---- Settings ---------------------------------------------------------------------
ok( 80 === $S['health_min_pct'] && 2 === $S['new_pages_per_month'] && 28 === $S['recent_change_days'] && 90 === $S['cleanup_min_age_days'] && 14 === $S['stale_data_days'], 'defaults match the GoBIG rules (80%, 2/month, 4 weeks, 3 months, 14 days)' );
$c = $D::clean_settings( array( 'health_min_pct' => '150', 'new_pages_per_month' => '-3', 'top_position' => 30, 'close_max_position' => 25, 'junk' => 9 ) );
ok( 100 === $c['health_min_pct'] && 0 === $c['new_pages_per_month'] && 30 === $c['close_max_position'] && ! isset( $c['junk'] ), 'settings clamped to limits; close range never below top position; unknown keys dropped' );

// ---- Health -------------------------------------------------------------------------
$pages = array( pg( 1, '/' , array( 'is_front' => true ) ), pg( 2, '/web-design/' ), pg( 3, '/seo/' ), pg( 4, '/about/' ), pg( 5, '/old-promo/' ), pg( 6, '/thanks/', array( 'indexable' => false, 'noindex_reason' => 'noindex in Yoast' ) ) );
$rows  = array( r( "$U/", 300, 5 ), r( "$U/web-design", 2600, 26 ), r( "https://www.acme.com/seo/", 40, 30 ), r( "$U/about/", 0, 0 ), r( "$U/not-a-page/", 9, 50 ) );
$h     = $D::health( $pages, $rows );
ok( 5 === $h['indexable'] && 3 === $h['with_impressions'] && 60 === $h['pct'], 'health: 3 of 5 indexable pages have impressions = 60% (noindexed page excluded, path matching ignores host and trailing slash)' );
ok( array( 4, 5 ) === array_column( $h['without'], 'id' ), 'health: lists the pages without impressions' );
ok( null === $D::health( array(), $rows )['pct'], 'health: no pages → unknown, not 0%' );

// ---- New-page allowance (monthly cap + health) ---------------------------------------
$a = $D::new_page_allowance( array( 'pct' => 85 ), 1, $S );
ok( $a['allowed'] && 1 === $a['used'] && 2 === $a['cap'], 'cap: 1 of 2 used, health 85% → allowed' );
$a = $D::new_page_allowance( array( 'pct' => 85 ), 2, $S );
ok( ! $a['allowed'] && false !== strpos( $a['reasons'][0], 'limit of 2 new pages' ), 'cap: 2 of 2 used → refused: ' . $a['reasons'][0] );
$a = $D::new_page_allowance( $h, 0, $S );
ok( ! $a['allowed'] && false !== strpos( $a['reasons'][0], 'Site health is 60%' ), 'health 60% < 80% → refused: ' . $a['reasons'][0] );
ok( $D::new_page_allowance( null, 0, $S )['allowed'], 'no data yet: health can’t block (cap still applies)' );
ok( ! $D::new_page_allowance( null, 0, array_merge( $S, array( 'new_pages_per_month' => 0 ) ) )['allowed'], 'cap 0 → never' );

// ---- Competing pages ----------------------------------------------------------------
$qp = array(
	r( array( 'web design huntsville', "$U/web-design/" ), 120, 14, 2 ),
	r( array( 'web design huntsville', "$U/services/" ), 60, 22, 0 ),        // 50% share → competing
	r( array( 'web designer huntsville', "$U/web-design/" ), 50, 12, 0 ),
	r( array( 'web designer huntsville', "$U/services/" ), 20, 25, 0 ),      // same pair, 40% → added to the pair
	r( array( 'seo huntsville', "$U/seo/" ), 200, 9, 3 ),
	r( array( 'seo huntsville', "$U/about/" ), 10, 40, 0 ),                  // 5% share → not competing
	r( array( 'tiny', "$U/a/" ), 10, 30 ), r( array( 'tiny', "$U/b/" ), 10, 30 ), // total 20 < 30 → ignored
	r( array( 'acme plumbing', "$U/" ), 300, 1.2, 40 ),
	r( array( 'acme plumbing', "$U/plumbing/" ), 150, 3, 5 ),               // home has more clicks, but home is never "main" for a money search…
	r( array( 'contact acme', "$U/contact/" ), 80, 2, 4 ),
	r( array( 'contact acme', "$U/about/" ), 70, 4, 6 ),                    // …and the contact page is never refocused
	r( array( 'blog thing', "$U/blog/" ), 100, 10 ), r( array( 'blog thing', "$U/blog-post/" ), 90, 12 ), // listing excluded → one page left
);
$pages2 = array_merge( $pages, array( pg( 7, '/services/' ), pg( 8, '/plumbing/' ), pg( 9, '/contact/', array( 'is_contact' => true ) ) ) );
$comp   = $D::competing( $qp, $pages2, $S );
$pairs  = array_map( function ( $c ) { return parse_url( $c['main'], PHP_URL_PATH ) . ' ← ' . parse_url( $c['refocus'], PHP_URL_PATH ) . ' (' . count( $c['queries'] ) . ')'; }, $comp );
ok( in_array( '/web-design/ ← /services/ (2)', $pairs, true ), 'competing: /web-design main, /services refocused, two shared searches grouped' );
ok( in_array( '/plumbing/ ← / (1)', $pairs, true ) === false, 'competing: the home page is never the page to refocus' );
ok( ! array_filter( $pairs, function ( $p ) { return false !== strpos( $p, '/seo/' ) || false !== strpos( $p, '/a/' ) || false !== strpos( $p, 'blog' ) || false !== strpos( $p, 'contact' ); } ), 'competing: small shares, tiny searches, listings and the contact page are skipped: ' . implode( '; ', $pairs ) );
ok( 1 === count( $comp ), 'competing: exactly one pair found' );
$pages3 = $pages2; $pages3[] = pg( 10, '/plumbing-x/' );
$qp2    = array( r( array( 'water heater repair', "$U/water-heaters/" ), 100, 8, 1 ), r( array( 'water heater repair', "$U/plumbing/" ), 90, 9, 4 ) );
$c2     = $D::competing( $qp2, $pages3, $S );
ok( '/plumbing/' === parse_url( $c2[0]['main'], PHP_URL_PATH ), 'competing: main page = more clicks, not more impressions' );
$pages4 = $pages3; $pages4[] = pg( 11, '/water-heaters/', array( 'protected' => true ) );
ok( array() === $D::competing( $qp2, $pages4, $S ), 'competing: a protected page is never refocused' );

// ---- Cleanup ------------------------------------------------------------------------
$cp = array(
	pg( 20, '/old-promo/', array( 'published' => '2026-03-01T00:00:00' ) ),        // no impressions → merge
	pg( 21, '/close-one/', array( 'published' => '2026-03-01T00:00:00' ) ),        // best 28 → strengthen
	pg( 22, '/far-one/', array( 'published' => '2026-03-01T00:00:00' ) ),          // best 55 → merge
	pg( 23, '/good/', array( 'published' => '2026-03-01T00:00:00' ) ),             // best 6 → fine
	pg( 24, '/new-page/', array( 'published' => '2026-10-01T00:00:00' ) ),         // too new
	pg( 25, '/linked/', array( 'published' => '2026-03-01T00:00:00', 'has_backlinks' => true ) ),
	pg( 26, '/keep/', array( 'published' => '2026-03-01T00:00:00', 'protected' => true ) ),
	pg( 27, '/', array( 'published' => '2026-03-01T00:00:00', 'is_front' => true ) ),
	pg( 28, '/contact/', array( 'published' => '2026-03-01T00:00:00', 'is_contact' => true ) ),
	pg( 29, '/hidden/', array( 'published' => '2026-03-01T00:00:00', 'indexable' => false ) ),
);
$hist = array(
	array( 'page' => array( r( "$U/close-one/", 40, 28 ), r( "$U/far-one/", 30, 55 ), r( "$U/good/", 500, 6 ) ), 'query_page' => array( r( array( 'q1', "$U/close-one/" ), 40, 28 ), r( array( 'q2', "$U/far-one/" ), 30, 55 ), r( array( 'q3', "$U/good/" ), 500, 6 ) ), 'page_truncated' => false ),
	array( 'page' => array( r( "$U/close-one/", 20, 31 ) ), 'query_page' => array( r( array( 'q1', "$U/close-one/" ), 20, 31 ) ), 'page_truncated' => false ),
);
$cl  = $D::cleanup( $cp, $hist, $S, $NOW );
$got = array();
foreach ( $cl as $c ) { $got[ $c['id'] ] = $c['action']; }
ok( array( 21 => 'strengthen', 20 => 'merge', 22 => 'merge' ) == $got, 'cleanup: close one → strengthen; no impressions / far → merge: ' . json_encode( $got ) );
ok( 21 === $cl[0]['id'] && 60 === $cl[0]['impressions'] && 28.0 === $cl[0]['best_position'], 'cleanup: strengthen first; impressions summed over snapshots, best position kept' );
ok( ! array_intersect( array_keys( $got ), array( 23, 24, 25, 26, 27, 28, 29 ) ), 'cleanup never lists: ranking pages, pages under 3 months, backlinks, protected, home, contact, noindexed' );
$hist[0]['page_truncated'] = true;
$got2 = array_column( $D::cleanup( $cp, $hist, $S, $NOW ), 'id' );
ok( ! in_array( 20, $got2, true ) && in_array( 22, $got2, true ), 'cleanup: when Google’s page list was cut off, “no impressions” isn’t trusted (but “never top 20” still is)' );
ok( array() === $D::cleanup( $cp, array(), $S, $NOW ), 'cleanup: no snapshots → no candidates' );

// ---- noindex detection --------------------------------------------------------------
$post = (object) array( 'ID' => 50, 'post_type' => 'page', 'post_password' => '' );
ok( '' === $D::noindex_reason( $post ), 'indexable by default' );
$META[50]['_yoast_wpseo_meta-robots-noindex'] = '1';
ok( 'noindex in Yoast' === $D::noindex_reason( $post ), 'Yoast page setting' );
$META[50]['_yoast_wpseo_meta-robots-noindex'] = '';
$OPT['wpseo_titles'] = array( 'noindex-page' => true );
ok( false !== strpos( $D::noindex_reason( $post ), 'default for pages' ), 'Yoast post-type default' );
$META[50]['_yoast_wpseo_meta-robots-noindex'] = '2';
ok( '' === $D::noindex_reason( $post ), 'Yoast page forced to index overrides the default' );
Peak_SEO_Fields::$p = 'rankmath'; $META[50]['rank_math_robots'] = array( 'noindex', 'nofollow' );
ok( 'noindex in Rank Math' === $D::noindex_reason( $post ), 'Rank Math' );
Peak_SEO_Fields::$p = 'seopress'; $META[50]['_seopress_robots_index'] = 'yes';
ok( 'noindex in SEOPress' === $D::noindex_reason( $post ), 'SEOPress' );
$OPT['blog_public'] = '0';
ok( 'site discourages search engines' === $D::noindex_reason( $post ), 'site-wide “Discourage search engines”' );
$OPT['blog_public'] = '1'; $post->post_password = 'x';
ok( 'password protected' === $D::noindex_reason( $post ), 'password-protected page' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
