<?php
/**
 * Page Discipline: a small site where every page earns its place.
 *
 *  - Site health: indexable pages vs pages with any Google impressions in
 *    the last 28 days. Under the minimum → no new pages; strengthen or
 *    consolidate instead.
 *  - Competing pages: two pages splitting impressions for the same search.
 *  - Cleanup candidates: older pages that get no impressions, or never rank
 *    in the top 20 for anything (never the home page, contact page,
 *    protected pages, or pages flagged "has backlinks").
 *  - New pages: at most N per calendar month, and only while health is OK.
 *  - Recently changed pages (last 4 weeks) are left alone.
 *
 * Every number is a per-site setting (Settings → Page Discipline). The
 * owner and Peak Visibility staff can change them in WordPress; the service
 * can only read them. All results are heuristics, not guarantees.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Discipline {

	const OPT_SETTINGS  = 'peak_seo_discipline';
	const META_PROTECT  = '_peak_protected';
	const META_BACKLINK = '_peak_has_backlinks';
	const META_NOINDEX  = '_peak_noindex'; // Peak SEO's own noindex (used from 0.10.0).

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** key => [ default, min, max, label, help ] */
	const FIELDS = array(
		'health_min_pct'            => array( 80, 0, 100, 'Site health minimum (%)', 'Below this share of indexable pages getting impressions, no new pages are proposed; existing pages are strengthened or consolidated instead.' ),
		'new_pages_per_month'       => array( 2, 0, 20, 'New pages per month (max)', 'Per calendar month, counting pending and approved new-page proposals.' ),
		'recent_change_days'        => array( 28, 0, 180, 'Leave changed pages alone for (days)', 'A page changed through Peak SEO isn’t changed again in this time unless the data shows a problem.' ),
		'cleanup_min_age_days'      => array( 90, 30, 730, 'Cleanup: page age at least (days)', 'Only pages published at least this long ago can be cleanup candidates.' ),
		'cleanup_lookback_days'     => array( 90, 28, 365, 'Cleanup: look back over (days)', 'Search Console snapshots from this period are checked for impressions and positions.' ),
		'top_position'              => array( 20, 5, 50, '“Ranking” means a position of at least', 'A page that never reaches this position for any search is a cleanup candidate.' ),
		'close_max_position'        => array( 40, 20, 100, 'Close enough to strengthen (position up to)', 'Cleanup candidates ranking between the two numbers are strengthened; the rest are suggested for merging.' ),
		'competing_min_impressions' => array( 30, 5, 10000, 'Competing pages: search impressions at least', 'A search must have at least this many impressions before split pages count as competing.' ),
		'competing_min_share'       => array( 20, 5, 50, 'Competing pages: second page share at least (%)', 'The second page needs at least this share of the first page’s impressions for that search.' ),
		'stale_data_days'           => array( 14, 3, 60, 'Search data counts as fresh for (days)', 'With older data, the brain falls back to simple checks and says so.' ),
		'location_min_position'     => array( 10, 3, 50, 'Location pages: town ranks past position', '“Almost winning” towns: approved service areas whose searches show the site, but past this position.' ),
	);

	public static function defaults() {
		return array_map( function ( $f ) { return $f[0]; }, self::FIELDS );
	}

	public static function settings() {
		$saved = get_option( self::OPT_SETTINGS, array() );
		return self::clean_settings( is_array( $saved ) ? $saved : array() );
	}

	public static function clean_settings( $in ) {
		$out = array();
		foreach ( self::FIELDS as $k => $f ) {
			$v         = isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? (int) $in[ $k ] : $f[0];
			$out[ $k ] = max( $f[1], min( $f[2], $v ) );
		}
		if ( $out['close_max_position'] < $out['top_position'] ) {
			$out['close_max_position'] = $out['top_position'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Pages                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Why a published page is kept out of Google, or '' if it can be indexed.
	 */
	public static function noindex_reason( $post ) {
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return 'site discourages search engines';
		}
		if ( ! empty( $post->post_password ) ) {
			return 'password protected';
		}
		if ( '1' === (string) get_post_meta( $post->ID, self::META_NOINDEX, true ) ) {
			return 'Peak SEO noindex';
		}
		$type = $post->post_type;
		switch ( Peak_SEO_Fields::provider() ) {
			case 'yoast':
				$v = (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
				if ( '1' === $v ) {
					return 'noindex in Yoast';
				}
				$titles = get_option( 'wpseo_titles', array() );
				if ( '' === $v && is_array( $titles ) && ! empty( $titles[ 'noindex-' . $type ] ) ) {
					return 'noindex in Yoast (default for ' . $type . 's)';
				}
				break;
			case 'rankmath':
				$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
				if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
					return 'noindex in Rank Math';
				}
				$opts = get_option( 'rank-math-options-titles', array() );
				if ( ! $robots && is_array( $opts ) && ! empty( $opts[ 'pt_' . $type . '_custom_robots' ] ) && in_array( 'noindex', (array) ( $opts[ 'pt_' . $type . '_robots' ] ?? array() ), true ) ) {
					return 'noindex in Rank Math (default for ' . $type . 's)';
				}
				break;
			case 'seopress':
				if ( 'yes' === (string) get_post_meta( $post->ID, '_seopress_robots_index', true ) ) {
					return 'noindex in SEOPress';
				}
				break;
		}
		return '';
	}

	public static function is_front( $post ) {
		return 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID;
	}

	public static function is_contact( $post ) {
		return (bool) preg_match( '/(^|-)contact(-|$)/', (string) $post->post_name ) || (bool) preg_match( '/^contact\b/i', (string) $post->post_title );
	}

	/**
	 * What the brain and the report need to know about a page.
	 */
	public static function page_facts( $post ) {
		$reason = self::noindex_reason( $post );
		return array(
			'published'      => mysql_to_rfc3339( $post->post_date_gmt ),
			'indexable'      => '' === $reason,
			'noindex_reason' => $reason,
			'is_front'       => self::is_front( $post ),
			'is_contact'     => self::is_contact( $post ),
			'protected'      => '1' === (string) get_post_meta( $post->ID, self::META_PROTECT, true ),
			'has_backlinks'  => '1' === (string) get_post_meta( $post->ID, self::META_BACKLINK, true ),
		);
	}

	/**
	 * Published pages and posts with their facts: [ id, url, path, title, … ].
	 */
	public static function site_pages() {
		$posts = get_posts(
			array(
				'post_type'      => Peak_SEO_Proposals::TARGET_TYPES,
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$url   = get_permalink( $p );
			$out[] = array(
				'id'    => $p->ID,
				'type'  => $p->post_type,
				'title' => $p->post_title,
				'url'   => $url,
				'path'  => self::path( $url ),
			) + self::page_facts( $p );
		}
		return $out;
	}

	/**
	 * Comparable path: "/web-design/" → "/web-design". Search Console URLs
	 * and WordPress permalinks are matched by path, so a staging copy of a
	 * site can be checked against the live site's data.
	 */
	public static function path( $url ) {
		$p = strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$p = '/' . trim( $p, '/' );
		return $p;
	}

	/* ------------------------------------------------------------------ */
	/* Calculations (pure: no database, so they can be tested)              */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array[] $pages     site_pages() rows.
	 * @param array[] $page_rows Current-period "page" rows (expanded).
	 * @return array { indexable, with_impressions, pct|null, missing[] }
	 */
	public static function health( $pages, $page_rows ) {
		$seen = array();
		foreach ( $page_rows as $r ) {
			if ( $r['impressions'] > 0 ) {
				$seen[ self::path( $r['keys'][0] ) ] = true;
			}
		}
		$indexable = array_values( array_filter( $pages, function ( $p ) { return $p['indexable']; } ) );
		$with      = array_filter( $indexable, function ( $p ) use ( $seen ) { return isset( $seen[ $p['path'] ] ); } );
		$missing   = array_values( array_filter( $indexable, function ( $p ) use ( $seen ) { return ! isset( $seen[ $p['path'] ] ); } ) );
		return array(
			'indexable'        => count( $indexable ),
			'with_impressions' => count( $with ),
			'pct'              => $indexable ? (int) round( count( $with ) / count( $indexable ) * 100 ) : null,
			'without'          => array_map( function ( $p ) { return array( 'id' => $p['id'], 'title' => $p['title'], 'url' => $p['url'] ); }, array_slice( $missing, 0, 50 ) ),
		);
	}

	/**
	 * Pairs of pages splitting impressions for the same searches.
	 *
	 * @param array[] $qp      Current-period query+page rows (expanded).
	 * @param array[] $pages   site_pages() rows (for front/contact/protected).
	 * @param array   $s       Settings.
	 * @return array[] Each: main, refocus (urls), queries[], impressions.
	 */
	public static function competing( $qp, $pages, $s ) {
		$by_path = array();
		foreach ( $pages as $p ) {
			$by_path[ $p['path'] ] = $p;
		}
		$by_query = array();
		foreach ( $qp as $r ) {
			$by_query[ $r['keys'][0] ][] = $r;
		}
		$pairs = array();
		foreach ( $by_query as $q => $rows ) {
			if ( count( $rows ) < 2 ) {
				continue;
			}
			$total = array_sum( array_column( $rows, 'impressions' ) );
			if ( $total < $s['competing_min_impressions'] ) {
				continue;
			}
			// Real pages only: listings (blog index, categories…) naturally share searches.
			$rows = array_values( array_filter( $rows, function ( $r ) { return ! Peak_SEO_GSC_Report::is_listing( $r['keys'][1] ?? '' ); } ) );
			usort( $rows, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
			if ( count( $rows ) < 2 || $rows[1]['impressions'] < $rows[0]['impressions'] * $s['competing_min_share'] / 100 || $rows[1]['impressions'] < 5 ) {
				continue;
			}
			$a = $rows[0];
			$b = $rows[1];
			// Main page: more clicks, then better position. The home page targets the brand,
			// so a service page wins a money search over it.
			$main  = self::better( $a, $b ) ? $a : $b;
			$other = $main === $a ? $b : $a;
			$mi    = $by_path[ self::path( $main['keys'][1] ) ] ?? null;
			if ( $mi && $mi['is_front'] ) {
				list( $main, $other ) = array( $other, $main );
			}
			$oi = $by_path[ self::path( $other['keys'][1] ) ] ?? null;
			if ( $oi && ( $oi['is_front'] || $oi['is_contact'] || $oi['protected'] ) ) {
				continue; // The page that would be refocused can't be touched.
			}
			$key = $main['keys'][1] . '|' . $other['keys'][1];
			if ( ! isset( $pairs[ $key ] ) ) {
				$pairs[ $key ] = array(
					'main'        => $main['keys'][1],
					'refocus'     => $other['keys'][1],
					'impressions' => 0,
					'queries'     => array(),
				);
			}
			$pairs[ $key ]['impressions'] += $total;
			$pairs[ $key ]['queries'][]    = array(
				'query'            => $q,
				'impressions'      => $total,
				'main_position'    => round( $main['position'], 1 ),
				'refocus_position' => round( $other['position'], 1 ),
			);
		}
		$pairs = array_values( $pairs );
		usort( $pairs, function ( $x, $y ) { return $y['impressions'] <=> $x['impressions']; } );
		return $pairs;
	}

	private static function better( $a, $b ) {
		if ( $a['clicks'] !== $b['clicks'] ) {
			return $a['clicks'] > $b['clicks'];
		}
		return $a['position'] <= $b['position'];
	}

	/**
	 * Pages that don't earn their place.
	 *
	 * @param array[] $pages    site_pages() rows.
	 * @param array[] $history  Per snapshot in the look-back window:
	 *                          [ 'page' => rows, 'query_page' => rows, 'page_truncated' => bool ].
	 * @param array   $s        Settings.
	 * @param int     $now      Unix time.
	 * @return array[] Each: id, title, url, impressions, best_position, action (strengthen|merge), why.
	 */
	public static function cleanup( $pages, $history, $s, $now ) {
		if ( ! $history ) {
			return array();
		}
		$impr       = array();
		$best       = array();
		$truncated  = false;
		foreach ( $history as $h ) {
			$truncated = $truncated || ! empty( $h['page_truncated'] );
			foreach ( $h['page'] as $r ) {
				$k          = self::path( $r['keys'][0] );
				$impr[ $k ] = ( $impr[ $k ] ?? 0 ) + $r['impressions'];
			}
			foreach ( $h['query_page'] as $r ) {
				$k = self::path( $r['keys'][1] ?? '' );
				if ( ! isset( $best[ $k ] ) || $r['position'] < $best[ $k ] ) {
					$best[ $k ] = $r['position'];
				}
			}
		}
		$out = array();
		foreach ( $pages as $p ) {
			if ( ! $p['indexable'] || $p['is_front'] || $p['is_contact'] || $p['protected'] || $p['has_backlinks'] ) {
				continue;
			}
			if ( $now - strtotime( $p['published'] ) < $s['cleanup_min_age_days'] * DAY_IN_SECONDS ) {
				continue;
			}
			$i  = $impr[ $p['path'] ] ?? 0;
			$bp = $best[ $p['path'] ] ?? null;
			if ( 0 === $i ) {
				if ( $truncated ) {
					continue; // Google's list was cut off at 1,000 pages: "no impressions" can't be trusted.
				}
				$why = 'No impressions in the last ' . (int) $s['cleanup_lookback_days'] . ' days.';
			} elseif ( null !== $bp && $bp > $s['top_position'] ) {
				$why = 'Never in the top ' . (int) $s['top_position'] . ' for any search (best position ' . round( $bp, 1 ) . ').';
			} else {
				continue; // Ranks well enough, or Google didn't say for which searches.
			}
			$action = ( null !== $bp && $bp <= $s['close_max_position'] ) ? 'strengthen' : 'merge';
			$out[]  = array(
				'id'            => $p['id'],
				'title'         => $p['title'],
				'url'           => $p['url'],
				'impressions'   => $i,
				'best_position' => null === $bp ? null : round( $bp, 1 ),
				'action'        => $action,
				'why'           => $why,
			);
		}
		usort( $out, function ( $a, $b ) { return array( 'strengthen' === $a['action'] ? 0 : 1, $a['impressions'] ) <=> array( 'strengthen' === $b['action'] ? 0 : 1, $b['impressions'] ); } );
		return $out;
	}

	/**
	 * May the service propose a new page now?
	 *
	 * @param array|null $health    health() result, or null without data.
	 * @param int        $this_month New-page proposals this calendar month (pending + approved).
	 * @param array      $s          Settings.
	 * @return array { allowed, reasons[], used, cap }
	 */
	public static function new_page_allowance( $health, $this_month, $s ) {
		$reasons = array();
		if ( $this_month >= $s['new_pages_per_month'] ) {
			$reasons[] = sprintf( 'This month’s limit of %d new %s is reached.', $s['new_pages_per_month'], 1 === $s['new_pages_per_month'] ? 'page' : 'pages' );
		}
		if ( $health && null !== $health['pct'] && $health['pct'] < $s['health_min_pct'] ) {
			$reasons[] = sprintf( 'Site health is %d%% (minimum %d%%): strengthen or consolidate existing pages first.', $health['pct'], $s['health_min_pct'] );
		}
		return array(
			'allowed' => ! $reasons,
			'reasons' => $reasons,
			'used'    => (int) $this_month,
			'cap'     => (int) $s['new_pages_per_month'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Gathering it all                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * New-page proposals created this calendar month (site time zone), pending or approved.
	 */
	public static function new_pages_this_month( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$start = wp_date( 'Y-m-01 00:00:00', $now );
		$count = 0;
		foreach ( Peak_SEO_Proposals::query() as $p ) {
			if ( 'create_page' === $p['kind'] && in_array( $p['status'], array( 'pending', 'approved' ), true ) && get_date_from_gmt( $p['created'] ) >= $start ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Pages changed through Peak SEO within the "leave alone" period: [ post_id => approved_at ].
	 */
	public static function recently_changed( $s, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$from = $now - $s['recent_change_days'] * DAY_IN_SECONDS;
		$out  = array();
		foreach ( Peak_SEO_Proposals::query() as $p ) {
			if ( in_array( $p['status'], array( 'approved', 'undone' ), true ) && $p['approved_at'] && strtotime( $p['approved_at'] ) >= $from && $p['target_id'] ) {
				$out[ $p['target_id'] ] = max( $out[ $p['target_id'] ] ?? '', $p['approved_at'] );
			}
		}
		return $out;
	}

	/**
	 * Everything for the report page and GET /discipline.
	 */
	public static function build( $now = null ) {
		$now      = null === $now ? time() : (int) $now;
		$s        = self::settings();
		$property = Peak_SEO_GSC::property();
		$pull     = $property ? Peak_SEO_GSC_Store::latest( $property, true ) : null;
		$pages    = self::site_pages();
		$out      = array(
			'settings'         => $s,
			'heuristic'        => true,
			'data'             => array( 'available' => (bool) $pull ),
			'health'           => null,
			'competing'        => array(),
			'cleanup'          => array(),
			'recently_changed' => self::recently_changed( $s, $now ),
		);
		if ( $pull ) {
			$age             = $now - strtotime( $pull['pulled_at'] . ' UTC' );
			$out['data']    += array(
				'pulled_at' => gmdate( 'c', strtotime( $pull['pulled_at'] . ' UTC' ) ),
				'age_days'  => (int) floor( $age / DAY_IN_SECONDS ),
				'fresh'     => $age <= $s['stale_data_days'] * DAY_IN_SECONDS,
			);
			$cur              = Peak_SEO_GSC_Store::data_sets( $pull['id'], 'current', array( 'page', 'query_page' ) );
			$out['health']    = self::health( $pages, $cur['page']['rows'] ?? array() );
			$out['competing'] = array_slice( self::competing( $cur['query_page']['rows'] ?? array(), $pages, $s ), 0, 25 );
			$history          = array();
			foreach ( Peak_SEO_GSC_Store::pulls_since( $property, $now - $s['cleanup_lookback_days'] * DAY_IN_SECONDS ) as $id ) {
				$d         = Peak_SEO_GSC_Store::data_sets( $id, 'current', array( 'page', 'query_page' ) );
				$history[] = array(
					'page'           => $d['page']['rows'] ?? array(),
					'query_page'     => $d['query_page']['rows'] ?? array(),
					'page_truncated' => ! empty( $d['page']['truncated'] ),
				);
			}
			$out['cleanup'] = array_slice( self::cleanup( $pages, $history, $s, $now ), 0, 50 );
		}
		$out['new_pages'] = self::new_page_allowance( $out['health'], self::new_pages_this_month( $now ), $s );
		return $out;
	}
}
