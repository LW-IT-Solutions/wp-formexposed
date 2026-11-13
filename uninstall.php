<?php
/**
 * formexposed - uninstall: remove everything the plugin stored - the options,
 * the quarantine and rescue tables, our transients and the scheduled events.
 * On a multisite network: on every site (since 1.4.1), because each site that
 * used the plugin has its own options and tables.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Everything of the plugin on the current site. */
function formexposed_uninstall_site() {
	global $wpdb;
	$options = array(
		'formexposed_key',
		'formexposed_last',
		'formexposed_notice_off',
		'formexposed_secret',
		'formexposed_guard',
		'formexposed_stats',
		'formexposed_linked',
		'formexposed_link_pending',
		'formexposed_rekey',
		'formexposed_agency_code',
		'formexposed_agency_done',
		'formexposed_agency_msg',
		'formexposed_version',
		'formexposed_swarm',
		'formexposed_swarm_off',
		'formexposed_versions',
		'formexposed_changes_queue',
		'formexposed_q_db',
		'formexposed_q_recipient',
		// 1.4.0
		'formexposed_r_db',
		'formexposed_rescue_off',
		'formexposed_mailfail_at',
		'formexposed_mailfail_last',
		'formexposed_watch_slugs',
		'formexposed_health',
		'formexposed_pow',
		'formexposed_recaptcha_backup',
		'formexposed_retention',
		'formexposed_retention_log',
		'formexposed_maint',
		// 1.5.0
		'formexposed_filters_candidate',
		'formexposed_filter_observations',
		'formexposed_field_metrics_on',
		'formexposed_recovery_on',
		'formexposed_recovery_email',
		'formexposed_filters',
		'formexposed_guard_extra',
		'formexposed_metrics_on',
		'formexposed_form_metrics',
		// 1.8.0 / 1.9.0: hosted form protection
		'formexposed_protect',
		'formexposed_protect_notice_off',
	);
	foreach ( $options as $o ) {
		delete_option( $o );
	}
	// Our own tables and transients, on uninstall only.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'formexposed_quarantine' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'formexposed_rescue' ) );
	delete_transient( 'formexposed_msg' );
	delete_transient( 'formexposed_listing' );
	delete_transient( 'formexposed_protect_msg' );
	// The rest of our transients (rate limit, used proof-of-work answers) expire within the hour;
	// in the database they are removed right away.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_formexposed_' ) . '%', $wpdb->esc_like( '_transient_timeout_formexposed_' ) . '%' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	wp_unschedule_hook( 'formexposed_daily' );
	wp_unschedule_hook( 'formexposed_changes' );
	wp_unschedule_hook( 'formexposed_hourly' );
	wp_unschedule_hook( 'formexposed_maintenance_check' );
	wp_unschedule_hook( 'formexposed_mailfail' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $formexposed_site ) {
		switch_to_blog( (int) $formexposed_site );
		formexposed_uninstall_site();
		restore_current_blog();
	}
} else {
	formexposed_uninstall_site();
}
