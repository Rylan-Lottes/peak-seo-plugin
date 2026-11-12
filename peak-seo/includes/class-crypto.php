<?php
/**
 * Encryption for secrets stored in the database (e.g. the Google service
 * account key). Uses libsodium's secretbox with a key derived from this
 * site's WordPress salts (wp-config.php AUTH_KEY / SECURE_AUTH_KEY and their
 * salts), so a database copy alone can't reveal the secret.
 *
 * If the salts change, stored secrets can no longer be read: decrypt()
 * returns false and the settings screen asks for the secret again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Crypto {

	const PREFIX = 'pkv1:';

	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	private static function key() {
		$material = 'peak-seo|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * @return string|WP_Error "pkv1:" + base64( nonce + ciphertext ).
	 */
	public static function encrypt( $plain ) {
		if ( ! self::available() ) {
			return new WP_Error( 'peak_no_sodium', 'This server cannot encrypt secrets (the PHP sodium extension is missing), so the key was not saved.' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @return string|false The secret, or false if it can't be read.
	 */
	public static function decrypt( $blob ) {
		if ( ! self::available() || ! is_string( $blob ) || 0 !== strpos( $blob, self::PREFIX ) ) {
			return false;
		}
		$raw = base64_decode( substr( $blob, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
		return false === $plain ? false : $plain;
	}
}
