<?php
/**
 * Request signing between this site and the Peak SEO service.
 *
 * Every request from the service carries:
 *   X-Peak-Timestamp: unix seconds
 *   X-Peak-Signature: hex HMAC-SHA256 of "{timestamp}\n{METHOD}\n{route}\n{body}" using the site key
 * Requests older than 5 minutes, or with a bad signature, are rejected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Auth {

	const MAX_SKEW = 300;

	public static function generate_key() {
		return 'pk_' . bin2hex( random_bytes( 24 ) );
	}

	public static function get_key() {
		return (string) get_option( 'peak_seo_site_key', '' );
	}

	/**
	 * REST permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check( $request ) {
		$key = self::get_key();
		if ( '' === $key ) {
			return new WP_Error( 'peak_no_key', 'This site has no Peak SEO key yet.', array( 'status' => 401 ) );
		}

		$ts  = $request->get_header( 'x_peak_timestamp' );
		$sig = $request->get_header( 'x_peak_signature' );
		if ( ! $ts || ! $sig || ! ctype_digit( (string) $ts ) ) {
			return new WP_Error( 'peak_unsigned', 'Missing signature.', array( 'status' => 401 ) );
		}
		if ( abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return new WP_Error( 'peak_expired', 'Request timestamp is too old or too far in the future.', array( 'status' => 401 ) );
		}

		$expected = self::sign( $key, $ts, $request->get_method(), $request->get_route(), $request->get_body() );
		if ( ! hash_equals( $expected, strtolower( $sig ) ) ) {
			return new WP_Error( 'peak_bad_signature', 'Signature does not match.', array( 'status' => 401 ) );
		}

		update_option( 'peak_seo_last_contact', time(), false );
		return true;
	}

	public static function sign( $key, $ts, $method, $route, $body ) {
		$payload = $ts . "\n" . strtoupper( $method ) . "\n" . $route . "\n" . (string) $body;
		return hash_hmac( 'sha256', $payload, $key );
	}
}
