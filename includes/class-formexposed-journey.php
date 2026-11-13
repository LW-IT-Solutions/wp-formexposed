<?php
/** Owner-operated recorder and optional visitor recovery. No drafts leave the browser. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class Formexposed_Journey {
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_formexposed_recovery', array( __CLASS__, 'save' ) );
	}
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_recovery' );
		update_option( 'formexposed_recovery_on', ! empty( $_POST['recovery_on'] ) ? 1 : 0, false );
		update_option( 'formexposed_recovery_email', sanitize_email( wp_unslash( $_POST['recovery_email'] ?? '' ) ), false );
		wp_safe_redirect( Formexposed_Core::page_url() );
		exit;
	}
	public static function assets() {
		if ( is_admin() ) {
			return;
		}
		if ( null !== filter_input( INPUT_GET, 'formexposed_record', FILTER_UNSAFE_RAW ) && current_user_can( 'manage_options' ) ) {
			// Only a logged-in site administrator can operate this. The fragment never goes to the server.
			nocache_headers();
			wp_enqueue_script( 'formexposed-recorder', plugins_url( '../assets/recorder.js', __FILE__ ), array(), FORMEXPOSED_VERSION, true );
			$u = wp_parse_url( FORMEXPOSED_API );
			wp_localize_script(
				'formexposed-recorder',
				'formexposedRecorder',
				array(
					'origin' => ( $u['scheme'] ?? 'https' ) . '://' . ( $u['host'] ?? '' ) . ( isset( $u['port'] ) ? ':' . $u['port'] : '' ),
					'lang'   => strtolower( str_replace( '_', '-', get_locale() ) ),
				)
			);
			return;
		}
		if ( ! get_option( 'formexposed_recovery_on' ) ) {
			return;
		}
		wp_enqueue_script( 'formexposed-recovery', plugins_url( '../assets/recovery.js', __FILE__ ), array(), FORMEXPOSED_VERSION, true );
		wp_localize_script(
			'formexposed-recovery',
			'formexposedRecovery',
			array(
				'email' => get_option( 'formexposed_recovery_email', '' ),
				'lang'  => strtolower( str_replace( '_', '-', get_locale() ) ),
			)
		);
	}
}
