<?php
// Owner check with approved facts.
define( 'ABSPATH', __DIR__ );
function wp_strip_all_tags( $t ) { return strip_tags( $t ); }
$OPT = array();
function get_option( $k, $d = false ) { global $OPT; return array_key_exists( $k, $OPT ) ? $OPT[ $k ] : $d; }
require $argv[1] . '/class-owner-check.php';

$fails = 0;
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }
function labels( $r ) { return array_values( array_unique( array_map( function ( $x ) { return explode( ': ', $x, 2 )[0]; }, $r ) ) ); }
$C = 'Peak_SEO_Owner_Check';
$none = array();
$facts = array(
	'service_areas' => "Huntsville, AL\nMadison, AL\nLimestone County",
	'services'      => array( 'Water heater repair', 'Drain cleaning', 'Leak repair', 'Tankless water heater installation' ),
	'phones'        => array( '(256) 555-0100' ),
	'hours'         => array( 'Mon-Fri 7am-6pm', '24/7 emergency service' ),
);

// --- Without approved facts: same behavior as 0.3.0 -------------------------
$cases = array(
	array( 'Repairs from $89', array( 'price' ) ),
	array( 'Call for a free estimate', array( 'price' ) ),
	array( 'Call (256) 555-0100 now', array( 'phone number' ) ),
	array( '<a href="tel:+12565550100">Call</a>', array( 'phone number' ) ),
	array( 'Open 7am to 6pm', array( 'hours' ) ),
	array( 'Emergency service 24/7', array( 'hours' ) ),
	array( 'Open Monday-Friday', array( 'hours' ) ),
	array( 'Same-day plumbing in Huntsville, AL', array( 'service area' ) ),
	array( 'Proudly serving Madison and Decatur', array( 'service area' ) ),
	array( 'Now covering all of Limestone County', array( 'service area' ) ),
	array( 'We proudly serve the whole valley', array( 'service area' ) ),
	array( 'All work is guaranteed', array( 'guarantee' ) ),
	array( 'We now offer tankless water heater installation', array( 'new service' ) ),
	array( 'Introducing sewer camera inspections', array( 'new service' ) ),
	array( 'Fast, honest plumbing you can trust', array() ),
	array( 'Clogged drains? Here are 3 ways to prevent them.', array() ),
);
foreach ( $cases as $c ) {
	$r = $C::check( array( 'Old text' ), array( $c[0] ), $none );
	ok( labels( $r ) === $c[1], 'no list: "' . $c[0] . '" -> [' . implode( ', ', $r ) . ']' );
}
ok( array() === $C::check( array( 'Call (256) 555-0100 today' ), array( 'Questions? Call 256-555-0100.' ), $none ), 'same phone in old text: not flagged' );
ok( array() === $C::check( array( 'Serving Huntsville, AL' ), array( '<h2>Serving Huntsville, AL since 1998</h2>' ), $none ), 'same area in old text: not flagged' );
$r = $C::check( array( 'x' ), array( 'Proudly serving Huntsville and Madison, Alabama since 1998' ), $none );
ok( array( 'service area: Huntsville', 'service area: Madison' ) === $r, 'state name and following words are not places: [' . implode( ', ', $r ) . ']' );

// --- With approved facts ----------------------------------------------------
$ok_texts = array(
	'Same-day plumbing in Huntsville, AL',
	'Proudly serving Huntsville and Madison, Alabama',
	'Now covering all of Limestone County',
	'Water Heater Repair Huntsville, AL | Rocket Valley',
	'Call 256.555.0100 for help',
	'<a href="tel:+1-256-555-0100">Call us</a>',
	'Emergency service 24/7',
	'Open Mon-Fri 7am-6pm',
	'Our business hours are Mon-Fri 7am-6pm',
	'We now offer tankless water heater installation',
	'We repair water heaters and leaks the same day',
	'Introducing drain cleaning for older homes',
);
foreach ( $ok_texts as $t ) {
	$r = $C::check( array( 'Old text' ), array( $t ), $facts );
	ok( array() === $r, 'approved: "' . $t . '" -> [' . implode( ', ', $r ) . ']' );
}
$still = array(
	array( 'Proudly serving Madison and Decatur', array( 'service area: Decatur' ) ),
	array( 'Same-day plumbing in Athens, AL', array( 'service area: Athens, AL' ) ),
	array( 'We proudly serve the whole valley', array( 'service area: "proudly serve" (no place named)' ) ),
	array( 'Call (256) 555-0199', array( 'phone number: (256) 555-0199' ) ),
	array( 'Open Saturday-Sunday', array( 'hours: Saturday-Sunday' ) ),
	array( 'Open until 9pm', array( 'hours: 9pm' ) ),
	array( 'Introducing sewer camera inspections', array( 'new service: Introducing sewer camera inspections' ) ),
	array( 'Repairs from $89', array( 'price' ) ),
	array( 'Backed by a 5-year warranty', array( 'guarantee' ) ),
);
foreach ( $still as $s ) {
	$r = $C::check( array( 'Old text' ), array( $s[0] ), $facts );
	ok( $r === $s[1], 'still flagged: "' . $s[0] . '" -> [' . implode( ', ', $r ) . ']' );
}

// --- The brain's real dry-run description ------------------------------------
$desc = 'Family-owned plumber serving homeowners in Huntsville and Madison, AL. Call us for water heater repair, drain cleaning and leak repair.';
$r    = $C::check( array( '' ), array( 'Plumber in Huntsville, AL | Rocket Valley Plumbing (Demo)', $desc ), $facts );
ok( array() === $r, 'brain dry-run title + description pass with approved facts: [' . implode( ', ', $r ) . ']' );
$r = $C::check( array( '' ), array( 'Serving homeowners in Decatur and Madison, AL' ), $facts );
ok( array( 'service area: Decatur, AL' ) === $r, 'unapproved town in a "X and Y, AL" list is caught: [' . implode( ', ', $r ) . ']' );
$r = $C::check( array( '' ), array( 'Serving homeowners across the valley' ), $facts );
ok( array( 'service area: "serving" (no place named)' ) === $r, 'bare "serving" with no place still flagged: [' . implode( ', ', $r ) . ']' );

// --- Settings list is read when no facts are passed ------------------------
$OPT['peak_seo_facts'] = array( 'service_areas' => array( 'Huntsville, AL' ) );
ok( array() === $C::check( array( 'x' ), array( 'Same-day plumbing in Huntsville, AL' ) ), 'reads approved facts from Settings' );
$OPT = array();
ok( array( 'service area: Huntsville, AL' ) === $C::check( array( 'x' ), array( 'Same-day plumbing in Huntsville, AL' ) ), 'empty Settings: flagged again' );

// --- clean_facts ------------------------------------------------------------
$cf = $C::clean_facts( array( 'services' => " Drain cleaning \n\n<b>Leak</b> repair\nDrain cleaning", 'bogus' => array( 'x' ) ) );
ok( array( 'Drain cleaning', 'Leak repair' ) === $cf['services'] && ! isset( $cf['bogus'] ) && array() === $cf['phones'], 'clean_facts trims, strips tags, dedupes, drops unknown keys' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
