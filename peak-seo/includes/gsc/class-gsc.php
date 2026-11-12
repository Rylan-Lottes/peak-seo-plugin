<?php
/**
 * Google Search Console connection: settings, credentials and the connection
 * test. Nothing here talks to Google until a key and a property are saved.
 *
 * Options (none autoloaded):
 *   peak_seo_gsc_auth      'service_account' (room for 'oauth' later)
 *   peak_seo_gsc_key       service account JSON, encrypted (Peak_SEO_Crypto)
 *   peak_seo_gsc_identity  [ email, project_id ] shown in Settings (never the key)
 *   peak_seo_gsc_property  sc-domain:example.com or https://www.example.com/
 *   peak_seo_gsc_status    last connection test result
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_GSC {

	const OPT_AUTH     = 'peak_seo_gsc_auth';
	const OPT_KEY      = 'peak_seo_gsc_key';
	const OPT_IDENTITY = 'peak_seo_gsc_identity';
	const OPT_PROPERTY = 'peak_seo_gsc_property';
	const OPT_STATUS   = 'peak_seo_gsc_status';

	const OPTIONS = array( self::OPT_AUTH, self::OPT_KEY, self::OPT_IDENTITY, self::OPT_PROPERTY, self::OPT_STATUS );

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function property() {
		return (string) get_option( self::OPT_PROPERTY, '' );
	}

	public static function has_key() {
		return '' !== (string) get_option( self::OPT_KEY, '' );
	}

	/**
	 * [ 'email' => ..., 'project_id' => ... ] for display, or [].
	 */
	public static function identity() {
		$id = get_option( self::OPT_IDENTITY, array() );
		return is_array( $id ) ? $id : array();
	}

	public static function configured() {
		return self::has_key() && '' !== self::property();
	}

	/**
	 * Check and tidy a property as typed by a person.
	 *
	 * @return string|WP_Error
	 */
	public static function normalize_property( $input ) {
		$p = trim( (string) $input );
		if ( '' === $p ) {
			return new WP_Error( 'peak_gsc_property_empty', 'Enter the Search Console property.' );
		}
		if ( 0 === stripos( $p, 'sc-domain:' ) ) {
			$domain = strtolower( trim( substr( $p, 10 ) ) );
			if ( ! preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain ) ) {
				return new WP_Error( 'peak_gsc_property_format', 'A Domain property is just “sc-domain:” plus the domain, with no https:// and no slash, e.g. sc-domain:example.com.' );
			}
			return 'sc-domain:' . $domain;
		}
		if ( preg_match( '#^https?://#i', $p ) ) {
			$parts = wp_parse_url( $p );
			if ( empty( $parts['host'] ) || ! preg_match( '/^([a-z0-9-]+\.)+[a-z0-9-]{2,63}$|^localhost$/i', $parts['host'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
				return new WP_Error( 'peak_gsc_property_format', 'A URL-prefix property is a full address like https://www.example.com/ (no ? or #).' );
			}
			$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
			if ( '/' !== substr( $path, -1 ) ) {
				$path .= '/'; // URL-prefix properties always end with a slash.
			}
			$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
			return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . $port . $path;
		}
		$guess = strtolower( preg_replace( '#/.*$#', '', $p ) );
		return new WP_Error( 'peak_gsc_property_format', 'Use sc-domain:' . $guess . ' for a Domain property, or the full address (e.g. https://' . $guess . '/) for a URL-prefix property. Copy it exactly as Search Console shows it in its property menu.' );
	}

	/**
	 * Save the property and, if one was pasted, a new key.
	 *
	 * @return true|WP_Error
	 */
	public static function save( $property, $key_json ) {
		$prop = self::normalize_property( $property );
		if ( is_wp_error( $prop ) ) {
			return $prop;
		}
		$key_json = trim( (string) $key_json );
		if ( '' === $key_json && ! self::has_key() ) {
			return new WP_Error( 'peak_gsc_key_empty', 'Paste the service account JSON key as well.' );
		}
		if ( '' !== $key_json ) {
			$key = Peak_SEO_GSC_Service_Account::parse_key( $key_json );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			$blob = Peak_SEO_Crypto::encrypt( wp_json_encode( $key ) );
			if ( is_wp_error( $blob ) ) {
				return $blob;
			}
			( new Peak_SEO_GSC_Service_Account( $key ) )->forget_token();
			update_option( self::OPT_AUTH, 'service_account', false );
			update_option( self::OPT_KEY, $blob, false );
			update_option(
				self::OPT_IDENTITY,
				array(
					'email'      => $key['client_email'],
					'project_id' => $key['project_id'],
				),
				false
			);
		}
		if ( $prop !== self::property() || '' !== $key_json ) {
			delete_option( self::OPT_STATUS ); // Old test result no longer applies.
		}
		update_option( self::OPT_PROPERTY, $prop, false );
		return true;
	}

	public static function remove_key() {
		delete_transient( Peak_SEO_GSC_Service_Account::TOKEN_TRANSIENT );
		foreach ( array( self::OPT_AUTH, self::OPT_KEY, self::OPT_IDENTITY, self::OPT_STATUS ) as $opt ) {
			delete_option( $opt );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Credentials and client                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @return Peak_SEO_GSC_Credentials|WP_Error
	 */
	public static function credentials() {
		if ( ! self::has_key() ) {
			return new WP_Error( 'peak_gsc_not_configured', 'Search Console is not connected yet: paste a service account key in Peak SEO → Settings.' );
		}
		$auth = (string) get_option( self::OPT_AUTH, 'service_account' );
		if ( 'service_account' !== $auth ) {
			return new WP_Error( 'peak_gsc_auth_unknown', 'Unknown Search Console sign-in method “' . $auth . '”.' );
		}
		$json = Peak_SEO_Crypto::decrypt( get_option( self::OPT_KEY, '' ) );
		$key  = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $key ) ) {
			return new WP_Error( 'peak_gsc_key_unreadable', 'The saved key can’t be read any more (this usually means the site’s security keys in wp-config.php changed). Paste the service account key again.' );
		}
		return new Peak_SEO_GSC_Service_Account( $key );
	}

	/**
	 * @return Peak_SEO_GSC_Client|WP_Error
	 */
	public static function client() {
		if ( ! self::configured() ) {
			return new WP_Error( 'peak_gsc_not_configured', 'Search Console is not connected yet: add a key and a property in Peak SEO → Settings.' );
		}
		$creds = self::credentials();
		return is_wp_error( $creds ) ? $creds : new Peak_SEO_GSC_Client( $creds );
	}

	/**
	 * Date range ending 3 days ago (Search Console data lags 2–3 days).
	 *
	 * @param int $days     Length of the range.
	 * @param int $offset   Extra days to shift back (28 = the 28 days before).
	 * @param int|null $now Unix time (for tests).
	 * @return array [ 'startDate' => 'Y-m-d', 'endDate' => 'Y-m-d' ]
	 */
	public static function date_range( $days = 28, $offset = 0, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$end = $now - ( 3 + $offset ) * DAY_IN_SECONDS;
		return array(
			'startDate' => gmdate( 'Y-m-d', $end - ( $days - 1 ) * DAY_IN_SECONDS ),
			'endDate'   => gmdate( 'Y-m-d', $end ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Connection test                                                     */
	/* ------------------------------------------------------------------ */

	const PERMISSIONS = array(
		'siteOwner'          => 'Owner',
		'siteFullUser'       => 'Full',
		'siteRestrictedUser' => 'Restricted',
		'siteUnverifiedUser' => 'Unverified',
	);

	/**
	 * Sign in, check the property, and read one small report.
	 *
	 * @return array { ok: bool, message: string, at: int, permission?: string, suggestions?: string[] }
	 */
	public static function test_connection() {
		$client   = self::client();
		$property = self::property();
		$status   = array(
			'ok'  => false,
			'at'  => time(),
			'for' => $property,
		);

		if ( is_wp_error( $client ) ) {
			$status['message'] = $client->get_error_message();
			return self::store_status( $status );
		}

		$site = $client->get_site( $property );
		if ( is_wp_error( $site ) ) {
			$status['message'] = $site->get_error_message();
			if ( in_array( $site->get_error_code(), array( 'peak_gsc_not_found', 'peak_gsc_no_access' ), true ) ) {
				$status['suggestions'] = self::suggestions( $client, $property );
			}
			return self::store_status( $status );
		}

		$level                = (string) ( $site['permissionLevel'] ?? '' );
		$status['permission'] = self::PERMISSIONS[ $level ] ?? $level;
		if ( 'siteUnverifiedUser' === $level || '' === $level ) {
			$status['message'] = 'Search Console lists ' . $property . ' for this account, but only as unverified, so it can’t read data. Add the service account as a user on the verified property (Settings → Users and permissions).';
			return self::store_status( $status );
		}

		$rows = $client->query( $property, self::date_range() + array( 'rowLimit' => 1 ) );
		if ( is_wp_error( $rows ) ) {
			$status['message'] = $rows->get_error_message();
			return self::store_status( $status );
		}
		$clicks      = isset( $rows[0]['clicks'] ) ? (int) $rows[0]['clicks'] : 0;
		$impressions = isset( $rows[0]['impressions'] ) ? (int) $rows[0]['impressions'] : 0;

		$status['ok']      = true;
		$status['message'] = sprintf(
			'Connected. %s can read %s (%s access). Last 28 days: %s clicks, %s impressions.',
			self::credentials()->label(),
			$property,
			$status['permission'],
			number_format_i18n( $clicks ),
			number_format_i18n( $impressions )
		);
		if ( 0 === $impressions ) {
			$status['message'] .= ' No search data yet; that’s normal for a new or very small site.';
		}
		return self::store_status( $status );
	}

	/**
	 * Properties the account can see that look like the one entered.
	 */
	private static function suggestions( $client, $property ) {
		$sites = $client->list_sites();
		if ( is_wp_error( $sites ) || ! $sites ) {
			return array();
		}
		$want = self::bare_host( $property );
		$all  = array();
		$near = array();
		foreach ( $sites as $s ) {
			if ( empty( $s['siteUrl'] ) || 'siteUnverifiedUser' === ( $s['permissionLevel'] ?? '' ) ) {
				continue;
			}
			$all[] = $s['siteUrl'];
			if ( $want && self::bare_host( $s['siteUrl'] ) === $want ) {
				$near[] = $s['siteUrl'];
			}
		}
		return array_slice( $near ? $near : $all, 0, 10 );
	}

	private static function bare_host( $property ) {
		$host = 0 === strpos( $property, 'sc-domain:' ) ? substr( $property, 10 ) : (string) wp_parse_url( $property, PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	private static function store_status( $status ) {
		update_option( self::OPT_STATUS, $status, false );
		return $status;
	}

	public static function status() {
		$s = get_option( self::OPT_STATUS, array() );
		return is_array( $s ) && ( $s['for'] ?? '' ) === self::property() ? $s : array();
	}
}
