<?php
/** Optional form counters. No field values, cookies, addresses or visitor identifiers. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class Formexposed_Form_Metrics {
	const OPT        = 'formexposed_form_metrics';
	const CATEGORIES = array( 'required', 'type', 'pattern', 'range', 'length', 'bad_input', 'custom' );
	const EVENTS     = array( 'seen', 'started', 'invalid', 'attempted', 'accepted_client', 'mail_failed_client' );
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_formexposed_metrics', array( __CLASS__, 'save' ) );
	}
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_metrics' );
		update_option( 'formexposed_metrics_on', ! empty( $_POST['metrics_on'] ) ? 1 : 0, false );
		$fieldOn = ! empty( $_POST['metrics_on'] ) && ! empty( $_POST['field_metrics_on'] );
		update_option( 'formexposed_field_metrics_on', $fieldOn ? 1 : 0, false );
		if ( ! $fieldOn ) {
			$s = get_option( self::OPT, array() );
			if ( is_array( $s ) ) {
				foreach ( $s as &$forms ) {
					foreach ( $forms as &$counts ) {
									unset( $counts['fields'] );
					}
				}unset( $forms, $counts );
				update_option( self::OPT, $s, false );}
		}
		if ( empty( $_POST['metrics_on'] ) ) {
			delete_option( self::OPT );
		}
		wp_safe_redirect( Formexposed_Core::page_url() );
		exit;
	}
	public static function token( $day ) {
		return hash_hmac( 'sha256', 'form-metrics|' . $day, wp_salt( 'auth' ) );}
	public static function assets() {
		if ( ! get_option( 'formexposed_metrics_on' ) || is_admin() || ! get_option( 'formexposed_linked' ) || ( null !== filter_input( INPUT_GET, 'formexposed_record', FILTER_CALLBACK, array( 'options' => 'sanitize_key' ) ) && current_user_can( 'manage_options' ) ) ) {
			return;
		}
		wp_enqueue_script( 'formexposed-metrics', plugins_url( '../assets/metrics.js', __FILE__ ), array(), FORMEXPOSED_VERSION, true );
		wp_localize_script(
			'formexposed-metrics',
			'formexposedMetrics',
			array(
				'url'    => rest_url( 'formexposed/v1/events' ),
				'token'  => self::token( gmdate( 'Y-m-d' ) ),
				'fields' => (bool) get_option( 'formexposed_field_metrics_on' ),
			)
		);
	}
	public static function routes() {
		register_rest_route(
			'formexposed/v1',
			'/events',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}
	public static function permission( $r ) {
		if ( ! get_option( 'formexposed_metrics_on' ) || ! get_option( 'formexposed_linked' ) ) {
			return false;
		}
		$origin = wp_parse_url( $r->get_header( 'origin' ) );
		$site   = wp_parse_url( home_url() );
		if ( ! $origin || strtolower( $origin['host'] ?? '' ) !== strtolower( $site['host'] ?? '' ) || ( $origin['scheme'] ?? '' ) !== ( $site['scheme'] ?? '' ) || ( $origin['port'] ?? null ) !== ( $site['port'] ?? null ) ) {
			return false;
		}
		$token = (string) $r->get_param( 'token' );
		return hash_equals( self::token( gmdate( 'Y-m-d' ) ), $token ) || hash_equals( self::token( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ), $token );
	}
	public static function receive( $r ) {
		$key      = (string) $r->get_param( 'key' );
		$event    = (string) $r->get_param( 'event' );
		$field    = $event === 'field_invalid';
		$position = (int) $r->get_param( 'position' );
		$category = (string) $r->get_param( 'category' );
		if ( $field && ( ! get_option( 'formexposed_field_metrics_on' ) || $position < 1 || $position > 30 || ! in_array( $category, self::CATEGORIES, true ) ) ) {
			return new WP_Error( 'invalid', __( 'Invalid field event.', 'formexposed' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/D', $key ) || ( ! $field && ! in_array( $event, self::EVENTS, true ) ) ) {
			return new WP_Error( 'invalid', __( 'Invalid event.', 'formexposed' ), array( 'status' => 400 ) );
		}
		// Short-lived local rate limiter only. It is never sent to the service.
		$lim = 'formexposed_metric_rl_' . gmdate( 'YmdHi' ) . '_' . substr( hash_hmac( 'sha256', Formexposed_Guard::client_ip(), wp_salt( 'auth' ) ), 0, 24 );
		$n   = (int) get_transient( $lim );
		if ( $n >= 60 ) {
			return new WP_Error( 'limited', __( 'Rate limited.', 'formexposed' ), array( 'status' => 429 ) );
		}set_transient( $lim, $n + 1, 60 );
		global $wpdb;
		$lock = 'fe_metrics_' . substr( hash( 'sha256', home_url() ), 0, 32 );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,1)', $lock ) ) !== 1 ) {
			return new WP_Error( 'busy', __( 'Try again.', 'formexposed' ), array( 'status' => 503 ) );
		}
		try {
			wp_cache_delete( self::OPT, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$s = get_option( self::OPT, array() );
			if ( ! is_array( $s ) ) {
				$s = array();
			}$day = gmdate( 'Y-m-d' );
			foreach ( array_keys( $s )as $d ) {
				if ( $d < gmdate( 'Y-m-d', time() - 13 * DAY_IN_SECONDS ) ) {
					unset( $s[ $d ] );
				}
			}
			if ( ! isset( $s[ $day ] ) ) {
				$s[ $day ] = array();
			}
			if ( ! isset( $s[ $day ][ $key ] ) && count( $s[ $day ] ) >= 20 ) {
				return new WP_Error( 'limited', __( 'Form limit.', 'formexposed' ), array( 'status' => 429 ) );
			}
			if ( $field ) {
				$s[ $day ][ $key ]['fields'][ $position ][ $category ] = min( 1000000, (int) ( $s[ $day ][ $key ]['fields'][ $position ][ $category ] ?? 0 ) + 1 );
			} else {
				$s[ $day ][ $key ][ $event ] = min( 1000000, (int) ( $s[ $day ][ $key ][ $event ] ?? 0 ) + 1 );
			}
			update_option( self::OPT, $s, false );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
	}
	public static function days() {
		if ( ! get_option( 'formexposed_metrics_on' ) ) {
			return array();
		}
		$s   = get_option( self::OPT, array() );
		$out = array();
		if ( ! is_array( $s ) ) {
			return $out;
		}
		foreach ( $s as $day => $forms ) {
			if ( $day >= gmdate( 'Y-m-d', time() - 13 * DAY_IN_SECONDS ) ) {
				foreach ( $forms as $key => $counts ) {
								$out[] = array(
									'day'    => $day,
									'key'    => $key,
									'counts' => $counts,
								);
				}
			}
		}
		return array_slice( $out, 0, 280 );
	}
}
