<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes the plugin's settings and its proposal records.
 * Changes you already approved stay on your pages.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'peak_seo_site_key' );
delete_option( 'peak_seo_last_contact' );
delete_option( 'peak_seo_central_approval' );
delete_option( 'peak_seo_central_changed' );
delete_option( 'peak_seo_digest_last' );
delete_option( 'peak_seo_facts' );

// Google Search Console.
foreach ( array( 'peak_seo_gsc_auth', 'peak_seo_gsc_key', 'peak_seo_gsc_identity', 'peak_seo_gsc_property', 'peak_seo_gsc_status' ) as $peak_opt ) {
	delete_option( $peak_opt );
}
delete_transient( 'peak_seo_gsc_token' );
wp_clear_scheduled_hook( 'peak_seo_weekly_digest' );

$peak_ids = get_posts(
	array(
		'post_type'      => 'peak_proposal',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $peak_ids as $peak_id ) {
	wp_delete_post( $peak_id, true );
}
