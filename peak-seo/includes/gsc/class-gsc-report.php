<?php
/**
 * "What to work on": turns the latest Search Console snapshot into four lists.
 *
 *   low_ctr      pages with plenty of impressions but a click-through rate well
 *                below what their position usually gets → better title/description
 *   striking     pages to strengthen, one entry per page: the searches it ranks
 *                5–30 for, plus weaker searches that clearly match its topic
 *   new_content  searches no page ranks within 30 for AND no existing page
 *                covers (the search lacks the words in any ranking page's
 *                address) → maybe a new page or post
 *   movers       biggest position changes vs the previous 28 days
 *
 * Quality over quantity: when existing pages are already found for a topic,
 * the report points at improving them rather than adding more pages. Blog
 * and archive listings (/blog/, /category/…) don't count as covering a
 * topic, since a dedicated page usually serves those searches better.
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
			'search_min_impressions'  => 30,   // A search counts for "strengthen" / "new page" from here.
			'strengthen_min_position' => 5,    // Better than this is already doing well.
			'strengthen_max_position' => 30,   // Up to here, improve the page that ranks.
			'strengthen_min_impressions' => 50, // Per page, all its searches together.
			'new_min_position'        => 30,   // New page only if nothing ranks better than this
			                                   // and no existing page matches the topic.
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
		$split              = self::classify_searches( $cur, $idx, $t );
		$report['sections'] = array(
			'low_ctr'     => self::low_ctr( $cur, $idx, $t ),
			'striking'    => self::strengthen( $split['pages'], $t ),
			'new_content' => self::new_content( $split['new'], $t ),
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

	/**
	 * Sort each search (with enough impressions, not already in the top
	 * positions) into either a page to strengthen or a new-page idea.
	 *
	 * @return array { pages: [ url => [ search rows… ] ], new: [ items… ] }
	 */
	private static function classify_searches( $cur, $idx, $t ) {
		$pages = array();
		$new   = array();
		foreach ( self::rows( $cur, 'query' ) as $r ) {
			$q = $r['keys'][0];
			if ( $r['impressions'] < $t['search_min_impressions'] ) {
				continue;
			}
			$ranking  = $idx['by_query'][ $q ] ?? array(); // Pages shown for this search, most impressions first.
			$best     = self::best_ranking( $ranking );
			$position = $best ? $best['position'] : $r['position'];
			if ( $position < $t['strengthen_min_position'] ) {
				continue; // Already at the top.
			}
			$search = array(
				'query'       => $q,
				'impressions' => $r['impressions'],
				'clicks'      => $r['clicks'],
				'position'    => round( $position, 1 ),
			);

			// Close enough: improve the page that already ranks (unless it's a blog/archive listing).
			if ( $best && $position <= $t['strengthen_max_position'] && ! self::is_listing( $best['keys'][1] ?? '' ) ) {
				$pages[ $best['keys'][1] ][] = $search + array( 'why' => 'close' );
				continue;
			}
			// Further away, but an existing page is clearly about this search: improve that page.
			$match = self::topic_page( $q, $ranking );
			if ( $match ) {
				$pages[ $match ][] = $search + array( 'why' => 'topic' );
				continue;
			}
			if ( ! $best && $position <= $t['new_min_position'] ) {
				continue; // Ranks reasonably, but Google didn't say which page (privacy-filtered): leave it.
			}
			// Left: nothing within range, or only a blog/archive listing, and no page on the topic.
			$new[] = $search + array(
				'best_position' => round( $position, 1 ),
				'best_page'     => $best ? ( $best['keys'][1] ?? '' ) : '',
			);
		}
		return array(
			'pages' => $pages,
			'new'   => $new,
		);
	}

	private static function strengthen( $pages, $t ) {
		$items = array();
		foreach ( $pages as $url => $searches ) {
			$impressions = array_sum( array_column( $searches, 'impressions' ) );
			if ( $impressions < $t['strengthen_min_impressions'] ) {
				continue;
			}
			usort( $searches, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
			$weighted = 0;
			foreach ( $searches as $s ) {
				$weighted += $s['position'] * $s['impressions'];
			}
			$items[] = array(
				'page'          => $url,
				'impressions'   => $impressions,
				'clicks'        => array_sum( array_column( $searches, 'clicks' ) ),
				'position'      => round( $weighted / max( 1, $impressions ), 1 ), // Impression-weighted.
				'best_position' => min( array_column( $searches, 'position' ) ),
				'search_count'  => count( $searches ),
				'searches'      => array_slice( $searches, 0, 10 ),
			) + self::post_for( $url );
		}
		usort( $items, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
		return self::section( 'Pages to strengthen', 'One job per page: the searches it already ranks 5–' . (int) $t['strengthen_max_position'] . ' for, plus weaker searches that clearly match its topic. Improving that one page (fuller content, clear headings for these searches, internal links, an FAQ) is usually worth more than adding new pages.', $items, $t );
	}

	private static function new_content( $new, $t ) {
		usort( $new, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
		$items = array_map(
			function ( $s ) {
				unset( $s['why'] );
				return $s;
			},
			$new
		);
		return self::section( 'Possible new pages', 'Searches where no page ranks within ' . (int) $t['new_min_position'] . ' and no existing page covers the topic (only a blog or archive listing shows up, if anything). Worth a new page only if it’s a service or topic the business really offers.', $items, $t );
	}

	/**
	 * The query+page row with the best (lowest) position.
	 */
	private static function best_ranking( $rows ) {
		$best = null;
		foreach ( $rows as $r ) {
			if ( ! $best || $r['position'] < $best['position'] ) {
				$best = $r;
			}
		}
		return $best;
	}

	/**
	 * A ranking page whose address words all appear in the search
	 * ("/web-design" covers "web design alabama" and "website design near me").
	 */
	private static function topic_page( $query, $ranking ) {
		$words = self::words( $query );
		foreach ( $ranking as $r ) {
			$url = $r['keys'][1] ?? '';
			if ( '' === $url || self::is_listing( $url ) ) {
				continue;
			}
			$need = self::words( (string) wp_parse_url( $url, PHP_URL_PATH ), true );
			if ( ! $need ) {
				continue; // The home page matches everything, so it covers nothing in particular.
			}
			$all = true;
			foreach ( $need as $w ) {
				$found = false;
				foreach ( $words as $have ) {
					if ( 0 === strpos( $have, $w ) ) { // "web" matches "website".
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Blog index, categories, tags, authors, pagination, search results.
	 */
	public static function is_listing( $url ) {
		$path = '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return (bool) preg_match( '#^/(blog|news|articles|posts)/?$#i', $path ) // The index itself, not /blog/some-post/.
			|| (bool) preg_match( '#^/(category|tag|author|search)(/|$)#i', $path )
			|| (bool) preg_match( '#/page/\d+/?$#', $path );
	}

	/**
	 * Main words, lowercased and singular. For URLs, also drops filler words.
	 */
	private static function words( $text, $is_url = false ) {
		$stop = array( 'a', 'an', 'and', 'the', 'of', 'for', 'in', 'on', 'to', 'with', 'is', 'are', 'what', 'how', 'why', 'your', 'our', 'me', 'my', 'near' );
		if ( $is_url ) {
			$stop = array_merge( $stop, array( 'blog', 'post', 'page', 'category', 'tag', 'www', 'html', 'php', 'index', 'amp' ) );
		}
		$out = array();
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $text ) ) as $w ) {
			if ( '' === $w || ctype_digit( $w ) || in_array( $w, $stop, true ) ) {
				continue;
			}
			$out[] = strlen( $w ) > 3 ? preg_replace( '/s$/', '', $w ) : $w;
		}
		return array_values( array_unique( $out ) );
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
