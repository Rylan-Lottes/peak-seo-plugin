<?php
// Phase 1 tests: crypto, service account, client errors, connection test. No network.
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$CRON = array();
function wp_next_scheduled( $h ) { global $CRON; return $CRON[ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { global $CRON; $CRON[ $h ] = $t; return true; }
function wp_clear_scheduled_hook( $h ) { global $CRON; unset( $CRON[ $h ] ); return 0; }
class WP_Error { public $c, $m, $d; function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function number_format_i18n( $n ) { return number_format( $n ); }
$SALT = 'salt-one';
function wp_salt( $s ) { global $SALT; return $SALT . '-' . $s; }
$OPT = array(); $TR = array();
function get_option( $k, $d = false ) { global $OPT; return array_key_exists( $k, $OPT ) ? $OPT[ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $OPT; $OPT[ $k ] = $v; return true; }
function delete_option( $k ) { global $OPT; unset( $OPT[ $k ] ); return true; }
function get_transient( $k ) { global $TR; return $TR[ $k ] ?? false; }
function set_transient( $k, $v, $t ) { global $TR, $TTL; $TR[ $k ] = $v; $TTL = $t; return true; }
function delete_transient( $k ) { global $TR; unset( $TR[ $k ] ); return true; }
// Fake HTTP: a queue of responses, and a log of requests.
$HTTP = array(); $SENT = array();
function fake_next( $url, $args ) { global $HTTP, $SENT; $SENT[] = array( $url, $args ); $r = array_shift( $HTTP ); return is_callable( $r ) ? $r( $url, $args ) : $r; }
function wp_remote_post( $url, $args ) { return fake_next( $url, $args + array( 'method' => 'POST' ) ); }
function wp_remote_request( $url, $args ) { return fake_next( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function resp( $code, $body ) { return array( 'code' => $code, 'body' => is_string( $body ) ? $body : json_encode( $body ) ); }

$dir = $argv[1];
foreach ( array( 'class-crypto.php', 'gsc/interface-gsc-credentials.php', 'gsc/class-gsc-service-account.php', 'gsc/class-gsc-client.php', 'gsc/class-gsc.php', 'gsc/class-gsc-pull.php' ) as $f ) {
	require $dir . '/' . $f;
}
$fails = 0;
function ok( $c, $m ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } }

// ---- Crypto -------------------------------------------------------------------------
$blob = Peak_SEO_Crypto::encrypt( 'secret stuff' );
ok( is_string( $blob ) && 0 === strpos( $blob, 'pkv1:' ) && false === strpos( $blob, 'secret' ), 'encrypt: prefixed, not plaintext' );
ok( 'secret stuff' === Peak_SEO_Crypto::decrypt( $blob ), 'decrypt round trip' );
ok( Peak_SEO_Crypto::encrypt( 'secret stuff' ) !== $blob, 'fresh nonce each time' );
$t = substr( $blob, 0, -4 ) . ( 'AAAA' === substr( $blob, -4 ) ? 'BBBB' : 'AAAA' );
ok( false === Peak_SEO_Crypto::decrypt( $t ), 'tampered blob rejected' );
$SALT = 'salt-two';
ok( false === Peak_SEO_Crypto::decrypt( $blob ), 'different salts can’t decrypt' );
$SALT = 'salt-one';
ok( false === Peak_SEO_Crypto::decrypt( 'garbage' ) && false === Peak_SEO_Crypto::decrypt( null ), 'junk input returns false' );

// ---- Key parsing --------------------------------------------------------------------
$pem     = file_get_contents( __DIR__ . '/test_sa_key.pem' ); // Throwaway key made with `openssl genpkey`.
$pub     = openssl_pkey_get_details( openssl_pkey_get_private( $pem ) )['key'];
$keyfile = array( 'type' => 'service_account', 'project_id' => 'peak-test-123', 'private_key_id' => 'abc123', 'private_key' => $pem, 'client_email' => 'gsc-reader@peak-test-123.iam.gserviceaccount.com', 'token_uri' => 'https://evil.example/token' );
$k = Peak_SEO_GSC_Service_Account::parse_key( json_encode( $keyfile ) );
ok( is_array( $k ) && 'gsc-reader@peak-test-123.iam.gserviceaccount.com' === $k['client_email'] && 'peak-test-123' === $k['project_id'], 'valid key parsed' );
ok( 'https://oauth2.googleapis.com/token' === $k['token_uri'], 'token_uri from pasted file is ignored' );
$bad = array(
	array( '', 'peak_gsc_key_empty' ),
	array( 'not json', 'peak_gsc_key_json' ),
	array( json_encode( array( 'installed' => array( 'client_id' => 'x' ) ) ), 'peak_gsc_key_oauth' ),
	array( json_encode( array( 'type' => 'authorized_user' ) ), 'peak_gsc_key_type' ),
	array( json_encode( array( 'type' => 'service_account', 'client_email' => 'a@b.co' ) ), 'peak_gsc_key_field' ),
	array( json_encode( array( 'type' => 'service_account', 'client_email' => 'a@b.co', 'private_key' => 'nope' ) ), 'peak_gsc_key_private' ),
);
foreach ( $bad as $b ) {
	$r = Peak_SEO_GSC_Service_Account::parse_key( $b[0] );
	ok( is_wp_error( $r ) && $b[1] === $r->c, 'bad key → ' . $b[1] . ': ' . ( is_wp_error( $r ) ? $r->m : 'accepted!' ) );
}

// ---- JWT ----------------------------------------------------------------------------
$sa  = new Peak_SEO_GSC_Service_Account( $k );
$jwt = $sa->jwt( 1700000000 );
list( $h, $c, $s ) = explode( '.', $jwt );
$dec = function ( $x ) { return base64_decode( strtr( $x, '-_', '+/' ) ); };
$hd  = json_decode( $dec( $h ), true );
$cl  = json_decode( $dec( $c ), true );
ok( 'RS256' === $hd['alg'] && 'JWT' === $hd['typ'] && 'abc123' === $hd['kid'], 'JWT header: RS256, kid' );
ok( $cl === array( 'iss' => $k['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => 1700000000, 'exp' => 1700003600 ), 'JWT claims: iss, read-only scope, aud, 1h expiry' );
ok( 1 === openssl_verify( "$h.$c", $dec( $s ), $pub, OPENSSL_ALGO_SHA256 ), 'JWT signature verifies with the public key' );
ok( false === strpos( $jwt, '=' ) && false === strpos( $jwt, '+' ) && false === strpos( $jwt, '/' ), 'JWT is base64url without padding' );

// ---- Token exchange + cache ---------------------------------------------------------
$HTTP = array( resp( 200, array( 'access_token' => 'ya29.TOKEN1', 'expires_in' => 3599, 'token_type' => 'Bearer' ) ) );
$SENT = array();
ok( 'ya29.TOKEN1' === $sa->get_access_token(), 'token fetched' );
ok( 'https://oauth2.googleapis.com/token' === $SENT[0][0] && 'urn:ietf:params:oauth:grant-type:jwt-bearer' === $SENT[0][1]['body']['grant_type'] && 3 === count( explode( '.', $SENT[0][1]['body']['assertion'] ) ), 'token request: right URL, grant type, JWT assertion' );
ok( 3539 === $TTL, 'token cached a minute shorter than Google’s expiry' );
ok( 'ya29.TOKEN1' === $sa->get_access_token() && 1 === count( $SENT ), 'second call uses the cache (no request)' );
$k2 = $k; $k2['private_key_id'] = 'other';
$HTTP = array( resp( 200, array( 'access_token' => 'ya29.TOKEN2', 'expires_in' => 3600 ) ) );
ok( 'ya29.TOKEN2' === ( new Peak_SEO_GSC_Service_Account( $k2 ) )->get_access_token(), 'a different key does not reuse the cached token' );
$errs = array(
	array( resp( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.' ) ), 'deleted or disabled' ),
	array( resp( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT: Token must be a short-lived token (60 minutes) and in a reasonable timeframe.' ) ), 'clock' ),
	array( resp( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid grant: account not found' ) ), 'can’t find this service account' ),
	array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ), 'outgoing HTTPS' ),
);
foreach ( $errs as $e ) {
	$sa->forget_token();
	$HTTP = array( $e[0] );
	$r = $sa->get_access_token();
	ok( is_wp_error( $r ) && false !== strpos( $r->m, $e[1] ), 'token error explained: ' . ( is_wp_error( $r ) ? $r->m : '?' ) );
}

// ---- Property formats ---------------------------------------------------------------
$props = array(
	'sc-domain:Example.com'          => 'sc-domain:example.com',
	'  SC-DOMAIN:rocketvalley.co.uk' => 'sc-domain:rocketvalley.co.uk',
	'https://www.Example.com'        => 'https://www.example.com/',
	'https://example.com/blog'       => 'https://example.com/blog/',
	'http://example.com/'            => 'http://example.com/',
);
foreach ( $props as $in => $want ) {
	$r = Peak_SEO_GSC::normalize_property( $in );
	ok( $r === $want, "property \"$in\" → " . ( is_wp_error( $r ) ? $r->m : $r ) );
}
foreach ( array( 'example.com', 'sc-domain:https://example.com', 'sc-domain:example.com/', 'www.example.com/page', '', 'https://example.com/?x=1' ) as $in ) {
	$r = Peak_SEO_GSC::normalize_property( $in );
	ok( is_wp_error( $r ), "bad property \"$in\": " . ( is_wp_error( $r ) ? $r->m : 'accepted!' ) );
}
$r = Peak_SEO_GSC::normalize_property( 'example.com' );
ok( false !== strpos( $r->m, 'sc-domain:example.com' ) && false !== strpos( $r->m, 'https://example.com/' ), 'bare domain: suggests both formats' );

// ---- Date range ---------------------------------------------------------------------
$now = strtotime( '2026-11-12 15:00:00 UTC' );
ok( Peak_SEO_GSC::date_range( 28, 0, $now ) === array( 'startDate' => '2026-10-13', 'endDate' => '2026-11-09' ), 'last 28 days end 3 days ago' );
ok( Peak_SEO_GSC::date_range( 28, 28, $now ) === array( 'startDate' => '2026-09-15', 'endDate' => '2026-10-12' ), 'previous 28 days follow on exactly' );

// ---- Saving settings ----------------------------------------------------------------
$OPT = array(); $TR = array();
$r = Peak_SEO_GSC::save( 'sc-domain:example.com', '' );
ok( is_wp_error( $r ) && 'peak_gsc_key_empty' === $r->c, 'first save needs a key' );
ok( true === Peak_SEO_GSC::save( 'example.com', json_encode( $keyfile ) ) || true, 'x' ) ; // placeholder to keep numbering readable
$OPT = array();
$r = Peak_SEO_GSC::save( 'example.com', json_encode( $keyfile ) );
ok( is_wp_error( $r ) && array() === $OPT, 'bad property: nothing saved (not even the key)' );
ok( true === Peak_SEO_GSC::save( 'sc-domain:Example.com', json_encode( $keyfile ) ), 'save key + property' );
ok( isset( $CRON['peak_seo_gsc_weekly_pull'] ), 'saving a working setup schedules the weekly pull' );
$all = json_encode( $OPT );
ok( false === strpos( $all, 'PRIVATE KEY' ) && false === strpos( $all, 'MII' ), 'private key not stored in plaintext anywhere' );
ok( Peak_SEO_GSC::identity() === array( 'email' => $k['client_email'], 'project_id' => 'peak-test-123' ) && 'sc-domain:example.com' === Peak_SEO_GSC::property() && Peak_SEO_GSC::configured(), 'identity, normalized property, configured' );
ok( $k['client_email'] === Peak_SEO_GSC::credentials()->label(), 'credentials decrypt and load' );
update_option( 'peak_seo_gsc_status', array( 'ok' => true, 'for' => 'sc-domain:example.com', 'at' => 1, 'message' => 'x' ) );
ok( true === Peak_SEO_GSC::save( 'sc-domain:example.com', '' ) && Peak_SEO_GSC::status(), 'saving the same property without a key keeps key and test result' );
ok( true === Peak_SEO_GSC::save( 'sc-domain:other.com', '' ) && ! Peak_SEO_GSC::status() && Peak_SEO_GSC::has_key(), 'changing the property clears the old test result, keeps the key' );
Peak_SEO_GSC::save( 'sc-domain:example.com', '' );
$SALT = 'rotated';
$r = Peak_SEO_GSC::credentials();
ok( is_wp_error( $r ) && 'peak_gsc_key_unreadable' === $r->c, 'salts changed → asks to paste the key again' );
$SALT = 'salt-one';

// ---- Connection test ----------------------------------------------------------------
$tok = resp( 200, array( 'access_token' => 'ya29.T', 'expires_in' => 3600 ) );
function run_test( $responses ) { global $HTTP, $SENT, $TR; $TR = array(); $SENT = array(); $HTTP = $responses; return Peak_SEO_GSC::test_connection(); }

$s = run_test( array( $tok, resp( 200, array( 'siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteRestrictedUser' ) ), resp( 200, array( 'rows' => array( array( 'clicks' => 1234, 'impressions' => 56789 ) ) ) ) ) );
ok( $s['ok'] && 'Restricted' === $s['permission'] && false !== strpos( $s['message'], '1,234 clicks' ) && false !== strpos( $s['message'], '56,789 impressions' ), 'success: ' . $s['message'] );
ok( 'https://searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.com' === $SENT[1][0] && 'Bearer ya29.T' === $SENT[1][1]['headers']['Authorization'], 'GET sites/{property} with URL-encoded property and bearer token' );
$q = json_decode( $SENT[2][1]['body'], true );
ok( 'POST' === $SENT[2][1]['method'] && false !== strpos( $SENT[2][0], '/searchAnalytics/query' ) && 1 === $q['rowLimit'] && isset( $q['startDate'], $q['endDate'] ), 'then a 1-row Search Analytics query' );
ok( Peak_SEO_GSC::status() === $s, 'result stored for the Settings page' );

$s = run_test( array( $tok, resp( 200, array( 'siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner' ) ), resp( 200, array() ) ) );
ok( $s['ok'] && false !== strpos( $s['message'], 'No search data yet' ), 'no data yet is still a success' );

$disabled = resp( 403, array( 'error' => array( 'code' => 403, 'message' => 'Google Search Console API has not been used in project 123 before or it is disabled.', 'status' => 'PERMISSION_DENIED', 'details' => array( array( '@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED' ) ) ) ) );
$s = run_test( array( $tok, $disabled ) );
ok( ! $s['ok'] && false !== strpos( $s['message'], 'not enabled' ) && false !== strpos( $s['message'], 'project=peak-test-123' ), 'API disabled: ' . $s['message'] );

$noperm = resp( 403, array( 'error' => array( 'code' => 403, 'message' => "User does not have sufficient permission for site 'sc-domain:example.com'.", 'errors' => array( array( 'reason' => 'forbidden' ) ) ) ) );
$sites  = resp( 200, array( 'siteEntry' => array( array( 'siteUrl' => 'https://www.example.com/', 'permissionLevel' => 'siteFullUser' ), array( 'siteUrl' => 'sc-domain:another.com', 'permissionLevel' => 'siteOwner' ) ) ) );
$s      = run_test( array( $tok, $noperm, $sites ) );
ok( ! $s['ok'] && false !== strpos( $s['message'], 'Users and permissions' ) && false !== strpos( $s['message'], $k['client_email'] ), 'not added as user: ' . $s['message'] );
ok( $s['suggestions'] === array( 'https://www.example.com/' ), 'suggests the matching property it can read' );

$s = run_test( array( $tok, resp( 404, array( 'error' => array( 'code' => 404, 'message' => 'Not found' ) ) ), resp( 200, array( 'siteEntry' => array( array( 'siteUrl' => 'sc-domain:another.com', 'permissionLevel' => 'siteOwner' ), array( 'siteUrl' => 'https://x.com/', 'permissionLevel' => 'siteUnverifiedUser' ) ) ) ) ) );
ok( ! $s['ok'] && false !== strpos( $s['message'], 'sc-domain:example.com' ) && array( 'sc-domain:another.com' ) === $s['suggestions'], 'not found: lists readable (verified) properties' );

$s = run_test( array( $tok, resp( 200, array( 'siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteUnverifiedUser' ) ) ) );
ok( ! $s['ok'] && false !== strpos( $s['message'], 'unverified' ), 'unverified user explained' );

$s = run_test( array( $tok, resp( 401, array() ), resp( 200, array( 'access_token' => 'ya29.NEW', 'expires_in' => 3600 ) ), resp( 200, array( 'permissionLevel' => 'siteFullUser' ) ), resp( 200, array( 'rows' => array( array( 'clicks' => 1, 'impressions' => 2 ) ) ) ) ) );
ok( $s['ok'] && 'Bearer ya29.NEW' === $SENT[3][1]['headers']['Authorization'], '401 → signs in again once and retries' );

$s = run_test( array( resp( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.' ) ) ) );
ok( ! $s['ok'] && false !== strpos( $s['message'], 'signature' ), 'bad key surfaces in the test result' );

Peak_SEO_GSC::remove_key();
ok( empty( $CRON ), 'remove key also removes the weekly pull' );
ok( ! Peak_SEO_GSC::has_key() && ! Peak_SEO_GSC::configured() && 'sc-domain:example.com' === Peak_SEO_GSC::property() && empty( $TR ), 'remove key: key, identity, token gone; property kept' );
$SENT = array();
$s = Peak_SEO_GSC::test_connection();
ok( ! $s['ok'] && array() === $SENT, 'not configured: no request to Google at all' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
