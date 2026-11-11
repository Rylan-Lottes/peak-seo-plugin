<?php
/**
 * Weekly summary email: the changes Peak Visibility approved on the owner's
 * behalf, each with a link to the History screen where it can be undone.
 * Sent to the site's admin email, and only when there is something to report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Digest {

	const HOOK     = 'peak_seo_weekly_digest';
	const OPT_LAST = 'peak_seo_digest_last';

	/**
	 * Make sure the weekly check is scheduled. Runs on every load because
	 * replacing the plugin with a newer zip does not re-run activation.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback.
	 */
	public static function run() {
		self::send( false );
	}

	/**
	 * Changes approved by Peak Visibility since a time (unix seconds).
	 */
	public static function approved_since( $since ) {
		$out = array();
		foreach ( Peak_SEO_Proposals::query() as $p ) {
			if ( Peak_SEO_Proposals::BY_PEAK !== $p['approved_by'] || ! $p['approved_at'] ) {
				continue;
			}
			if ( strtotime( $p['approved_at'] ) >= $since ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Send the summary.
	 *
	 * @param bool $manual True from the Settings button: covers the past 7
	 *                     days, sends even when empty, and does not move the
	 *                     weekly marker.
	 * @return true|WP_Error|null Null when there was nothing to send.
	 */
	public static function send( $manual = false ) {
		$now   = time();
		$last  = (int) get_option( self::OPT_LAST, 0 );
		$since = $manual || ! $last ? $now - WEEK_IN_SECONDS : $last;
		$items = self::approved_since( $since );

		if ( ! $manual ) {
			update_option( self::OPT_LAST, $now, false );
			if ( ! $items ) {
				return null;
			}
		}

		$to   = get_option( 'admin_email' );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$n    = count( $items );
		if ( $n ) {
			$subject = sprintf( '[%s] Peak Visibility approved %d %s this week', $site, $n, 1 === $n ? 'change' : 'changes' );
		} else {
			$subject = sprintf( '[%s] No changes approved by Peak Visibility this week', $site );
		}

		$sent = wp_mail( $to, $subject, self::body( $items, $site ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( ! $sent ) {
			return new WP_Error( 'peak_mail_failed', 'WordPress could not send the email. Check that this site can send mail.' );
		}
		return true;
	}

	/**
	 * Link to one change in History, where the owner can undo it.
	 */
	public static function history_link( $id ) {
		return add_query_arg(
			array(
				'page'       => Peak_SEO_Admin::SLUG,
				'peak_focus' => (int) $id,
			),
			admin_url( 'admin.php' )
		) . '#peak-proposal-' . (int) $id;
	}

	private static function body( $items, $site ) {
		$history = admin_url( 'admin.php?page=' . Peak_SEO_Admin::SLUG ) . '#peak-history';
		$h       = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;max-width:620px">';
		$h      .= '<p>Hello,</p>';
		if ( $items ) {
			$h .= '<p>You let Peak Visibility review and approve changes on <strong>' . esc_html( $site ) . '</strong>. Here is what was approved in the past week. Each one can be undone from your dashboard.</p>';
			$h .= '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">';
			foreach ( $items as $p ) {
				$page  = get_post( $p['target_id'] );
				$when  = wp_date( get_option( 'date_format' ), strtotime( $p['approved_at'] ) );
				$state = 'undone' === $p['status'] ? ' <span style="color:#614200">(already undone)</span>' : '';
				$h    .= '<tr><td style="padding:12px 0;border-top:1px solid #dcdcde">';
				$h    .= '<strong>' . esc_html( $p['summary'] ) . '</strong>' . $state . '<br />';
				$h    .= '<span style="color:#50575e;font-size:13px">' . esc_html( $when );
				if ( $page ) {
					$h .= ' &middot; <a href="' . esc_url( get_permalink( $page ) ) . '" style="color:#2271b1">' . esc_html( $page->post_title ) . '</a>';
				}
				$h .= '</span><br />';
				if ( 'approved' === $p['status'] ) {
					$h .= '<a href="' . esc_url( self::history_link( $p['id'] ) ) . '" style="color:#2271b1;font-size:13px">Review or undo this change</a>';
				}
				$h .= '</td></tr>';
			}
			$h .= '</table>';
		} else {
			$h .= '<p>Peak Visibility did not approve any changes on <strong>' . esc_html( $site ) . '</strong> in the past week.</p>';
		}
		$h .= '<p style="margin-top:20px"><a href="' . esc_url( $history ) . '" style="color:#2271b1">See all changes in your dashboard</a></p>';
		$h .= '<p style="color:#50575e;font-size:13px">You get this email because &ldquo;Let Peak Visibility review and approve changes for me&rdquo; is on in Peak SEO &rarr; Settings. Turn it off there at any time.</p>';
		$h .= '</div>';
		return $h;
	}
}
