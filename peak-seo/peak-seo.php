<?php
/**
 * Plugin Name:       Peak SEO
 * Description:       Connects this site to the Peak SEO service. Suggested SEO improvements arrive as proposals that you preview, approve or reject. Nothing changes on your site until you approve it, and every change can be undone.
 * Version:           0.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Peak Visibility
 * Author URI:        https://www.peakvisibility.net
 * License:           GPL-2.0-or-later
 * Text Domain:       peak-seo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEAK_SEO_VERSION', '0.7.0' );
define( 'PEAK_SEO_FILE', __FILE__ );
define( 'PEAK_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'PEAK_SEO_URL', plugin_dir_url( __FILE__ ) );

require_once PEAK_SEO_DIR . 'includes/class-auth.php';
require_once PEAK_SEO_DIR . 'includes/class-crypto.php';
require_once PEAK_SEO_DIR . 'includes/gsc/interface-gsc-credentials.php';
require_once PEAK_SEO_DIR . 'includes/gsc/class-gsc-service-account.php';
require_once PEAK_SEO_DIR . 'includes/gsc/class-gsc-client.php';
require_once PEAK_SEO_DIR . 'includes/gsc/class-gsc.php';
require_once PEAK_SEO_DIR . 'includes/gsc/class-gsc-store.php';
require_once PEAK_SEO_DIR . 'includes/gsc/class-gsc-pull.php';
require_once PEAK_SEO_DIR . 'includes/gsc/class-gsc-report.php';
require_once PEAK_SEO_DIR . 'includes/gsc/class-gsc-report-page.php';
require_once PEAK_SEO_DIR . 'includes/class-seo-fields.php';
require_once PEAK_SEO_DIR . 'includes/class-elementor.php';
require_once PEAK_SEO_DIR . 'includes/class-owner-check.php';
require_once PEAK_SEO_DIR . 'includes/class-proposals.php';
require_once PEAK_SEO_DIR . 'includes/class-digest.php';
require_once PEAK_SEO_DIR . 'includes/class-rest.php';
require_once PEAK_SEO_DIR . 'includes/class-admin.php';

add_action( 'init', array( 'Peak_SEO_Proposals', 'register_post_type' ) );
add_action( 'rest_api_init', array( 'Peak_SEO_Rest', 'register_routes' ) );
add_action( 'wp_head', array( 'Peak_SEO_Fields', 'output_fallback_meta' ), 1 );
add_filter( 'pre_get_document_title', array( 'Peak_SEO_Fields', 'filter_fallback_title' ), 20 );
add_action( 'template_redirect', array( 'Peak_SEO_Proposals', 'maybe_preview' ) );
add_action( 'init', array( 'Peak_SEO_Digest', 'schedule' ) );
add_action( Peak_SEO_Digest::HOOK, array( 'Peak_SEO_Digest', 'run' ) );
add_action( 'init', array( 'Peak_SEO_GSC_Store', 'maybe_install' ), 5 );
add_action( 'admin_init', array( 'Peak_SEO_GSC_Pull', 'schedule' ) ); // Settings only change in wp-admin.
add_action( Peak_SEO_GSC_Pull::HOOK, array( 'Peak_SEO_GSC_Pull', 'cron' ) );

if ( is_admin() ) {
	Peak_SEO_Admin::init();
}

register_activation_hook( __FILE__, 'peak_seo_activate' );
register_deactivation_hook( __FILE__, 'peak_seo_deactivate' );

/**
 * Create a site key on first activation.
 */
function peak_seo_activate() {
	Peak_SEO_Proposals::register_post_type();
	Peak_SEO_GSC_Store::install();
	if ( ! get_option( 'peak_seo_site_key' ) ) {
		update_option( 'peak_seo_site_key', Peak_SEO_Auth::generate_key(), false );
	}
}

/**
 * Stop scheduled jobs. Data and settings stay until the plugin is deleted.
 */
function peak_seo_deactivate() {
	Peak_SEO_Digest::unschedule();
	Peak_SEO_GSC_Pull::unschedule();
}
