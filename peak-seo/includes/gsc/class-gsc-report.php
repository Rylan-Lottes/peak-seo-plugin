<?php
/**
 * "What to work on": turns the latest Search Console snapshot into four lists.
 *
 *   low_ctr      pages with plenty of impressions but a click-through rate well
 *                below what their position usually gets → better title/description
 *   striking     searches at average position 5–20 → strengthen the page that ranks
 *   new_content  searches with impressions where no page ranks better than 20
 *                → maybe a new page or post
 *   movers       biggest position changes vs the previous 28 days
 *
 * Thresholds keep tiny numbers out; they can be changed with the
 * `peak_seo_gsc_thresholds` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_GSC_Report {

	/**
	 * Typical organic click-through rate by position (rounded industry
	 * averages). Only used to spot pages doing much worse than usual.
	 */
	const EXPECTED_CTR = array( 1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.033, 8 => 0.027, 9 => 0.023, 10 => 0.02 );

	public static function thresholds() {
		$defaults = array(
			'min_total_impressions'   => 300,  // Below this for the whole site: "not enough data yet".
			'low_ctr_min_impressions' => 100,
			'low_ctr_max_position'    => 15,   // Lower than this, CTR is low anyway.
			'low_ctr_ratio'           => 0.5,  // Flag when CTR < half the usual for the position.
			'striking_min_impressions' => 50,
			'striking_min_position'   => 5,
			'striking_max_position'   => 20,
			'new_min_impressions'     => 30,
			'new_min_position'        => 20,   // Best page ranks worse than this.
			'movers_min_impressions'  => 50,   // In both periods.
			'movers_min_change'       => 3,    // Positions.
			'limit'                   => 25,   // Items per list.
		);
		$t = apply_filters( 'peak_seo_gsc_thresholds', $defaults );
		return is_array( $t ) ? array_merge( $defaults, $t ) : $defaults;
	}

	public static function expected_ctr( $position ) {
		$p = max( 1, (float) $position );
		if ( $p <= 10 ) {
			$lo = (int) floor( $p );
			$hi = min( 10, $lo + 1 );
			return self::EXPECTED_CTR[ $lo ] + ( self::EXPECTED_CTR[ $hi ] - self::EXPECTED_CTR[ $lo ] ) * ( $p - $lo );
		}
		return max( 0.003, 0.02 * pow( 0.85, $p - 10 ) ); // Page 2 and beyond.
	}

	/**
	 * The report for the latest successful pull.
	 *
	 * @return array
	 */
	public static function build( $property = null ) {
		$property = null === $property ? Peak_SEO_GSC::property() : $property;
		$pull     = $property ? Peak_SEO_GSC_Store::latest( $property, true ) : null;
		if ( ! $pull ) {
			return array(
				'property'    => $property,
				'available'   => false,
				'enough_data' => false,
				'message'     => Peak_SEO_GSC::configured() ? 'No Search Console data yet. Click Pull now in Peak SEO → Settings, or wait for the weekly pull.' : 'Search Console is not connected. Add a key and property in Peak SEO → Settings.',
				'sections'    => array(),
			);
		}
		$t     = self::thresholds();
		$cache = 'peak_seo_gsc_report_' . md5( $pull['id'] . '|' . wp_json_encode( $t ) );
		$hit   = get_transient( $cache );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$report = self::compute( $pull, Peak_SEO_GSC_Store::data( $pull['id'] ), $t );
		set_transient( $cache, $report, DAY_IN_SECONDS );
		return $report;
	}

	/**
	 * Pure calculation (no database), so it can be tested.
	 */
	public static function compute( array $pull, array $data, array $t ) {
		$cur    = $data['current'] ?? array();
		$prev   = $data['previous'] ?? array();
		$totals = array(
			'current'  => self::totals( $cur ),
			'previous' => self::totals( $prev ),
		);
		$report = array(
			'property'     => $pull['property'],
			'available'    => true,
			'pulled_at'    => gmdate( 'c', strtotime( $pull['pulled_at'] . ' UTC' ) ),
			'pull_id'      => (int) $pull['id'],
			'current'      => array( 'start' => $pull['current_start'], 'end' => $pull['current_end'] ),
			'previous'     => array( 'start' => $pull['previous_start'], 'end' => $pull['previous_end'] ),
			'totals'       => $totals,
			'thresholds'   => $t,
			'truncated'    => self::any_truncated( $cur ),
			'enough_data'  => $totals['current']['impressions'] >= $t['min_total_impressions'],
			'message'      => '',
			'sections'     => array(),
		);
		if ( ! $report['enough_data'] ) {
			$report['message'] = sprintf( 'Not enough data yet: %s impressions in the last 28 days (the report needs at least %s). Lists appear once the site gets more search traffic.', number_format_i18n( $totals['current']['impressions'] ), number_format_i18n( $t['min_total_impressions'] ) );
			return $report;
		}

		$idx                = self::index( $cur );
		$report['sections'] = array(
			'low_ctr'     => self::low_ctr( $cur, $idx, $t ),
			'striking'    => self::striking( $cur, $idx, $t ),
			'new_content' => self::new_content( $cur, $t ),
			'movers'      => self::movers( $cur, $prev, $t ),
		);
		return $report;
	}

	/* ------------------------------------------------------------------ */
	/* The four lists                                                      */
	/* ------------------------------------------------------------------ */

	private static function low_ctr( $cur, $idx, $t ) {
		$items = array();
		foreach ( self::rows( $cur, 'page' ) as $r ) {
			if ( $r['impressions'] < $t['low_ctr_min_impressions'] || $r['position'] > $t['low_ctr_max_position'] ) {
				continue;
			}
			$expected = self::expected_ctr( $r['position'] );
			if ( $r['ctr'] >= $expected * $t['low_ctr_ratio'] ) {
				continue;
			}
			$items[] = array(
				'page'           => $r['keys'][0],
				'impressions'    => $r['impressions'],
				'clicks'         => $r['clicks'],
				'ctr'            => round( $r['ctr'], 4 ),
				'expected_ctr'   => round( $expected, 4 ),
				'position'       => round( $r['position'], 1 ),
				'missed_clicks'  => (int) round( $r['impressions'] * $expected - $r['clicks'] ),
				'top_queries'    => self::top_queries_for_page( $idx, $r['keys'][0], 3 ),
			) + self::post_for( $r['keys'][0] );
		}
		usort( $items, function ( $a, $b ) { return $b['missed_clicks'] <=> $a['missed_clicks']; } );
		return self::section( 'Better search title or description', 'Pages shown often in Google but clicked much less than usual for their position. A clearer search title and description could win more of those clicks.', $items, $t );
	}

	private static function striking( $cur, $idx, $t ) {
		$items = array();
		foreach ( self::rows( $cur, 'query' ) as $r ) {
			if ( $r['impressions'] < $t['striking_min_impressions'] || $r['position'] < $t['striking_min_position'] || $r['position'] > $t['striking_max_position'] ) {
				continue;
			}
			$page    = self::best_page_for_query( $idx, $r['keys'][0] );
			$items[] = array(
				'query'       => $r['keys'][0],
				'impressions' => $r['impressions'],
				'clicks'      => $r['clicks'],
				'position'    => round( $r['position'], 1 ),
				'page'        => $page,
			) + ( $page ? self::post_for( $page ) : array() );
		}
		usort( $items, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
		return self::section( 'Pages to strengthen', 'Searches where the site sits just below the top results (average position 5–20). Better content, clearer headings, internal links or an FAQ on the ranking page can move it up.', $items, $t );
	}

	private static function new_content( $cur, $t ) {
		$best = array(); // query => [ position, page ]
		foreach ( self::rows( $cur, 'query_page' ) as $r ) {
			$q = $r['keys'][0];
			if ( ! isset( $best[ $q ] ) || $r['position'] < $best[ $q ][0] ) {
				$best[ $q ] = array( $r['position'], $r['keys'][1] ?? '' );
			}
		}
		$items = array();
		foreach ( self::rows( $cur, 'query' ) as $r ) {
			$q        = $r['keys'][0];
			$position = isset( $best[ $q ] ) ? $best[ $q ][0] : $r['position'];
			if ( $r['impressions'] < $t['new_min_impressions'] || $position <= $t['new_min_position'] ) {
				continue;
			}
			$items[] = array(
				'query'         => $q,
				'impressions'   => $r['impressions'],
				'best_position' => round( $position, 1 ),
				'best_page'     => isset( $best[ $q ] ) ? $best[ $q ][1] : '',
			);
		}
		usort( $items, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
		return self::section( 'Possible new pages', 'Searches that show the site but where no page ranks better than position ' . (int) $t['new_min_position'] . '. A page written for the search could rank much better.', $items, $t );
	}

	private static function movers( $cur, $prev, $t ) {
		$items = array();
		foreach ( array( 'query', 'page' ) as $type ) {
			$before = array();
			foreach ( self::rows( $prev, $type ) as $r ) {
				$before[ $r['keys'][0] ] = $r;
			}
			foreach ( self::rows( $cur, $type ) as $r ) {
				$k = $r['keys'][0];
				if ( ! isset( $before[ $k ] ) ) {
					continue;
				}
				$b = $before[ $k ];
				if ( min( $r['impressions'], $b['impressions'] ) < $t['movers_min_impressions'] ) {
					continue;
				}
				$change = round( $b['position'] - $r['position'], 1 ); // Positive = moved up.
				if ( abs( $change ) < $t['movers_min_change'] ) {
					continue;
				}
				$items[] = array(
					'type'            => $type,
					'key'             => $k,
					'position'        => round( $r['position'], 1 ),
					'position_before' => round( $b['position'], 1 ),
					'change'          => $change,
					'direction'       => $change > 0 ? 'up' : 'down',
					'impressions'     => $r['impressions'],
					'clicks'          => $r['clicks'],
					'clicks_before'   => $b['clicks'],
				);
			}
		}
		usort( $items, function ( $a, $b ) { return abs( $b['change'] ) <=> abs( $a['change'] ) ?: $b['impressions'] <=> $a['impressions']; } );
		return self::section( 'Biggest position changes', 'Searches and pages whose average position changed the most since the previous 28 days. Drops are worth a look first.', $items, $t );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function section( $title, $help, $items, $t ) {
		return array(
			'title' => $title,
			'help'  => $help,
			'total' => count( $items ),
			'items' => array_slice( $items, 0, (int) $t['limit'] ),
		);
	}

	private static function rows( $period, $dataset ) {
		return isset( $period[ $dataset ]['rows'] ) ? $period[ $dataset ]['rows'] : array();
	}

	private static function any_truncated( $period ) {
		foreach ( $period as $set ) {
			if ( ! empty( $set['truncated'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function totals( $period ) {
		$r = self::rows( $period, 'totals' );
		$r = $r ? $r[0] : array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 );
		return array(
			'clicks'      => (int) $r['clicks'],
			'impressions' => (int) $r['impressions'],
			'ctr'         => round( (float) $r['ctr'], 4 ),
			'position'    => round( (float) $r['position'], 1 ),
		);
	}

	/**
	 * Query+page rows grouped both ways, each group sorted by impressions.
	 */
	private static function index( $cur ) {
		$idx = array( 'by_query' => array(), 'by_page' => array() );
		foreach ( self::rows( $cur, 'query_page' ) as $r ) {
			$idx['by_query'][ $r['keys'][0] ][]      = $r;
			$idx['by_page'][ $r['keys'][1] ?? '' ][] = $r;
		}
		$sort = function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; };
		foreach ( $idx as &$groups ) {
			foreach ( $groups as &$g ) {
				usort( $g, $sort );
			}
			unset( $g );
		}
		unset( $groups );
		return $idx;
	}

	private static function best_page_for_query( $idx, $query ) {
		return isset( $idx['by_query'][ $query ][0] ) ? ( $idx['by_query'][ $query ][0]['keys'][1] ?? '' ) : '';
	}

	private static function top_queries_for_page( $idx, $page, $n ) {
		return array_map( function ( $r ) { return $r['keys'][0]; }, array_slice( $idx['by_page'][ $page ] ?? array(), 0, $n ) );
	}

	/**
	 * The WordPress post for a URL, when the URL is on this site.
	 */
	private static function post_for( $url ) {
		if ( ! function_exists( 'url_to_postid' ) ) {
			return array();
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host || strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) !== strtolower( preg_replace( '/^www\./', '', $host ) ) ) {
			return array();
		}
		$id = url_to_postid( $url );
		return $id ? array( 'post_id' => $id, 'title' => get_the_title( $id ) ) : array();
	}
}
