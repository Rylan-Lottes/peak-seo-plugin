<?php
/**
 * A way to get Google access tokens for Search Console.
 *
 * Today: Peak_SEO_GSC_Service_Account (a pasted service account key).
 * Later: a "Sign in with Google" (OAuth) class can implement the same
 * interface, and nothing that calls Search Console has to change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Peak_SEO_GSC_Credentials {

	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * A valid access token (cached until shortly before it expires).
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token();

	/**
	 * Drop any cached token (after a 401, or when the credentials change).
	 */
	public function forget_token();

	/**
	 * Who Google sees, for messages: e.g. the service account email.
	 */
	public function label();

	/**
	 * What to tell the owner to add in Search Console → Users and permissions.
	 */
	public function user_to_add();

	/**
	 * Google Cloud project id, if known (for "enable the API" links).
	 */
	public function project_id();
}
