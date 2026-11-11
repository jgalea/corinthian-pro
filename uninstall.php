<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$corinthian_pro_cleanup = static function () use ( $wpdb ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'corinthian_segments_' ) . '%' ) );
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_corinthian_ai_calls_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_corinthian_ai_calls_' ) . '%'
		)
	);
	// phpcs:enable
	wp_cache_flush();
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $corinthian_pro_site ) {
		switch_to_blog( (int) $corinthian_pro_site );
		$corinthian_pro_cleanup();
		restore_current_blog();
	}
	delete_site_transient( 'corinthian_pro_release' );
	// User meta is global on multisite, so one pass covered it; the transients are per site.
} else {
	$corinthian_pro_cleanup();
	delete_site_transient( 'corinthian_pro_release' );
}
