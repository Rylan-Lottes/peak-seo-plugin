<?php
/**
 * Reads and writes SEO title / meta description, using whichever SEO plugin
 * the site already runs (Yoast, Rank Math, All in One SEO, SEOPress).
 * If none is active, Peak SEO stores its own values and prints them itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Fields {

	const OWN_TITLE = '_peak_seo_title';
	const OWN_DESC  = '_peak_seo_description';

	/**
	 * Which SEO plugin is active: yoast | rankmath | aioseo | seopress | none.
	 */
	public static function provider() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return 'none';
	}

	/**
	 * All in One SEO stores its fields in its own table, so v0 does not edit them.
	 */
	public static function supported() {
		return 'aioseo' !== self::provider();
	}

	private static function keys() {
		switch ( self::provider() ) {
			case 'yoast':
				return array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
			case 'rankmath':
				return array( 'rank_math_title', 'rank_math_description' );
			case 'seopress':
				return array( '_seopress_titles_title', '_seopress_titles_desc' );
			default:
				return array( self::OWN_TITLE, self::OWN_DESC );
		}
	}

	public static function get( $post_id ) {
		list( $tk, $dk ) = self::keys();
		return array(
			'seo_title'       => (string) get_post_meta( $post_id, $tk, true ),
			'seo_description' => (string) get_post_meta( $post_id, $dk, true ),
			'provider'        => self::provider(),
		);
	}

	public static function set( $post_id, $title = null, $description = null ) {
		if ( ! self::supported() ) {
			return;
		}
		list( $tk, $dk ) = self::keys();
		if ( null !== $title ) {
			update_post_meta( $post_id, $tk, sanitize_text_field( $title ) );
		}
		if ( null !== $description ) {
			update_post_meta( $post_id, $dk, sanitize_textarea_field( $description ) );
		}
	}

	/**
	 * When no supported SEO plugin is active, print Peak's own description.
	 */
	public static function output_fallback_meta() {
		if ( 'none' !== self::provider() || ! is_singular() ) {
			return;
		}
		$desc = get_post_meta( get_queried_object_id(), self::OWN_DESC, true );
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
	}

	public static function filter_fallback_title( $title ) {
		if ( 'none' !== self::provider() || ! is_singular() ) {
			return $title;
		}
		$own = get_post_meta( get_queried_object_id(), self::OWN_TITLE, true );
		return $own ? $own : $title;
	}
}
