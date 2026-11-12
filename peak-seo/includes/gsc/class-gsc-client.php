<?php
/**
 * Minimal Search Console API client (read-only). Turns Google's errors into
 * messages a site owner can act on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_GSC_Client {

	const API = 'https://searchconsole.googleapis.com/webmasters/v3/';

	/** @var Peak_SEO_GSC_Credentials */
	private $creds;

	public function __construct( Peak_SEO_GSC_Credentials $creds ) {
		$this->creds = $creds;
	}

	/**
	 * Properties this account can see: [ [ 'siteUrl' => ..., 'permissionLevel' => ... ], ... ].
	 *
	 * @return array|WP_Error
	 */
	public function list_sites() {
		$r = $this->request( 'GET', 'sites' );
		return is_wp_error( $r ) ? $r : ( isset( $r['siteEntry'] ) && is_array( $r['siteEntry'] ) ? $r['siteEntry'] : array() );
	}

	/**
	 * One property, with this account's permissionLevel.
	 *
	 * @return array|WP_Error
	 */
	public function get_site( $property ) {
		return $this->request( 'GET', 'sites/' . rawurlencode( $property ), null, $property );
	}

	/**
	 * Search Analytics query.
	 * https://developers.google.com/webmaster-tools/v1/searchanalytics/query
	 *
	 * @return array|WP_Error Rows ([] when there is no data).
	 */
	public function query( $property, array $body ) {
		$r = $this->request( 'POST', 'sites/' . rawurlencode( $property ) . '/searchAnalytics/query', $body, $property );
		return is_wp_error( $r ) ? $r : ( isset( $r['rows'] ) && is_array( $r['rows'] ) ? $r['rows'] : array() );
	}

	/**
	 * @return array|WP_Error Decoded JSON.
	 */
	private function request( $method, $path, $body = null, $property = '', $retry = true ) {
		$token = $this->creds->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'peak_gsc_network', 'Could not reach Search Console (' . $res->get_error_message() . '). Check that this server can make outgoing HTTPS requests.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}
		if ( 401 === $code && $retry ) {
			$this->creds->forget_token(); // Expired or revoked token: sign in again once.
			return $this->request( $method, $path, $body, $property, false );
		}
		return $this->api_error( $code, is_array( $data ) ? $data : array(), $property );
	}

	/**
	 * Plain-language version of a Search Console API error.
	 */
	public function api_error( $code, $data, $property ) {
		$err     = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$message = (string) ( $err['message'] ?? '' );
		$reasons = array();
		foreach ( array_merge( (array) ( $err['errors'] ?? array() ), (array) ( $err['details'] ?? array() ) ) as $e ) {
			if ( is_array( $e ) && ! empty( $e['reason'] ) ) {
				$reasons[] = (string) $e['reason'];
			}
		}
		$who     = $this->creds->user_to_add();
		$project = $this->creds->project_id();
		$prop    = $property ? '“' . $property . '”' : 'this property';

		if ( 403 === $code && ( array_intersect( $reasons, array( 'SERVICE_DISABLED', 'accessNotConfigured' ) ) || preg_match( '/has not been used in project|is disabled|API has not been used/i', $message ) ) ) {
			$link = 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com' . ( $project ? '?project=' . rawurlencode( $project ) : '' );
			return new WP_Error( 'peak_gsc_api_disabled', 'The Google Search Console API is not enabled' . ( $project ? ' in Google Cloud project “' . $project . '”' : '' ) . '. Enable it at ' . $link . ', wait a minute, then test again.', array( 'status' => 403 ) );
		}
		if ( 403 === $code ) {
			return new WP_Error( 'peak_gsc_no_access', $who . ' can’t read ' . $prop . '. In Search Console, open that property → Settings → Users and permissions → Add user, enter ' . $who . ', and choose Restricted (read-only is enough).', array( 'status' => 403 ) );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'peak_gsc_not_found', 'Search Console has no property ' . $prop . ' that ' . $who . ' can see. Check the spelling: a Domain property looks like sc-domain:example.com, a URL-prefix property like https://www.example.com/ (with the exact http/https and www).', array( 'status' => 404 ) );
		}
		if ( 400 === $code ) {
			return new WP_Error( 'peak_gsc_bad_request', 'Search Console didn’t accept the request' . ( $message ? ': ' . $message : '' ) . '.', array( 'status' => 400 ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'peak_gsc_quota', 'Search Console’s usage limit was reached. Try again in a few minutes.', array( 'status' => 429 ) );
		}
		if ( 401 === $code ) {
			return new WP_Error( 'peak_gsc_unauthorized', 'Google didn’t accept the sign-in. Test again; if it keeps failing, paste a new key.', array( 'status' => 401 ) );
		}
		return new WP_Error( 'peak_gsc_error', 'Search Console returned an error (HTTP ' . $code . ( $message ? ': ' . $message : '' ) . '). This is usually temporary; try again later.', array( 'status' => $code ) );
	}
}
