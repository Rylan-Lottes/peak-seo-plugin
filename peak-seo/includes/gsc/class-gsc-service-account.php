<?php
/**
 * Google service account credentials: sign a JWT with the account's private
 * key (RS256) and exchange it for an access token.
 * https://developers.google.com/identity/protocols/oauth2/service-account#httprest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_GSC_Service_Account implements Peak_SEO_GSC_Credentials {

	const TOKEN_URI       = 'https://oauth2.googleapis.com/token';
	const TOKEN_TRANSIENT = 'peak_seo_gsc_token';

	/** @var array Parsed key file. */
	private $key;

	public function __construct( array $key ) {
		$this->key = $key;
	}

	/**
	 * Check a pasted key file and return its fields, or a clear error.
	 *
	 * @return array|WP_Error
	 */
	public static function parse_key( $json ) {
		$json = trim( (string) $json );
		if ( '' === $json ) {
			return new WP_Error( 'peak_gsc_key_empty', 'Paste the contents of the service account JSON key file.' );
		}
		$key = json_decode( $json, true );
		if ( ! is_array( $key ) ) {
			return new WP_Error( 'peak_gsc_key_json', 'That isn’t valid JSON. Open the .json file you downloaded from Google Cloud in a text editor and paste all of it, from { to }.' );
		}
		if ( isset( $key['installed'] ) || isset( $key['web'] ) ) {
			return new WP_Error( 'peak_gsc_key_oauth', 'That is an OAuth client file, not a service account key. In Google Cloud go to IAM & Admin → Service accounts → your account → Keys → Add key → JSON.' );
		}
		if ( ( $key['type'] ?? '' ) !== 'service_account' ) {
			return new WP_Error( 'peak_gsc_key_type', 'That JSON is not a service account key (its "type" should be "service_account").' );
		}
		foreach ( array( 'client_email', 'private_key' ) as $field ) {
			if ( empty( $key[ $field ] ) || ! is_string( $key[ $field ] ) ) {
				return new WP_Error( 'peak_gsc_key_field', 'The key file is missing "' . $field . '". Download a fresh JSON key from Google Cloud.' );
			}
		}
		if ( ! is_email( $key['client_email'] ) ) {
			return new WP_Error( 'peak_gsc_key_email', 'The key file’s "client_email" is not a valid email address.' );
		}
		if ( ! function_exists( 'openssl_pkey_get_private' ) ) {
			return new WP_Error( 'peak_gsc_no_openssl', 'This server’s PHP has no OpenSSL extension, which is needed to sign requests to Google.' );
		}
		$pkey = openssl_pkey_get_private( $key['private_key'] );
		if ( false === $pkey ) {
			return new WP_Error( 'peak_gsc_key_private', 'The key file’s private key can’t be read. Download a fresh JSON key from Google Cloud and paste it unchanged.' );
		}
		return array(
			'type'           => 'service_account',
			'client_email'   => $key['client_email'],
			'private_key'    => $key['private_key'],
			'private_key_id' => (string) ( $key['private_key_id'] ?? '' ),
			'project_id'     => (string) ( $key['project_id'] ?? '' ),
			'token_uri'      => self::TOKEN_URI, // Never trust a token URL from pasted input.
		);
	}

	public function label() {
		return $this->key['client_email'];
	}

	public function user_to_add() {
		return $this->key['client_email'];
	}

	public function project_id() {
		return $this->key['project_id'];
	}

	/**
	 * The signed assertion sent to Google's token endpoint.
	 *
	 * @param int|null $now Unix time (for tests).
	 * @return string|WP_Error
	 */
	public function jwt( $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		if ( '' !== $this->key['private_key_id'] ) {
			$header['kid'] = $this->key['private_key_id'];
		}
		$claims = array(
			'iss'   => $this->key['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URI,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);
		$input = self::b64url( wp_json_encode( $header ) ) . '.' . self::b64url( wp_json_encode( $claims ) );
		$pkey  = openssl_pkey_get_private( $this->key['private_key'] );
		if ( false === $pkey || ! openssl_sign( $input, $signature, $pkey, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'peak_gsc_sign', 'Could not sign the request with the service account key. Paste the key again.' );
		}
		return $input . '.' . self::b64url( $signature );
	}

	public function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_array( $cached ) && ( $cached['for'] ?? '' ) === $this->cache_id() && ! empty( $cached['token'] ) ) {
			return $cached['token'];
		}

		$jwt = $this->jwt();
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}
		$res = wp_remote_post(
			self::TOKEN_URI,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'peak_gsc_network', 'Could not reach Google to sign in (' . $res->get_error_message() . '). Check that this server can make outgoing HTTPS requests.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			return self::token_error( $code, is_array( $body ) ? $body : array() );
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 ); // Refresh a minute early.
		set_transient(
			self::TOKEN_TRANSIENT,
			array(
				'for'   => $this->cache_id(),
				'token' => $body['access_token'],
			),
			$ttl
		);
		return $body['access_token'];
	}

	public function forget_token() {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/**
	 * Plain-language version of Google's token errors.
	 */
	private static function token_error( $code, $body ) {
		$err  = (string) ( $body['error'] ?? '' );
		$desc = (string) ( $body['error_description'] ?? '' );
		if ( 'invalid_grant' === $err && false !== stripos( $desc, 'signature' ) ) {
			$msg = 'Google rejected the key’s signature. The key may have been deleted or disabled in Google Cloud. Create a new JSON key and paste it here.';
		} elseif ( 'invalid_grant' === $err && ( false !== stripos( $desc, 'timeframe' ) || false !== stripos( $desc, 'iat' ) || false !== stripos( $desc, 'exp' ) ) ) {
			$msg = 'Google says the request’s time is wrong. This server’s clock is probably off; ask your host to sync it (NTP).';
		} elseif ( 'invalid_grant' === $err && false !== stripos( $desc, 'account not found' ) ) {
			$msg = 'Google can’t find this service account. It may have been deleted. Create a new one (or a new key) in Google Cloud.';
		} elseif ( 'unauthorized_client' === $err || 'disabled_client' === $err || false !== stripos( $desc, 'disabled' ) ) {
			$msg = 'This service account is disabled in Google Cloud. Enable it, or create a new key.';
		} else {
			$msg = 'Google would not sign in with this key' . ( $desc ? ': ' . $desc : ( $err ? ': ' . $err : ' (HTTP ' . $code . ')' ) ) . '.';
		}
		return new WP_Error( 'peak_gsc_token', $msg, array( 'status' => $code ) );
	}

	private function cache_id() {
		return md5( $this->key['client_email'] . '|' . $this->key['private_key_id'] );
	}

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
