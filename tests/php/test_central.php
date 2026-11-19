<?php
// Tests for Peak_SEO_Owner_Check and the approval gate in Peak_SEO_Proposals::approve.
define( 'ABSPATH', __DIR__ );
class WP_Error { public $c, $m, $d; function __construct( $c, $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_strip_all_tags( $t ) { return strip_tags( $t ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
$OPT  = array();
$META = array();
$CAN  = false;
function get_option( $k, $d = false ) { global $OPT; return array_key_exists( $k, $OPT ) ? $OPT[ $k ] : $d; }
function get_post_meta( $id, $k, $s ) { global $META; return $META[ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { global $META; $META[ $id ][ $k ] = $v; }
function current_user_can( $c ) { global $CAN; return $CAN; }
function get_current_user_id() { global $CAN; return $CAN ? 7 : 0; }
function get_post( $id ) { return 44 === $id ? (object) array( 'ID' => 44, 'post_type' => 'peak_proposal', 'post_title' => 'T', 'post_date_gmt' => '2026-11-10 00:00:00' ) : null; }
function mysql_to_rfc3339( $d ) { return $d; }
function gmdate_stub() {}
$dir = $argv[1];
require $dir . '/class-owner-check.php';
require $dir . '/class-proposals.php';

$fails = 0;
function labels( $r ) { return array_values( array_unique( array_map( function ( $x ) { return explode( ': ', $x, 2 )[0]; }, (array) $r ) ) ); }
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }
$C = 'Peak_SEO_Owner_Check';

// ---- Owner check: things that must be flagged when ADDED -------------------
$flag = array(
	array( 'Repairs from $89', 'price' ),
	array( 'Drain cleaning for 99 dollars', 'price' ),
	array( 'Get 15% off this month', 'price' ),
	array( 'Call for a free estimate', 'price' ),
	array( 'Call (256) 555-0100 now', 'phone number' ),
	array( 'Call 256.555.0100', 'phone number' ),
	array( '<a href="tel:+12565550100">Call</a>', 'phone number' ),
	array( 'Open 7am to 6pm', 'hours' ),
	array( 'Emergency service 24/7', 'hours' ),
	array( 'Open Monday-Friday', 'hours' ),
	array( 'Same-day plumbing in Huntsville, AL', 'service area' ),
	array( 'Proudly serving Madison and Decatur', 'service area' ),
	array( 'Now covering all of Limestone County', 'service area' ),
	array( 'All work is guaranteed', 'guarantee' ),
	array( 'Backed by a 5-year warranty', 'guarantee' ),
	array( 'We now offer tankless water heater installation', 'new service' ),
	array( 'Introducing sewer camera inspections', 'new service' ),
);
foreach ( $flag as $t ) {
	$r = $C::check( array( 'Fast, honest plumbing.' ), array( $t[0] ) );
	ok( in_array( $t[1], labels( $r ), true ), "flags {$t[1]}: \"{$t[0]}\" -> [" . implode( ', ', $r ) . ']' );
}

// ---- Not flagged: harmless rewording ---------------------------------------
$safe = array(
	'Fast, honest plumbing you can trust',
	'Call us for water heater repair, drain cleaning and leak repair.',
	'Learn how to spot a failing water heater before it floods your basement.',
	'Our team explains every repair before we start.',
	'Clogged drains? Here are 3 ways to prevent them.',
);
foreach ( $safe as $t ) {
	$r = $C::check( array( 'Old text' ), array( $t ) );
	ok( array() === $r, "not flagged: \"$t\" -> [" . implode( ', ', $r ) . ']' );
}

// ---- Already in the old text: not flagged ---------------------------------
ok( array() === labels( $C::check( array( 'Call (256) 555-0100 today' ), array( 'Questions? Call 256-555-0100.' ) ) ), 'same phone, different format: not flagged' );
ok( array( 'phone number' ) === labels( $C::check( array( 'Call (256) 555-0100' ), array( 'Call (256) 555-0199' ) ) ), 'different phone: flagged' );
ok( array() === labels( $C::check( array( '<p>Serving Huntsville, AL</p>' ), array( '<h2>Serving Huntsville, AL since 1998</h2>' ) ) ), 'same area kept, HTML ignored: not flagged' );
ok( array( 'service area' ) === labels( $C::check( array( 'Serving Huntsville, AL' ), array( 'Serving Huntsville, AL and Madison, AL' ) ) ), 'new city added: flagged' );
ok( array( 'price' ) === labels( $C::check( array( 'From $89' ), array( 'From $79' ) ) ), 'price changed: flagged' );
ok( array() === labels( $C::check( array( 'Open 24/7' ), array( 'We answer 24/7' ) ) ), 'hours unchanged: not flagged' );

// ---- Approval gate ---------------------------------------------------------
$P = 'Peak_SEO_Proposals';
function reset_proposal( $needs ) {
	global $META;
	$META[44] = array( '_peak_kind' => 'update_seo', '_peak_target' => 9999, '_peak_changes' => '{}', '_peak_status' => 'pending' );
	if ( null !== $needs ) {
		$META[44]['_peak_needs_owner']   = $needs ? '1' : '0';
		$META[44]['_peak_owner_reasons'] = $needs ? array( 'phone number' ) : array();
	}
}

reset_proposal( false );
$OPT = array(); $CAN = false;
$r = $P::approve( 44, $P::BY_PEAK );
ok( is_wp_error( $r ) && 'peak_central_off' === $r->c && 403 === $r->d['status'], 'API approve refused when setting is off (403 peak_central_off)' );
ok( 'pending' === $META[44]['_peak_status'], '...and the proposal stays pending' );

$OPT = array( 'peak_seo_central_approval' => '1' );
reset_proposal( true );
$r = $P::approve( 44, $P::BY_PEAK );
ok( is_wp_error( $r ) && 'peak_needs_owner' === $r->c && str_contains( $r->m, 'phone number' ), 'API approve refused for needs_owner: ' . ( is_wp_error( $r ) ? $r->m : '' ) );

reset_proposal( null ); // Received before 0.3.0: never checked.
$r = $P::approve( 44, $P::BY_PEAK );
ok( is_wp_error( $r ) && 'peak_needs_owner' === $r->c, 'API approve refused for proposals from before 0.3.0' );
ok( $P::get( 44 )['owner_reasons'] === array( 'received before this check existed' ), '...with a reason shown' );

reset_proposal( false );
$r = $P::approve( 44, $P::BY_PEAK );
ok( is_wp_error( $r ) && 'peak_bad_target' === $r->c, 'API approve passes the gate when on and not needs_owner (stops at missing test page)' );

reset_proposal( true );
$CAN = false;
$r = $P::approve( 44, $P::BY_OWNER );
ok( is_wp_error( $r ) && 'peak_forbidden' === $r->c, 'owner approve refused without a logged-in editor' );
$CAN = true;
$r = $P::approve( 44, $P::BY_OWNER );
ok( is_wp_error( $r ) && 'peak_bad_target' === $r->c, 'logged-in owner passes the gate even for needs_owner' );
$r = $P::approve( 44, 'someone-else' );
ok( is_wp_error( $r ) && 'peak_forbidden' === $r->c, 'unknown approver refused' );

$META[44]['_peak_status'] = 'approved';
$OPT = array( 'peak_seo_central_approval' => '1' );
$r = $P::approve( 44, $P::BY_PEAK );
ok( is_wp_error( $r ) && 'peak_not_pending' === $r->c, 'already-approved proposal cannot be approved again' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
