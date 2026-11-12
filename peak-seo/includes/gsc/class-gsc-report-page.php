<?php
/**
 * Peak SEO → Search Console: the "What to work on" report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_GSC_Report_Page {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}
		$r        = Peak_SEO_GSC_Report::build();
		$settings = admin_url( 'admin.php?page=' . Peak_SEO_Admin::SLUG . '-settings#peak-gsc-data' );

		echo '<div class="wrap peak-seo peak-gsc-report"><h1>Search Console: what to work on</h1>';
		if ( empty( $r['available'] ) ) {
			echo '<div class="peak-empty">' . esc_html( $r['message'] ) . ' <a href="' . esc_url( $settings ) . '">Open settings</a></div></div>';
			return;
		}

		$fmt  = get_option( 'date_format' );
		$when = strtotime( $r['pulled_at'] );
		echo '<p class="peak-intro"><code>' . esc_html( $r['property'] ) . '</code> · ' . esc_html( self::range( $r['current'], $fmt ) ) . ', compared with ' . esc_html( self::range( $r['previous'], $fmt ) ) . '. Data pulled ' . esc_html( human_time_diff( $when ) ) . ' ago (<a href="' . esc_url( $settings ) . '">pull again</a>).</p>';

		self::totals( $r['totals'] );

		if ( ! $r['enough_data'] ) {
			echo '<div class="peak-empty">' . esc_html( $r['message'] ) . '</div></div>';
			return;
		}
		if ( $r['truncated'] ) {
			echo '<p class="description">Some lists reached Google’s 1,000-row limit, so the smallest searches are left out. That doesn’t change the lists below.</p>';
		}

		$s = $r['sections'];
		self::table(
			$s['low_ctr'],
			array( 'Page', 'Impressions', 'Clicks', 'CTR (usual)', 'Position', 'Main searches' ),
			function ( $i ) {
				return array(
					self::page_cell( $i['page'], $i ),
					number_format_i18n( $i['impressions'] ),
					number_format_i18n( $i['clicks'] ),
					esc_html( self::pct( $i['ctr'] ) . ' (' . self::pct( $i['expected_ctr'] ) . ')' ),
					esc_html( $i['position'] ),
					esc_html( implode( ', ', $i['top_queries'] ) ),
				);
			}
		);
		self::table(
			$s['striking'],
			array( 'Page', 'Searches it could win (position)', 'Impressions', 'Clicks', 'Avg position' ),
			function ( $i ) {
				$list = array();
				foreach ( array_slice( $i['searches'], 0, 6 ) as $q ) {
					$list[] = esc_html( $q['query'] ) . ' <span class="description">(' . esc_html( $q['position'] ) . ')</span>';
				}
				$more = $i['search_count'] - count( $list );
				return array(
					self::page_cell( $i['page'], $i ),
					implode( '<br />', $list ) . ( $more > 0 ? '<br /><span class="description">+ ' . (int) $more . ' more</span>' : '' ),
					number_format_i18n( $i['impressions'] ),
					number_format_i18n( $i['clicks'] ),
					esc_html( $i['position'] ),
				);
			}
		);
		self::table(
			$s['new_content'],
			array( 'Search', 'Impressions', 'Best position', 'Shows up on' ),
			function ( $i ) {
				return array(
					'<strong>' . esc_html( $i['query'] ) . '</strong>',
					number_format_i18n( $i['impressions'] ),
					esc_html( $i['best_position'] ),
					$i['best_page'] ? self::page_cell( $i['best_page'], array() ) : '&mdash;',
				);
			}
		);
		self::table(
			$s['movers'],
			array( 'Search or page', 'Now', 'Before', 'Change', 'Impressions' ),
			function ( $i ) {
				$arrow = 'up' === $i['direction'] ? '▲' : '▼';
				return array(
					'query' === $i['type'] ? '<strong>' . esc_html( $i['key'] ) . '</strong>' : self::page_cell( $i['key'], array() ),
					esc_html( $i['position'] ),
					esc_html( $i['position_before'] ),
					'<span class="peak-move-' . esc_attr( $i['direction'] ) . '">' . esc_html( $arrow . ' ' . abs( $i['change'] ) ) . '</span>',
					number_format_i18n( $i['impressions'] ),
				);
			}
		);

		echo '<p class="description">Position is the average position in Google (1 = top). Lists show searches and pages above minimum impression counts, so tiny numbers don’t create noise. The same report is available as JSON at <code>' . esc_html( rest_url( Peak_SEO_Rest::NS . '/gsc-report' ) ) . '</code> for an administrator (Application Password) or a signed Peak SEO request.</p>';
		echo '</div>';
	}

	private static function totals( $t ) {
		$c     = $t['current'];
		$p     = $t['previous'];
		$had   = $p['impressions'] > 0; // Was there any data in the previous 28 days?
		$tiles = array(
			array( 'Clicks', number_format_i18n( $c['clicks'] ), self::change( $c['clicks'], $p['clicks'], false, $had ) ),
			array( 'Impressions', number_format_i18n( $c['impressions'] ), self::change( $c['impressions'], $p['impressions'], false, $had ) ),
			array( 'Click-through rate', self::pct( $c['ctr'] ), self::change( $c['ctr'], $p['ctr'], false, $had, true ) ),
			array( 'Average position', (string) $c['position'], self::change( $c['position'], $p['position'], true, $had ) ),
		);
		echo '<div class="peak-tiles">';
		foreach ( $tiles as $tile ) {
			echo '<div class="peak-tile"><div class="peak-tile-label">' . esc_html( $tile[0] ) . '</div><div class="peak-tile-value">' . esc_html( $tile[1] ) . '</div><div class="peak-tile-change">' . wp_kses_post( $tile[2] ) . '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * "▲ 12% vs previous". For position, lower is better.
	 */
	private static function change( $now, $before, $lower_is_better, $had_data, $is_rate = false ) {
		if ( ! $had_data ) {
			return '<span class="description">no earlier data</span>';
		}
		if ( ! $before ) {
			if ( ! $now ) {
				return '<span class="description">same as previous</span>';
			}
			$from = $is_rate ? self::pct( 0 ) : '0';
			return '<span class="peak-move-up">' . esc_html( '▲ up from ' . $from ) . '</span>';
		}
		if ( $lower_is_better ) {
			$diff = round( $before - $now, 1 );
			$text = ( $diff >= 0 ? '▲ ' : '▼ ' ) . abs( $diff ) . ' vs previous';
			$good = $diff >= 0;
		} else {
			$pct  = ( $now - $before ) / $before * 100;
			$text = ( $pct >= 0 ? '▲ ' : '▼ ' ) . abs( round( $pct ) ) . '% vs previous';
			$good = $pct >= 0;
		}
		return '<span class="peak-move-' . ( $good ? 'up' : 'down' ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function table( $section, $headers, $row ) {
		echo '<h2>' . esc_html( $section['title'] ) . ' <span class="peak-count-badge">' . (int) $section['total'] . '</span></h2>';
		echo '<p class="description" style="max-width:760px">' . esc_html( $section['help'] ) . '</p>';
		if ( ! $section['items'] ) {
			echo '<p class="peak-none">Nothing here right now.</p>';
			return;
		}
		echo '<table class="widefat striped peak-report"><thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $section['items'] as $item ) {
			echo '<tr><td>' . implode( '</td><td>', $row( $item ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- cells are escaped above.
		}
		echo '</tbody></table>';
		if ( $section['total'] > count( $section['items'] ) ) {
			echo '<p class="description">Showing the top ' . (int) count( $section['items'] ) . ' of ' . (int) $section['total'] . '.</p>';
		}
	}

	private static function page_cell( $url, $item ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$out  = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( '' === $path ? $url : $path ) . '</a>';
		if ( ! empty( $item['post_id'] ) ) {
			$out .= '<br /><span class="description">' . esc_html( $item['title'] ) . '</span> · <a href="' . esc_url( get_edit_post_link( $item['post_id'] ) ) . '">Edit</a>';
		}
		return $out;
	}

	/**
	 * 12.3%, 0.45%, 0.02%: more decimals for small rates so they don't show as 0%.
	 */
	private static function pct( $ctr ) {
		$p = $ctr * 100;
		if ( 0.0 === (float) $p ) {
			return '0%';
		}
		return ( $p >= 1 ? round( $p, 1 ) : ( $p >= 0.1 ? round( $p, 2 ) : max( 0.01, round( $p, 2 ) ) ) ) . '%';
	}

	private static function range( $r, $fmt ) {
		return wp_date( $fmt, strtotime( $r['start'] . ' 12:00 UTC' ) ) . ' – ' . wp_date( $fmt, strtotime( $r['end'] . ' 12:00 UTC' ) );
	}
}
