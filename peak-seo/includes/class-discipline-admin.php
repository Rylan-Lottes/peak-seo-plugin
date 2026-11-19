<?php
/**
 * Page Discipline screens: the settings form (Settings page), the owner-only
 * "Protected / Has backlinks" box on the page editor, and the section on
 * Peak SEO → Search Console.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Discipline_Admin {

	public static function init() {
		add_action( 'admin_post_peak_seo_discipline', array( __CLASS__, 'handle_settings' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_flags' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only the site owner can change this.' );
		}
		check_admin_referer( 'peak_seo_discipline' );
		$raw = isset( $_POST['discipline'] ) && is_array( $_POST['discipline'] ) ? wp_unslash( $_POST['discipline'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- numbers, cleaned below.
		if ( ! empty( $_POST['reset'] ) ) {
			$raw = Peak_SEO_Discipline::defaults();
		}
		update_option( Peak_SEO_Discipline::OPT_SETTINGS, Peak_SEO_Discipline::clean_settings( $raw ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => Peak_SEO_Admin::SLUG . '-settings', 'peak_done' => 'discipline' ), admin_url( 'admin.php' ) ) . '#peak-discipline' );
		exit;
	}

	public static function render_settings() {
		$s = Peak_SEO_Discipline::settings();
		echo '<h2 id="peak-discipline">Page Discipline</h2>';
		echo '<p class="description" style="max-width:720px">Rules that keep the site small and every page useful. Peak Visibility’s service follows them and can read them, but only people logged in to this site can change them. These are rules of thumb, not guarantees.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'peak_seo_discipline' );
		echo '<input type="hidden" name="action" value="peak_seo_discipline" /><table class="form-table"><tbody>';
		foreach ( Peak_SEO_Discipline::FIELDS as $key => $f ) {
			echo '<tr><th><label for="peak-d-' . esc_attr( $key ) . '">' . esc_html( $f[3] ) . '</label></th><td>';
			echo '<input type="number" class="small-text" id="peak-d-' . esc_attr( $key ) . '" name="discipline[' . esc_attr( $key ) . ']" value="' . (int) $s[ $key ] . '" min="' . (int) $f[1] . '" max="' . (int) $f[2] . '" />';
			echo ' <span class="description">Default ' . (int) $f[0] . '. ' . esc_html( $f[4] ) . '</span></td></tr>';
		}
		echo '</tbody></table><p><button type="submit" class="button button-primary">Save Page Discipline</button> <button type="submit" name="reset" value="1" class="button">Reset to defaults</button></p></form>';
		echo '<p class="description">Mark individual pages as <strong>Protected</strong> or <strong>Has backlinks</strong> in the “Peak SEO” box when editing them; those pages are never suggested for cleanup or refocusing. The home page and contact page are always left alone.</p>';
	}

	/* ------------------------------------------------------------------ */
	/* Per-page flags (owner-only)                                         */
	/* ------------------------------------------------------------------ */

	public static function meta_box() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( Peak_SEO_Proposals::TARGET_TYPES as $type ) {
			add_meta_box( 'peak-seo-flags', 'Peak SEO', array( __CLASS__, 'render_box' ), $type, 'side', 'low' );
		}
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'peak_seo_flags_' . $post->ID, 'peak_seo_flags_nonce' );
		$protected = '1' === (string) get_post_meta( $post->ID, Peak_SEO_Discipline::META_PROTECT, true );
		$backlinks = '1' === (string) get_post_meta( $post->ID, Peak_SEO_Discipline::META_BACKLINK, true );
		echo '<p><label><input type="checkbox" name="peak_protected" value="1"' . checked( $protected, true, false ) . ' /> Protected</label><br /><span class="description">Never suggest merging, noindexing or refocusing this page.</span></p>';
		echo '<p><label><input type="checkbox" name="peak_backlinks" value="1"' . checked( $backlinks, true, false ) . ' /> Has backlinks</label><br /><span class="description">Other websites link here, so it’s never a cleanup candidate. (Google’s API doesn’t report links, so this is set by hand.)</span></p>';
		$reason = Peak_SEO_Discipline::noindex_reason( $post );
		if ( 'publish' === $post->post_status && $reason ) {
			echo '<p class="description">Not indexable: ' . esc_html( $reason ) . '.</p>';
		}
	}

	public static function save_flags( $post_id, $post ) {
		if ( ! isset( $_POST['peak_seo_flags_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['peak_seo_flags_nonce'] ) ), 'peak_seo_flags_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'peak_protected' => Peak_SEO_Discipline::META_PROTECT, 'peak_backlinks' => Peak_SEO_Discipline::META_BACKLINK ) as $field => $meta ) {
			if ( ! empty( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta, '1' );
			} else {
				delete_post_meta( $post_id, $meta );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Report section                                                      */
	/* ------------------------------------------------------------------ */

	public static function render_report_section() {
		$d = Peak_SEO_Discipline::build();
		$s = $d['settings'];
		echo '<h2>Page Discipline <span class="peak-count-badge">rules of thumb</span></h2>';

		$h = $d['health'];
		echo '<div class="peak-tiles">';
		if ( $h && null !== $h['pct'] ) {
			$ok = $h['pct'] >= $s['health_min_pct'];
			echo '<div class="peak-tile"><div class="peak-tile-label">Site health</div><div class="peak-tile-value">' . (int) $h['pct'] . '%</div><div class="peak-tile-change"><span class="' . ( $ok ? 'peak-move-up' : 'peak-move-down' ) . '">' . esc_html( sprintf( '%d of %d indexable pages got impressions · minimum %d%%', $h['with_impressions'], $h['indexable'], $s['health_min_pct'] ) ) . '</span></div></div>';
		} else {
			echo '<div class="peak-tile"><div class="peak-tile-label">Site health</div><div class="peak-tile-value">–</div><div class="peak-tile-change">Needs Search Console data</div></div>';
		}
		$n = $d['new_pages'];
		echo '<div class="peak-tile"><div class="peak-tile-label">New pages this month</div><div class="peak-tile-value">' . (int) $n['used'] . ' / ' . (int) $n['cap'] . '</div><div class="peak-tile-change">' . ( $n['allowed'] ? '<span class="peak-move-up">More allowed</span>' : '<span class="peak-move-down">' . esc_html( implode( ' ', $n['reasons'] ) ) . '</span>' ) . '</div></div>';
		echo '<div class="peak-tile"><div class="peak-tile-label">Left alone (recently changed)</div><div class="peak-tile-value">' . count( $d['recently_changed'] ) . '</div><div class="peak-tile-change">Changed in the last ' . (int) $s['recent_change_days'] . ' days</div></div>';
		echo '</div>';

		echo '<h3>Competing pages <span class="peak-count-badge">' . count( $d['competing'] ) . '</span></h3>';
		echo '<p class="description" style="max-width:760px">Two pages splitting Google’s impressions for the same searches. One page should own the search; the other is refocused on a different question and links to the main page.</p>';
		if ( ! $d['competing'] ) {
			echo '<p class="peak-none">Nothing here right now.</p>';
		} else {
			echo '<table class="widefat striped peak-report"><thead><tr><th>Main page</th><th>Page to refocus</th><th>Searches they share</th><th>Impressions</th></tr></thead><tbody>';
			foreach ( array_slice( $d['competing'], 0, 10 ) as $c ) {
				$qs = array_map( function ( $q ) { return esc_html( $q['query'] ); }, array_slice( $c['queries'], 0, 5 ) );
				echo '<tr><td>' . esc_html( wp_parse_url( $c['main'], PHP_URL_PATH ) ) . '</td><td>' . esc_html( wp_parse_url( $c['refocus'], PHP_URL_PATH ) ) . '</td><td>' . implode( '<br />', $qs ) . '</td><td>' . number_format_i18n( $c['impressions'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			}
			echo '</tbody></table>';
		}

		echo '<h3>Cleanup candidates <span class="peak-count-badge">' . count( $d['cleanup'] ) . '</span></h3>';
		echo '<p class="description" style="max-width:760px">Pages at least ' . (int) $s['cleanup_min_age_days'] . ' days old that got no impressions, or never reached the top ' . (int) $s['top_position'] . ', in the last ' . (int) $s['cleanup_lookback_days'] . ' days. Close ones (up to position ' . (int) $s['close_max_position'] . ') are worth strengthening; the rest are worth merging into a stronger page. Never the home page, contact page, protected pages or pages with backlinks.</p>';
		if ( ! $d['cleanup'] ) {
			echo '<p class="peak-none">Nothing here right now.</p>';
		} else {
			echo '<table class="widefat striped peak-report"><thead><tr><th>Page</th><th>Suggestion</th><th>Why</th></tr></thead><tbody>';
			foreach ( array_slice( $d['cleanup'], 0, 15 ) as $c ) {
				echo '<tr><td><a href="' . esc_url( $c['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $c['title'] ) . '</a> · <a href="' . esc_url( get_edit_post_link( $c['id'] ) ) . '">Edit</a></td><td>' . ( 'strengthen' === $c['action'] ? 'Strengthen' : 'Merge into a stronger page' ) . '</td><td>' . esc_html( $c['why'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
