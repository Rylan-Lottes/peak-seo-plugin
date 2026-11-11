<?php
/**
 * Proposals: changes suggested by the Peak SEO service.
 *
 * A proposal never touches the live site until a logged-in user approves it.
 * Approving stores a snapshot of the page first, so every change can be undone.
 *
 * Kinds:
 *   update_seo      change a page's SEO title and/or meta description
 *   update_content  change a page's title, content and/or excerpt; on Elementor
 *                   pages, change widget text through `elementor` edits instead
 *   create_page     add a new page (saved as a draft until approved)
 *
 * Who can approve:
 *   - a logged-in user with edit_pages, always;
 *   - the Peak SEO service through the signed API, only when the owner has
 *     turned on "Let Peak Visibility review and approve changes for me" AND
 *     the proposal is not marked needs_owner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Proposals {

	const CPT            = 'peak_proposal';
	const KINDS          = array( 'update_seo', 'update_content', 'create_page' );
	const TARGET_TYPES   = array( 'page', 'post' );
	const MAX_CONTENT    = 200000;
	const OPT_CENTRAL    = 'peak_seo_central_approval';
	const BY_OWNER       = 'owner';
	const BY_PEAK        = 'peak';

	/**
	 * Has the owner allowed Peak Visibility to approve changes?
	 */
	public static function central_approval_enabled() {
		return '1' === get_option( self::OPT_CENTRAL, '0' );
	}

	public static function register_post_type() {
		register_post_type(
			self::CPT,
			array(
				'label'           => 'Peak SEO proposals',
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Page builders                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Name of the page builder that controls this page's layout, or ''.
	 * Content written by a page builder is not stored as plain post content.
	 * Elementor pages accept text edits through `changes.elementor`; other
	 * builders refuse content edits (SEO field edits always work).
	 */
	public static function page_builder( $post_id ) {
		if ( Peak_SEO_Elementor::is_built_with( $post_id ) ) {
			return 'elementor';
		}
		if ( 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true ) ) {
			return 'divi';
		}
		if ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
			return 'beaver-builder';
		}
		if ( 'true' === get_post_meta( $post_id, '_wpb_vc_js_status', true ) ) {
			return 'wpbakery';
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( false !== strpos( $content, '[et_pb_' ) ) {
			return 'divi';
		}
		if ( false !== strpos( $content, '[vc_row' ) ) {
			return 'wpbakery';
		}
		if ( false !== strpos( $content, '[cs_' ) || false !== strpos( $content, '<!--cs-' ) ) {
			return 'cornerstone';
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Create                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate and store a proposal sent by the service.
	 *
	 * @param array $data Decoded JSON body.
	 * @return int|WP_Error Proposal id.
	 */
	public static function create( $data ) {
		$kind    = isset( $data['kind'] ) ? (string) $data['kind'] : '';
		$changes = isset( $data['changes'] ) && is_array( $data['changes'] ) ? $data['changes'] : array();
		$summary = isset( $data['summary'] ) ? sanitize_text_field( $data['summary'] ) : '';
		$reason  = isset( $data['reason'] ) ? sanitize_textarea_field( $data['reason'] ) : '';
		$target  = isset( $data['target_id'] ) ? absint( $data['target_id'] ) : 0;

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return self::error( 'peak_bad_kind', 'Unknown proposal kind.' );
		}
		if ( '' === $summary ) {
			return self::error( 'peak_no_summary', 'A summary is required.' );
		}

		$clean = array();

		if ( 'create_page' === $kind ) {
			if ( empty( $changes['title'] ) || empty( $changes['content'] ) ) {
				return self::error( 'peak_missing', 'New pages need a title and content.' );
			}
			$clean['title']   = sanitize_text_field( $changes['title'] );
			$clean['content'] = self::clean_content( $changes['content'] );
			if ( is_wp_error( $clean['content'] ) ) {
				return $clean['content'];
			}
			$clean['slug'] = isset( $changes['slug'] ) ? sanitize_title( $changes['slug'] ) : sanitize_title( $clean['title'] );
			if ( get_page_by_path( $clean['slug'], OBJECT, 'page' ) ) {
				return self::error( 'peak_slug_taken', 'A page already uses that address.' );
			}
			$clean['parent'] = isset( $changes['parent_id'] ) ? absint( $changes['parent_id'] ) : 0;
		} else {
			$post = get_post( $target );
			if ( ! $post || ! in_array( $post->post_type, self::TARGET_TYPES, true ) || 'trash' === $post->post_status ) {
				return self::error( 'peak_bad_target', 'Target page not found.' );
			}
		}

		if ( 'update_seo' === $kind ) {
			if ( ! Peak_SEO_Fields::supported() ) {
				return self::error( 'peak_seo_unsupported', 'This site uses All in One SEO, which Peak SEO cannot edit yet.' );
			}
			if ( isset( $changes['seo_title'] ) ) {
				$clean['seo_title'] = sanitize_text_field( $changes['seo_title'] );
			}
			if ( isset( $changes['seo_description'] ) ) {
				$clean['seo_description'] = sanitize_textarea_field( $changes['seo_description'] );
			}
			if ( empty( $clean ) ) {
				return self::error( 'peak_missing', 'Nothing to change.' );
			}
		}

		if ( 'update_content' === $kind ) {
			$builder = self::page_builder( $target );
			if ( 'elementor' === $builder ) {
				if ( isset( $changes['content'] ) || isset( $changes['excerpt'] ) ) {
					return self::error( 'peak_page_builder', 'This page is built with Elementor. Send text edits as "elementor": [{"id": "<widget id>", "text": "..."}] instead of "content".' );
				}
				if ( isset( $changes['elementor'] ) ) {
					$edits = Peak_SEO_Elementor::clean_edits( Peak_SEO_Elementor::get_data( $target ), $changes['elementor'] );
					if ( is_wp_error( $edits ) ) {
						return $edits;
					}
					$clean['elementor'] = $edits;
				}
			} elseif ( '' !== $builder ) {
				return self::error( 'peak_page_builder', "This page is built with {$builder}; content edits are not supported on it yet. SEO field edits still work." );
			} elseif ( isset( $changes['elementor'] ) ) {
				return self::error( 'peak_not_elementor', 'This page is not built with Elementor. Send "content" instead.' );
			}
			if ( isset( $changes['title'] ) ) {
				$clean['title'] = sanitize_text_field( $changes['title'] );
			}
			if ( isset( $changes['content'] ) ) {
				$clean['content'] = self::clean_content( $changes['content'] );
				if ( is_wp_error( $clean['content'] ) ) {
					return $clean['content'];
				}
			}
			if ( isset( $changes['excerpt'] ) ) {
				$clean['excerpt'] = sanitize_textarea_field( $changes['excerpt'] );
			}
			if ( empty( $clean ) ) {
				return self::error( 'peak_missing', 'Nothing to change.' );
			}
		}

		// Facts only the owner can vouch for (prices, phone numbers, hours...).
		$owner_reasons = Peak_SEO_Owner_Check::check_proposal( $kind, $target, $clean );
		if ( ! empty( $data['needs_owner'] ) ) {
			array_unshift( $owner_reasons, 'marked by Peak Visibility' );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $summary,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_peak_kind', $kind );
		update_post_meta( $id, '_peak_target', $target );
		update_post_meta( $id, '_peak_changes', wp_slash( wp_json_encode( $clean ) ) );
		update_post_meta( $id, '_peak_reason', $reason );
		update_post_meta( $id, '_peak_status', 'pending' );
		update_post_meta( $id, '_peak_needs_owner', $owner_reasons ? '1' : '0' );
		update_post_meta( $id, '_peak_owner_reasons', $owner_reasons );

		// New pages are created as drafts right away so the owner can preview them.
		if ( 'create_page' === $kind ) {
			$draft = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => $clean['title'],
					'post_content' => $clean['content'],
					'post_name'    => $clean['slug'],
					'post_parent'  => $clean['parent'],
				),
				true
			);
			if ( is_wp_error( $draft ) ) {
				wp_delete_post( $id, true );
				return $draft;
			}
			update_post_meta( $draft, '_peak_created_by', $id );
			update_post_meta( $id, '_peak_target', $draft );
		}

		self::log( $id, 'received' );
		return $id;
	}

	private static function clean_content( $content ) {
		$content = (string) $content;
		if ( strlen( $content ) > self::MAX_CONTENT ) {
			return self::error( 'peak_too_long', 'Content is too long.' );
		}
		return wp_kses_post( $content );
	}

	/* ------------------------------------------------------------------ */
	/* Read                                                                */
	/* ------------------------------------------------------------------ */

	public static function get( $id ) {
		$p = get_post( $id );
		if ( ! $p || self::CPT !== $p->post_type ) {
			return null;
		}
		return array(
			'id'         => $p->ID,
			'summary'    => $p->post_title,
			'kind'       => get_post_meta( $p->ID, '_peak_kind', true ),
			'target_id'  => (int) get_post_meta( $p->ID, '_peak_target', true ),
			'changes'    => json_decode( (string) get_post_meta( $p->ID, '_peak_changes', true ), true ),
			'reason'     => get_post_meta( $p->ID, '_peak_reason', true ),
			'status'     => get_post_meta( $p->ID, '_peak_status', true ),
			'needs_owner'   => self::needs_owner( $p->ID ),
			'owner_reasons' => self::owner_reasons( $p->ID ),
			'approved_by'   => (string) get_post_meta( $p->ID, '_peak_approved_by', true ), // owner | peak | ''
			'approved_user' => (int) get_post_meta( $p->ID, '_peak_approved_user', true ),
			'approved_at'   => (string) get_post_meta( $p->ID, '_peak_approved_at', true ),
			'created'    => mysql_to_rfc3339( $p->post_date_gmt ),
			'log'        => self::get_log( $p->ID ),
		);
	}

	/**
	 * Proposals received before 0.3.0 were never checked, so they count as
	 * needing the owner.
	 */
	private static function needs_owner( $id ) {
		return '0' !== get_post_meta( $id, '_peak_needs_owner', true );
	}

	private static function owner_reasons( $id ) {
		$r = get_post_meta( $id, '_peak_owner_reasons', true );
		if ( is_array( $r ) ) {
			return array_values( $r );
		}
		return self::needs_owner( $id ) ? array( 'received before this check existed' ) : array();
	}

	public static function query( $status = '' ) {
		$args = array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( $status ) {
			$args['meta_key']   = '_peak_status';
			$args['meta_value'] = $status;
		}
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), get_posts( $args ) ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Approve / reject / undo (called from the admin screen only)         */
	/* ------------------------------------------------------------------ */

	/**
	 * Apply a proposal.
	 *
	 * @param int    $id Proposal id.
	 * @param string $by BY_OWNER (logged-in user, admin screen) or BY_PEAK (signed API).
	 */
	public static function approve( $id, $by = self::BY_OWNER ) {
		$p = self::get( $id );
		if ( ! $p || 'pending' !== $p['status'] ) {
			return self::error( 'peak_not_pending', 'This proposal is not waiting for approval.' );
		}
		if ( self::BY_PEAK === $by ) {
			if ( ! self::central_approval_enabled() ) {
				return new WP_Error( 'peak_central_off', 'The site owner has not allowed Peak Visibility to approve changes.', array( 'status' => 403 ) );
			}
			if ( $p['needs_owner'] ) {
				return new WP_Error( 'peak_needs_owner', 'Only the site owner can approve this change (' . implode( ', ', $p['owner_reasons'] ) . ').', array( 'status' => 403 ) );
			}
		} elseif ( self::BY_OWNER !== $by || ! current_user_can( 'edit_pages' ) ) {
			return new WP_Error( 'peak_forbidden', 'You cannot approve changes.', array( 'status' => 403 ) );
		}
		$target = $p['target_id'];
		$c      = (array) $p['changes'];

		if ( 'create_page' === $p['kind'] ) {
			$r = wp_update_post( array( 'ID' => $target, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			self::save_snapshot( $id, array( 'created' => $target ) );
		} else {
			$post = get_post( $target );
			if ( ! $post ) {
				return self::error( 'peak_bad_target', 'The page no longer exists.' );
			}
			$seo  = Peak_SEO_Fields::get( $target );
			$snap = array(
				'title'           => $post->post_title,
				'content'         => $post->post_content,
				'excerpt'         => $post->post_excerpt,
				'seo_title'       => $seo['seo_title'],
				'seo_description' => $seo['seo_description'],
			);

			$elementor = null;
			if ( 'update_content' === $p['kind'] ) {
				$builder = self::page_builder( $target );
				if ( isset( $c['elementor'] ) ) {
					if ( 'elementor' !== $builder ) {
						return self::error( 'peak_page_builder', 'This page is no longer built with Elementor; nothing was changed.' );
					}
					// Check every edit before writing anything.
					$elementor = Peak_SEO_Elementor::apply_edits( Peak_SEO_Elementor::get_data( $target ), $c['elementor'] );
					if ( is_wp_error( $elementor ) ) {
						return $elementor;
					}
					$snap['elementor_data'] = Peak_SEO_Elementor::get_raw( $target );
				} elseif ( '' !== $builder ) {
					return self::error( 'peak_page_builder', 'This page is now controlled by a page builder; content was not changed.' );
				}
			}

			self::save_snapshot( $id, $snap );

			if ( null !== $elementor ) {
				Peak_SEO_Elementor::save( $target, $elementor );
			}

			if ( 'update_content' === $p['kind'] && ( isset( $c['title'] ) || isset( $c['content'] ) || isset( $c['excerpt'] ) ) ) {
				$update = array( 'ID' => $target );
				if ( isset( $c['title'] ) ) {
					$update['post_title'] = $c['title'];
				}
				if ( isset( $c['content'] ) ) {
					$update['post_content'] = $c['content'];
				}
				if ( isset( $c['excerpt'] ) ) {
					$update['post_excerpt'] = $c['excerpt'];
				}
				$r = wp_update_post( wp_slash( $update ), true ); // Creates a WordPress revision too.
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}

			if ( 'update_seo' === $p['kind'] ) {
				Peak_SEO_Fields::set(
					$target,
					isset( $c['seo_title'] ) ? $c['seo_title'] : null,
					isset( $c['seo_description'] ) ? $c['seo_description'] : null
				);
			}
		}

		update_post_meta( $id, '_peak_status', 'approved' );
		update_post_meta( $id, '_peak_approved_by', $by );
		update_post_meta( $id, '_peak_approved_user', self::BY_OWNER === $by ? get_current_user_id() : 0 );
		update_post_meta( $id, '_peak_approved_at', gmdate( 'c' ) );
		self::log( $id, 'approved', $by );
		return true;
	}

	public static function reject( $id ) {
		$p = self::get( $id );
		if ( ! $p || 'pending' !== $p['status'] ) {
			return self::error( 'peak_not_pending', 'This proposal is not waiting for approval.' );
		}
		if ( 'create_page' === $p['kind'] && $p['target_id'] ) {
			wp_trash_post( $p['target_id'] ); // Our own draft; recoverable from Trash.
		}
		update_post_meta( $id, '_peak_status', 'rejected' );
		self::log( $id, 'rejected' );
		return true;
	}

	public static function undo( $id ) {
		$p = self::get( $id );
		if ( ! $p || 'approved' !== $p['status'] ) {
			return self::error( 'peak_not_approved', 'Only approved changes can be undone.' );
		}
		$snap = json_decode( (string) get_post_meta( $id, '_peak_before', true ), true );
		if ( ! is_array( $snap ) ) {
			return self::error( 'peak_no_snapshot', 'No saved version to restore.' );
		}

		if ( 'create_page' === $p['kind'] ) {
			wp_update_post( array( 'ID' => (int) $snap['created'], 'post_status' => 'draft' ) );
		} elseif ( 'update_content' === $p['kind'] && isset( $snap['elementor_data'] ) ) {
			// Restores the layout and the plain-text copy Elementor keeps in post_content.
			Peak_SEO_Elementor::save( $p['target_id'], $snap['elementor_data'], $snap['content'] );
			if ( isset( $p['changes']['title'] ) ) {
				wp_update_post( wp_slash( array( 'ID' => $p['target_id'], 'post_title' => $snap['title'] ) ) );
			}
		} elseif ( 'update_content' === $p['kind'] ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $p['target_id'],
						'post_title'   => $snap['title'],
						'post_content' => $snap['content'],
						'post_excerpt' => $snap['excerpt'],
					)
				)
			);
		} else {
			Peak_SEO_Fields::set( $p['target_id'], $snap['seo_title'], $snap['seo_description'] );
		}

		update_post_meta( $id, '_peak_status', 'undone' );
		self::log( $id, 'undone' );
		return true;
	}

	private static function save_snapshot( $id, $snap ) {
		update_post_meta( $id, '_peak_before', wp_slash( wp_json_encode( $snap ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Preview of content changes (logged-in editors only)                 */
	/* ------------------------------------------------------------------ */

	public static function preview_url( $id ) {
		$p = self::get( $id );
		if ( ! $p ) {
			return '';
		}
		if ( 'create_page' === $p['kind'] ) {
			return get_preview_post_link( $p['target_id'] );
		}
		if ( 'update_content' !== $p['kind'] ) {
			return '';
		}
		return add_query_arg(
			array(
				'peak_preview' => $id,
				'_wpnonce'     => wp_create_nonce( 'peak_preview_' . $id ),
			),
			get_permalink( $p['target_id'] )
		);
	}

	public static function maybe_preview() {
		if ( empty( $_GET['peak_preview'] ) ) {
			return;
		}
		$id = absint( $_GET['peak_preview'] );
		if ( ! current_user_can( 'edit_pages' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'peak_preview_' . $id ) ) {
			return;
		}
		$p = self::get( $id );
		if ( ! $p || 'update_content' !== $p['kind'] || get_queried_object_id() !== $p['target_id'] ) {
			return;
		}
		$c = (array) $p['changes'];
		if ( isset( $c['elementor'] ) ) {
			$r = Peak_SEO_Elementor::preview( $p['target_id'], $c['elementor'] );
			if ( is_wp_error( $r ) ) {
				wp_die( esc_html( 'Peak SEO cannot preview this change: ' . $r->get_error_message() ) );
			}
		}
		if ( isset( $c['content'] ) ) {
			add_filter(
				'the_content',
				function ( $content ) use ( $c, $p ) {
					return ( in_the_loop() && get_the_ID() === $p['target_id'] ) ? $c['content'] : $content;
				},
				1
			);
		}
		if ( isset( $c['title'] ) ) {
			add_filter(
				'the_title',
				function ( $title, $post_id = 0 ) use ( $c, $p ) {
					return ( (int) $post_id === $p['target_id'] ) ? $c['title'] : $title;
				},
				10,
				2
			);
		}
		add_action(
			'wp_footer',
			function () {
				echo '<div style="position:fixed;bottom:0;left:0;right:0;z-index:99999;background:#1d2327;color:#fff;padding:10px 16px;font:14px/1.4 sans-serif;text-align:center">Peak SEO preview: this is how the page would look if you approve the change. Nothing is live yet.</div>';
			}
		);
	}

	/* ------------------------------------------------------------------ */

	private static function get_log( $id ) {
		$log = get_post_meta( $id, '_peak_log', true );
		return is_array( $log ) ? $log : array();
	}

	private static function log( $id, $event, $by = '' ) {
		$log   = self::get_log( $id );
		$entry = array(
			'event' => $event,
			'at'    => gmdate( 'c' ),
			'user'  => get_current_user_id(),
		);
		if ( $by ) {
			$entry['by'] = $by;
		}
		$log[] = $entry;
		update_post_meta( $id, '_peak_log', $log );
	}

	private static function error( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
