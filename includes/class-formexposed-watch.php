<?php
/**
 * formexposed - what the plugin watches besides the protection layer (since 1.3.0).
 *
 *   reCAPTCHA  every answer of Google's check (siteverify), whichever plugin
 *              asks (WordPress HTTP API, filter http_response). Only three
 *              numbers are kept (Formexposed_Stats::recaptcha).
 *   Submissions accepted by Contact Form 7, WPForms, Gravity Forms, Elementor
 *              Pro are counted - no content.
 *   Changes    updates, activations and theme switches (name and version).
 *              While the site is connected they are sent to formexposed.com
 *              within a minute: the service then checks the forms again and
 *              names the change if protection is lost.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formexposed_Watch {

	const OPT_SNAPSHOT = 'formexposed_versions';
	const OPT_QUEUE    = 'formexposed_changes_queue';
	const CRON_CHANGES = 'formexposed_changes';

	public static function init() {
		add_filter( 'http_response', array( __CLASS__, 'http_response' ), 10, 3 );

		add_action( 'wpcf7_submit', array( __CLASS__, 'cf7_submit' ), 10, 2 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'counted' ), 10, 0 );
		add_action( 'gform_after_submission', array( __CLASS__, 'gform_after' ), 10, 2 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'counted' ), 10, 0 );

		foreach ( array( 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'switch_theme', '_core_updated_successfully' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'soon' ), 99, 0 );
		}
		add_action( self::CRON_CHANGES, array( __CLASS__, 'changes' ) );
	}

	// ------------------------------------------------------------------ reCAPTCHA

	/** Looks at the answer, changes nothing. */
	public static function http_response( $response, $args = array(), $url = '' ) {
		$p = wp_parse_url( (string) $url );
		if ( empty( $p['host'] ) || ! in_array( strtolower( $p['host'] ), array( 'www.google.com', 'google.com', 'www.recaptcha.net', 'recaptcha.net' ), true )
			|| empty( $p['path'] ) || '/recaptcha/api/siteverify' !== $p['path'] || is_wp_error( $response ) ) {
			return $response;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $j ) ) {
			Formexposed_Stats::recaptcha( ! empty( $j['success'] ), isset( $j['score'] ) ? (float) $j['score'] : null, ! empty( $j['action'] ) );
		}
		return $response;
	}

	// ------------------------------------------------------------------ submissions

	public static function counted() {
		Formexposed_Stats::submission();
	}

	/** Contact Form 7: accepted = the mail step was reached (sent or failed). */
	public static function cf7_submit( $form = null, $result = array() ) {
		if ( is_array( $result ) && isset( $result['status'] ) && in_array( $result['status'], array( 'mail_sent', 'mail_failed' ), true ) ) {
			Formexposed_Stats::submission();
		}
	}

	/** Gravity Forms: spam entries are no submissions. */
	public static function gform_after( $entry = array(), $form = array() ) {
		if ( ! is_array( $entry ) || ! isset( $entry['status'] ) || 'spam' !== $entry['status'] ) {
			Formexposed_Stats::submission();
		}
	}

	// ------------------------------------------------------------------ changes

	/** After an update or switch: compare in a minute, when all of it is done. */
	public static function soon() {
		if ( ! Formexposed_Core::$stopping && ! wp_next_scheduled( self::CRON_CHANGES ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_CHANGES );
		}
	}

	/** Versions right now: active plugins, the theme, WordPress. */
	public static function snapshot() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array( 'c' => array( 'WordPress', (string) get_bloginfo( 'version' ) ) );
		$alle = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $datei ) {
			if ( isset( $alle[ $datei ] ) ) {
				$out[ 'p:' . $datei ] = array( (string) $alle[ $datei ]['Name'], (string) $alle[ $datei ]['Version'] );
			}
		}
		$t = wp_get_theme();
		$out[ 't:' . $t->get_stylesheet() ] = array( (string) $t->get( 'Name' ), (string) $t->get( 'Version' ) );
		return $out;
	}

	/** The differences to the last snapshot, as change events. */
	public static function diff( $alt, $neu ) {
		$out  = array();
		$zeit = gmdate( 'Y-m-d H:i:s' );
		foreach ( $neu as $k => $v ) {
			if ( ! isset( $alt[ $k ] ) ) {
				$out[] = array( 'at' => $zeit, 'kind' => 0 === strpos( $k, 't:' ) ? 'theme' : 'activate', 'name' => $v[0], 'from' => '', 'to' => $v[1] );
			} elseif ( $alt[ $k ][1] !== $v[1] ) {
				$out[] = array( 'at' => $zeit, 'kind' => 'c' === $k ? 'core' : ( 0 === strpos( $k, 't:' ) ? 'theme' : 'plugin' ), 'name' => $v[0], 'from' => $alt[ $k ][1], 'to' => $v[1] );
			}
		}
		foreach ( $alt as $k => $v ) {
			if ( ! isset( $neu[ $k ] ) && 0 === strpos( $k, 'p:' ) ) {
				$out[] = array( 'at' => $zeit, 'kind' => 'deactivate', 'name' => $v[0], 'from' => $v[1], 'to' => '' );
			}
		}
		return $out;
	}

	/**
	 * Cron: compare, queue, send (only while connected). Also run once a day.
	 * Since 1.4.0, after a plugin change the inventory goes along (POST
	 * /api/plugin/stats) so a vulnerable version is noticed the same day -
	 * $inventory is false in the daily run, which sends it anyway.
	 */
	public static function changes( $inventory = true ) {
		$alt = get_option( self::OPT_SNAPSHOT );
		$neu = self::snapshot();
		update_option( self::OPT_SNAPSHOT, $neu, false );
		if ( ! is_array( $alt ) ) {
			return;   // first run: nothing to compare with
		}
		$diff = self::diff( $alt, $neu );
		$q    = array_merge( (array) get_option( self::OPT_QUEUE, array() ), $diff );
		$q    = array_slice( $q, -50 );
		if ( ! $q ) {
			return;
		}
		if ( ! Formexposed_Core::linked() ) {
			delete_option( self::OPT_QUEUE );   // nobody to tell - keep nothing
			return;
		}
		$r = Formexposed_Core::api_changes( $q );
		if ( is_wp_error( $r ) ) {
			update_option( self::OPT_QUEUE, $q, false );   // next try: the next change or tomorrow
		} else {
			delete_option( self::OPT_QUEUE );
		}
		$plugins = array_filter( $diff, static function ( $c ) {
			return in_array( $c['kind'], array( 'plugin', 'activate', 'deactivate' ), true );
		} );
		if ( $inventory && $plugins ) {
			Formexposed_Core::api_stats( 'inventory' );
		}
	}
}
