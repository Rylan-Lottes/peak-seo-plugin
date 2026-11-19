<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { public $c, $m; function __construct( $c, $m, $d = null ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function wp_kses_post( $t ) { return preg_replace( '#<script.*?</script>#is', '', $t ); }
function wp_kses( $t, $tags ) { return strip_tags( $t, array_keys( $tags ) ); }
$META = array();
function get_post_meta( $id, $k, $s ) { global $META; return $META[ $id ][ $k ] ?? ''; }
require $argv[1];
$fails = 0;
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }

// Shaped like page 35: atomic flexboxes with e-heading, classic container with text-editor.
$data = array(
	array( 'id' => '50664f4', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array(
		array( 'id' => '860e908', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'title' => array( '$$type' => 'html-v3', 'value' => array( 'content' => null, 'children' => array() ) ) ), 'elements' => array() ),
	) ),
	array( 'id' => '35c0e18', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array(
		array( 'id' => 'e1264aa', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'title' => array( '$$type' => 'html', 'value' => 'Fast, honest plumbing in Huntsville' ), 'tag' => array( '$$type' => 'string', 'value' => 'h2' ) ), 'elements' => array() ),
	) ),
	array( 'id' => '3ff8c12', 'elType' => 'container', 'settings' => array( 'content_width' => 'boxed' ), 'elements' => array(
		array( 'id' => 'ce24710', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Call us for water heater repair, drain cleaning and leak repair.</p>', 'align' => 'center' ), 'elements' => array() ),
		array( 'id' => 'b0b0b0b', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Call now', 'link' => array( 'url' => 'tel:555' ) ), 'elements' => array() ),
		array( 'id' => 'c1c1c1c', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => array( 'paragraph' => array( '$$type' => 'html-v3', 'value' => array( 'content' => array( '$$type' => 'string', 'value' => 'Old para' ), 'children' => array() ) ) ), 'elements' => array() ),
		array( 'id' => 'd2d2d2d', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'title' => array( '$$type' => 'html-v3', 'value' => array( 'content' => 'x', 'children' => array( array( '$$type' => 'strong' ) ) ) ) ), 'elements' => array() ),
		array( 'id' => 'f3f3f3f', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => 'a.jpg' ) ), 'elements' => array() ),
	) ),
);
$E = 'Peak_SEO_Elementor';

// Detection.
$META[15] = array( '_elementor_edit_mode' => 'builder' );
ok( ! $E::is_built_with( 15 ), 'post 15 case: edit_mode=builder but no data is NOT Elementor' );
$META[16] = array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[]' );
ok( ! $E::is_built_with( 16 ), 'edit_mode=builder with [] data is NOT Elementor' );
$META[17] = array( '_elementor_edit_mode' => 'builder', '_elementor_data' => json_encode( array( array( 'id' => 'a', 'elType' => 'container', 'elements' => array() ) ) ) );
ok( ! $E::is_built_with( 17 ), 'empty container only is NOT Elementor' );
$META[18] = array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '{broken' );
ok( ! $E::is_built_with( 18 ), 'broken JSON is NOT Elementor' );
$META[35] = array( '_elementor_edit_mode' => 'builder', '_elementor_data' => json_encode( $data ) );
ok( $E::is_built_with( 35 ), 'page 35 shape IS Elementor' );
$META[36] = array( '_elementor_data' => json_encode( $data ) );
ok( ! $E::is_built_with( 36 ), 'data without builder mode is NOT Elementor' );

// Reading.
$f   = $E::text_fields( $data );
$ids = array_column( $f, 'id' );
ok( $ids === array( '860e908', 'e1264aa', 'ce24710', 'b0b0b0b', 'c1c1c1c' ), 'text widgets found in page order: ' . implode( ',', $ids ) );
ok( '' === $f[0]['text'] && 'Fast, honest plumbing in Huntsville' === $f[1]['text'] && 'Old para' === $f[4]['text'], 'texts read from atomic shapes' );
ok( ! in_array( 'd2d2d2d', $ids, true ), 'heading with nested children is skipped' );

// Validating.
$r = $E::clean_edits( $data, array( array( 'id' => 'e1264aa', 'text' => 'Same-day <strong>plumbing</strong> <script>x</script><div>in</div> Huntsville' ) ) );
ok( ! is_wp_error( $r ) && 'Same-day <strong>plumbing</strong> xin Huntsville' === $r[0]['text'] && 'Fast, honest plumbing in Huntsville' === $r[0]['before'], 'heading limited to inline tags; before text recorded' );
ok( is_wp_error( $E::clean_edits( $data, array( array( 'id' => 'f3f3f3f', 'text' => 'x' ) ) ) ), 'image widget refused' );
ok( is_wp_error( $E::clean_edits( $data, array( array( 'id' => 'd2d2d2d', 'text' => 'x' ) ) ) ), 'nested-children heading refused' );
ok( is_wp_error( $E::clean_edits( $data, array( array( 'id' => 'nope', 'text' => 'x' ) ) ) ), 'unknown id refused' );
ok( is_wp_error( $E::clean_edits( $data, array( array( 'id' => 'e1264aa', 'text' => 'Fast, honest plumbing in Huntsville' ) ) ) ), 'no-op edit refused' );
ok( is_wp_error( $E::clean_edits( $data, array( array( 'id' => 'e1264aa', 'text' => 'a' ), array( 'id' => 'e1264aa', 'text' => 'b' ) ) ) ), 'same widget twice refused' );
ok( is_wp_error( $E::clean_edits( $data, 'x' ) ), 'non-list refused' );

// Applying.
$edits = $E::clean_edits(
	$data,
	array(
		array( 'id' => 'e1264aa', 'text' => 'Same-day plumbing in Huntsville, AL' ),
		array( 'id' => '860e908', 'text' => 'Licensed plumbers' ),
		array( 'id' => 'ce24710', 'text' => '<p>Call <a href="tel:555">555</a> today.</p>' ),
		array( 'id' => 'b0b0b0b', 'text' => 'Call <a href="x">today</a>' ),
		array( 'id' => 'c1c1c1c', 'text' => 'New para' ),
	)
);
ok( ! is_wp_error( $edits ) && 5 === count( $edits ), 'five edits accepted' );
$new = $E::apply_edits( $data, $edits );
ok( ! is_wp_error( $new ), 'edits applied' );
$h = $new[1]['elements'][0]['settings'];
ok( array( '$$type' => 'html', 'value' => 'Same-day plumbing in Huntsville, AL' ) === $h['title'] && array( '$$type' => 'string', 'value' => 'h2' ) === $h['tag'], 'atomic heading keeps its shape and other settings' );
ok( array( '$$type' => 'html-v3', 'value' => array( 'content' => 'Licensed plumbers', 'children' => array() ) ) === $new[0]['elements'][0]['settings']['title'], 'empty html-v3 heading filled' );
ok( array( 'editor' => '<p>Call <a href="tel:555">555</a> today.</p>', 'align' => 'center' ) === $new[2]['elements'][0]['settings'], 'text editor keeps its alignment setting' );
ok( 'Call today' === $new[2]['elements'][1]['settings']['text'] && array( 'url' => 'tel:555' ) === $new[2]['elements'][1]['settings']['link'], 'button drops nested link, keeps its own link' );
ok( array( '$$type' => 'string', 'value' => 'New para' ) === $new[2]['elements'][2]['settings']['paragraph']['value']['content'], 'wrapped html-v3 content stays wrapped' );
$strip = function ( $d ) use ( &$strip ) {
	foreach ( $d as &$e ) {
		unset( $e['settings']['title'], $e['settings']['editor'], $e['settings']['text'], $e['settings']['paragraph'] );
		if ( ! empty( $e['elements'] ) ) {
			$e['elements'] = $strip( $e['elements'] );
		}
	}
	return $d;
};
ok( $strip( $new ) === $strip( $data ), 'nothing else in the layout changed' );
ok( $new[2]['elements'][3] === $data[2]['elements'][3] && $new[2]['elements'][4] === $data[2]['elements'][4], 'untouched widgets identical' );

// Conflicts.
$changed = $data;
$changed[1]['elements'][0]['settings']['title']['value'] = 'Owner wrote this';
$r = $E::apply_edits( $changed, $edits );
ok( is_wp_error( $r ) && str_contains( $r->m, 'changed in Elementor' ), 'owner edit not overwritten: ' . ( is_wp_error( $r ) ? $r->m : '' ) );
$gone = $data;
unset( $gone[2]['elements'][1] );
$gone[2]['elements'] = array_values( $gone[2]['elements'] );
$r = $E::apply_edits( $gone, $edits );
ok( is_wp_error( $r ) && str_contains( $r->m, 'b0b0b0b' ), 'deleted widget detected: ' . ( is_wp_error( $r ) ? $r->m : '' ) );

// Storage round trip.
ok( $E::decode( wp_json_encode( $new ) ) === $new, 'JSON round trip' );
$u  = $E::clean_edits( $data, array( array( 'id' => 'e1264aa', 'text' => 'Café — 24/7 "quotes" \\ back' ) ) );
$un = $E::apply_edits( $data, $u );
ok( 'Café — 24/7 "quotes" \\ back' === $E::decode( wp_json_encode( $un ) )[1]['elements'][0]['settings']['title']['value'], 'unicode, quotes and backslash survive' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
