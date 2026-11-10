<?php
/**
 * Dashboard screens: review proposals (approve / reject / undo) and settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Admin {

	const SLUG = 'peak-seo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_peak_seo_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_peak_seo_regen_key', array( __CLASS__, 'handle_regen_key' ) );
	}

	public static function menu() {
		$pending = count( Peak_SEO_Proposals::query( 'pending' ) );
		$badge   = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		add_menu_page( 'Peak SEO', 'Peak SEO' . $badge, 'edit_pages', self::SLUG, array( __CLASS__, 'render_proposals' ), 'dashicons-chart-line', 81 );
		add_submenu_page( self::SLUG, 'Proposed changes', 'Proposed changes', 'edit_pages', self::SLUG, array( __CLASS__, 'render_proposals' ) );
		add_submenu_page( self::SLUG, 'Peak SEO settings', 'Settings', 'manage_options', self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'peak-seo-admin', PEAK_SEO_URL . 'assets/admin.css', array(), PEAK_SEO_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_action() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}
		$id = isset( $_POST['proposal'] ) ? absint( $_POST['proposal'] ) : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		check_admin_referer( 'peak_seo_' . $do . '_' . $id );

		switch ( $do ) {
			case 'approve':
				$r = Peak_SEO_Proposals::approve( $id );
				break;
			case 'reject':
				$r = Peak_SEO_Proposals::reject( $id );
				break;
			case 'undo':
				$r = Peak_SEO_Proposals::undo( $id );
				break;
			default:
				$r = new WP_Error( 'peak_bad_action', 'Unknown action.' );
		}

		$args = array( 'page' => self::SLUG );
		if ( is_wp_error( $r ) ) {
			$args['peak_error'] = rawurlencode( $r->get_error_message() );
		} else {
			$args['peak_done'] = $do;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_regen_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}
		check_admin_referer( 'peak_seo_regen_key' );
		update_option( 'peak_seo_site_key', Peak_SEO_Auth::generate_key(), false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG . '-settings', 'peak_done' => 'key' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function button( $id, $do, $label, $class = 'button' ) {
		$confirm = 'undo' === $do ? ' onclick="return confirm(\'Put back the version from before this change?\');"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="peak-inline">';
		wp_nonce_field( 'peak_seo_' . $do . '_' . $id );
		echo '<input type="hidden" name="action" value="peak_seo_action" />';
		echo '<input type="hidden" name="proposal" value="' . (int) $id . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '" />';
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . $confirm . '>' . esc_html( $label ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</form>';
	}

	/* ------------------------------------------------------------------ */
	/* Proposals screen                                                    */
	/* ------------------------------------------------------------------ */

	private static function notices() {
		if ( ! empty( $_GET['peak_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['peak_error'] ) ) ) ) . '</p></div>';
		}
		$done = isset( $_GET['peak_done'] ) ? sanitize_key( $_GET['peak_done'] ) : '';
		$msgs = array(
			'approve' => 'Approved. The change is live on your site.',
			'reject'  => 'Rejected. Nothing on your site changed.',
			'undo'    => 'Undone. The previous version is back.',
			'key'     => 'New site key created. Update it in your Peak SEO account, or the service can no longer connect.',
		);
		if ( isset( $msgs[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $done ] ) . '</p></div>';
		}
	}

	private static function kind_label( $kind ) {
		$labels = array(
			'update_seo'     => 'Search title & description',
			'update_content' => 'Page content',
			'create_page'    => 'New page',
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	public static function render_proposals() {
		$pending = Peak_SEO_Proposals::query( 'pending' );
		$all     = Peak_SEO_Proposals::query();
		$history = array_values( array_filter( $all, function ( $p ) { return 'pending' !== $p['status']; } ) );

		echo '<div class="wrap peak-seo">';
		echo '<h1>Peak SEO: proposed changes</h1>';
		self::notices();
		echo '<p class="peak-intro">Each suggestion below is waiting for you. Nothing changes on your site until you click <strong>Approve</strong>, and any approved change can be undone.</p>';

		if ( empty( $pending ) ) {
			echo '<div class="peak-empty">No changes waiting for review.</div>';
		}

		foreach ( $pending as $p ) {
			self::render_card( $p );
		}

		if ( $history ) {
			echo '<h2>History</h2><table class="widefat striped peak-history"><thead><tr><th>Date</th><th>Change</th><th>Type</th><th>Page</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $history as $p ) {
				$page = get_post( $p['target_id'] );
				echo '<tr>';
				echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ), $p['created'] ) ) . '</td>';
				echo '<td>' . esc_html( $p['summary'] ) . '</td>';
				echo '<td>' . esc_html( self::kind_label( $p['kind'] ) ) . '</td>';
				echo '<td>' . ( $page ? '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">' . esc_html( $page->post_title ) . '</a>' : '&mdash;' ) . '</td>';
				echo '<td><span class="peak-status peak-status-' . esc_attr( $p['status'] ) . '">' . esc_html( ucfirst( $p['status'] ) ) . '</span></td>';
				echo '<td>';
				if ( 'approved' === $p['status'] ) {
					self::button( $p['id'], 'undo', 'Undo' );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private static function render_card( $p ) {
		$c    = (array) $p['changes'];
		$page = get_post( $p['target_id'] );

		echo '<div class="peak-card">';
		echo '<div class="peak-card-head"><span class="peak-kind">' . esc_html( self::kind_label( $p['kind'] ) ) . '</span>';
		if ( $page && 'create_page' !== $p['kind'] ) {
			echo ' <span class="peak-page">on <a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">' . esc_html( $page->post_title ) . '</a></span>';
		}
		echo '</div>';
		echo '<h2 class="peak-summary">' . esc_html( $p['summary'] ) . '</h2>';
		if ( $p['reason'] ) {
			echo '<p class="peak-reason"><strong>Why:</strong> ' . esc_html( $p['reason'] ) . '</p>';
		}

		if ( 'update_seo' === $p['kind'] && $page ) {
			$now = Peak_SEO_Fields::get( $page->ID );
			echo '<table class="peak-compare"><thead><tr><th></th><th>Now</th><th>Proposed</th></tr></thead><tbody>';
			if ( isset( $c['seo_title'] ) ) {
				self::compare_row( 'Search title', $now['seo_title'] ? $now['seo_title'] : '(default: ' . $page->post_title . ')', $c['seo_title'], 60 );
			}
			if ( isset( $c['seo_description'] ) ) {
				self::compare_row( 'Description', $now['seo_description'] ? $now['seo_description'] : '(none)', $c['seo_description'], 160 );
			}
			echo '</tbody></table>';
			$title = isset( $c['seo_title'] ) ? $c['seo_title'] : ( $now['seo_title'] ? $now['seo_title'] : $page->post_title );
			$desc  = isset( $c['seo_description'] ) ? $c['seo_description'] : $now['seo_description'];
			echo '<div class="peak-serp"><div class="peak-serp-label">How it could look on Google</div>';
			echo '<div class="peak-serp-url">' . esc_html( get_permalink( $page ) ) . '</div>';
			echo '<div class="peak-serp-title">' . esc_html( $title ) . '</div>';
			echo '<div class="peak-serp-desc">' . esc_html( $desc ) . '</div></div>';
		}

		if ( 'update_content' === $p['kind'] && $page ) {
			if ( isset( $c['title'] ) ) {
				echo wp_text_diff( $page->post_title, $c['title'], array( 'title' => 'Page title', 'title_left' => 'Now', 'title_right' => 'Proposed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( isset( $c['content'] ) ) {
				echo wp_text_diff( $page->post_content, $c['content'], array( 'title' => 'Page content', 'title_left' => 'Now', 'title_right' => 'Proposed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( isset( $c['excerpt'] ) ) {
				echo wp_text_diff( $page->post_excerpt, $c['excerpt'], array( 'title' => 'Excerpt', 'title_left' => 'Now', 'title_right' => 'Proposed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}

		if ( 'create_page' === $p['kind'] ) {
			echo '<p><strong>Title:</strong> ' . esc_html( $c['title'] ) . '<br /><strong>Address:</strong> ' . esc_html( home_url( '/' . $c['slug'] . '/' ) ) . '</p>';
		}

		echo '<div class="peak-actions">';
		self::button( $p['id'], 'approve', 'Approve', 'button button-primary' );
		self::button( $p['id'], 'reject', 'Reject' );
		$preview = Peak_SEO_Proposals::preview_url( $p['id'] );
		if ( $preview ) {
			echo '<a class="button" href="' . esc_url( $preview ) . '" target="_blank">Preview</a>';
		}
		echo '</div></div>';
	}

	private static function compare_row( $label, $now, $new, $limit ) {
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $new ) : strlen( $new );
		$warn = $len > $limit ? ' peak-over' : '';
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $now ) . '</td><td>' . esc_html( $new ) . '<div class="peak-count' . esc_attr( $warn ) . '">' . (int) $len . ' / ' . (int) $limit . ' characters</div></td></tr>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                     */
	/* ------------------------------------------------------------------ */

	public static function render_settings() {
		$key  = Peak_SEO_Auth::get_key();
		$last = (int) get_option( 'peak_seo_last_contact', 0 );

		echo '<div class="wrap peak-seo"><h1>Peak SEO settings</h1>';
		self::notices();
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Site address</th><td><code>' . esc_html( home_url( '/' ) ) . '</code></td></tr>';
		echo '<tr><th>Site key</th><td><input type="text" readonly class="regular-text code" id="peak-key" value="' . esc_attr( $key ) . '" onclick="this.select()" /> ';
		echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'peak-key\').value);this.textContent=\'Copied\'">Copy</button>';
		echo '<p class="description">Paste this key into your Peak SEO account to connect this site. Keep it private: it lets the service read your pages and send suggestions (it cannot publish anything on its own).</p></td></tr>';
		echo '<tr><th>Last contact</th><td>' . ( $last ? esc_html( human_time_diff( $last ) . ' ago' ) : 'Not connected yet' ) . '</td></tr>';
		echo '<tr><th>SEO plugin detected</th><td>' . esc_html( Peak_SEO_Fields::provider() ) . ( Peak_SEO_Fields::supported() ? '' : ' (search titles and descriptions cannot be edited yet)' ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>Disconnect or reset</h2><p>Creating a new key immediately disconnects anything using the old one.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'peak_seo_regen_key' );
		echo '<input type="hidden" name="action" value="peak_seo_regen_key" />';
		echo '<button type="submit" class="button" onclick="return confirm(\'Create a new key? The current connection will stop working.\');">Create new key</button></form>';
		echo '</div>';
	}
}
