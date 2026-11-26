<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formexposed - the protection layer ("Fix it for me", free since 1.5.1).
 *
 * Adds server-side checks to the forms of Contact Form 7, WPForms, Gravity
 * Forms and Elementor Pro, each through the form plugin's own hooks. Nothing
 * leaves this site: no keys from us, no third-party service, no script from
 * elsewhere. What it adds to every form:
 *
 *   formexposed_website  a honeypot field, off-screen; a filled one is spam
 *   formexposed_time     the time the page was rendered
 *   formexposed_token    HMAC of that time with this site's salt; altered = spam
 *   formexposed_js       empty in the HTML; set by a small inline script on the
 *                        first real interaction (focus, key, pointer). A bot
 *                        that posts without running the page stays empty.
 *
 * Contact Form 7 renders the fields through its own filters; for the other
 * plugins the inline script adds them to the form (also to forms that appear
 * later, e.g. in a popup). A bot that does not run the page lacks the token
 * and is stopped either way.
 *
 * And checks on submission:
 *   - honeypot empty, token valid, at least FILL_SECONDS since render
 *   - the interaction proof matches
 *   - no line break in single-line fields (header injection)
 *   - the e-mail domain can receive mail (MX or A record)
 *   - at most RATE_MAX submissions per address in RATE_WINDOW seconds
 *
 *   Contact Form 7   wpcf7_spam                      -> marked as spam, not sent
 *   WPForms          wpforms_process_initial_errors  -> error above the form
 *   Gravity Forms    gform_entry_is_spam             -> entry filed as spam, no notification
 *   Elementor Pro    elementor_pro/forms/validation  -> error below the form
 * Since 1.5.0:
 *   Fluent Forms     fluentform/validation_errors    -> error below the form
 *   Formidable       frm_validate_entry              -> error above the form
 *   Ninja Forms      ninja_forms_submit_data         -> error below the form (the fields travel as
 *                    "extra" data of the submission, set by guard.js; no proof of work there)
 *   Comments         pre_comment_approved            -> filed as spam (Comments -> Spam)
 *   Registration     registration_errors, woocommerce_register_post -> error, no account
 *   WooCommerce      woocommerce_after_checkout_validation (classic checkout, off by default)
 *   Login            authenticate on wp-login.php (off by default; only the page proof,
 *                    never the rate limit; FORMEXPOSED_NO_LOGIN_GUARD switches it off)
 * Comments, registrations and logins are counted, but never kept in the quarantine
 * (a login carries a password). Content and origin filters: Formexposed_Filters.
 *
 * Every stopped submission is counted by reason (Formexposed_Stats) and kept
 * for 14 days in the quarantine of this site (Formexposed_Quarantine), where a
 * person stopped by mistake can be delivered with one click.
 *
 * Swarm protection (since 1.3.0): links in stopped messages are counted by
 * domain; a domain seen in spam on several connected sites comes back in the
 * daily list (option formexposed_swarm) and a message linking to it is stopped
 * too (reason "swarm", into the quarantine like every other).
 *
 * Proof of work (since 1.4.0, optional, Formexposed_Pow): every form also
 * carries formexposed_pow; the inline script fetches a challenge on the first
 * interaction, solves it and holds a submit back until the answer is there.
 * Checked right after the form token (reason "pow").
 *
 * Switched on with one click in Settings -> formexposed - for every site, without
 * an account (since 1.5.1; before, only for Small and Business). It stays on until
 * the site owner turns it off.
 *
 * Page caches: the time is rendered into the HTML. On a cached page it is as
 * old as the cache, so the minimum fill time no longer holds there; the other
 * checks still do.
 */

class Formexposed_Guard {

	/** Options of other plugins this class reads (or, where said, changes). */
	const OPTION_WPCF7 = 'wpcf7';

	const OPT          = 'formexposed_guard';   // array('on' => bool, 'since' => int)
	const FILL_SECONDS = 3;
	const RATE_MAX     = 5;
	const RATE_WINDOW  = 600;

	/** Reason texts (spam log, self-test) by the key the daily counts use. */
	const REASONS = array(
		'honeypot'       => 'honeypot filled',
		'token'          => 'form token missing or altered',
		'too_fast'       => 'submitted too fast',
		'no_interaction' => 'no interaction with the page',
		'line_break'     => 'line break in a single-line field',
		'mail_domain'    => 'e-mail domain cannot receive mail',
		'rate_limit'     => 'too many submissions',
		'swarm'          => 'link to a known spam domain',
		'pow'            => 'proof of work missing or invalid',
		'disposable'     => 'throw-away e-mail address',
		'script'         => 'message in a blocked writing system',
		'keyword'        => 'blocked word',
		'country'        => 'country not allowed',
	);

	/** Option formexposed_guard_extra: which additional places the layer protects (since 1.5.0). */
	const OPT_EXTRA = 'formexposed_guard_extra';
	const EXTRA_DEFAULTS = array( 'comments' => true, 'register' => true, 'woo_register' => true, 'woo_checkout' => false, 'login' => false );

	/** Forms the inline script equips (Contact Form 7 renders its fields itself). Since 1.5.0 more, see selectors(). */
	const SELECTORS = 'form.wpforms-form,form[id^="gform_"],form.elementor-form,form.frm-fluent-form,form.frm-show-form';

	/** The additional places switched on (since 1.5.0). */
	public static function extra() {
		$o = get_option( self::OPT_EXTRA );
		$o = is_array( $o ) ? $o : array();
		$e = array();
		foreach ( self::EXTRA_DEFAULTS as $k => $v ) {
			$e[ $k ] = isset( $o[ $k ] ) ? (bool) $o[ $k ] : $v;
		}
		if ( defined( 'FORMEXPOSED_NO_LOGIN_GUARD' ) && FORMEXPOSED_NO_LOGIN_GUARD ) {
			$e['login'] = false;
		}
		return $e;
	}

	/** All forms the script equips on this site. */
	public static function selectors() {
		$e   = self::extra();
		$sel = array( self::SELECTORS );
		if ( $e['comments'] ) {
			$sel[] = '#commentform,form.comment-form';
		}
		if ( $e['woo_register'] ) {
			$sel[] = 'form.woocommerce-form-register';
		}
		if ( $e['woo_checkout'] ) {
			$sel[] = 'form.checkout.woocommerce-checkout';
		}
		if ( $e['register'] ) {
			$sel[] = '#registerform';
		}
		if ( $e['login'] ) {
			$sel[] = '#loginform';
		}
		return implode( ',', $sel );
	}

	/** This request passed every check of the layer, including a verified proof of work (since 1.9.0). */
	private static $strict_pass = false;
	private static $pow_ok      = false;

	/**
	 * Our own fields of the current POST request (the form plugins verify their own requests), sanitized as they are read.
	 * The trap field keeps every printable character (special ones as HTML entities), so a filled trap never turns
	 * into an empty one.
	 */
	public static function request() {
		$text = array( 'filter' => FILTER_CALLBACK, 'options' => 'sanitize_text_field' );
		$p    = filter_input_array( INPUT_POST, array(
			'formexposed_website' => array( 'filter' => FILTER_SANITIZE_SPECIAL_CHARS, 'flags' => FILTER_FLAG_STRIP_LOW ),
			'formexposed_time'    => $text,
			'formexposed_token'   => $text,
			'formexposed_js'      => $text,
			'formexposed_pow'     => $text,
		) );
		return is_array( $p ) ? array_filter( $p, 'is_string' ) : array();
	}

	/**
	 * One field of the current POST request, sanitized as it is read: "data" (the URL-encoded form of Fluent Forms)
	 * with FILTER_SANITIZE_URL, so wp_parse_str() still reads it, every other field with sanitize_text_field().
	 */
	public static function posted( $name ) {
		$v = 'data' === $name
			? filter_input( INPUT_POST, $name, FILTER_SANITIZE_URL )
			: filter_input( INPUT_POST, $name, FILTER_CALLBACK, array( 'options' => 'sanitize_text_field' ) );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * The independent check the hosted form protection may fall back on (since 1.9.0): this request
	 * passed the layer, and the proof of work was switched on and verified - not skipped.
	 */
	public static function passed_strict() {
		return self::$strict_pass;
	}

	/** Raw WPForms entry of the current submission (wpforms_process_before). */
	private static $wpforms_entry = array();

	public static function wpforms_entry( $entry ) {
		self::$wpforms_entry = is_array( $entry ) ? $entry : array();
	}

	/** One render time per page view: hidden fields and proof must belong together. */
	private static $ts = null;
	private static function now_ts() {
		if ( null === self::$ts ) {
			self::$ts = (string) time();
		}
		return self::$ts;
	}

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_guard_extra', array( __CLASS__, 'action_extra' ) );
			add_action( 'admin_post_formexposed_filters', array( 'Formexposed_Filters', 'action_save' ) );
		}
		if ( ! self::on() ) {
			return;
		}
		add_filter( 'wpcf7_form_hidden_fields', array( __CLASS__, 'hidden_fields' ) );
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'form_elements' ) );
		add_filter( 'wpcf7_posted_data', array( __CLASS__, 'cf7_posted_data' ) );
		add_filter( 'wpcf7_spam', array( __CLASS__, 'spam' ), 20, 2 );
		add_action( 'wpforms_process_before', array( __CLASS__, 'wpforms_entry' ), 10, 1 );
		add_filter( 'wpforms_process_initial_errors', array( __CLASS__, 'wpforms_errors' ), 20, 2 );
		add_filter( 'gform_entry_is_spam', array( __CLASS__, 'gform_spam' ), 20, 3 );
		add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'elementor_validation' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// since 1.5.0
		add_filter( 'fluentform/validation_errors', array( __CLASS__, 'fluent_errors' ), 20, 4 );
		add_filter( 'frm_validate_entry', array( __CLASS__, 'formidable_errors' ), 20, 2 );
		add_filter( 'ninja_forms_submit_data', array( __CLASS__, 'ninja_submit' ), 20, 1 );
		$e = self::extra();
		if ( $e['comments'] ) {
			add_filter( 'pre_comment_approved', array( __CLASS__, 'comment_approved' ), 20, 2 );
		}
		if ( $e['register'] ) {
			add_filter( 'registration_errors', array( __CLASS__, 'registration_errors' ), 20, 3 );
		}
		if ( $e['woo_register'] ) {
			add_action( 'woocommerce_register_post', array( __CLASS__, 'woo_register' ), 20, 3 );
		}
		if ( $e['woo_checkout'] ) {
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'woo_checkout' ), 20, 2 );
		}
		if ( $e['login'] ) {
			add_filter( 'authenticate', array( __CLASS__, 'login' ), 30, 3 );
		}
		if ( $e['login'] || $e['register'] ) {
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		}
	}

	public static function on() {
		$o = get_option( self::OPT );
		return is_array( $o ) && ! empty( $o['on'] );
	}

	public static function set( $on ) {
		update_option( self::OPT, array( 'on' => (bool) $on, 'since' => time() ), false );
	}

	/** Is Contact Form 7 active on this site? */
	public static function cf7() {
		return class_exists( 'WPCF7' ) || defined( 'WPCF7_VERSION' );
	}

	/** The supported form plugins that are active here. */
	public static function plugins() {
		$p = array();
		if ( self::cf7() ) {
			$p[] = 'Contact Form 7';
		}
		if ( defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' ) ) {
			$p[] = 'WPForms';
		}
		if ( class_exists( 'GFForms' ) ) {
			$p[] = 'Gravity Forms';
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$p[] = 'Elementor Pro';
		}
		if ( defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' ) ) {
			$p[] = 'Fluent Forms';
		}
		if ( class_exists( 'FrmAppHelper' ) ) {
			$p[] = 'Formidable Forms';
		}
		if ( class_exists( 'Ninja_Forms' ) ) {
			$p[] = 'Ninja Forms';
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$p[] = 'WooCommerce';
		}
		return $p;
	}

	private static function sign( $what ) {
		return substr( hash_hmac( 'sha256', 'formexposed|' . $what, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** Expected value of the interaction proof for a render time. */
	private static function js_value( $ts ) {
		return substr( self::sign( 'js|' . $ts ), 0, 16 );
	}

	/** Reason text -> the key of the daily counts. */
	public static function reason_key( $text ) {
		$k = array_search( $text, self::REASONS, true );
		return false === $k ? 'other' : $k;
	}

	// ------------------------------------------------------------------ render

	public static function hidden_fields( $fields ) {
		$ts                           = self::now_ts();
		$fields['formexposed_time']   = $ts;
		$fields['formexposed_token']  = self::sign( 'time|' . $ts );
		return $fields;
	}

	public static function form_elements( $html ) {
		$ts = self::now_ts();
		// The proof value travels in a data attribute and is copied by the
		// script on the first interaction. It is not a secret - it only proves
		// that something ran this page's script after a person touched the form.
		$html .= '<span class="formexposed-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important">'
			. '<label>' . esc_html__( 'Leave this field empty', 'formexposed' ) . '<input type="text" name="formexposed_website" value="" tabindex="-1" autocomplete="off"></label>'
			. '</span>'
			. '<input type="hidden" name="formexposed_js" value="" data-fe="' . esc_attr( self::js_value( $ts ) ) . '">';
		if ( Formexposed_Pow::on() ) {
			$html .= '<input type="hidden" name="formexposed_pow" value="">';   // filled by the script once the answer is found
		}
		return $html;
	}

	/**
	 * assets/js/guard.js, in the footer of every front-end page while the layer is
	 * on: equips the forms of WPForms, Gravity Forms and Elementor with the fields
	 * (Contact Form 7 has them already) and copies the proof into every form on
	 * its first real interaction. Watches the page for forms that come later.
	 * The render time and its signature travel in window.formexposedGuard.
	 *
	 * With the proof of work on (since 1.4.0): on an interaction the script
	 * fetches a challenge and solves it in steps of c.pow.chunk hashes; a
	 * submit event that comes before the answer is stopped in the capture
	 * phase (before the AJAX handlers of CF7, WPForms, Elementor see it) and
	 * sent again with requestSubmit() once the answer is in the field. Every
	 * answer goes with one submission only; the next one is prepared on the
	 * next interaction.
	 */
	public static function enqueue() {
		wp_enqueue_script( 'formexposed-guard', plugins_url( 'assets/js/guard.js', FORMEXPOSED_FILE ), array(), FORMEXPOSED_VERSION, true );
		wp_add_inline_script( 'formexposed-guard', 'window.formexposedGuard = ' . wp_json_encode( self::script_config() ) . ';', 'before' );
	}

	/** The settings of assets/js/guard.js for this page view. */
	public static function script_config() {
		$ts = self::now_ts();
		return array(
			'sel'   => self::selectors(),
			'nf'    => class_exists( 'Ninja_Forms' ),
			'nopow' => '#loginform,#registerform,form.checkout,form.woocommerce-form-register',
			'time'  => $ts,
			'token' => self::sign( 'time|' . $ts ),
			'fe'    => self::js_value( $ts ),
			'label' => __( 'Leave this field empty', 'formexposed' ),
			'pow'   => Formexposed_Pow::on() ? Formexposed_Pow::script_config() : false,
		);
	}

	// ------------------------------------------------------------------ check

	/**
	 * The checks every form plugin shares. $p: the submitted data (our fields
	 * travel as formexposed_*), $fields: list of array(value, kind), kind
	 * 'line' (name, e-mail, subject, phone ...) or 'email'. Returns the reason
	 * text, or null when the submission passes.
	 */
	public static function check( $p, $fields, $pairs = array(), $pow = true ) {
		$grund = self::check_proof( $p );
		if ( null === $grund && $pow ) {
			$grund = self::check_pow( $p );
		}
		if ( null === $grund ) {
			$grund = self::check_fields( $fields );
		}
		if ( null === $grund ) {
			$grund = self::check_filters( $fields, $pairs );
		}
		if ( null === $grund ) {
			$grund = self::check_swarm( $pairs );
		}
		if ( null === $grund ) {
			$grund = self::check_rate();
		}
		if ( null === $grund ) {
			Formexposed_Pow::consume();   // passed: this answer is spent
			self::$strict_pass = $pow && self::$pow_ok;
		}
		return $grund;
	}

	/** Content and origin filters (since 1.5.0, Formexposed_Filters). */
	private static function check_filters( $fields, $pairs ) {
		$k = Formexposed_Filters::check( $fields, $pairs );
		return null === $k ? null : self::REASONS[ $k ];
	}

	/** The proof of work (since 1.4.0), when it is on. */
	private static function check_pow( $p ) {
		if ( ! Formexposed_Pow::on() ) {
			return null;
		}
		if ( ! isset( $p['formexposed_pow'] ) && Formexposed_Pow::in_grace() ) {
			return null;   // no field at all: a page a cache still serves from before the switch (first day only)
		}
		$v = isset( $p['formexposed_pow'] ) && is_string( $p['formexposed_pow'] ) ? (string) $p['formexposed_pow'] : '';
		if ( true === Formexposed_Pow::verify( $v ) ) {
			self::$pow_ok = true;
			return null;
		}
		return self::REASONS['pow'];
	}

	/** Domains of the links in a message (lower case, without www.). */
	public static function links_in( $pairs ) {
		$hosts = array();
		foreach ( (array) $pairs as $p ) {
			if ( preg_match_all( '~(?:https?://|www\.)([a-z0-9][a-z0-9.-]*\.[a-z]{2,24})~i', (string) $p[1], $m ) ) {
				foreach ( $m[1] as $h ) {
					$hosts[] = preg_replace( '/^www\./', '', strtolower( rtrim( $h, '.' ) ) );
				}
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/** A link to a domain on the swarm list, or (only if the service sends nets) a sender from a listed net. */
	private static function check_swarm( $pairs ) {
		$l = get_option( 'formexposed_swarm' );
		if ( ! is_array( $l ) || get_option( 'formexposed_swarm_off' ) ) {
			return null;
		}
		$liste = isset( $l['links'] ) && is_array( $l['links'] ) ? array_flip( $l['links'] ) : array();
		foreach ( self::links_in( $pairs ) as $h ) {
			for ( $x = $h; false !== $x && '' !== $x; $x = strpos( $x, '.' ) !== false ? substr( $x, strpos( $x, '.' ) + 1 ) : false ) {
				if ( isset( $liste[ $x ] ) && false !== strpos( $x, '.' ) ) {
					return self::REASONS['swarm'];
				}
			}
		}
		$ip = self::client_ip();
		if ( '' !== $ip && ! empty( $l['nets'] ) && is_array( $l['nets'] ) ) {
			$netz = self::net_of( $ip );
			if ( '' !== $netz && in_array( $netz, $l['nets'], true ) ) {
				return self::REASONS['swarm'];
			}
		}
		return null;
	}

	/**
	 * The visitor's address (rate limit, swarm nets). Behind a reverse proxy or a
	 * CDN every visitor would share the proxy's address - there the filter
	 * formexposed_client_ip can return the real one (only a valid IP counts).
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'formexposed_client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** 203.0.113.7 -> 203.0.113.0/24, 2001:db8:1:2::5 -> 2001:db8:1::/48. */
	public static function net_of( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return implode( '.', array_slice( explode( '.', $ip ), 0, 3 ) ) . '.0/24';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			return false === $bin ? '' : inet_ntop( substr( $bin, 0, 6 ) . str_repeat( chr( 0 ), 10 ) ) . '/48';
		}
		return '';
	}

	/** What happens to every stopped submission: counted, links noted, kept in the quarantine. */
	private static function stopped( $source, $form, $grund, $pairs ) {
		$key = self::reason_key( $grund );
		Formexposed_Stats::blocked( $key );
		Formexposed_Stats::links( self::links_in( $pairs ) );
		Formexposed_Quarantine::store( $source, $form, $key, $pairs );
	}

	private static function check_proof( $p ) {
		$hp = isset( $p['formexposed_website'] ) && is_string( $p['formexposed_website'] ) ? (string) wp_unslash( $p['formexposed_website'] ) : '';
		if ( '' !== trim( $hp ) ) {
			return self::REASONS['honeypot'];
		}
		$ts  = isset( $p['formexposed_time'] ) && is_string( $p['formexposed_time'] ) ? (string) wp_unslash( $p['formexposed_time'] ) : '';
		$tok = isset( $p['formexposed_token'] ) && is_string( $p['formexposed_token'] ) ? (string) wp_unslash( $p['formexposed_token'] ) : '';
		if ( ! preg_match( '/^\d{9,11}$/', $ts ) || ! hash_equals( self::sign( 'time|' . $ts ), $tok ) ) {
			return self::REASONS['token'];
		}
		if ( time() - (int) $ts < self::FILL_SECONDS ) {
			return self::REASONS['too_fast'];
		}
		$js = isset( $p['formexposed_js'] ) && is_string( $p['formexposed_js'] ) ? (string) wp_unslash( $p['formexposed_js'] ) : '';
		if ( ! hash_equals( self::js_value( $ts ), $js ) ) {
			return self::REASONS['no_interaction'];
		}
		return null;
	}

	private static function check_fields( $fields ) {
		foreach ( $fields as $f ) {
			$wert = (string) wp_unslash( $f[0] );
			if ( preg_match( '/[\r\n]/', $wert ) ) {
				return self::REASONS['line_break'];
			}
			if ( 'email' === $f[1] && ! self::mail_domain_ok( $wert ) ) {
				return self::REASONS['mail_domain'];
			}
		}
		return null;
	}

	private static function mail_domain_ok( $adr ) {
		$adr = trim( $adr );
		$at  = strrpos( $adr, '@' );
		if ( false === $at || ! function_exists( 'checkdnsrr' ) ) {
			return true;   // no address to judge; the form plugin validates the format
		}
		$dom = substr( $adr, $at + 1 );
		return ! ( '' === $dom || preg_match( '/\.(invalid|test|example|localhost)$/i', $dom ) || ( ! checkdnsrr( $dom . '.', 'MX' ) && ! checkdnsrr( $dom . '.', 'A' ) ) );
	}

	/**
	 * Rate limit per address. Counted only for submissions that passed the
	 * checks above: the checks are cheap, and a bot that fails them anyway
	 * should not use up a real visitor's allowance behind the same NAT.
	 */
	private static function check_rate() {
		$ip  = self::client_ip();
		$key = 'formexposed_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_MAX ) {
			return self::REASONS['rate_limit'];
		}
		set_transient( $key, $n + 1, self::RATE_WINDOW );
		return null;
	}

	/** Field type of a form plugin -> 'line', 'email' or null (not checked). */
	private static function kind( $type ) {
		if ( 'email' === $type ) {
			return 'email';
		}
		return in_array( $type, array( 'text', 'name', 'phone', 'tel', 'url', 'website', 'number' ), true ) ? 'line' : null;
	}

	/** What a person sees when WPForms or Elementor refuses. Bots see the same. */
	private static function message( $grund ) {
		switch ( self::reason_key( $grund ) ) {
			case 'mail_domain':
				return __( 'Please check your e-mail address - its domain cannot receive mail.', 'formexposed' );
			case 'rate_limit':
				return __( 'Too many messages from your connection. Please wait a few minutes and try again.', 'formexposed' );
			case 'disposable':
				return __( 'Please use a permanent e-mail address - we cannot reply to a throw-away address.', 'formexposed' );
			default:
				return __( 'Your message could not be sent. Please reload the page and try again.', 'formexposed' );
		}
	}

	// ------------------------------------------------------------------ Contact Form 7

	/**
	 * CF7 asks: is this submission spam? We only ever say yes - a submission
	 * another check already flagged stays flagged.
	 */
	public static function spam( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam;
		}
		$grund = self::reason( $submission );
		if ( null === $grund ) {
			return false;
		}
		$titel = '';
		if ( $submission && method_exists( $submission, 'get_contact_form' ) && $submission->get_contact_form() && method_exists( $submission->get_contact_form(), 'title' ) ) {
			$titel = (string) $submission->get_contact_form()->title();
		}
		self::stopped( 'cf7', '' !== $titel ? $titel : 'Contact Form 7', $grund, self::cf7_pairs( self::cf7_data( $submission ) ) );
		if ( $submission && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( array( 'agent' => 'formexposed', 'reason' => $grund ) );
		}
		return true;
	}

	/** Why is this CF7 submission spam? null = it is not. Public for the self-test. */
	public static function reason( $submission = null, $post = null ) {
		$p    = is_array( $post ) ? $post : self::request();
		$data = is_array( $post ) ? $post : self::cf7_data( $submission );

		$grund = self::check_proof( $p );
		if ( null === $grund ) {
			$grund = self::check_pow( $p );
		}
		if ( null !== $grund ) {
			return $grund;
		}
		// Field types from the form tags; without them, guessed from the name.
		$typ = array();
		if ( $submission && method_exists( $submission, 'get_contact_form' ) ) {
			$form = $submission->get_contact_form();
			if ( $form && method_exists( $form, 'scan_form_tags' ) ) {
				foreach ( $form->scan_form_tags() as $tag ) {
					if ( in_array( $tag->basetype, array( 'text', 'email', 'tel', 'url', 'number' ), true ) && '' !== $tag->name ) {
						$typ[ $tag->name ] = $tag->basetype;
					}
				}
			}
		}
		$fields = array();
		foreach ( $data as $name => $wert ) {
			if ( ! is_string( $wert ) || 0 === strpos( (string) $name, '_wpcf7' ) || 0 === strpos( (string) $name, 'formexposed_' ) ) {
				continue;
			}
			$ist_mail  = ( isset( $typ[ $name ] ) && 'email' === $typ[ $name ] ) || ( ! $typ && preg_match( '/mail/i', (string) $name ) );
			$einzeilig = isset( $typ[ $name ] ) || ( ! $typ && preg_match( '/name|mail|subject|betreff|temat|phone|tel/i', (string) $name ) );
			if ( $ist_mail ) {
				$fields[] = array( $wert, 'email' );
			} elseif ( $einzeilig ) {
				$fields[] = array( $wert, 'line' );
			}
		}
		$grund = self::check_fields( $fields );
		if ( null === $grund ) {
			$grund = self::check_filters( $fields, self::cf7_pairs( $data ) );
		}
		if ( null === $grund ) {
			$grund = self::check_swarm( self::cf7_pairs( $data ) );
		}
		if ( null === $grund ) {
			$grund = self::check_rate();
		}
		if ( null === $grund ) {
			Formexposed_Pow::consume();   // passed: this answer is spent
			self::$strict_pass = self::$pow_ok;
		}
		return $grund;
	}

	/** The fields of a CF7 submission (without our own; those are read from the request). */
	private static function cf7_data( $submission ) {
		return $submission && method_exists( $submission, 'get_posted_data' ) ? (array) $submission->get_posted_data() : array();
	}

	/**
	 * CF7 takes every posted field into its data (Flamingo stores it, mail tags
	 * can print it). Our hidden fields - since 1.4.0 also the long proof-of-work
	 * answer - are no part of the enquiry. The checks above read $_POST.
	 */
	public static function cf7_posted_data( $data ) {
		if ( is_array( $data ) ) {
			foreach ( array_keys( $data ) as $k ) {
				if ( 0 === strpos( (string) $k, 'formexposed_' ) ) {
					unset( $data[ $k ] );
				}
			}
		}
		return $data;
	}

	/** The fields a person filled in (CF7 posts them under their own names). */
	private static function cf7_pairs( $p ) {
		$out = array();
		foreach ( (array) $p as $name => $wert ) {
			if ( ! is_string( $wert ) || 0 === strpos( (string) $name, '_wpcf7' ) || 0 === strpos( (string) $name, 'formexposed_' ) || in_array( $name, array( 'g-recaptcha-response', '_wpnonce' ), true ) ) {
				continue;
			}
			$out[] = array( (string) $name, (string) wp_unslash( $wert ) );
		}
		return $out;
	}

	// ------------------------------------------------------------------ WPForms

	/** WPForms: after its own field validation, before anything is saved or sent. */
	public static function wpforms_errors( $errors, $form_data ) {
		$id = isset( $form_data['id'] ) ? $form_data['id'] : 0;
		if ( ! empty( $errors[ $id ] ) ) {
			return $errors;   // already refused for another reason
		}
		$fields = array();
		$pairs  = array();
		$posted = isset( self::$wpforms_entry['fields'] ) && is_array( self::$wpforms_entry['fields'] ) ? self::$wpforms_entry['fields'] : array();
		foreach ( $posted as $fid => $wert ) {
			$label = isset( $form_data['fields'][ $fid ]['label'] ) ? (string) $form_data['fields'][ $fid ]['label'] : 'Field ' . $fid;
			$art   = self::kind( isset( $form_data['fields'][ $fid ]['type'] ) ? (string) $form_data['fields'][ $fid ]['type'] : '' );
			$teile = array();
			foreach ( (array) $wert as $w ) {
				if ( is_string( $w ) ) {
					$teile[] = (string) wp_unslash( $w );
					if ( null !== $art ) {
						$fields[] = array( $w, $art );
					}
				}
			}
			if ( $teile ) {
				$pairs[] = array( $label, implode( ' ', $teile ) );
			}
		}
		$grund = self::check( self::request(), $fields, $pairs );
		if ( null !== $grund ) {
			self::stopped( 'wpforms', isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : 'WPForms', $grund, $pairs );
			$errors[ $id ]['header'] = esc_html( self::message( $grund ) );
		}
		return $errors;
	}

	// ------------------------------------------------------------------ Gravity Forms

	/** Gravity Forms: a spam entry is kept under Entries -> Spam and no notification goes out. */
	public static function gform_spam( $is_spam, $form = array(), $entry = array() ) {
		if ( $is_spam ) {
			return $is_spam;
		}
		$fields = array();
		$pairs  = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $f ) {
			if ( ! is_object( $f ) ) {
				continue;
			}
			$art   = self::kind( isset( $f->type ) ? (string) $f->type : '' );
			$ids   = ! empty( $f->inputs ) && is_array( $f->inputs ) ? wp_list_pluck( $f->inputs, 'id' ) : array( isset( $f->id ) ? $f->id : '' );
			$teile = array();
			foreach ( $ids as $iid ) {
				if ( isset( $entry[ (string) $iid ] ) && is_string( $entry[ (string) $iid ] ) && '' !== $entry[ (string) $iid ] ) {
					$teile[] = $entry[ (string) $iid ];
					if ( null !== $art ) {
						$fields[] = array( $entry[ (string) $iid ], $art );
					}
				}
			}
			if ( $teile ) {
				$pairs[] = array( isset( $f->label ) && '' !== (string) $f->label ? (string) $f->label : 'Field ' . ( isset( $f->id ) ? $f->id : '' ), implode( ' ', $teile ) );
			}
		}
		$grund = self::check( self::request(), $fields, $pairs );
		if ( null === $grund ) {
			return false;
		}
		self::stopped( 'gravityforms', isset( $form['title'] ) ? (string) $form['title'] : 'Gravity Forms', $grund, $pairs );
		if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			GFCommon::set_spam_filter( isset( $form['id'] ) ? $form['id'] : 0, 'formexposed', $grund );
		}
		return true;
	}

	// ------------------------------------------------------------------ Elementor Pro

	/** Elementor Pro forms: an error stops sending and shows the message below the form. */
	public static function elementor_validation( $record, $ajax_handler ) {
		$fields = array();
		$pairs  = array();
		$liste  = is_object( $record ) && method_exists( $record, 'get' ) ? (array) $record->get( 'fields' ) : array();
		foreach ( $liste as $id => $f ) {
			if ( ! isset( $f['value'] ) || ! is_string( $f['value'] ) ) {
				continue;
			}
			$art = self::kind( isset( $f['type'] ) ? (string) $f['type'] : '' );
			if ( null !== $art ) {
				$fields[] = array( $f['value'], $art );
			}
			if ( '' !== $f['value'] ) {
				$pairs[] = array( isset( $f['title'] ) && '' !== (string) $f['title'] ? (string) $f['title'] : (string) $id, $f['value'] );
			}
		}
		$grund = self::check( self::request(), $fields, $pairs );
		if ( null === $grund ) {
			return;
		}
		$name = is_object( $record ) && method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		self::stopped( 'elementor', '' !== $name ? $name : 'Elementor', $grund, $pairs );
		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error' ) ) {
			$ajax_handler->add_error( 'formexposed', self::message( $grund ) );
			if ( method_exists( $ajax_handler, 'add_error_message' ) ) {
				$ajax_handler->add_error_message( self::message( $grund ) );
			}
		}
	}

	// ------------------------------------------------------------------ Fluent Forms (since 1.5.0)

	/**
	 * Fluent Forms posts the whole form as one URL-encoded string ("data"); $form_data is
	 * that string parsed, so our fields are in it, not in $_POST. Field types from the form.
	 */
	public static function fluent_errors( $errors, $form_data, $form = null, $fields = array() ) {
		if ( ! empty( $errors ) || ! is_array( $form_data ) ) {
			return $errors;
		}
		$liste = array();
		$pairs = array();
		$typen = array();
		foreach ( (array) $fields as $name => $f ) {
			$typ = is_array( $f ) && isset( $f['element'] ) ? (string) $f['element'] : '';
			$typen[ (string) $name ] = 'input_email' === $typ ? 'email' : ( in_array( $typ, array( 'input_text', 'input_name', 'phone', 'input_url', 'input_number' ), true ) ? 'line' : null );
		}
		foreach ( $form_data as $name => $wert ) {
			if ( 0 === strpos( (string) $name, 'formexposed_' ) || 0 === strpos( (string) $name, '_' ) ) {
				continue;
			}
			foreach ( (array) $wert as $w ) {
				if ( ! is_string( $w ) || '' === $w ) {
					continue;
				}
				$art = isset( $typen[ $name ] ) ? $typen[ $name ] : ( preg_match( '/mail/i', (string) $name ) ? 'email' : null );
				if ( null !== $art ) {
					$liste[] = array( $w, $art );
				}
				$pairs[] = array( (string) $name, $w );
			}
		}
		// Fluent Forms keeps only the fields it knows in $form_data (1.9.0, measured with Fluent Forms 6.2): our fields
		// travel in the raw "data" string of the request, so they are read from there.
		$raw = array();
		wp_parse_str( self::posted( 'data' ), $raw );
		$own = array();
		foreach ( array( 'formexposed_website', 'formexposed_time', 'formexposed_token', 'formexposed_js', 'formexposed_pow' ) as $k ) {
			if ( isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ) {
				$own[ $k ] = $raw[ $k ];
			} elseif ( isset( $form_data[ $k ] ) && is_string( $form_data[ $k ] ) ) {
				$own[ $k ] = $form_data[ $k ];
			}
		}
		$grund = self::check( $own, $liste, $pairs );
		if ( null !== $grund ) {
			self::stopped( 'fluentform', is_object( $form ) && isset( $form->title ) ? (string) $form->title : 'Fluent Forms', $grund, $pairs );
			$errors['formexposed'] = array( self::message( $grund ) );
		}
		return $errors;
	}

	// ------------------------------------------------------------------ Formidable (since 1.5.0)

	/** Formidable: field values under item_meta[<field id>]; our fields arrive as plain $_POST keys. */
	public static function formidable_errors( $errors, $values ) {
		if ( ! empty( $errors ) || ! is_array( $values ) ) {
			return $errors;
		}
		$liste = array();
		$pairs = array();
		foreach ( isset( $values['item_meta'] ) && is_array( $values['item_meta'] ) ? $values['item_meta'] : array() as $fid => $wert ) {
			if ( ! is_numeric( $fid ) ) {
				continue;
			}
			$typ = class_exists( 'FrmField' ) && method_exists( 'FrmField', 'get_type' ) ? (string) FrmField::get_type( (int) $fid ) : '';
			foreach ( (array) $wert as $w ) {
				if ( ! is_string( $w ) || '' === $w ) {
					continue;
				}
				$art = 'email' === $typ ? 'email' : ( in_array( $typ, array( 'text', 'name', 'phone', 'url', 'number' ), true ) ? 'line' : null );
				if ( null !== $art ) {
					$liste[] = array( $w, $art );
				}
				$pairs[] = array( 'Field ' . $fid, $w );
			}
		}
		$grund = self::check( $values, $liste, $pairs );
		if ( null !== $grund ) {
			self::stopped( 'formidable', 'Formidable', $grund, $pairs );
			$errors['formexposed'] = self::message( $grund );
		}
		return $errors;
	}

	// ------------------------------------------------------------------ Ninja Forms (since 1.5.0)

	/**
	 * Ninja Forms submits its own field models, not the HTML form - guard.js adds our
	 * values as "extra" data (add:extra). No proof of work: Ninja Forms cannot hold a submit.
	 */
	public static function ninja_submit( $form_data ) {
		if ( ! is_array( $form_data ) || ! empty( $form_data['errors'] ) ) {
			return $form_data;
		}
		$p     = isset( $form_data['extra'] ) && is_array( $form_data['extra'] ) ? $form_data['extra'] : array();
		$liste = array();
		$pairs = array();
		foreach ( isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array() as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['value'] ) || ! is_string( $f['value'] ) || '' === $f['value'] ) {
				continue;
			}
			$typ = isset( $f['type'] ) ? (string) $f['type'] : '';
			$art = 'email' === $typ ? 'email' : ( in_array( $typ, array( 'textbox', 'firstname', 'lastname', 'phone', 'address', 'city' ), true ) ? 'line' : null );
			if ( null !== $art ) {
				$liste[] = array( $f['value'], $art );
			}
			$pairs[] = array( isset( $f['label'] ) ? (string) $f['label'] : ( isset( $f['key'] ) ? (string) $f['key'] : 'Field' ), $f['value'] );
		}
		$grund = self::check( $p, $liste, $pairs, false );
		if ( null !== $grund ) {
			self::stopped( 'ninjaforms', isset( $form_data['settings']['title'] ) ? (string) $form_data['settings']['title'] : 'Ninja Forms', $grund, $pairs );
			// Ninja Forms takes errors from ['errors']['fields'][<field id>] after this filter (Submission::process); a form-level
			// entry there is ignored. The message goes to the first field - the submission stops and the visitor sees it.
			$erstes = null;
			foreach ( ( isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array() ) as $fid => $f ) {
				$erstes = is_array( $f ) && isset( $f['id'] ) ? $f['id'] : $fid;
				break;
			}
			if ( null !== $erstes ) {
				$form_data['errors']['fields'][ $erstes ] = self::message( $grund );
			}
			$form_data['errors']['form']['formexposed'] = self::message( $grund );
		}
		return $form_data;
	}

	// ------------------------------------------------------------------ Comments (since 1.5.0)

	/**
	 * A comment that fails goes to Comments -> Spam (WordPress keeps it there); counted, not quarantined.
	 * Only the core comment form (wp-comments-post.php): comments that arrive through XML-RPC, the REST API
	 * or AJAX comment plugins never carry our fields and must not end up in spam unseen (1.5.1).
	 */
	public static function comment_approved( $approved, $commentdata = array() ) {
		if ( 'spam' === $approved || 'trash' === $approved || is_wp_error( $approved ) || is_user_logged_in() ) {
			return $approved;
		}
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-comments-post.php' !== $GLOBALS['pagenow'] ) {
			return $approved;
		}
		$c = is_array( $commentdata ) ? $commentdata : array();
		if ( ! empty( $c['comment_type'] ) && ! in_array( $c['comment_type'], array( 'comment', '' ), true ) ) {
			return $approved;   // pingbacks and trackbacks do not come from a page
		}
		$liste = array(
			array( isset( $c['comment_author'] ) ? (string) $c['comment_author'] : '', 'line' ),
			array( isset( $c['comment_author_email'] ) ? (string) $c['comment_author_email'] : '', 'email' ),
		);
		$pairs = array( array( 'Comment', isset( $c['comment_content'] ) ? (string) $c['comment_content'] : '' ), array( 'Website', isset( $c['comment_author_url'] ) ? (string) $c['comment_author_url'] : '' ) );
		$grund = self::check( self::request(), array_filter( $liste, static function ( $f ) { return '' !== $f[0]; } ), $pairs );
		if ( null === $grund ) {
			return $approved;
		}
		self::counted( $grund, $pairs );
		return 'spam';
	}

	// ------------------------------------------------------------------ Registration (since 1.5.0)

	/** wp-login.php?action=register only - own forms that call register_new_user() carry no fields of ours (1.5.1). */
	public static function registration_errors( $errors, $login = '', $email = '' ) {
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] ) {
			return $errors;
		}
		return self::registration_check( $errors, $login, $email );
	}

	private static function registration_check( $errors, $login, $email ) {
		$grund = self::check( self::request(), array( array( (string) $email, 'email' ), array( (string) $login, 'line' ) ), array(), false );
		if ( null !== $grund && is_wp_error( $errors ) ) {
			self::counted( $grund, array() );
			$errors->add( 'formexposed', self::message( $grund ) );
		}
		return $errors;
	}

	/**
	 * WooCommerce "My account" registration only. WooCommerce fires the same action in
	 * wc_create_new_customer() - also for "create an account" in the classic and the block
	 * checkout, which carry no fields of ours; checking there failed every such order (1.5.1).
	 */
	public static function woo_register( $username, $email, $errors ) {
		if ( '' === self::posted( 'woocommerce-register-nonce' ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || doing_action( 'woocommerce_checkout_process' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		self::registration_check( $errors, (string) $username, (string) $email );
	}

	/** WooCommerce classic checkout (off by default: an order is worth more than a stopped bot). */
	public static function woo_checkout( $data, $errors ) {
		if ( ! is_wp_error( $errors ) || $errors->has_errors() ) {
			return;
		}
		$d     = is_array( $data ) ? $data : array();
		$liste = array(
			array( isset( $d['billing_email'] ) ? (string) $d['billing_email'] : '', 'email' ),
			array( isset( $d['billing_first_name'] ) ? (string) $d['billing_first_name'] : '', 'line' ),
			array( isset( $d['billing_last_name'] ) ? (string) $d['billing_last_name'] : '', 'line' ),
		);
		$grund = self::check( self::request(), array_filter( $liste, static function ( $f ) { return '' !== $f[0]; } ), array( array( 'Order notes', isset( $d['order_comments'] ) ? (string) $d['order_comments'] : '' ) ), false );
		if ( null !== $grund ) {
			self::counted( $grund, array() );
			$errors->add( 'formexposed', self::message( $grund ) );
		}
	}

	// ------------------------------------------------------------------ Login (since 1.5.0, off by default)

	/**
	 * Only the login form of wp-login.php, never XML-RPC, REST or application passwords.
	 * Only the page proof (trap field, token, time, interaction) - a person who mistypes
	 * the password five times must not be locked out by our rate limit.
	 */
	public static function login( $user, $username = '', $password = '' ) {
		if ( is_wp_error( $user ) && 'empty_username' === $user->get_error_code() ) {
			return $user;
		}
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] || '' === self::posted( 'log' ) ) {
			return $user;
		}
		$grund = self::check_proof( self::request() );
		// 1.5.1: no minimum fill time at the login - password managers fill and send at once
		if ( null === $grund || self::REASONS['too_fast'] === $grund ) {
			return $user;
		}
		self::counted( $grund, array() );
		return new WP_Error( 'formexposed', '<strong>' . esc_html__( 'Error:', 'formexposed' ) . '</strong> ' . esc_html__( 'Please reload the login page and try again.', 'formexposed' ) );
	}

	/** Counted (and links noted), but not kept: comments, registrations, logins. */
	private static function counted( $grund, $pairs ) {
		Formexposed_Stats::blocked( self::reason_key( $grund ) );
		Formexposed_Stats::links( self::links_in( $pairs ) );
	}

	/** Settings page: which additional places the layer protects. */
	public static function action_extra() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_guard_extra' );
		$neu = array();
		foreach ( array_keys( self::EXTRA_DEFAULTS ) as $k ) {
			$neu[ $k ] = ! empty( $_POST[ 'extra_' . $k ] );
		}
		update_option( self::OPT_EXTRA, $neu, false );
		set_transient( 'formexposed_msg', array( 'ok', __( 'Saved.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url() );
		exit;
	}

	// ------------------------------------------------------------------ CF7 test key

	/** CF7 stores reCAPTCHA keys as option wpcf7['recaptcha'] = array(sitekey => secret). */
	public static function cf7_test_key() {
		$o = get_option( self::OPTION_WPCF7 );
		if ( ! is_array( $o ) || empty( $o['recaptcha'] ) || ! is_array( $o['recaptcha'] ) ) {
			return '';
		}
		foreach ( array_keys( $o['recaptcha'] ) as $k ) {
			if ( 0 === strpos( (string) $k, '6LeIxAcTAAAAAJcZVRqyHh4xpd6Kd5GYHrU' ) ) {
				return (string) $k;
			}
		}
		return '';
	}

	/** Removes a Google TEST key from CF7. It let every submission through; without it the layer above protects. */
	public static function cf7_remove_test_key() {
		$o = get_option( self::OPTION_WPCF7 );
		if ( ! is_array( $o ) || empty( $o['recaptcha'] ) ) {
			return false;
		}
		foreach ( array_keys( $o['recaptcha'] ) as $k ) {
			if ( 0 === strpos( (string) $k, '6LeIxAcTAAAAAJcZVRqyHh4xpd6Kd5GYHrU' ) ) {
				unset( $o['recaptcha'][ $k ] );
			}
		}
		if ( ! $o['recaptcha'] ) {
			unset( $o['recaptcha'] );
		}
		return update_option( self::OPTION_WPCF7, $o );
	}
}
