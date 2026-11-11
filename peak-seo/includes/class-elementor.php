<?php
/**
 * Elementor support: detect real Elementor pages and edit the text inside
 * their widgets without touching layout, styles or any other setting.
 *
 * Elementor keeps a page's layout as JSON in the `_elementor_data` post meta.
 * Peak SEO only ever changes the text setting of these widgets:
 *
 *   classic   heading (title), text-editor (editor), button (text)
 *   atomic    e-heading (title), e-paragraph (paragraph), e-button (text)
 *
 * Every edit names the widget by its Elementor element id. Anything else in
 * the JSON is copied through unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Elementor {

	const DATA_META  = '_elementor_data';
	const MODE_META  = '_elementor_edit_mode';
	const CACHE_META = '_elementor_element_cache';
	const CSS_META   = '_elementor_css';

	/**
	 * Widget type => [ setting key, 'inline' | 'rich' ].
	 * "inline" text allows a few inline tags; "rich" text allows normal post HTML.
	 */
	const WIDGETS = array(
		'heading'     => array( 'title', 'inline' ),
		'text-editor' => array( 'editor', 'rich' ),
		'button'      => array( 'text', 'inline' ),
		'e-heading'   => array( 'title', 'inline' ),
		'e-paragraph' => array( 'paragraph', 'rich-atomic' ),
		'e-button'    => array( 'text', 'inline' ),
	);

	const LABELS = array(
		'heading'     => 'Heading',
		'text-editor' => 'Text',
		'button'      => 'Button',
		'e-heading'   => 'Heading',
		'e-paragraph' => 'Paragraph',
		'e-button'    => 'Button',
	);

	/** Atomic (Elementor 4) wrappers whose value is a plain string. */
	const STRING_TYPES = array( 'html', 'escaped-html', 'string' );

	const MAX_TEXT = 20000;

	/* ------------------------------------------------------------------ */
	/* Detection                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * True only when the page really is built with Elementor: the edit mode is
	 * "builder" AND the saved layout contains at least one widget. Opening a
	 * post with "Edit with Elementor" sets the edit mode without saving any
	 * layout, and those posts still show their normal content.
	 */
	public static function is_built_with( $post_id ) {
		if ( 'builder' !== get_post_meta( $post_id, self::MODE_META, true ) ) {
			return false;
		}
		return self::has_widgets( self::get_data( $post_id ) );
	}

	/**
	 * The page's Elementor layout as an array ([] when missing or broken).
	 */
	public static function get_data( $post_id ) {
		return self::decode( get_post_meta( $post_id, self::DATA_META, true ) );
	}

	/**
	 * The raw stored JSON string, used for snapshots so undo is exact.
	 */
	public static function get_raw( $post_id ) {
		$raw = get_post_meta( $post_id, self::DATA_META, true );
		return is_string( $raw ) ? $raw : wp_json_encode( $raw );
	}

	public static function decode( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	public static function has_widgets( $elements ) {
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ! empty( $el['widgetType'] ) ) {
				return true;
			}
			if ( ! empty( $el['elements'] ) && self::has_widgets( $el['elements'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Reading text                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Every editable text widget, in page order.
	 *
	 * @return array[] Each: id, widget, label, field, text.
	 */
	public static function text_fields( $elements ) {
		$out = array();
		self::walk(
			$elements,
			function ( $el ) use ( &$out ) {
				$field = self::field_for( $el );
				if ( ! $field ) {
					return;
				}
				$text = self::read_value( isset( $el['settings'][ $field ] ) ? $el['settings'][ $field ] : null );
				if ( null === $text ) {
					return; // A shape we do not understand: leave it alone.
				}
				$out[] = array(
					'id'     => (string) $el['id'],
					'widget' => $el['widgetType'],
					'label'  => self::LABELS[ $el['widgetType'] ],
					'field'  => $field,
					'text'   => $text,
				);
			}
		);
		return $out;
	}

	/**
	 * Text of one widget by element id, or null.
	 */
	public static function find_text( $elements, $id ) {
		foreach ( self::text_fields( $elements ) as $f ) {
			if ( $f['id'] === (string) $id ) {
				return $f;
			}
		}
		return null;
	}

	private static function field_for( $el ) {
		if ( ! is_array( $el ) || empty( $el['id'] ) || empty( $el['widgetType'] ) ) {
			return null;
		}
		$type = $el['widgetType'];
		return isset( self::WIDGETS[ $type ] ) ? self::WIDGETS[ $type ][0] : null;
	}

	/**
	 * Read a stored setting value as a string, or null for unknown shapes.
	 *
	 * Supported: "text" (classic), {"$$type":"html","value":"text"} (atomic),
	 * and {"$$type":"html-v2|html-v3","value":{"content":...,"children":[]}}
	 * when it has no nested children.
	 */
	public static function read_value( $v ) {
		if ( null === $v ) {
			return '';
		}
		if ( is_string( $v ) ) {
			return $v;
		}
		if ( ! is_array( $v ) || ! isset( $v['$$type'] ) || ! array_key_exists( 'value', $v ) ) {
			return null;
		}
		if ( in_array( $v['$$type'], self::STRING_TYPES, true ) ) {
			return is_string( $v['value'] ) ? $v['value'] : ( null === $v['value'] ? '' : null );
		}
		if ( in_array( $v['$$type'], array( 'html-v2', 'html-v3' ), true ) && is_array( $v['value'] ) ) {
			$inner = $v['value'];
			if ( ! empty( $inner['children'] ) ) {
				return null; // Nested inline elements: not safe to rewrite as one string.
			}
			$content = isset( $inner['content'] ) ? $inner['content'] : null;
			if ( null === $content ) {
				return '';
			}
			if ( is_string( $content ) ) {
				return $content;
			}
			if ( is_array( $content ) && isset( $content['value'] ) && is_string( $content['value'] ) ) {
				return $content['value'];
			}
		}
		return null;
	}

	/**
	 * Write a new string into a setting, keeping its original shape.
	 */
	public static function write_value( $orig, $text ) {
		if ( null === $orig || is_string( $orig ) ) {
			return $text;
		}
		if ( in_array( $orig['$$type'], self::STRING_TYPES, true ) ) {
			$orig['value'] = $text;
			return $orig;
		}
		// html-v2 / html-v3 without children.
		$content = isset( $orig['value']['content'] ) ? $orig['value']['content'] : null;
		if ( is_array( $content ) && isset( $content['$$type'] ) ) {
			$content['value'] = $text;
		} else {
			$content = $text;
		}
		$orig['value']['content']  = $content;
		$orig['value']['children'] = array();
		return $orig;
	}

	/* ------------------------------------------------------------------ */
	/* Validating and applying edits                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Check edits sent by the service against the page as it is now.
	 *
	 * @param array $elements Current layout.
	 * @param mixed $edits    [ { "id": "860e908", "text": "New heading" }, ... ]
	 * @return array|WP_Error Clean edits: id, widget, label, field, before, text.
	 */
	public static function clean_edits( $elements, $edits ) {
		if ( ! is_array( $edits ) || empty( $edits ) ) {
			return new WP_Error( 'peak_missing', 'No Elementor text edits given.', array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( $edits as $e ) {
			$id   = is_array( $e ) && isset( $e['id'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) $e['id'] ) : '';
			$text = is_array( $e ) && isset( $e['text'] ) && is_string( $e['text'] ) ? $e['text'] : null;
			if ( '' === $id || null === $text ) {
				return new WP_Error( 'peak_bad_edit', 'Each Elementor edit needs an "id" and a "text".', array( 'status' => 400 ) );
			}
			if ( isset( $clean[ $id ] ) ) {
				return new WP_Error( 'peak_bad_edit', "Widget {$id} is edited twice.", array( 'status' => 400 ) );
			}
			if ( strlen( $text ) > self::MAX_TEXT ) {
				return new WP_Error( 'peak_too_long', "Text for widget {$id} is too long.", array( 'status' => 400 ) );
			}
			$found = self::find_text( $elements, $id );
			if ( ! $found ) {
				return new WP_Error( 'peak_bad_edit', "Widget {$id} is not an editable heading, text or button on this page.", array( 'status' => 400 ) );
			}
			$new = self::sanitize( $found['widget'], $text );
			if ( $new === $found['text'] ) {
				continue; // No change.
			}
			$clean[ $id ] = array(
				'id'     => $id,
				'widget' => $found['widget'],
				'label'  => $found['label'],
				'field'  => $found['field'],
				'before' => $found['text'],
				'text'   => $new,
			);
		}
		if ( empty( $clean ) ) {
			return new WP_Error( 'peak_missing', 'Nothing to change: the proposed text matches the page.', array( 'status' => 400 ) );
		}
		return array_values( $clean );
	}

	public static function sanitize( $widget, $text ) {
		$mode = self::WIDGETS[ $widget ][1];
		if ( 'rich' === $mode ) {
			return wp_kses_post( $text );
		}
		$tags = array(
			'b'      => array(),
			'strong' => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			's'      => array(),
			'del'    => array(),
			'sup'    => array(),
			'sub'    => array(),
			'span'   => array(),
			'br'     => array(),
			'a'      => array(
				'href'   => true,
				'target' => true,
			),
		);
		if ( 'rich-atomic' === $mode ) {
			// Same tags Elementor allows in its atomic paragraph.
			$tags += array(
				'ul'         => array(),
				'ol'         => array(),
				'li'         => array(),
				'blockquote' => array(),
			);
			unset( $tags['i'] );
		}
		if ( 'e-button' === $widget || 'button' === $widget ) {
			unset( $tags['a'] ); // A button is already a link.
		}
		return wp_kses( $text, $tags );
	}

	/**
	 * Apply edits to a layout. Each widget must still hold the text it had when
	 * the proposal arrived, so a change made in Elementor meanwhile is never
	 * overwritten.
	 *
	 * @return array|WP_Error New layout.
	 */
	public static function apply_edits( $elements, $edits ) {
		$by_id = array();
		foreach ( $edits as $e ) {
			$by_id[ (string) $e['id'] ] = $e;
		}
		$seen  = array();
		$error = null;
		$out   = self::map(
			$elements,
			function ( $el ) use ( $by_id, &$seen, &$error ) {
				$id = isset( $el['id'] ) ? (string) $el['id'] : '';
				if ( '' === $id || ! isset( $by_id[ $id ] ) ) {
					return $el;
				}
				$e     = $by_id[ $id ];
				$field = self::field_for( $el );
				$orig  = isset( $el['settings'][ $field ] ) ? $el['settings'][ $field ] : null;
				$now   = $field ? self::read_value( $orig ) : null;
				if ( $field !== $e['field'] || null === $now ) {
					$error = "Widget {$id} is no longer an editable {$e['label']}.";
					return $el;
				}
				if ( $now !== $e['before'] ) {
					$error = "The {$e['label']} ({$id}) was changed in Elementor after this proposal arrived, so it was not overwritten.";
					return $el;
				}
				if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
					$el['settings'] = array();
				}
				$el['settings'][ $field ] = self::write_value( $orig, $e['text'] );
				$seen[ $id ]              = true;
				return $el;
			}
		);
		if ( $error ) {
			return new WP_Error( 'peak_elementor_changed', $error, array( 'status' => 409 ) );
		}
		$missing = array_diff( array_keys( $by_id ), array_keys( $seen ) );
		if ( $missing ) {
			return new WP_Error( 'peak_elementor_changed', 'Widget ' . implode( ', ', $missing ) . ' is no longer on the page.', array( 'status' => 409 ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Save a layout (array) or restore a raw JSON snapshot (string), then clear
	 * Elementor's caches so the page shows the new text right away.
	 */
	public static function save( $post_id, $data, $post_content = null ) {
		$json = is_string( $data ) ? $data : wp_json_encode( $data );
		// Elementor reads this with get_post_meta + json_decode; wp_slash keeps backslashes intact.
		update_post_meta( $post_id, self::DATA_META, wp_slash( $json ) );
		self::clear_cache( $post_id );

		if ( null !== $post_content ) {
			// Undo: put back the exact plain-text copy we saved.
			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $post_content ) ) );
		} else {
			self::refresh_plain_text( $post_id );
		}
		self::clear_cache( $post_id );
	}

	/**
	 * Elementor keeps a plain copy of the page in post_content (used by search,
	 * feeds and when Elementor is turned off). Ask Elementor to refresh it.
	 */
	private static function refresh_plain_text( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->db ) ) {
			return;
		}
		try {
			\Elementor\Plugin::$instance->db->save_plain_text( $post_id );
		} catch ( \Throwable $e ) {
			// The page itself is already saved; the plain copy is only a fallback.
		}
	}

	public static function clear_cache( $post_id ) {
		delete_post_meta( $post_id, self::CACHE_META );
		delete_post_meta( $post_id, self::CSS_META );
		clean_post_cache( $post_id );
	}

	/* ------------------------------------------------------------------ */
	/* Preview                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * For this request only, make Elementor render the page from the proposed
	 * layout instead of the saved one. Nothing is written.
	 */
	public static function preview( $post_id, $edits ) {
		$data = self::apply_edits( self::get_data( $post_id ), $edits );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$json = wp_json_encode( $data );
		add_filter(
			'get_post_metadata',
			function ( $value, $object_id, $meta_key ) use ( $post_id, $json ) {
				if ( (int) $object_id !== (int) $post_id ) {
					return $value;
				}
				if ( self::DATA_META === $meta_key ) {
					return array( $json );
				}
				if ( self::CACHE_META === $meta_key ) {
					return array( '' ); // Skip Elementor's cached HTML.
				}
				return $value;
			},
			10,
			3
		);
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Tree helpers                                                        */
	/* ------------------------------------------------------------------ */

	private static function walk( $elements, $fn ) {
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$fn( $el );
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $fn );
			}
		}
	}

	private static function map( $elements, $fn ) {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el = $fn( $el );
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::map( $el['elements'], $fn );
			}
			$elements[ $i ] = $el;
		}
		return $elements;
	}
}
