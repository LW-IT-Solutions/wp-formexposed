<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formexposed - hosted form protection (formconfirmed), since 1.8.0.
 *
 * Connects the forms of this site to the hosted form protection of formexposed
 * (product name in FORMEXPOSED_PROTECT_NAME). On pages with a protected form the
 * widget script of the service solves a small proof of work in the visitor's
 * browser; on submission this server asks the verify API with the secret key.
 *
 * Unlike the protection layer (class-formexposed-guard.php), which runs on this
 * server only, this needs a site key and a secret key - typed in, or fetched
 * with one click when the site is connected to a formexposed account.
 *
 * Outcome of a submission (since 1.9.0, the outage rule of the service):
 *   allow                       the message goes through.
 *   block                       refused (the form shows its error, or the message is filed as spam).
 *   flag                        never waved through: held for review (default) or refused, as chosen.
 *   no clear answer             the service could not be reached, answered with an error code (wrong
 *                               secret, site paused, service switched off), answered something unknown,
 *                               or the visitor's browser reported that it could not reach the service.
 *                               The message goes through only if an independent check on this site
 *                               passed: the protection layer with the proof of work (see fallback()).
 *                               Otherwise it is held for review or refused, as chosen - never let
 *                               through silently.
 * Held messages wait in the quarantine of this site (14 days) and can be delivered with one click.
 * The login form is the exception: there the password check is the independent check, so only
 * "block" refuses a login.
 *
 * One adapter per form plugin. Each puts the widget element into the form (bound to a fixed
 * purpose, see PURPOSES) and decides on submission - after the protection layer of this plugin,
 * so the fallback knows whether that layer passed the same submission.
 */

class Formexposed_Protect {

	const OPT    = 'formexposed_protect';

	/** WordPress' own setting "Anyone can register". */
	const OPTION_USERS_CAN_REGISTER = 'users_can_register';

	const FIELD  = 'fcp-response';
	const HP     = 'fcp-website';
	const TIMEOUT = 5;

	/** Adapters in the order of the settings page. */
	const ADAPTERS = array( 'cf7', 'wpforms', 'gravityforms', 'elementor', 'fluentform', 'formidable', 'comments', 'register', 'woo_register', 'login' );

	/** Switched on for a new installation (the login form only on request). */
	const DEFAULT_ADAPTERS = array( 'cf7', 'wpforms', 'gravityforms', 'elementor', 'fluentform', 'formidable', 'comments', 'register', 'woo_register' );

	/** The purpose each adapter binds its token to (data-purpose / expected_purpose of the service). */
	const PURPOSES = array(
		'cf7'          => 'contact',
		'wpforms'      => 'contact',
		'gravityforms' => 'contact',
		'elementor'    => 'contact',
		'fluentform'   => 'contact',
		'formidable'   => 'contact',
		'comments'     => 'comment',
		'register'     => 'register',
		'woo_register' => 'register',
		'login'        => 'login',
	);

	/** Error codes of the service that mean "no check took place" (not the visitor's fault). */
	const NO_CHECK_CODES = array( 'service-disabled', 'site-paused', 'invalid-input-secret', 'missing-input-secret', 'bad-request' );

	/** The result of the current submission, for adapters that decide in two steps. */
	private static $result = null;

	/** The decision for the current submission: pass, hold or reject. */
	private static $decision = null;

	/** The adapter the current submission was checked for. */
	private static $checked = '';

	/** Raw WPForms entry of the current submission (wpforms_process_before). */
	private static $wpforms_entry = array();

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_protect_save', array( __CLASS__, 'action_save' ) );
			add_action( 'admin_post_formexposed_protect_connect', array( __CLASS__, 'action_connect' ) );
			add_action( 'admin_post_formexposed_protect_test', array( __CLASS__, 'action_test' ) );
			add_action( 'admin_post_formexposed_protect_dismiss', array( __CLASS__, 'action_dismiss' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
		if ( ! self::on() || self::handed_over() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'async_tag' ), 10, 2 );
		foreach ( self::active_adapters() as $a ) {
			call_user_func( array( __CLASS__, 'adapter_' . $a ) );
		}
	}

	/**
	 * The formconfirmed plugin is active (since 1.10.0): it checks the forms with the same keys (it takes
	 * them over when it is activated), so this tab stands down - a token is valid once, two checks of
	 * the same submission would refuse it. The protection layer keeps running; formconfirmed uses it as
	 * its backup check and puts held messages into the quarantine of this plugin.
	 */
	public static function handed_over() {
		return defined( 'FORMCONFIRMED_VERSION' );
	}

	/** The saved settings, with defaults. */
	public static function opt() {
		$o = get_option( self::OPT, array() );
		$o = is_array( $o ) ? $o : array();
		// 1.8.x: "flag_spam" (treat flagged messages as spam) became the choice for every unclear result.
		if ( ! isset( $o['uncertain'] ) && ! empty( $o['flag_spam'] ) ) {
			$o['uncertain'] = 'reject';
		}
		$o = wp_parse_args( $o, array(
			'site_key'  => '',
			'secret'    => '',
			'adapters'  => self::DEFAULT_ADAPTERS,
			'content'   => true,
			'uncertain' => 'hold',
			'stats'     => array(),
			'problem'   => array(),
			'ok_at'     => 0,
		) );
		$o['adapters']  = array_values( array_intersect( self::ADAPTERS, (array) $o['adapters'] ) );
		$o['uncertain'] = 'reject' === $o['uncertain'] ? 'reject' : 'hold';
		$o['stats']     = wp_parse_args( (array) $o['stats'], array( 'allow' => 0, 'flag' => 0, 'block' => 0, 'unreachable' => 0, 'fallback' => 0, 'held' => 0, 'rejected' => 0 ) );
		return $o;
	}

	private static function save_opt( $o ) {
		unset( $o['flag_spam'] );
		update_option( self::OPT, $o, false );
	}

	public static function on() {
		$o = self::opt();
		return '' !== $o['site_key'] && '' !== $o['secret'];
	}

	/** The adapters that are switched on AND whose form plugin is active. */
	public static function active_adapters() {
		$out = array();
		foreach ( self::opt()['adapters'] as $a ) {
			if ( self::available( $a ) ) {
				$out[] = $a;
			}
		}
		return $out;
	}

	/** Is the form plugin (or core feature) of an adapter present? */
	public static function available( $a ) {
		switch ( $a ) {
			case 'cf7':
				return defined( 'WPCF7_VERSION' );
			case 'wpforms':
				return defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' );
			case 'gravityforms':
				return class_exists( 'GFForms' );
			case 'elementor':
				return defined( 'ELEMENTOR_PRO_VERSION' );
			case 'fluentform':
				return defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' );
			case 'formidable':
				return class_exists( 'FrmAppHelper' );
			case 'woo_register':
				return class_exists( 'WooCommerce' );
			case 'register':
				return (bool) get_option( self::OPTION_USERS_CAN_REGISTER );
			default:
				return true;   // comments, login
		}
	}

	public static function labels() {
		return array(
			'cf7'          => 'Contact Form 7',
			'wpforms'      => 'WPForms',
			'gravityforms' => 'Gravity Forms',
			'elementor'    => __( 'Elementor Pro forms', 'formexposed' ),
			'fluentform'   => 'Fluent Forms',
			'formidable'   => 'Formidable Forms',
			'comments'     => __( 'Comments', 'formexposed' ),
			'register'     => __( 'WordPress registration', 'formexposed' ),
			'woo_register' => __( 'WooCommerce account registration', 'formexposed' ),
			'login'        => __( 'WordPress login', 'formexposed' ),
		);
	}

	// ------------------------------------------------------------------ front end

	/** The widget script is registered on every page, but only loaded where a protected form is printed. */
	public static function register_script() {
		wp_register_script( 'formexposed-protect', FORMEXPOSED_PROTECT_API . '/widget.js', array(), FORMEXPOSED_VERSION, array( 'in_footer' => true ) );
		wp_register_script( 'formexposed-protect-forms', plugins_url( 'assets/js/protect.js', FORMEXPOSED_FILE ), array(), FORMEXPOSED_VERSION, array( 'in_footer' => true ) );
	}

	/** Load the widget (and, for forms that are equipped in the browser, the small helper). */
	private static function load( $helper = false ) {
		if ( ! wp_script_is( 'formexposed-protect', 'registered' ) ) {
			self::register_script();
		}
		wp_enqueue_script( 'formexposed-protect' );
		if ( $helper && ! wp_script_is( 'formexposed-protect-forms', 'enqueued' ) ) {
			wp_enqueue_script( 'formexposed-protect-forms' );
			$sel = array();
			if ( in_array( 'elementor', self::active_adapters(), true ) ) {
				$sel[] = 'form.elementor-form';
			}
			if ( in_array( 'fluentform', self::active_adapters(), true ) ) {
				$sel[] = 'form.frm-fluent-form';
			}
			wp_add_inline_script( 'formexposed-protect-forms', 'window.formexposedProtect = ' . wp_json_encode( array(
				'sel'     => implode( ',', $sel ),
				'sitekey' => self::opt()['site_key'],
				'purpose' => self::PURPOSES['elementor'],
			) ) . ';', 'before' );
		}
	}

	public static function async_tag( $tag, $handle ) {
		return 'formexposed-protect' === $handle ? str_replace( ' src=', ' async defer src=', $tag ) : $tag;
	}

	/** The element the widget turns into the hidden token field, a trap field and the badge. */
	public static function element( $adapter ) {
		self::load();
		return '<div class="fcp-widget" data-sitekey="' . esc_attr( self::opt()['site_key'] ) . '" data-purpose="' . esc_attr( self::PURPOSES[ $adapter ] ) . '"></div>';
	}

	/** Prints the element (for actions that print inside the form). */
	private static function print_element( $adapter ) {
		echo wp_kses( self::element( $adapter ), array( 'div' => array( 'class' => true, 'data-sitekey' => true, 'data-purpose' => true ) ) );
	}

	/**
	 * A field of the current POST request (the form plugins verify their own requests), sanitized as it is read:
	 * the trap field with FILTER_SANITIZE_SPECIAL_CHARS (a filled trap never turns into an empty one), "data" (the
	 * URL-encoded form of Fluent Forms) with FILTER_SANITIZE_URL, every other field with sanitize_text_field().
	 */
	private static function posted( $name ) {
		if ( self::HP === $name ) {
			$v = filter_input( INPUT_POST, $name, FILTER_SANITIZE_SPECIAL_CHARS, FILTER_FLAG_STRIP_LOW );
		} elseif ( 'data' === $name ) {
			$v = filter_input( INPUT_POST, $name, FILTER_SANITIZE_URL );
		} else {
			$v = filter_input( INPUT_POST, $name, FILTER_CALLBACK, array( 'options' => 'sanitize_text_field' ) );
		}
		return is_string( $v ) ? $v : '';
	}

	// ------------------------------------------------------------------ the check

	/**
	 * Asks the verify API.
	 * $data:    where the token and the trap field are (null: the POST request; Fluent Forms: its parsed data).
	 * $content: message text for the content check (only if allowed in the settings; checked in memory, not stored).
	 * $email:   the e-mail address of the form (disposable providers, domains without mail).
	 * Returns array(action, score, reasons, unavailable, codes).
	 */
	public static function verify( $adapter, $data = null, $content = '', $email = '' ) {
		if ( null !== self::$result && self::$checked === $adapter ) {
			return self::$result;   // some form plugins run their validation filter twice; a token is valid once
		}
		self::$checked = $adapter;
		$o     = self::opt();
		$token = is_array( $data ) ? ( isset( $data[ self::FIELD ] ) && is_string( $data[ self::FIELD ] ) ? $data[ self::FIELD ] : '' ) : self::posted( self::FIELD );
		$hp    = is_array( $data ) ? ( isset( $data[ self::HP ] ) && is_string( $data[ self::HP ] ) ? $data[ self::HP ] : '' ) : self::posted( self::HP );
		$body  = array(
			'secret'           => $o['secret'],
			'response'         => substr( sanitize_text_field( $token ), 0, 4000 ),
			'remoteip'         => Formexposed_Guard::client_ip(),
			'hp'               => substr( sanitize_text_field( $hp ), 0, 2048 ),
			'expected_purpose' => self::PURPOSES[ $adapter ],
			'form_mode'        => 'interactive',
		);
		$email = sanitize_email( (string) $email );
		if ( '' !== $email && is_email( $email ) ) {
			$body['email'] = $email;
		}
		if ( $o['content'] && 'login' !== $adapter && '' !== trim( (string) $content ) ) {
			$body['content'] = mb_substr( (string) $content, 0, 2000 );
		}
		$r = wp_remote_post( FORMEXPOSED_PROTECT_API . '/verify', array(
			'timeout' => self::TIMEOUT,
			'body'    => $body,
		) );
		$res          = self::read_answer( $r );
		self::$result = $res;
		self::note( $res );
		return $res;
	}

	/**
	 * Turns the HTTP answer into a result. Anything that is not a well-formed answer of the service
	 * (no answer, HTTP error, unknown action, error codes that mean no check took place) is
	 * "unavailable" - never a pass. Public for the tests.
	 */
	public static function read_answer( $r ) {
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		$j    = ( is_wp_error( $r ) || 200 !== $code ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$none = array(
			'action'      => 'none',
			'score'       => null,
			'reasons'     => array(),
			'unavailable' => true,
			'codes'       => array( is_wp_error( $r ) ? 'no-answer' : 'http-' . $code ),
		);
		if ( ! is_array( $j ) || ! isset( $j['action'] ) || ! is_string( $j['action'] ) ) {
			if ( is_array( $j ) ) {
				$none['codes'] = array( 'invalid-answer' );
			}
			return $none;
		}
		$codes   = array_values( array_filter( (array) ( isset( $j['error-codes'] ) ? $j['error-codes'] : array() ), 'is_string' ) );
		$reasons = array_values( array_filter( (array) ( isset( $j['reasons'] ) ? $j['reasons'] : array() ), 'is_string' ) );
		$own     = array_values( array_intersect( $codes, self::NO_CHECK_CODES ) );
		if ( $own ) {
			$none['codes'] = $own;
			return $none;
		}
		if ( ! in_array( $j['action'], array( 'allow', 'flag', 'block' ), true ) || ( 'allow' === $j['action'] && true !== ( isset( $j['success'] ) ? $j['success'] : null ) ) ) {
			$none['codes'] = array( 'invalid-answer' );
			return $none;
		}
		return array(
			'action'      => $j['action'],
			'score'       => isset( $j['score'] ) && is_numeric( $j['score'] ) ? (float) $j['score'] : null,
			'reasons'     => $reasons,
			// The browser could not reach the service: an unsigned claim, not a check (1.8.3).
			'unavailable' => 'block' !== $j['action'] && in_array( 'service_unavailable', $reasons, true ),
			'codes'       => $codes,
		);
	}

	/**
	 * The independent check that may stand in when the service gave no clear answer: the protection
	 * layer of this plugin, switched on WITH the proof of work, has checked and passed this same
	 * submission (honeypot, signed fill time, interaction, proof of work, rate limit, filters).
	 */
	public static function fallback() {
		return Formexposed_Guard::passed_strict();
	}

	/** Can the fallback run at all for an adapter (protection layer with proof of work covers it)? */
	public static function fallback_ready( $adapter = 'cf7' ) {
		if ( in_array( $adapter, array( 'register', 'woo_register', 'login' ), true ) ) {
			return false;   // the protection layer checks these without the proof of work
		}
		return Formexposed_Guard::on() && Formexposed_Pow::on();
	}

	/**
	 * pass, hold or reject. $can_hold: the adapter can keep a message back without the visitor
	 * noticing (otherwise "hold" refuses it too, and the message still goes to the quarantine).
	 */
	public static function decide( $res, $adapter ) {
		if ( null !== self::$decision ) {
			return self::$decision;
		}
		$mode = self::opt()['uncertain'];
		if ( 'block' === $res['action'] ) {
			$d = 'reject';
		} elseif ( $res['unavailable'] ) {
			if ( 'login' === $adapter ) {
				$d = 'pass';   // the password check is the independent check
			} elseif ( self::fallback_ready( $adapter ) && self::fallback() ) {
				$d = 'pass';
				self::count( 'fallback' );
			} else {
				$d = $mode;
			}
		} elseif ( 'flag' === $res['action'] ) {
			$d = 'login' === $adapter ? 'pass' : $mode;
		} else {
			$d = 'pass';
		}
		if ( 'pass' !== $d ) {
			self::count( 'hold' === $d ? 'held' : 'rejected' );
		}
		self::$decision = $d;
		return $d;
	}

	/** Counts and remembers the last answer (for the settings page). */
	private static function note( $res ) {
		$o = self::opt();
		$k = $res['unavailable'] ? 'unreachable' : $res['action'];
		$o['stats'][ $k ] = (int) $o['stats'][ $k ] + 1;
		if ( $res['unavailable'] ) {
			$o['problem'] = array(
				'code' => implode( ',', $res['codes'] ? $res['codes'] : $res['reasons'] ),
				'at'   => time(),
			);
		} else {
			$o['ok_at'] = time();
		}
		self::save_opt( $o );
	}

	private static function count( $what ) {
		$o = self::opt();
		$o['stats'][ $what ] = (int) $o['stats'][ $what ] + 1;
		self::save_opt( $o );
	}

	/** Quarantine reason for a result. */
	private static function reason_key( $res ) {
		if ( 'block' === $res['action'] ) {
			return 'fc_block';
		}
		return $res['unavailable'] ? 'fc_unavailable' : 'fc_flag';
	}

	/** Keeps a message that was not passed in the quarantine of this site (14 days). */
	private static function keep( $source, $form, $pairs ) {
		if ( self::$result ) {
			Formexposed_Quarantine::store( $source, $form, self::reason_key( self::$result ), $pairs );
		}
	}

	/** The longest text of a list of pairs - usually the message. */
	private static function longest( $pairs ) {
		$best = '';
		foreach ( (array) $pairs as $p ) {
			if ( mb_strlen( (string) $p[1] ) > mb_strlen( $best ) ) {
				$best = (string) $p[1];
			}
		}
		return $best;
	}

	/** The first e-mail address of a list of pairs. */
	private static function first_email( $pairs ) {
		foreach ( (array) $pairs as $p ) {
			if ( is_email( trim( (string) $p[1] ) ) ) {
				return trim( (string) $p[1] );
			}
		}
		return '';
	}

	/** What the visitor sees when a message is refused. */
	private static function message( $res = null ) {
		$res = $res ? $res : self::$result;
		if ( $res && $res['unavailable'] ) {
			return __( 'Your message could not be checked right now. Please try again in a few minutes.', 'formexposed' );
		}
		return __( 'Your message could not be sent. Please reload the page and try again.', 'formexposed' );
	}

	// ------------------------------------------------------------------ adapters

	/** Contact Form 7: element in the form; refuse = CF7 spam message; hold = no mail, the visitor sees the normal confirmation. */
	public static function adapter_cf7() {
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'cf7_element' ) );
		add_filter( 'wpcf7_spam', array( __CLASS__, 'cf7_spam' ), 30, 2 );
		add_filter( 'wpcf7_skip_mail', array( __CLASS__, 'cf7_skip_mail' ), 30 );
	}

	public static function cf7_element( $html ) {
		return false === strpos( $html, 'fcp-widget' ) ? $html . self::element( 'cf7' ) : $html;
	}

	private static function cf7_pairs( $submission ) {
		$pairs = array();
		$data  = $submission && method_exists( $submission, 'get_posted_data' ) ? (array) $submission->get_posted_data() : array();
		foreach ( $data as $k => $v ) {
			if ( 0 === strpos( (string) $k, '_' ) || 0 === strpos( (string) $k, 'formexposed_' ) || 0 === strpos( (string) $k, 'fcp-' ) || 'g-recaptcha-response' === $k ) {
				continue;
			}
			$v = is_array( $v ) ? implode( ', ', array_filter( $v, 'is_scalar' ) ) : (string) $v;
			if ( '' !== $v ) {
				$pairs[] = array( (string) $k, $v );
			}
		}
		return $pairs;
	}

	public static function cf7_spam( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam;   // another check (also the protection layer) already said spam
		}
		$pairs = self::cf7_pairs( $submission );
		$res   = self::verify( 'cf7', null, self::longest( $pairs ), self::first_email( $pairs ) );
		$d     = self::decide( $res, 'cf7' );
		if ( 'pass' === $d ) {
			return false;
		}
		$title = $submission && method_exists( $submission, 'get_contact_form' ) && $submission->get_contact_form() ? (string) $submission->get_contact_form()->title() : 'Contact Form 7';
		self::keep( 'cf7', $title, $pairs );
		if ( 'hold' === $d ) {
			return false;   // wpcf7_skip_mail keeps the e-mail back
		}
		if ( $submission && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( array( 'agent' => 'formexposed', 'reason' => FORMEXPOSED_PROTECT_NAME . ': ' . self::reason_key( $res ) ) );
		}
		return true;
	}

	public static function cf7_skip_mail( $skip ) {
		return 'hold' === self::$decision ? true : $skip;
	}

	/** WPForms: element before the submit button; refuse = error above the form; hold = no notification e-mail. */
	public static function adapter_wpforms() {
		add_action( 'wpforms_display_submit_before', array( __CLASS__, 'wpforms_element' ) );
		add_action( 'wpforms_process_before', array( __CLASS__, 'wpforms_entry' ), 10, 1 );
		add_filter( 'wpforms_process_initial_errors', array( __CLASS__, 'wpforms_errors' ), 30, 2 );
		add_filter( 'wpforms_entry_email', array( __CLASS__, 'wpforms_email' ), 30 );
	}

	public static function wpforms_element() {
		self::print_element( 'wpforms' );
	}

	public static function wpforms_entry( $entry ) {
		self::$wpforms_entry = is_array( $entry ) ? $entry : array();
	}

	public static function wpforms_errors( $errors, $form_data ) {
		$id = isset( $form_data['id'] ) ? $form_data['id'] : 0;
		if ( ! empty( $errors[ $id ] ) ) {
			return $errors;   // already refused (also by the protection layer)
		}
		$pairs  = array();
		$fields = isset( self::$wpforms_entry['fields'] ) && is_array( self::$wpforms_entry['fields'] ) ? self::$wpforms_entry['fields'] : array();
		foreach ( $fields as $fid => $wert ) {
			$teile = array_filter( array_map( 'strval', array_filter( (array) $wert, 'is_scalar' ) ), 'strlen' );
			if ( $teile ) {
				$label   = isset( $form_data['fields'][ $fid ]['label'] ) ? (string) $form_data['fields'][ $fid ]['label'] : 'Field ' . $fid;
				$pairs[] = array( $label, wp_unslash( implode( ' ', $teile ) ) );
			}
		}
		$res = self::verify( 'wpforms', null, self::longest( $pairs ), self::first_email( $pairs ) );
		$d   = self::decide( $res, 'wpforms' );
		if ( 'pass' !== $d ) {
			self::keep( 'wpforms', isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : 'WPForms', $pairs );
		}
		if ( 'reject' === $d ) {
			$errors[ $id ]['header'] = esc_html( self::message( $res ) );
		}
		return $errors;
	}

	public static function wpforms_email( $send ) {
		return 'hold' === self::$decision ? false : $send;
	}

	/** Gravity Forms: element before the submit button; anything not passed is filed under Entries -> Spam (no notification). */
	public static function adapter_gravityforms() {
		add_filter( 'gform_submit_button', array( __CLASS__, 'gform_button' ), 10, 2 );
		add_filter( 'gform_entry_is_spam', array( __CLASS__, 'gform_spam' ), 30, 3 );
	}

	public static function gform_button( $button, $form = array() ) {
		return self::element( 'gravityforms' ) . $button;
	}

	public static function gform_spam( $is_spam, $form = array(), $entry = array() ) {
		if ( $is_spam ) {
			return $is_spam;
		}
		$pairs = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $f ) {
			if ( ! is_object( $f ) || ! isset( $f->id ) ) {
				continue;
			}
			$ids   = ! empty( $f->inputs ) && is_array( $f->inputs ) ? wp_list_pluck( $f->inputs, 'id' ) : array( $f->id );
			$teile = array();
			foreach ( $ids as $iid ) {
				if ( isset( $entry[ (string) $iid ] ) && is_string( $entry[ (string) $iid ] ) && '' !== $entry[ (string) $iid ] ) {
					$teile[] = $entry[ (string) $iid ];
				}
			}
			if ( $teile ) {
				$pairs[] = array( isset( $f->label ) && '' !== (string) $f->label ? (string) $f->label : 'Field ' . $f->id, implode( ' ', $teile ) );
			}
		}
		$res = self::verify( 'gravityforms', null, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'gravityforms' ) ) {
			return false;
		}
		self::keep( 'gravityforms', isset( $form['title'] ) ? (string) $form['title'] : 'Gravity Forms', $pairs );
		if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			GFCommon::set_spam_filter( isset( $form['id'] ) ? $form['id'] : 0, FORMEXPOSED_PROTECT_NAME, self::reason_key( $res ) );
		}
		return true;
	}

	/** Elementor Pro forms: the helper script puts the element into the form; anything not passed is refused (error below the form). */
	public static function adapter_elementor() {
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'elementor_render' ), 10, 2 );
		add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'elementor_validation' ), 30, 2 );
	}

	public static function elementor_render( $content, $widget = null ) {
		if ( is_object( $widget ) && method_exists( $widget, 'get_name' ) && 'form' === $widget->get_name() ) {
			self::load( true );
		}
		return $content;
	}

	public static function elementor_validation( $record, $ajax_handler ) {
		if ( is_object( $ajax_handler ) && ! empty( $ajax_handler->errors ) ) {
			return;   // already refused
		}
		$pairs = array();
		foreach ( is_object( $record ) && method_exists( $record, 'get' ) ? (array) $record->get( 'fields' ) : array() as $id => $f ) {
			if ( isset( $f['value'] ) && is_string( $f['value'] ) && '' !== $f['value'] ) {
				$pairs[] = array( isset( $f['title'] ) && '' !== (string) $f['title'] ? (string) $f['title'] : (string) $id, $f['value'] );
			}
		}
		$res = self::verify( 'elementor', null, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'elementor' ) ) {
			return;
		}
		$name = is_object( $record ) && method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		self::keep( 'elementor', '' !== $name ? $name : 'Elementor', $pairs );
		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message( self::message( $res ) );
		}
		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error' ) ) {
			$ajax_handler->add_error( 'formexposed', self::message( $res ) );
		}
	}

	/** Fluent Forms: element before the submit button; anything not passed is refused (error below the form). */
	public static function adapter_fluentform() {
		add_action( 'fluentform/render_item_submit_button', array( __CLASS__, 'fluent_element' ), 5 );
		add_action( 'fluentform/before_form_render', array( __CLASS__, 'fluent_load' ) );
		add_filter( 'fluentform/validation_errors', array( __CLASS__, 'fluent_errors' ), 30, 4 );
	}

	public static function fluent_element() {
		self::print_element( 'fluentform' );
	}

	public static function fluent_load() {
		self::load( true );   // multi-step forms have no submit button item: the helper equips them
	}

	public static function fluent_errors( $errors, $form_data, $form = null, $fields = array() ) {
		if ( ! empty( $errors ) || ! is_array( $form_data ) ) {
			return $errors;
		}
		$pairs = array();
		foreach ( $form_data as $name => $wert ) {
			if ( 0 === strpos( (string) $name, '_' ) || 0 === strpos( (string) $name, 'formexposed_' ) || 0 === strpos( (string) $name, 'fcp-' ) ) {
				continue;
			}
			$teile = array_filter( array_map( 'strval', array_filter( (array) $wert, 'is_scalar' ) ), 'strlen' );
			if ( $teile ) {
				$pairs[] = array( (string) $name, implode( ' ', $teile ) );
			}
		}
		// Fluent Forms keeps only its own fields in $form_data; token and trap field travel in the raw "data" string.
		$raw = array();
		wp_parse_str( self::posted( 'data' ), $raw );
		$src = array(
			self::FIELD => isset( $raw[ self::FIELD ] ) && is_string( $raw[ self::FIELD ] ) ? $raw[ self::FIELD ] : '',
			self::HP    => isset( $raw[ self::HP ] ) && is_string( $raw[ self::HP ] ) ? $raw[ self::HP ] : '',
		);
		$res = self::verify( 'fluentform', $src, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'fluentform' ) ) {
			return $errors;
		}
		self::keep( 'fluentform', is_object( $form ) && isset( $form->title ) ? (string) $form->title : 'Fluent Forms', $pairs );
		$errors['formexposed'] = array( self::message( $res ) );
		return $errors;
	}

	/** Formidable Forms: element inside the form; anything not passed is refused (error above the form). */
	public static function adapter_formidable() {
		add_action( 'frm_entry_form', array( __CLASS__, 'formidable_element' ) );
		add_filter( 'frm_validate_entry', array( __CLASS__, 'formidable_errors' ), 30, 2 );
	}

	public static function formidable_element() {
		self::print_element( 'formidable' );
	}

	public static function formidable_errors( $errors, $values ) {
		if ( ! empty( $errors ) || ! is_array( $values ) ) {
			return $errors;
		}
		$pairs = array();
		foreach ( isset( $values['item_meta'] ) && is_array( $values['item_meta'] ) ? $values['item_meta'] : array() as $fid => $wert ) {
			if ( ! is_numeric( $fid ) ) {
				continue;
			}
			$teile = array_filter( array_map( 'strval', array_filter( (array) $wert, 'is_scalar' ) ), 'strlen' );
			if ( $teile ) {
				$pairs[] = array( 'Field ' . $fid, wp_unslash( implode( ' ', $teile ) ) );
			}
		}
		$res = self::verify( 'formidable', null, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'formidable' ) ) {
			return $errors;
		}
		self::keep( 'formidable', 'Formidable', $pairs );
		$errors['formexposed'] = self::message( $res );
		return $errors;
	}

	/**
	 * Comments: element above the submit button. Only comments from the comment form of the theme
	 * (wp-comments-post.php) - REST, XML-RPC, pingbacks carry no token. block = refused with an
	 * error page; hold = held for moderation; refuse (setting) = Comments -> Spam.
	 */
	public static function adapter_comments() {
		add_filter( 'comment_form_submit_field', array( __CLASS__, 'comment_field' ) );
		add_filter( 'pre_comment_approved', array( __CLASS__, 'comment_approved' ), 30, 2 );
	}

	public static function comment_field( $field ) {
		return self::element( 'comments' ) . $field;
	}

	public static function comment_approved( $approved, $commentdata = array() ) {
		if ( 'spam' === $approved || 'trash' === $approved || is_wp_error( $approved ) ) {
			return $approved;
		}
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-comments-post.php' !== $GLOBALS['pagenow'] ) {
			return $approved;
		}
		if ( is_user_logged_in() && current_user_can( 'moderate_comments' ) ) {
			return $approved;
		}
		$c = is_array( $commentdata ) ? $commentdata : array();
		if ( ! empty( $c['comment_type'] ) && 'comment' !== $c['comment_type'] ) {
			return $approved;
		}
		$res = self::verify( 'comments', null, isset( $c['comment_content'] ) ? (string) $c['comment_content'] : '', isset( $c['comment_author_email'] ) ? (string) $c['comment_author_email'] : '' );
		$d   = self::decide( $res, 'comments' );
		if ( 'block' === $res['action'] ) {
			wp_die( esc_html( self::message( $res ) ), '', array( 'response' => 400, 'back_link' => true ) );
		}
		if ( 'hold' === $d ) {
			return 0;
		}
		return 'reject' === $d ? 'spam' : $approved;
	}

	/** WordPress registration (wp-login.php?action=register): anything not passed is refused, no account. */
	public static function adapter_register() {
		add_action( 'register_form', array( __CLASS__, 'register_element' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'register_errors' ), 30, 3 );
	}

	public static function register_element() {
		self::print_element( 'register' );
	}

	public static function register_errors( $errors, $login = '', $email = '' ) {
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] || ! is_wp_error( $errors ) || $errors->has_errors() ) {
			return $errors;
		}
		$res = self::verify( 'register', null, '', (string) $email );
		if ( 'pass' !== self::decide( $res, 'register' ) ) {
			$errors->add( 'formexposed_protect', esc_html( self::message( $res ) ) );
		}
		return $errors;
	}

	/** WooCommerce "My account" registration (not the account creation in the checkout). */
	public static function adapter_woo_register() {
		add_action( 'woocommerce_register_form', array( __CLASS__, 'woo_element' ) );
		add_action( 'woocommerce_register_post', array( __CLASS__, 'woo_register' ), 30, 3 );
	}

	public static function woo_element() {
		self::print_element( 'woo_register' );
	}

	public static function woo_register( $username, $email, $errors ) {
		if ( '' === self::posted( 'woocommerce-register-nonce' ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || doing_action( 'woocommerce_checkout_process' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! is_wp_error( $errors ) || $errors->has_errors() ) {
			return;
		}
		$res = self::verify( 'woo_register', null, '', (string) $email );
		if ( 'pass' !== self::decide( $res, 'woo_register' ) ) {
			$errors->add( 'formexposed_protect', esc_html( self::message( $res ) ) );
		}
	}

	/** WordPress login (off by default): only a "block" refuses - the password is the independent check. */
	public static function adapter_login() {
		add_action( 'login_form', array( __CLASS__, 'login_element' ) );
		add_filter( 'authenticate', array( __CLASS__, 'login' ), 40, 3 );
	}

	public static function login_element() {
		self::print_element( 'login' );
	}

	public static function login( $user, $username = '', $password = '' ) {
		if ( ! ( $user instanceof WP_User ) || ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] || '' === self::posted( 'log' ) ) {
			return $user;   // only a correct login from the login page is checked
		}
		$res = self::verify( 'login', null, '', '' );
		if ( 'pass' === self::decide( $res, 'login' ) ) {
			return $user;
		}
		return new WP_Error( 'formexposed_protect', '<strong>' . esc_html__( 'Error:', 'formexposed' ) . '</strong> ' . esc_html__( 'Please reload the login page and try again.', 'formexposed' ) );
	}

	// ------------------------------------------------------------------ admin

	/** A problem with the service in the last 24 hours (for the notice and the page). */
	public static function recent_problem() {
		$o = self::opt();
		if ( empty( $o['problem']['at'] ) || (int) $o['problem']['at'] < time() - DAY_IN_SECONDS ) {
			return null;
		}
		if ( (int) $o['ok_at'] > (int) $o['problem']['at'] ) {
			return null;   // a clear answer came after it
		}
		return $o['problem'];
	}

	/** A problem code in words. */
	public static function problem_text( $code ) {
		$codes = explode( ',', (string) $code );
		if ( array_intersect( $codes, array( 'invalid-input-secret', 'missing-input-secret' ) ) ) {
			return __( 'The service does not accept the secret key. Enter it again or fetch new keys.', 'formexposed' );
		}
		if ( in_array( 'site-paused', $codes, true ) ) {
			return __( 'The protected site is paused in your account (or its plan ended). Activate it again there.', 'formexposed' );
		}
		if ( in_array( 'service-disabled', $codes, true ) ) {
			return __( 'The service is switched off at the moment.', 'formexposed' );
		}
		if ( in_array( 'service_unavailable', $codes, true ) ) {
			return __( 'A visitor\'s browser could not reach the service (for example a network filter blocks it).', 'formexposed' );
		}
		return __( 'The service could not be reached or did not give a valid answer.', 'formexposed' );
	}

	/** Notice while messages are held because the service gave no clear answer (dashboard, plugins page, our page). */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		$p = self::recent_problem();
		if ( ! $p || (int) get_option( 'formexposed_protect_notice_off', 0 ) > time() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( FORMEXPOSED_PROTECT_NAME ) . ':</strong> '
			. esc_html( self::problem_text( $p['code'] ) ) . ' '
			. esc_html__( 'Messages without a clear check are held or refused, not let through.', 'formexposed' )
			. ' <a href="' . esc_url( Formexposed_Core::page_url( array( 'tab' => 'protect' ) ) ) . '">' . esc_html__( 'Open form protection', 'formexposed' ) . '</a> · '
			. '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_protect_dismiss' ), 'formexposed_protect_dismiss' ) ) . '">' . esc_html__( 'Hide for a day', 'formexposed' ) . '</a></p></div>';
	}

	public static function action_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_protect_dismiss' );
		update_option( 'formexposed_protect_notice_off', time() + DAY_IN_SECONDS, false );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/** The tab "Form protection" of Settings -> formexposed. */
	public static function page( $msg ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o      = self::opt();
		$labels = self::labels();
		$p      = self::recent_problem();
		$s      = $o['stats'];
		?>
		<div class="wrap formexposed-wrap" style="max-width:980px">
			<h1>formexposed · <?php esc_html_e( 'Form protection', 'formexposed' ); ?></h1>
			<?php Formexposed_Core::nav( 'protect' ); ?>
			<?php if ( is_array( $msg ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'ok' === $msg[0] || 'success' === $msg[0] ? 'success' : ( 'warning' === $msg[0] ? 'warning' : 'error' ) ); ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( self::handed_over() ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'The formconfirmed plugin is active and checks your forms now (Settings -> formconfirmed). The settings below are not used while it is active.', 'formexposed' ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: product name of the hosted form protection */ __( 'Hosted form protection by %s: an invisible proof of work in the visitor\'s browser, timing and trap-field signals, an e-mail check and an optional check of the message text - on servers in the EU, without cookies or puzzles. It needs keys from a formexposed account and works together with the free protection layer on the Overview tab.', 'formexposed' ), FORMEXPOSED_PROTECT_NAME ) ); ?></p>

			<div class="card" style="max-width:none">
				<h2 class="title"><?php esc_html_e( 'Status', 'formexposed' ); ?></h2>
				<?php if ( ! self::on() ) : ?>
					<p><span class="dashicons dashicons-minus" aria-hidden="true"></span> <strong><?php esc_html_e( 'Not active.', 'formexposed' ); ?></strong> <?php esc_html_e( 'Enter the site key and the secret key below, or fetch them from your formexposed account.', 'formexposed' ); ?></p>
				<?php elseif ( $p ) : ?>
					<p><span class="dashicons dashicons-warning" style="color:#996800" aria-hidden="true"></span> <strong><?php esc_html_e( 'Active, with a problem:', 'formexposed' ); ?></strong> <?php echo esc_html( self::problem_text( $p['code'] ) ); ?>
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( '(last seen %s)', 'formexposed' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $p['at'] ) ) );
					?>
					</p>
				<?php else : ?>
					<p><span class="dashicons dashicons-yes-alt" style="color:#00750f" aria-hidden="true"></span> <strong><?php esc_html_e( 'Active.', 'formexposed' ); ?></strong>
					<?php
					if ( $o['ok_at'] ) {
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Last answer of the service: %s.', 'formexposed' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $o['ok_at'] ) ) );
					} else {
						esc_html_e( 'No message has been checked yet.', 'formexposed' );
					}
					?>
					</p>
				<?php endif; ?>
				<?php if ( self::on() ) : ?>
					<p><?php echo esc_html( sprintf( /* translators: 1: allowed, 2: flagged, 3: blocked, 4: without clear answer, 5: let through by the fallback check, 6: held, 7: refused */ __( 'Since activation: %1$d allowed, %2$d flagged, %3$d blocked, %4$d without a clear answer from the service (%5$d of them passed the fallback check). On this site: %6$d held for review, %7$d refused.', 'formexposed' ), $s['allow'], $s['flag'], $s['block'], $s['unreachable'], $s['fallback'], $s['held'], $s['rejected'] ) ); ?>
					<a href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'quarantine' ) ) ); ?>"><?php esc_html_e( 'Open the quarantine', 'formexposed' ); ?></a></p>
					<p><strong><?php esc_html_e( 'Fallback check:', 'formexposed' ); ?></strong>
					<?php
					if ( self::fallback_ready() ) {
						esc_html_e( 'ready - the protection layer with the proof of work is on. When the service gives no clear answer, a message that passed it is delivered.', 'formexposed' );
					} else {
						esc_html_e( 'not available - when the service gives no clear answer, messages are held or refused. Switch on the protection layer and the proof of work on the Overview tab to have an independent second check.', 'formexposed' );
					}
					?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'formexposed_protect_test' ); ?>
						<input type="hidden" name="action" value="formexposed_protect_test">
						<p><button class="button"><?php esc_html_e( 'Test the connection', 'formexposed' ); ?></button>
						<span class="description"><?php esc_html_e( 'Asks the service whether it answers and accepts the secret key. Counts as one refused test request in the statistics of your account.', 'formexposed' ); ?></span></p>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( Formexposed_Core::linked() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'formexposed_protect_connect' ); ?>
				<input type="hidden" name="action" value="formexposed_protect_connect">
				<p><button class="button button-primary"><?php esc_html_e( 'Get keys from my formexposed account', 'formexposed' ); ?></button>
				<span class="description"><?php esc_html_e( 'Creates the protected site in your account (or a new secret key for it) and fills in the fields below.', 'formexposed' ); ?></span></p>
			</form>
			<?php else : ?>
			<p class="description"><?php esc_html_e( 'Tip: connect this site to a formexposed account on the Overview tab - then one click fetches the keys.', 'formexposed' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'formexposed_protect_save' ); ?>
				<input type="hidden" name="action" value="formexposed_protect_save">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="fep-site"><?php esc_html_e( 'Site key', 'formexposed' ); ?></label></th>
						<td><input id="fep-site" name="site_key" class="regular-text code" value="<?php echo esc_attr( $o['site_key'] ); ?>" autocomplete="off" spellcheck="false">
						<p class="description"><?php esc_html_e( 'Public, printed in the form. Starts with fcp_pub_.', 'formexposed' ); ?></p></td></tr>
					<tr><th scope="row"><label for="fep-secret"><?php esc_html_e( 'Secret key', 'formexposed' ); ?></label></th>
						<td><input id="fep-secret" name="secret" type="password" class="regular-text code" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $o['secret'] ? esc_attr__( 'saved - leave empty to keep', 'formexposed' ) : ''; ?>">
						<?php if ( $o['secret'] ) : ?>
							<label style="display:block;margin-top:6px"><input type="checkbox" name="remove_keys" value="1"> <?php esc_html_e( 'Remove both keys (switches the hosted protection off)', 'formexposed' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Only this server uses it, when it asks the service about a submission. It is never printed on your pages.', 'formexposed' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Protect', 'formexposed' ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Protect', 'formexposed' ); ?></legend>
						<?php foreach ( self::ADAPTERS as $a ) : ?>
							<label style="display:block;margin-bottom:4px"><input type="checkbox" name="adapters[]" value="<?php echo esc_attr( $a ); ?>" <?php checked( in_array( $a, $o['adapters'], true ) ); ?>> <?php echo esc_html( $labels[ $a ] ); ?>
							<?php if ( ! self::available( $a ) ) : ?>
								<span class="description">- <?php echo 'register' === $a ? esc_html__( 'registration is closed on this site', 'formexposed' ) : esc_html__( 'not installed', 'formexposed' ); ?></span>
							<?php elseif ( 'login' === $a ) : ?>
								<span class="description">- <?php esc_html_e( 'only "block" stops a login with the correct password; when the service gives no clear answer, the password decides', 'formexposed' ); ?></span>
							<?php elseif ( 'gravityforms' === $a ) : ?>
								<span class="description">- <?php esc_html_e( 'messages that are not passed are filed under Entries -> Spam', 'formexposed' ); ?></span>
							<?php endif; ?>
							</label>
						<?php endforeach; ?>
						</fieldset></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Message text', 'formexposed' ); ?></th>
						<td><label><input type="checkbox" name="content" value="1" <?php checked( $o['content'] ); ?>> <?php esc_html_e( 'Send the message text along for the content check (spam filter in every plan, AI check where your plan includes it). Checked in memory, not stored.', 'formexposed' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Not a clear pass', 'formexposed' ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Not a clear pass', 'formexposed' ); ?></legend>
						<p class="description" style="margin-bottom:8px"><?php esc_html_e( 'When the service flags a message as suspicious, or gives no clear answer and no fallback check passed:', 'formexposed' ); ?></p>
						<label style="display:block;margin-bottom:4px"><input type="radio" name="uncertain" value="hold" <?php checked( 'hold', $o['uncertain'] ); ?>> <strong><?php esc_html_e( 'Hold for review', 'formexposed' ); ?></strong> - <?php esc_html_e( 'the message waits in the quarantine (14 days) and is delivered with one click; comments wait for moderation. Contact Form 7 and WPForms show the visitor the normal confirmation; Elementor, Fluent Forms and Formidable cannot hold a message back, there the visitor sees an error.', 'formexposed' ); ?></label>
						<label style="display:block"><input type="radio" name="uncertain" value="reject" <?php checked( 'reject', $o['uncertain'] ); ?>> <strong><?php esc_html_e( 'Refuse', 'formexposed' ); ?></strong> - <?php esc_html_e( 'the visitor sees an error and can try again; comments go to spam. A copy still waits in the quarantine.', 'formexposed' ); ?></label>
						<p class="description" style="margin-top:8px"><?php esc_html_e( 'Never: let it through unseen. Registrations are always refused in that case; logins are decided by the password.', 'formexposed' ); ?></p>
						</fieldset></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function action_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_protect_save' );
		$o        = self::opt();
		$site_key = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : '';
		$secret   = isset( $_POST['secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) ) : '';
		$o['site_key'] = preg_replace( '/[^a-z0-9_]/', '', strtolower( $site_key ) );
		if ( '' !== $secret ) {
			$o['secret'] = $secret;
		}
		if ( ! empty( $_POST['remove_keys'] ) ) {
			$o['site_key'] = '';
			$o['secret']   = '';
		}
		$adapters      = isset( $_POST['adapters'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['adapters'] ) ) : array();
		$o['adapters'] = array_values( array_intersect( self::ADAPTERS, $adapters ) );
		$o['content']  = ! empty( $_POST['content'] );
		$uncertain     = isset( $_POST['uncertain'] ) ? sanitize_key( wp_unslash( $_POST['uncertain'] ) ) : 'hold';
		$o['uncertain'] = 'reject' === $uncertain ? 'reject' : 'hold';
		$neu_key       = '' !== $o['site_key'] && '' !== $o['secret'] && ! self::on();
		self::save_opt( $o );
		if ( $neu_key || '' === $o['site_key'] ) {
			Formexposed_Pow::purge_page_caches();   // the forms change: cached pages would still show the old ones
		}
		$warn = '' !== $o['site_key'] && ! preg_match( '/^[a-z]{2,8}_pub_[a-f0-9]{24}$/', $o['site_key'] );
		set_transient( 'formexposed_msg', $warn ? array( 'warning', __( 'Saved. The site key does not look like a key of the service - please check it.', 'formexposed' ) ) : array( 'ok', __( 'Saved.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'protect' ) ) );
		exit;
	}

	/** "Test the connection": the health endpoint, then the secret key (an empty response is refused, the code tells why). */
	public static function action_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_protect_test' );
		$h = wp_remote_get( FORMEXPOSED_PROTECT_API . '/health', array( 'timeout' => self::TIMEOUT ) );
		if ( is_wp_error( $h ) || 200 !== (int) wp_remote_retrieve_response_code( $h ) ) {
			/* translators: %s: error message or HTTP status */
			$msg = array( 'error', sprintf( __( 'The service did not answer (%s). Messages are held or refused until it answers again, unless the fallback check passes.', 'formexposed' ), is_wp_error( $h ) ? $h->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $h ) ) );
		} else {
			$r = wp_remote_post( FORMEXPOSED_PROTECT_API . '/verify', array(
				'timeout' => self::TIMEOUT,
				'body'    => array( 'secret' => self::opt()['secret'], 'response' => '' ),
			) );
			$j     = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
			$codes = is_array( $j ) && isset( $j['error-codes'] ) ? array_filter( (array) $j['error-codes'], 'is_string' ) : array();
			if ( in_array( 'missing-input-response', $codes, true ) ) {
				$o          = self::opt();
				$o['ok_at'] = time();
				self::save_opt( $o );
				$msg = array( 'ok', __( 'Connected: the service answers and accepts the secret key.', 'formexposed' ) );
			} elseif ( $codes ) {
				$msg = array( 'error', self::problem_text( implode( ',', $codes ) ) );
			} else {
				$msg = array( 'error', __( 'The service gave an answer this plugin does not understand.', 'formexposed' ) );
			}
		}
		set_transient( 'formexposed_msg', $msg, 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'protect' ) ) );
		exit;
	}

	/** POST /api/plugin/protect with the connect secret: site and keys from the formexposed account. */
	public static function action_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_protect_connect' );
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/protect', array(
			'timeout' => 15,
			'body'    => array(
				'site'   => Formexposed_Core::host(),
				'key'    => Formexposed_Core::key(),
				'secret' => Formexposed_Core::secret(),
			),
		) );
		$j = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
		if ( is_array( $j ) && 'success' === ( isset( $j['status'] ) ? $j['status'] : '' ) && ! empty( $j['site_key'] ) && ! empty( $j['secret'] ) ) {
			$o             = self::opt();
			$o['site_key'] = preg_replace( '/[^a-z0-9_]/', '', strtolower( sanitize_text_field( (string) $j['site_key'] ) ) );
			$o['secret']   = sanitize_text_field( (string) $j['secret'] );
			self::save_opt( $o );
			Formexposed_Pow::purge_page_caches();
			set_transient( 'formexposed_msg', array( 'ok', __( 'Connected. The forms are protected now.', 'formexposed' ) ), 120 );
		} else {
			$m = is_array( $j ) && ! empty( $j['message'] ) ? sanitize_text_field( (string) $j['message'] ) : ( is_wp_error( $r ) ? $r->get_error_message() : __( 'The service did not answer.', 'formexposed' ) );
			set_transient( 'formexposed_msg', array( 'error', $m ), 120 );
		}
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'protect' ) ) );
		exit;
	}

	/** Paragraph for the privacy policy guide while the hosted protection is on. */
	public static function privacy_paragraph() {
		if ( ! self::on() ) {
			return '';
		}
		return sprintf(
			/* translators: %s: product name of the hosted form protection */
			__( 'Our forms are protected by %s, a service of LW IT Solutions (formexposed) with servers in the EU. On pages with a protected form your browser loads a script of the service and solves a small calculation task; no cookies are set and nothing is stored on your device. When you send the form, this website sends the result, your IP address, the e-mail address entered in the form and - if enabled - the message text to the service, which checks them for spam and returns a decision. The message text is checked in memory and not stored; the IP address is only kept shortened and hashed. If the check cannot be completed, your message is held for review or refused, not delivered unchecked.', 'formexposed' ),
			FORMEXPOSED_PROTECT_NAME
		);
	}
}
