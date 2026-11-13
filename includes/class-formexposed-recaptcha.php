<?php
/**
 * formexposed - move away from Google reCAPTCHA in one click (since 1.4.0).
 *
 * Why: Google counts reCAPTCHA checks against a free quota and, above it, lets
 * every request pass (the plugin already warns about that, 1.3.0); reCAPTCHA
 * loads Google's script and cookies, which in the EU needs the visitor's
 * consent before the form may use it; and the visitor's IP address and
 * browser data go to Google. The proof of work of this plugin
 * (Formexposed_Pow) runs on the site itself, invisible and without cookies.
 *
 * Where reCAPTCHA is found (places()):
 *   cf7           Contact Form 7 integration keys (option wpcf7['recaptcha'])
 *   wpforms       WPForms CAPTCHA provider "recaptcha" with keys (wpforms_settings)
 *   gravityforms  Gravity Forms forms with a reCAPTCHA field, or the reCAPTCHA add-on
 *   elementor     Elementor Pro forms with a reCAPTCHA field
 *
 * migrate() needs the protection layer on, runs the self-test of the proof of
 * work, switches it on, keeps a backup of the reCAPTCHA settings (option
 * formexposed_recaptcha_backup) and then switches Contact Form 7 (keys
 * removed) and WPForms (provider "none") away from reCAPTCHA. Forms of Gravity
 * Forms and Elementor are never edited by us - the settings page says what to
 * remove where. undo() puts the backup back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formexposed_Recaptcha {

	/** Options of other plugins this class reads (or, where said, changes). */
	const OPTION_GRAVITYFORMSADDON_GRAVITYFORMSRECAPTCHA_SETTINGS = 'gravityformsaddon_gravityformsrecaptcha_settings';
	const OPTION_WPCF7 = 'wpcf7';
	const OPTION_WPFORMS_SETTINGS = 'wpforms_settings';

	const OPT_BACKUP = 'formexposed_recaptcha_backup';

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_recaptcha_off', array( __CLASS__, 'action_migrate' ) );
			add_action( 'admin_post_formexposed_recaptcha_undo', array( __CLASS__, 'action_undo' ) );
		}
	}

	/** The places still using reCAPTCHA: cf7, wpforms, gravityforms, elementor. */
	public static function places() {
		return self::places_from( self::details() );
	}

	/** The same from a details() result already at hand. */
	public static function places_from( $d ) {
		$out = array();
		foreach ( array( 'cf7', 'wpforms', 'gravityforms', 'elementor' ) as $k ) {
			if ( ! empty( $d[ $k ] ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/** cf7/wpforms: bool; gravityforms/elementor: list of forms (id, title, edit). */
	public static function details() {
		$out = array( 'cf7' => (bool) self::cf7_keys(), 'wpforms' => self::wpforms_on(), 'gravityforms' => array(), 'elementor' => array() );
		try {
			$out['gravityforms'] = self::gf_forms();
		} catch ( Throwable $e ) {
			$out['gravityforms'] = array();
		}
		try {
			$out['elementor'] = self::elementor_forms();
		} catch ( Throwable $e ) {
			$out['elementor'] = array();
		}
		return $out;
	}

	/** Contact Form 7 keeps its keys as option wpcf7['recaptcha'] = array(sitekey => secret). */
	private static function cf7_keys() {
		if ( ! Formexposed_Guard::cf7() ) {
			return array();
		}
		$o = get_option( self::OPTION_WPCF7 );
		return is_array( $o ) && ! empty( $o['recaptcha'] ) && is_array( $o['recaptcha'] ) ? $o['recaptcha'] : array();
	}

	/** WPForms: provider recaptcha (its default when unset) with both keys. */
	private static function wpforms_on() {
		if ( ! defined( 'WPFORMS_VERSION' ) && ! function_exists( 'wpforms' ) ) {
			return false;
		}
		$s = get_option( self::OPTION_WPFORMS_SETTINGS );
		if ( ! is_array( $s ) ) {
			return false;
		}
		$provider = isset( $s['captcha-provider'] ) ? (string) $s['captcha-provider'] : 'recaptcha';
		return 'recaptcha' === $provider && ! empty( $s['recaptcha-site-key'] ) && ! empty( $s['recaptcha-secret-key'] );
	}

	private static function gf_forms() {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return array();
		}
		$out = array();
		$v3  = get_option( self::OPTION_GRAVITYFORMSADDON_GRAVITYFORMSRECAPTCHA_SETTINGS );
		if ( is_array( $v3 ) && ! empty( $v3['site_key_v3'] ) ) {
			$out[] = array( 'id' => 0, 'title' => __( 'reCAPTCHA add-on (v3, all forms)', 'formexposed' ), 'edit' => admin_url( 'admin.php?page=gf_settings&subview=gravityformsrecaptcha' ) );
		}
		foreach ( Formexposed_Health::gf_all_forms() as $form ) {
			foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $f ) {
				$typ = is_object( $f ) ? ( isset( $f->type ) ? $f->type : '' ) : ( is_array( $f ) && isset( $f['type'] ) ? $f['type'] : '' );
				$art = is_object( $f ) ? ( isset( $f->captchaType ) ? $f->captchaType : '' ) : ( is_array( $f ) && isset( $f['captchaType'] ) ? $f['captchaType'] : '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- Gravity Forms property
				// captchaType '' is reCAPTCHA; simple_captcha and math come from Really Simple CAPTCHA.
				if ( 'captcha' === $typ && in_array( (string) $art, array( '', 'recaptcha' ), true ) ) {
					$out[] = array( 'id' => (int) $form['id'], 'title' => isset( $form['title'] ) ? (string) $form['title'] : '', 'edit' => admin_url( 'admin.php?page=gf_edit_forms&id=' . (int) $form['id'] ) );
					break;
				}
			}
		}
		return $out;
	}

	private static function elementor_forms() {
		$out = array();
		foreach ( Formexposed_Health::elementor_widgets() as $w ) {
			foreach ( isset( $w['settings']['form_fields'] ) ? (array) $w['settings']['form_fields'] : array() as $f ) {
				if ( is_array( $f ) && isset( $f['field_type'] ) && in_array( $f['field_type'], array( 'recaptcha', 'recaptcha_v3' ), true ) ) {
					$name  = ! empty( $w['settings']['form_name'] ) ? (string) $w['settings']['form_name'] : 'Elementor';
					$out[] = array( 'id' => (int) $w['post_id'], 'title' => $name . ' (' . $w['post_title'] . ')', 'edit' => admin_url( 'post.php?post=' . (int) $w['post_id'] . '&action=elementor' ) );
					break;
				}
			}
		}
		return $out;
	}

	public static function backup() {
		$b = get_option( self::OPT_BACKUP );
		return is_array( $b ) ? $b : array();
	}

	/**
	 * The move. Returns array(switched => list, manual => details of GF/Elementor)
	 * or WP_Error - then nothing was changed.
	 */
	public static function migrate() {
		if ( ! Formexposed_Guard::on() ) {
			return new WP_Error( 'guard_off', __( 'Turn on the protection layer first - the proof of work that replaces reCAPTCHA is one of its checks.', 'formexposed' ) );
		}
		$test = Formexposed_Pow::self_test();
		if ( is_wp_error( $test ) ) {
			return $test;
		}
		$keys = self::cf7_keys();
		$wpf  = self::wpforms_on();
		// The backup first: whatever happens after it, Undo knows the old state.
		// A second move keeps what the first one saved for the parts it does not touch.
		$alt    = self::backup();
		$backup = array(
			'at'      => time(),
			'pow'     => array_key_exists( 'pow', $alt ) ? (bool) $alt['pow'] : Formexposed_Pow::enabled(),
			'cf7'     => $keys ? $keys : ( isset( $alt['cf7'] ) ? $alt['cf7'] : null ),
			'wpforms' => null,
		);
		if ( $wpf ) {
			$s                 = get_option( self::OPTION_WPFORMS_SETTINGS );
			$backup['wpforms'] = array( 'captcha-provider' => isset( $s['captcha-provider'] ) ? (string) $s['captcha-provider'] : null );
		} elseif ( isset( $alt['wpforms'] ) ) {
			$backup['wpforms'] = $alt['wpforms'];
		}
		update_option( self::OPT_BACKUP, $backup, false );
		$war = Formexposed_Pow::enabled();
		Formexposed_Pow::set( true );   // empties the page caches when it switches
		$done = array();
		if ( $keys ) {
			$o = get_option( self::OPTION_WPCF7 );
			unset( $o['recaptcha'] );
			update_option( self::OPTION_WPCF7, $o );
			$done[] = 'cf7';
		}
		if ( $wpf ) {
			$s                     = get_option( self::OPTION_WPFORMS_SETTINGS );
			$s['captcha-provider'] = 'none';
			update_option( self::OPTION_WPFORMS_SETTINGS, $s );
			$done[] = 'wpforms';
		}
		if ( $war && $done ) {
			Formexposed_Pow::purge_page_caches();   // the proof was on already, but the forms lost their reCAPTCHA
		}
		$d = self::details();
		return array( 'switched' => $done, 'manual' => array( 'gravityforms' => $d['gravityforms'], 'elementor' => $d['elementor'] ) );
	}

	/** Puts the saved reCAPTCHA settings and the former proof-of-work state back. */
	public static function undo() {
		$b = self::backup();
		if ( ! $b ) {
			return new WP_Error( 'no_backup', __( 'There is nothing to undo.', 'formexposed' ) );
		}
		if ( ! empty( $b['cf7'] ) && is_array( $b['cf7'] ) ) {
			$o              = get_option( self::OPTION_WPCF7 );
			$o              = is_array( $o ) ? $o : array();
			$o['recaptcha'] = $b['cf7'];
			update_option( self::OPTION_WPCF7, $o );
		}
		if ( isset( $b['wpforms'] ) && is_array( $b['wpforms'] ) ) {
			$s = get_option( self::OPTION_WPFORMS_SETTINGS );
			$s = is_array( $s ) ? $s : array();
			if ( null === $b['wpforms']['captcha-provider'] ) {
				unset( $s['captcha-provider'] );
			} else {
				$s['captcha-provider'] = (string) $b['wpforms']['captcha-provider'];
			}
			update_option( self::OPTION_WPFORMS_SETTINGS, $s );
		}
		$war = Formexposed_Pow::enabled();
		Formexposed_Pow::set( ! empty( $b['pow'] ) );
		if ( ! empty( $b['pow'] ) === $war ) {
			Formexposed_Pow::purge_page_caches();   // set() did not switch, but reCAPTCHA is back in the forms
		}
		delete_option( self::OPT_BACKUP );
		return true;
	}

	public static function action_migrate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_recaptcha_off' );
		$r = self::migrate();
		if ( is_wp_error( $r ) ) {
			set_transient( 'formexposed_msg', array( 'error', $r->get_error_message() ), 120 );
		} else {
			$teile = array();
			foreach ( $r['switched'] as $k ) {
				$teile[] = self::place_name( $k );
			}
			$text = $teile
				/* translators: %s: list of form plugins */
				? sprintf( __( 'Done: the invisible proof of work is on and %s no longer use reCAPTCHA. Undo is possible below.', 'formexposed' ), implode( ', ', $teile ) )
				: __( 'The invisible proof of work is on.', 'formexposed' );
			if ( $r['manual']['gravityforms'] || $r['manual']['elementor'] ) {
				$text .= ' ' . __( 'Remove the reCAPTCHA fields listed below from your Gravity Forms / Elementor forms yourself.', 'formexposed' );
			}
			$text .= ' ' . __( 'Page caches of the common caching plugins were emptied; if a CDN caches your pages, clear its cache too.', 'formexposed' );
			set_transient( 'formexposed_msg', array( 'ok', $text ), 120 );
		}
		wp_safe_redirect( Formexposed_Core::page_url() );
		exit;
	}

	public static function action_undo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_recaptcha_undo' );
		$r = self::undo();
		set_transient( 'formexposed_msg', is_wp_error( $r ) ? array( 'error', $r->get_error_message() ) : array( 'ok', __( 'Undone: the reCAPTCHA settings are back as they were.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url() );
		exit;
	}

	public static function place_name( $k ) {
		$n = array( 'cf7' => 'Contact Form 7', 'wpforms' => 'WPForms', 'gravityforms' => 'Gravity Forms', 'elementor' => 'Elementor Pro' );
		return isset( $n[ $k ] ) ? $n[ $k ] : $k;
	}
}
