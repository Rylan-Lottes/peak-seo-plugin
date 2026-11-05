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
