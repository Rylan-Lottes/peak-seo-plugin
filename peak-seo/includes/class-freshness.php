<?php
/**
 * Helping Google notice changes.
 *
 * Google decides what to re-crawl partly from the sitemap's "last modified"
 * dates (Yoast, Rank Math and SEOPress sitemaps include them). A change to
 * only a search title or description is stored in post meta, which doesn't
 * update the page's modified date, so Google had no hint the page changed.
 * touch() moves the date forward and clears the SEO plugin's sitemap cache.
 *
 * It writes the two date columns directly instead of calling wp_update_post():
 * re-saving a page through wp_update_post() without a logged-in user (an
 * approval through the API) runs the content through kses again, which could
 * strip embeds or scripts the owner added.
 *
 * checks() lists anything that stops Google seeing the site's changes:
 * "Discourage search engines" switched on, no sitemap submitted in Search
 * Console, or sitemap errors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Freshness {

	const OPT_SITEMAPS = 'peak_seo_gsc_sitemaps';

	/**
	 * Mark a post as modified now and refresh sitemap caches.
	 */
	public static function touch( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return false;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			array(
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
		self::refresh_sitemaps( $post_id );
		do_action( 'peak_seo_post_touched', $post_id );
		return true;
	}

	/**
	 * Clear the SEO plugin's cached sitemap so the new date shows right away.
	 */
	private static function refresh_sitemaps( $post_id ) {
		// Yoast SEO.
		if ( class_exists( 'WPSEO_Sitemaps_Cache' ) && method_exists( 'WPSEO_Sitemaps_Cache', 'invalidate_post' ) ) {
			WPSEO_Sitemaps_Cache::invalidate_post( $post_id );
		}
		// Rank Math.
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
		// SEOPress keeps its sitemap in transients named after the post type.
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			delete_transient( 'seopress_sitemap_' . get_post_type( $post_id ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sitemap status from Search Console                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Save the sitemaps Search Console knows for the property (read-only API).
	 * Called during each pull; a failure here never fails the pull.
	 */
	public static function store_sitemaps( $client, $property, $now = null ) {
		$list = $client->list_sitemaps( $property );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$items = array();
		foreach ( $list as $s ) {
			$items[] = array(
				'path'            => (string) ( $s['path'] ?? '' ),
				'last_submitted'  => (string) ( $s['lastSubmitted'] ?? '' ),
				'last_downloaded' => (string) ( $s['lastDownloaded'] ?? '' ),
				'pending'         => ! empty( $s['isPending'] ),
				'errors'          => (int) ( $s['errors'] ?? 0 ),
				'warnings'        => (int) ( $s['warnings'] ?? 0 ),
			);
		}
		update_option(
			self::OPT_SITEMAPS,
			array(
				'property' => $property,
				'at'       => null === $now ? time() : (int) $now,
				'items'    => $items,
			),
			false
		);
		return $items;
	}

	public static function sitemaps( $property ) {
		$s = get_option( self::OPT_SITEMAPS, array() );
		return is_array( $s ) && ( $s['property'] ?? '' ) === $property ? $s : null;
	}

	/**
	 * The sitemap address this site's SEO plugin serves.
	 */
	public static function expected_sitemap() {
		switch ( Peak_SEO_Fields::provider() ) {
			case 'yoast':
			case 'rankmath':
			case 'aioseo':
				return home_url( '/sitemap_index.xml' );
			case 'seopress':
				return home_url( '/sitemaps.xml' );
			default:
				return home_url( '/wp-sitemap.xml' ); // WordPress's own sitemap.
		}
	}

	/* ------------------------------------------------------------------ */
	/* Checks                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Things that stop Google seeing changes, most serious first.
	 *
	 * @param string   $property Search Console property.
	 * @param int|null $now      For tests.
	 * @return array[] Each: level (error|warning|ok), title, detail, link (optional).
	 */
	public static function checks( $property, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$out    = array();
		$gsc    = $property ? 'https://search.google.com/search-console/sitemaps?resource_id=' . rawurlencode( $property ) : 'https://search.google.com/search-console';
		$expect = self::expected_sitemap();

		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$out[] = array(
				'level'  => 'error',
				'title'  => 'Search engines are discouraged from this site',
				'detail' => 'WordPress → Settings → Reading → “Discourage search engines from indexing this site” is ticked, so Google is asked not to index any page. Untick it unless this is a staging copy.',
				'link'   => admin_url( 'options-reading.php' ),
			);
		}

		$s = $property ? self::sitemaps( $property ) : null;
		if ( ! $s ) {
			$out[] = array(
				'level'  => 'warning',
				'title'  => 'Sitemap status not checked yet',
				'detail' => 'The next Search Console pull also checks which sitemaps Google has for this site.',
			);
			return $out;
		}
		if ( ! $s['items'] ) {
			$out[] = array(
				'level'  => 'warning',
				'title'  => 'No sitemap submitted to Google',
				'detail' => 'Without a sitemap, Google can be slow to notice changed and new pages. In Search Console → Sitemaps, submit ' . $expect,
				'link'   => $gsc,
			);
			return $out;
		}

		$fresh = false;
		foreach ( $s['items'] as $item ) {
			$read = $item['last_downloaded'] ? strtotime( $item['last_downloaded'] ) : 0;
			if ( $item['errors'] > 0 ) {
				$out[] = array(
					'level'  => 'warning',
					'title'  => 'Sitemap has errors',
					'detail' => sprintf( '%s: %d %s reported by Google. Open it in Search Console to see which.', $item['path'], $item['errors'], 1 === $item['errors'] ? 'error' : 'errors' ),
					'link'   => $gsc,
				);
			}
			if ( $read && $now - $read <= 30 * DAY_IN_SECONDS ) {
				$fresh = true;
			}
		}
		if ( ! $fresh ) {
			$out[] = array(
				'level'  => 'warning',
				'title'  => 'Google hasn’t read the sitemap recently',
				'detail' => 'None of the submitted sitemaps was downloaded by Google in the last 30 days. Check that ' . $expect . ' opens, then resubmit it in Search Console → Sitemaps.',
				'link'   => $gsc,
			);
		}
		if ( ! $out ) {
			$latest = max( array_map( function ( $i ) { return $i['last_downloaded'] ? strtotime( $i['last_downloaded'] ) : 0; }, $s['items'] ) );
			$out[]  = array(
				'level'  => 'ok',
				'title'  => 'Google can see your changes',
				'detail' => sprintf( 'Sitemap submitted (%s), last read by Google %s ago. Pages changed through Peak SEO get a new “last modified” date so Google knows to look again.', implode( ', ', array_column( $s['items'], 'path' ) ), human_time_diff( $latest, $now ) ),
			);
		}
		return $out;
	}
}
