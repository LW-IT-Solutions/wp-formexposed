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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the presence of the parameter is read; the recorder runs for site administrators only.
		if ( isset( $_GET['formexposed_record'] ) && current_user_can( 'manage_options' ) ) {
			// Only a logged-in site administrator can operate this. The fragment never goes to the server.
			nocache_headers();
			wp_enqueue_script( 'formexposed-recorder', plugins_url( 'assets/recorder.js', FORMEXPOSED_FILE ), array(), FORMEXPOSED_VERSION, true );
			$u = wp_parse_url( FORMEXPOSED_API );
			wp_localize_script(
				'formexposed-recorder',
				'formexposedRecorder',
				array(
					'origin' => ( $u['scheme'] ?? 'https' ) . '://' . ( $u['host'] ?? '' ) . ( isset( $u['port'] ) ? ':' . $u['port'] : '' ),
					'text'   => array(
						'title'     => __( 'formexposed: record check journey', 'formexposed' ),
						'intro'     => __( 'Click the steps to the form. No typing or submissions.', 'formexposed' ),
						'pick'      => __( 'Select form', 'formexposed' ),
						'stop'      => __( 'Stop recording', 'formexposed' ),
						'steps'     => __( 'Steps:', 'formexposed' ),
						'unclear'   => __( 'This element cannot be selected uniquely.', 'formexposed' ),
						'notStored' => __( 'This click is not recorded. Choose a link or a safe open button.', 'formexposed' ),
						'maxSteps'  => __( 'Maximum five steps.', 'formexposed' ),
						'done'      => __( 'Form copied. Review and save in the formexposed window.', 'formexposed' ),
					),
				)
			);
			return;
		}
		if ( ! get_option( 'formexposed_recovery_on' ) ) {
			return;
		}
		wp_enqueue_script( 'formexposed-recovery', plugins_url( 'assets/recovery.js', FORMEXPOSED_FILE ), array(), FORMEXPOSED_VERSION, true );
		wp_localize_script(
			'formexposed-recovery',
			'formexposedRecovery',
			array(
				'email' => sanitize_email( (string) get_option( 'formexposed_recovery_email', '' ) ),
				'text'  => array(
					'kept'    => __( 'The enquiry was not sent. Your entries remain in this open form.', 'formexposed' ),
					'offline' => __( 'You are offline. Reconnect before sending again.', 'formexposed' ),
					'failed'  => __( 'Sending failed. Review your entries and send again.', 'formexposed' ),
					'back'    => __( 'Back to the form', 'formexposed' ),
					'mail'    => __( 'Contact by email instead', 'formexposed' ),
					'unknown' => __( 'The delivery status is unclear. Check for a confirmation before sending again.', 'formexposed' ),
				),
			)
		);
	}
}
