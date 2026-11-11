<?php
/**
 * REST endpoints the Peak SEO service calls. All require a signed request
 * (see Peak_SEO_Auth). The service can READ the site and SUBMIT proposals;
 * it cannot publish or change anything by itself. It can approve a proposal
 * only when the site owner has turned that on, and never one marked
 * needs_owner. It can never reject, delete, undo or change settings.
 *
 * Namespace: /wp-json/peak-seo/v1
 *   GET  /site               site info + which SEO plugin and builders are present
 *   GET  /pages              pages and posts with SEO fields (no full content)
 *   GET  /pages/{id}         one page with full content (and Elementor text widgets)
 *   POST /proposals          submit a proposal
 *   GET  /proposals          list proposals (?status=pending|approved|rejected|undone)
 *   GET  /proposals/{id}     one proposal
 *   POST /proposals/{id}/approve   approve (only with the owner's permission)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Rest {

	const NS = 'peak-seo/v1';

	public static function register_routes() {
		$auth = array( 'Peak_SEO_Auth', 'check' );

		register_rest_route( self::NS, '/site', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'site' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/pages', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pages' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'page' ), 'permission_callback' => $auth ) );
		register_rest_route(
			self::NS,
			'/proposals',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_proposals' ), 'permission_callback' => $auth ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_proposal' ), 'permission_callback' => $auth ),
			)
		);
		register_rest_route( self::NS, '/proposals/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_proposal' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/proposals/(?P<id>\d+)/approve', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'approve_proposal' ), 'permission_callback' => $auth ) );
	}

	public static function site() {
		$theme = wp_get_theme();
		return array(
			'name'           => get_bloginfo( 'name' ),
			'tagline'        => get_bloginfo( 'description' ),
			'url'            => home_url( '/' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'plugin_version' => PEAK_SEO_VERSION,
			'theme'          => $theme->get( 'Name' ),
			'seo_plugin'     => Peak_SEO_Fields::provider(),
			'seo_editable'   => Peak_SEO_Fields::supported(),
			'central_approval' => Peak_SEO_Proposals::central_approval_enabled(),
			'approved_facts'   => Peak_SEO_Owner_Check::approved_facts(), // Read-only: only the owner can change these.
			'page_builders'  => array_values(
				array_filter(
					array(
						defined( 'ELEMENTOR_VERSION' ) ? 'elementor' : '',
						defined( 'ET_BUILDER_VERSION' ) ? 'divi' : '',
						class_exists( 'FLBuilder' ) ? 'beaver-builder' : '',
						defined( 'WPB_VC_VERSION' ) ? 'wpbakery' : '',
					)
				)
			),
		);
	}

	private static function summarize( $post ) {
		$seo     = Peak_SEO_Fields::get( $post->ID );
		$builder = Peak_SEO_Proposals::page_builder( $post->ID );
		if ( 'elementor' === $builder ) {
			$editable = 'elementor_text';
		} else {
			$editable = '' === $builder ? 'content' : 'none';
		}
		return array(
			'id'              => $post->ID,
			'type'            => $post->post_type,
			'status'          => $post->post_status,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'url'             => get_permalink( $post ),
			'modified'        => mysql_to_rfc3339( $post->post_modified_gmt ),
			'seo_title'       => $seo['seo_title'],
			'seo_description' => $seo['seo_description'],
			'page_builder'    => $builder,
			'content_editing' => $editable, // content | elementor_text | none
			'word_count'      => str_word_count( wp_strip_all_tags( $post->post_content ) ),
		);
	}

	public static function pages() {
		$posts = get_posts(
			array(
				'post_type'      => Peak_SEO_Proposals::TARGET_TYPES,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		return array_map( array( __CLASS__, 'summarize' ), $posts );
	}

	public static function page( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! in_array( $post->post_type, Peak_SEO_Proposals::TARGET_TYPES, true ) || 'publish' !== $post->post_status ) {
			return new WP_Error( 'peak_not_found', 'Page not found.', array( 'status' => 404 ) );
		}
		$out             = self::summarize( $post );
		$out['content']  = $post->post_content;
		$out['excerpt']  = $post->post_excerpt;
		$out['rendered'] = apply_filters( 'the_content', $post->post_content ); // What visitors see, including builder output.
		if ( 'elementor' === $out['page_builder'] ) {
			// Text widgets the service can edit, by Elementor element id.
			$out['elementor_text'] = array_map(
				function ( $f ) {
					unset( $f['field'] );
					return $f;
				},
				Peak_SEO_Elementor::text_fields( Peak_SEO_Elementor::get_data( $post->ID ) )
			);
		}
		return $out;
	}

	public static function create_proposal( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'peak_bad_json', 'Body must be JSON.', array( 'status' => 400 ) );
		}
		$id = Peak_SEO_Proposals::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( Peak_SEO_Proposals::get( $id ), 201 );
	}

	public static function list_proposals( $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		return Peak_SEO_Proposals::query( $status );
	}

	public static function approve_proposal( $request ) {
		$id = (int) $request['id'];
		if ( ! Peak_SEO_Proposals::get( $id ) ) {
			return new WP_Error( 'peak_not_found', 'Proposal not found.', array( 'status' => 404 ) );
		}
		$r = Peak_SEO_Proposals::approve( $id, Peak_SEO_Proposals::BY_PEAK );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return Peak_SEO_Proposals::get( $id );
	}

	public static function get_proposal( $request ) {
		$p = Peak_SEO_Proposals::get( (int) $request['id'] );
		return $p ? $p : new WP_Error( 'peak_not_found', 'Proposal not found.', array( 'status' => 404 ) );
	}
}
