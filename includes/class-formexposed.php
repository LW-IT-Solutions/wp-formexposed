<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formexposed - the plugin, in one class.
 *
 * Options (all prefixed formexposed_, all removed by uninstall.php):
 *   formexposed_key          public key, printed as <meta name="formexposed-verify">.
 *                            Since 1.2.0 a hash of the secret (see key()).
 *   formexposed_secret       random, never printed; travels only server-to-server (since 1.4.0 no longer in the
 *                            connect link: that carries a one-time code, see connect_target())
 *   formexposed_last         the last check: token, status, score, level, missing[], report_url, checked_at
 *   formexposed_notice_off   timestamp until which the "exposed" notice stays hidden
 *   formexposed_linked       time of the last "Connect" or agency-code connection. Only then
 *                            the daily counts are sent (Formexposed_Stats).
 *   formexposed_rekey        set when 1.2.0 replaced an older key: the site has to connect once more
 *   formexposed_agency_code  an agency's rollout code (fxc_...) waiting to be used, e.g. set with WP-CLI
 *   formexposed_agency_done  hash of the code this site was connected with; formexposed_agency_msg: the last error
 *   formexposed_version      the version the options were last brought up to date for
 *   formexposed_stats        the daily counts (Formexposed_Stats)
 *   formexposed_swarm        the swarm list from the service (links, nets, at); formexposed_swarm_off: not taking part
 *   formexposed_versions, formexposed_changes_queue   what Formexposed_Watch compares and still has to send
 *   formexposed_q_db, formexposed_q_recipient         quarantine table version, address for released messages
 * Since 1.4.0:
 *   formexposed_r_db, formexposed_rescue_off          rescue table version; set = keep no failed form e-mails
 *   formexposed_mailfail_at, formexposed_mailfail_last  last report of a failed form e-mail and the answer
 *   formexposed_watch_slugs, formexposed_health       the service's list that can only narrow the built-in watch list; the last form check
 *   formexposed_pow, formexposed_recaptcha_backup     proof of work on/off; reCAPTCHA settings before the move
 *   formexposed_retention, formexposed_retention_log  storage limit in days; deletions per day
 *   formexposed_maint                                 maintenance state and what was reported
 * Table {prefix}formexposed_quarantine: stopped submissions, 14 days (Formexposed_Quarantine).
 * Table {prefix}formexposed_rescue: form e-mails that could not be sent, 30 days (Formexposed_Rescue).
 *
 * Flow: Settings -> formexposed -> "Check now" (admin-post, nonce) -> POST
 * FORMEXPOSED_API/api/plugin/check -> token. The page then polls
 * admin-ajax (formexposed_status, nonce) every 5 s; each poll asks the API
 * for /api/plugin/status and stores the answer. No external script, no
 * external style - everything the admin loads comes from this site.
 *
 * Since 1.2.0 a daily WP-Cron event (formexposed_daily) uses a waiting agency
 * code and, only for connected sites, sends the counts of the last 14 days.
 * Since 1.3.0 the answer carries the swarm list; the same event compares the
 * versions of plugins/theme/core and tidies the quarantine.
 * Since 1.4.0 the event also tidies the rescued enquiries, the counts carry
 * the inventory, the form check (counts only) and the storage overview, and
 * the storage limit runs last. An hourly event (formexposed_hourly) reports a
 * maintenance mode.
 */

class Formexposed_Core {

	const OPT_KEY     = 'formexposed_key';
	const OPT_LAST    = 'formexposed_last';
	const OPT_NOTICE  = 'formexposed_notice_off';
	const OPT_SECRET  = 'formexposed_secret';   // never printed anywhere; travels only server-to-server
	const OPT_LINKED  = 'formexposed_linked';
	const OPT_PENDING = 'formexposed_link_pending';   // 1.5.1: Connect clicked, waiting for formexposed.com to confirm
	const OPT_REKEY   = 'formexposed_rekey';
	const OPT_CODE    = 'formexposed_agency_code';
	const OPT_CODE_OK = 'formexposed_agency_done';
	const OPT_CODE_MSG = 'formexposed_agency_msg';
	const OPT_VERSION = 'formexposed_version';
	const CRON        = 'formexposed_daily';
	const CODE_RE     = '/^fxc_[a-f0-9]{24}$/';

	/** The answer of the last claim, for the admin message and WP-CLI. */
	public static $claim_result = null;

	/** Activation - and after every update, which does not run the activation hook. */
	public static function activate() {
		self::secret();
		self::key();
		Formexposed_Quarantine::install();
		Formexposed_Rescue::install();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + wp_rand( 600, DAY_IN_SECONDS ), 'daily', self::CRON );
		}
		if ( ! wp_next_scheduled( Formexposed_Maintenance::CRON ) ) {
			wp_schedule_event( time() + wp_rand( 60, HOUR_IN_SECONDS ), 'hourly', Formexposed_Maintenance::CRON );
		}
		update_option( self::OPT_VERSION, FORMEXPOSED_VERSION, false );
	}

	/** Set while this plugin is being deactivated: nothing may schedule new events then (1.9.0). */
	public static $stopping = false;

	public static function deactivate() {
		self::$stopping = true;
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( Formexposed_Watch::CRON_CHANGES );
		wp_clear_scheduled_hook( Formexposed_Maintenance::CRON );
		wp_clear_scheduled_hook( Formexposed_Maintenance::CRON_NOW );
		wp_unschedule_hook( Formexposed_Rescue::CRON );   // single events with arguments
	}

	public static function init() {
		if ( get_option( self::OPT_VERSION ) !== FORMEXPOSED_VERSION ) {
			self::activate();
		}
		add_action( 'wp_head', array( __CLASS__, 'meta_tag' ), 1 );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		// A rollout code set from outside (wp option update/add, WP-CLI, a deployment script) is used at once.
		add_action( 'add_option_' . self::OPT_CODE, array( __CLASS__, 'code_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPT_CODE, array( __CLASS__, 'code_updated' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_post_formexposed_check', array( __CLASS__, 'action_check' ) );
			add_action( 'admin_post_formexposed_dismiss', array( __CLASS__, 'action_dismiss' ) );
			add_action( 'admin_post_formexposed_connect', array( __CLASS__, 'action_connect' ) );
			add_action( 'admin_post_formexposed_guard_on', array( __CLASS__, 'action_guard_on' ) );
			add_action( 'admin_post_formexposed_guard_off', array( __CLASS__, 'action_guard_off' ) );
			add_action( 'admin_post_formexposed_testkey', array( __CLASS__, 'action_testkey' ) );
			add_action( 'admin_post_formexposed_agency', array( __CLASS__, 'action_agency' ) );
			add_action( 'admin_post_formexposed_swarm', array( __CLASS__, 'action_swarm' ) );
			add_action( 'wp_ajax_formexposed_status', array( __CLASS__, 'ajax_status' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( FORMEXPOSED_FILE ), array( __CLASS__, 'action_links' ) );
		}
	}

	// ------------------------------------------------------------------ data

	public static function derive_key( $secret ) {
		return substr( hash( 'sha256', 'formexposed-verify|' . $secret ), 0, 32 );
	}

	/**
	 * The public key. Since 1.2.0 it is a hash of the secret: formexposed.com
	 * accepts a connection only from whoever knows the secret behind the key -
	 * this site. Before, key and secret were unrelated, and anyone who read the
	 * key from the page could have registered a secret of their own. An older
	 * key is replaced once; a site that was connected then connects again.
	 */
	public static function key() {
		$k    = (string) get_option( self::OPT_KEY );
		$soll = self::derive_key( self::secret() );
		if ( $k !== $soll ) {
			if ( '' !== $k ) {
				update_option( self::OPT_REKEY, time(), false );
			}
			update_option( self::OPT_KEY, $soll, false );
			$k = $soll;
		}
		return $k;
	}

	public static function secret() {
		$s = (string) get_option( self::OPT_SECRET );
		if ( ! preg_match( '/^[a-f0-9]{32,64}$/', $s ) ) {
			$s = bin2hex( random_bytes( 16 ) );
			update_option( self::OPT_SECRET, $s, false );
		}
		return $s;
	}

	public static function host() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public static function last() {
		$l = get_option( self::OPT_LAST );
		return is_array( $l ) ? $l : array();
	}

	/** Connected (this site's view): a Connect click or an agency code. The server has the final word. */
	public static function linked() {
		return (int) get_option( self::OPT_LINKED, 0 ) > 0;
	}

	private static function set_linked() {
		update_option( self::OPT_LINKED, time(), false );
		delete_option( self::OPT_REKEY );
		delete_option( self::OPT_PENDING );
	}

	/**
	 * 1.5.1: "connected" only once formexposed.com says so. After a Connect click the plugin asks
	 * (at most every 10 minutes, for 7 days) - before that nothing is sent, as the readme promises.
	 */
	public static function link_check() {
		if ( self::linked() ) {
			return true;
		}
		$seit = (int) get_option( self::OPT_PENDING, 0 );
		if ( $seit <= 0 || time() - $seit > 7 * DAY_IN_SECONDS || get_transient( 'formexposed_link_check' ) ) {
			return false;
		}
		set_transient( 'formexposed_link_check', 1, 10 * MINUTE_IN_SECONDS );
		self::api_plan();   // sets "connected" when the server confirms
		return self::linked();
	}

	/** Show "connect again" for 30 days after the key was renewed. */
	public static function rekey_pending() {
		$t = (int) get_option( self::OPT_REKEY, 0 );
		return $t > 0 && time() - $t < 30 * DAY_IN_SECONDS;
	}

	/**
	 * Where "Connect" sends the admin (since 1.4.0): the one-time link that
	 * POST /api/plugin/register hands out - /connect?t=<32 hex>, valid 15
	 * minutes, usable once, and bound on the server to the secret registered
	 * in the same request. The public meta key alone must not be enough to
	 * connect a site (anyone can read it), and the secret no longer travels in
	 * the browser.
	 *
	 * Only a link to our own /connect?t= followed by the code is used - a link
	 * to another host, or anything after the code, is refused (no open
	 * redirect); then the link is built from connect_token. A code that is
	 * there but broken is an error, never a reason to fall back. Only a server
	 * that answers without any code (before 27.09.2026) gets the old link,
	 * legacy_connect_url(). Returns the URL or WP_Error.
	 */
	public static function connect_target( $r ) {
		$basis = untrailingslashit( FORMEXPOSED_API ) . '/connect?t=';
		$code  = '/^[a-f0-9]{32}$/';
		$url   = is_array( $r ) && isset( $r['connect_url'] ) && is_string( $r['connect_url'] ) ? $r['connect_url'] : '';
		if ( '' !== $url && 0 === strpos( $url, $basis ) && preg_match( $code, (string) substr( $url, strlen( $basis ) ) ) ) {
			return $url;
		}
		$token = is_array( $r ) && isset( $r['connect_token'] ) && is_string( $r['connect_token'] ) ? $r['connect_token'] : '';
		if ( preg_match( $code, $token ) ) {
			return $basis . $token;
		}
		if ( is_array( $r ) && ( array_key_exists( 'connect_token', $r ) || array_key_exists( 'connect_url', $r ) ) ) {
			return new WP_Error( 'connect_code', __( 'formexposed.com gave an unexpected answer. Please try again later.', 'formexposed' ) );
		}
		// 1.5.1: no fallback any more to the old link that carried the secret in the browser address
		return new WP_Error( 'connect_code', __( 'formexposed.com gave an unexpected answer. Please try again later.', 'formexposed' ) );
	}

	/** The admin button: register the secret server-to-server, then go to /connect. */
	public static function connect_action_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_connect' ), 'formexposed_connect' );
	}

	/** POST /api/plugin/register: proves the site, stores the secret's hash and (since 1.4.0) hands out the one-time connect code. */
	public static function api_register() {
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/register', array(
			'timeout' => 25,
			'body'    => array( 'site' => self::host(), 'key' => self::key(), 'secret' => self::secret(), 'plugin_version' => FORMEXPOSED_VERSION, 'wp_version' => get_bloginfo( 'version' ) ),
		) );
		return self::api_antwort( $r );
	}

	public static function action_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_connect' );
		$r    = self::api_register();
		$ziel = is_wp_error( $r ) ? $r : self::connect_target( $r );
		if ( is_wp_error( $ziel ) ) {
			set_transient( 'formexposed_msg', array( 'error', $ziel->get_error_message() ), 120 );
			wp_safe_redirect( self::page_url() );
			exit;
		}
		update_option( self::OPT_PENDING, time(), false );   // 1.5.1: "connected" only after formexposed.com confirms (link_check)
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'api_host' ) );   // only our own FORMEXPOSED_API/connect?t=<code>, see connect_target()
		wp_safe_redirect( $ziel );
		exit;
	}

	/** allowed_redirect_hosts, only for the redirect to /connect: the host of FORMEXPOSED_API. */
	public static function api_host( $hosts ) {
		$h = wp_parse_url( FORMEXPOSED_API, PHP_URL_HOST );
		if ( is_string( $h ) && '' !== $h ) {
			$hosts[] = $h;
		}
		return $hosts;
	}

	// ------------------------------------------------------------------ agency code

	/**
	 * POST /api/plugin/claim: connect this site to an agency's workspace with a
	 * rollout code - no login in a browser, so it works for many sites at once
	 * (WP-CLI). The code is used once and then forgotten; only its hash stays.
	 * Returns array(host, workspace, verified) or WP_Error.
	 */
	public static function claim( $code ) {
		$r = self::api_antwort( wp_remote_post( FORMEXPOSED_API . '/api/plugin/claim', array(
			'timeout' => 25,
			'body'    => array( 'site' => self::host(), 'key' => self::key(), 'secret' => self::secret(), 'code' => $code, 'plugin_version' => FORMEXPOSED_VERSION, 'wp_version' => get_bloginfo( 'version' ) ),
		) ) );
		if ( is_wp_error( $r ) ) {
			update_option( self::OPT_CODE_MSG, array( time(), $r->get_error_message() ), false );
			if ( in_array( $r->get_error_code(), array( 'code_invalid', 'code_plan' ), true ) ) {
				delete_option( self::OPT_CODE );   // trying again tomorrow would not help
			}
		} else {
			self::set_linked();
			update_option( self::OPT_CODE_OK, hash( 'sha256', $code ), false );
			delete_option( self::OPT_CODE_MSG );
			delete_option( self::OPT_CODE );
		}
		self::$claim_result = $r;
		return $r;
	}

	public static function code_added( $option, $value ) {
		self::code_pending( $value );
	}

	public static function code_updated( $old, $value ) {
		self::code_pending( $value );
	}

	private static function code_pending( $value ) {
		$code = strtolower( trim( (string) $value ) );
		if ( preg_match( self::CODE_RE, $code ) ) {
			self::claim( $code );
		}
	}

	public static function action_agency() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_agency' );
		$code = isset( $_POST['formexposed_code'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['formexposed_code'] ) ) ) ) : '';
		if ( ! preg_match( self::CODE_RE, $code ) ) {
			set_transient( 'formexposed_msg', array( 'error', __( 'This is not a formexposed agency code. It starts with fxc_ and has 28 characters.', 'formexposed' ) ), 120 );
		} else {
			self::$claim_result = null;
			if ( get_option( self::OPT_CODE ) === $code ) {
				self::claim( $code );   // same value: update_option would not fire its hook
			} else {
				update_option( self::OPT_CODE, $code, false );
			}
			$r = self::$claim_result;
			if ( is_wp_error( $r ) ) {
				set_transient( 'formexposed_msg', array( 'error', $r->get_error_message() ), 120 );
			} elseif ( is_array( $r ) ) {
				/* translators: %s: name of the agency's workspace */
				set_transient( 'formexposed_msg', array( 'ok', sprintf( __( 'Connected to "%s". Your agency now sees this site and its protection.', 'formexposed' ), isset( $r['workspace'] ) ? sanitize_text_field( (string) $r['workspace'] ) : '' ) ), 120 );
			}
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Take part in the swarm protection or not (settings page). */
	public static function action_swarm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_swarm' );
		if ( ! empty( $_POST['swarm_on'] ) ) {
			delete_option( 'formexposed_swarm_off' );
		} else {
			update_option( 'formexposed_swarm_off', 1, false );
			delete_option( 'formexposed_swarm' );
		}
		set_transient( 'formexposed_msg', array( 'ok', __( 'Saved.', 'formexposed' ) ), 120 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	// ------------------------------------------------------------------ daily

	/**
	 * WP-Cron, once a day: a waiting agency code; the quick clean-up of the
	 * quarantine and the rescued enquiries; the counts with the form check -
	 * only while connected; the changes; and last the storage limit, which may
	 * have a long backlog to delete (it stops after a time budget and goes on
	 * the next day) - so it can never keep the counts from going out. What it
	 * deletes today is in tomorrow's counts.
	 */
	public static function daily() {
		$code = strtolower( trim( (string) get_option( self::OPT_CODE ) ) );
		if ( preg_match( self::CODE_RE, $code ) ) {
			self::claim( $code );
		}
		Formexposed_Filters::candidate(); // expires the local observation candidate and its counters
		Formexposed_Quarantine::purge();
		Formexposed_Rescue::purge();
		if ( self::link_check() ) {
			$r = self::api_stats();
			if ( is_wp_error( $r ) && in_array( $r->get_error_code(), array( 'not_connected', 'con_no_secret' ), true ) ) {
				delete_option( self::OPT_LINKED );   // no longer in any account: stop sending until the next Connect
				delete_option( 'formexposed_swarm' );
			}
		}
		Formexposed_Watch::changes( false );   // also catches updates made outside WordPress (FTP, deploys)
		Formexposed_Retention::run();
	}

	/**
	 * POST /api/plugin/stats: the counts of the last 14 days. Numbers only.
	 * Since 1.4.0 also: inventory ({slug: version} of the watched plugins),
	 * config (counts of the form check, no names) and retention (stored
	 * enquiries per source, numbers). $what 'inventory': only the days and the
	 * inventory (after a plugin update). The answer brings the watch list.
	 */
	public static function api_stats( $what = 'all' ) {
		$body = array(
			'site'           => self::host(),
			'key'            => self::key(),
			'secret'         => self::secret(),
			'plugin_version' => FORMEXPOSED_VERSION,
			'days'           => wp_json_encode( Formexposed_Stats::days() ),
			'forms'          => wp_json_encode( Formexposed_Form_Metrics::days() ),
			'swarm'          => get_option( 'formexposed_swarm_off' ) ? '0' : '1',
			'inventory'      => wp_json_encode( (object) Formexposed_Health::inventory() ),
		);
		if ( 'all' === $what ) {
			$body['config']    = wp_json_encode( Formexposed_Health::payload( Formexposed_Health::scan() ) );
			$body['retention'] = wp_json_encode( Formexposed_Retention::payload() );
		}
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/stats', array(
			'timeout' => 20,
			'body'    => $body,
		) );
		$r = self::api_antwort( $r );
		if ( is_array( $r ) ) {
			// Can only narrow the built-in watch list; none or an empty one = the built-in list (Formexposed_Health::slugs).
			Formexposed_Health::set_slugs( isset( $r['watch_slugs'] ) && is_array( $r['watch_slugs'] ) ? $r['watch_slugs'] : array() );
		}
		if ( is_array( $r ) && isset( $r['swarm'] ) && is_array( $r['swarm'] ) ) {
			$links = isset( $r['swarm']['links'] ) && is_array( $r['swarm']['links'] ) ? array_values( array_filter( array_map( 'strval', $r['swarm']['links'] ), static function ( $d ) { return (bool) preg_match( '/^[a-z0-9.-]+\.[a-z]{2,24}$/', $d ); } ) ) : array();
			$nets  = isset( $r['swarm']['nets'] ) && is_array( $r['swarm']['nets'] ) ? array_values( array_filter( array_map( 'strval', $r['swarm']['nets'] ), static function ( $n ) { return (bool) preg_match( '~^[0-9a-f:.]+/(24|48)$~', $n ); } ) ) : array();
			update_option( 'formexposed_swarm', array( 'links' => array_slice( $links, 0, 5000 ), 'nets' => array_slice( $nets, 0, 5000 ), 'at' => time() ), false );
		}
		return $r;
	}

	/** POST /api/plugin/changes: what changed on this site (names and versions). Only while connected. */
	public static function api_changes( $changes ) {
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/changes', array(
			'timeout' => 15,
			'body'    => array( 'site' => self::host(), 'key' => self::key(), 'secret' => self::secret(), 'plugin_version' => FORMEXPOSED_VERSION, 'changes' => wp_json_encode( array_values( $changes ) ) ),
		) );
		return self::api_antwort( $r );
	}

	/** POST /api/plugin/mailfail: a form's e-mail just failed (kind of error, whether the enquiry was kept). Since 1.4.0. */
	public static function api_mailfail( $kind, $rescued ) {
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/mailfail', array(
			'timeout' => 15,
			'body'    => array(
				'site'    => self::host(),
				'key'     => self::key(),
				'secret'  => self::secret(),
				'kind'    => in_array( $kind, array( 'smtp_connect', 'smtp_auth', 'recipient', 'other' ), true ) ? $kind : 'other',
				'rescued' => $rescued ? '1' : '0',
			),
		) );
		return self::api_antwort( $r );
	}

	/** POST /api/plugin/maintenance: state on (every hour while it lasts) or off (once). Since 1.4.0. */
	public static function api_maintenance( $on ) {
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/maintenance', array(
			'timeout' => 15,
			'body'    => array( 'site' => self::host(), 'key' => self::key(), 'secret' => self::secret(), 'state' => $on ? 'on' : 'off' ),
		) );
		return self::api_antwort( $r );
	}

	// ------------------------------------------------------------------ front

	/** The proof: this site shows its key. Public by design; it is not a secret. */
	public static function meta_tag() {
		echo '<meta name="formexposed-verify" content="' . esc_attr( self::key() ) . '">' . "\n";
	}

	// ------------------------------------------------------------------ api

	/** POST /api/plugin/check. Returns array(token, report_url, existing) or WP_Error. */
	public static function api_check() {
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/check', array(
			'timeout' => 25,
			'body'    => array(
				'site'           => self::host(),
				'key'            => self::key(),
				'secret'         => self::secret(),
				'plugin_version' => FORMEXPOSED_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'lang'           => get_locale(),
			),
		) );
		return self::api_antwort( $r );
	}

	/**
	 * POST /api/plugin/plan: is this site connected, and on which plan? (Since 1.5.1 only to confirm the connection after Connect.)
	 * Carries the secret, so only this site can ask. Returns array(connected, plan, fix_allowed) or WP_Error.
	 */
	public static function api_plan() {
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/plan', array(
			'timeout' => 15,
			'body'    => array( 'site' => self::host(), 'key' => self::key(), 'secret' => self::secret() ),
		) );
		$r = self::api_antwort( $r );
		if ( is_array( $r ) && ! empty( $r['connected'] ) && ! self::linked() ) {
			self::set_linked();   // connected in the browser (/connect): from now on the daily counts go too
		}
		return $r;
	}

	/**
	 * Shared gate of the "Fix it for me" actions: capability and nonce. Since 1.5.1 the protection
	 * layer is free for every site - it runs on this server only, so no plan and no account is asked.
	 */
	private static function fix_gate( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( $nonce );
		return true;
	}

	/**
	 * Message after a fix. Since 1.10.2 a fix never contacts formexposed.com by itself: a new check runs
	 * only when the administrator clicks "Check now" / "Check again", as the readme says.
	 */
	private static function message_after_fix( $text ) {
		set_transient( 'formexposed_msg', array( 'ok', $text . ' ' . __( 'To confirm it, run a new check with "Check now" or "Check again".', 'formexposed' ) ), 120 );
	}

	public static function action_guard_on() {
		if ( self::fix_gate( 'formexposed_guard_on' ) ) {
			if ( ! Formexposed_Guard::plugins() ) {
				set_transient( 'formexposed_msg', array( 'error', __( 'The one-click protection works with Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable, Ninja Forms and WooCommerce. None of them is active on this site.', 'formexposed' ) ), 120 );
			} else {
				Formexposed_Guard::set( true );
				self::message_after_fix( __( 'Protection is on.', 'formexposed' ) );
			}
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Turning it off needs no plan - it is always the owner's choice. */
	public static function action_guard_off() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_guard_off' );
		Formexposed_Guard::set( false );
		set_transient( 'formexposed_msg', array( 'ok', __( 'Protection is off.', 'formexposed' ) ), 120 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function action_testkey() {
		if ( self::fix_gate( 'formexposed_testkey' ) ) {
			Formexposed_Guard::cf7_remove_test_key();
			if ( Formexposed_Guard::cf7() ) {
				Formexposed_Guard::set( true );
			}
			self::message_after_fix( __( 'The reCAPTCHA test key is removed and the protection layer is on. Add your own reCAPTCHA keys under Contact -> Integration if you want reCAPTCHA as well.', 'formexposed' ) );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** GET /api/plugin/status. */
	public static function api_status( $token ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
			return new WP_Error( 'token', __( 'No check has been started yet.', 'formexposed' ) );
		}
		$r = wp_remote_get( FORMEXPOSED_API . '/api/plugin/status?' . http_build_query( array( 'token' => $token, 'lang' => get_locale() ) ), array( 'timeout' => 15 ) );
		return self::api_antwort( $r );
	}

	private static function api_antwort( $r ) {
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'http', sprintf( /* translators: %s: error text */ __( 'formexposed.com could not be reached: %s', 'formexposed' ), $r->get_error_message() ) );
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $j ) ) {
			return new WP_Error( 'json', __( 'formexposed.com gave an unexpected answer. Please try again later.', 'formexposed' ) );
		}
		if ( isset( $j['status'] ) && in_array( $j['status'], array( 'error', 'unknown' ), true ) ) {
			return new WP_Error( isset( $j['code'] ) ? sanitize_key( (string) $j['code'] ) : 'api', isset( $j['message'] ) ? sanitize_text_field( (string) $j['message'] ) : __( 'The check was refused.', 'formexposed' ) );
		}
		return $j;
	}

	/** Ask the API for the state of the last check and remember it. */
	public static function refresh() {
		$last = self::last();
		if ( empty( $last['token'] ) ) {
			return $last;
		}
		$s = self::api_status( $last['token'] );
		if ( is_wp_error( $s ) ) {
			$last['error'] = $s->get_error_message();
			update_option( self::OPT_LAST, $last, false );
			return $last;
		}
		$neu = array(
			'token'      => $last['token'],
			'started_at' => isset( $last['started_at'] ) ? $last['started_at'] : time(),
			'status'     => isset( $s['status'] ) ? sanitize_key( (string) $s['status'] ) : 'unknown',
			'report_url' => isset( $s['report_url'] ) ? esc_url_raw( (string) $s['report_url'] ) : ( isset( $last['report_url'] ) ? $last['report_url'] : '' ),
			'reason'     => isset( $s['grund'] ) ? sanitize_text_field( (string) $s['grund'] ) : '',
			'checked_at' => time(),
		);
		if ( 'done' === $neu['status'] ) {
			$neu['score']   = isset( $s['score'] ) ? (int) $s['score'] : null;
			$neu['level']   = isset( $s['level'] ) ? sanitize_key( (string) $s['level'] ) : 'noform';
			$neu['forms']   = isset( $s['forms'] ) ? (int) $s['forms'] : 0;
			$neu['pages']   = isset( $s['pages'] ) ? (int) $s['pages'] : 0;
			$neu['cms']     = isset( $s['cms'] ) ? sanitize_text_field( (string) $s['cms'] ) : '';
			$neu['missing'] = isset( $s['missing'] ) && is_array( $s['missing'] ) ? array_values( array_map( array( __CLASS__, 'clean_missing' ), $s['missing'] ) ) : array();
			$neu['passed']  = isset( $s['passed'] ) && is_array( $s['passed'] ) ? array_map( 'sanitize_key', $s['passed'] ) : array();
		}
		update_option( self::OPT_LAST, $neu, false );
		return $neu;
	}

	private static function clean_missing( $m ) {
		return array(
			'key'     => isset( $m['key'] ) ? sanitize_key( $m['key'] ) : '',
			'title'   => isset( $m['title'] ) ? sanitize_text_field( $m['title'] ) : '',
			'general' => isset( $m['general'] ) ? sanitize_text_field( $m['general'] ) : '',
			'cms'     => isset( $m['cms'] ) ? sanitize_text_field( $m['cms'] ) : '',
		);
	}

	// ------------------------------------------------------------------ admin

	public static function menu() {
		add_options_page( 'formexposed', 'formexposed', 'manage_options', 'formexposed', array( __CLASS__, 'page' ) );
	}

	/** assets/js/admin.js on our settings page only; it polls while the page shows a running check (#formexposed-wait). */
	public static function admin_assets( $hook ) {
		if ( 'settings_page_formexposed' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'formexposed-admin', plugins_url( 'assets/js/admin.js', FORMEXPOSED_FILE ), array(), FORMEXPOSED_VERSION, true );
		wp_add_inline_script( 'formexposed-admin', 'window.formexposedAdmin = ' . wp_json_encode( array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'formexposed_status' ),
			'text'  => array(
				'cancel'  => __( 'Cancel', 'formexposed' ),
				'confirm' => __( 'Delete', 'formexposed' ),
			),
		) ) . ';', 'before' );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=formexposed' ) ) . '">' . esc_html__( 'Settings', 'formexposed' ) . '</a>' );
		return $links;
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'options-general.php?page=formexposed' ) );
	}

	/** "Check now": one POST to the API, then back to the page. */
	public static function action_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_check' );
		$r = self::api_check();
		if ( is_wp_error( $r ) ) {
			set_transient( 'formexposed_msg', array( 'error', $r->get_error_message() ), 120 );
		} else {
			update_option( self::OPT_LAST, array(
				'token'      => sanitize_text_field( (string) $r['token'] ),
				'status'     => 'queued',
				'report_url' => esc_url_raw( (string) $r['report_url'] ),
				'started_at' => time(),
				'checked_at' => time(),
			), false );
			set_transient( 'formexposed_msg', array( 'ok', ! empty( $r['existing'] ) ? __( 'This site was checked recently - showing that result.', 'formexposed' ) : __( 'Check started. The result appears here in a minute or two.', 'formexposed' ) ), 120 );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function action_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_dismiss' );
		update_option( self::OPT_NOTICE, time() + 7 * DAY_IN_SECONDS, false );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/** Polled by the page while a check runs. */
	public static function ajax_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'not allowed' ), 403 );
		}
		check_ajax_referer( 'formexposed_status', 'nonce' );
		$l = self::refresh();
		wp_send_json_success( array(
			'status' => isset( $l['status'] ) ? $l['status'] : 'unknown',
			'error'  => isset( $l['error'] ) ? $l['error'] : '',
		) );
	}

	/** Notice on the dashboard and the plugins page when the last check said "exposed" (hidden for a week on request). */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$l = self::last();
		if ( empty( $l['status'] ) || 'done' !== $l['status'] || empty( $l['level'] ) || 'exposed' !== $l['level'] ) {
			return;
		}
		if ( (int) get_option( self::OPT_NOTICE, 0 ) > time() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>formexposed:</strong> ' . esc_html__( 'Your contact form is exposed to bots and spam.', 'formexposed' ) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'See what is missing', 'formexposed' ) . '</a> · <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_dismiss' ), 'formexposed_dismiss' ) ) . '">' . esc_html__( 'Hide for a week', 'formexposed' ) . '</a></p></div>';
	}

	/** The settings page. */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		$last = self::last();
		if ( ! empty( $last['token'] ) && in_array( isset( $last['status'] ) ? $last['status'] : '', array( 'queued', 'running' ), true ) ) {
			$last = self::refresh();
		}
		$msg   = get_transient( 'formexposed_msg' );
		delete_transient( 'formexposed_msg' );
		$tab   = self::tab();
		if ( 'protect' === $tab ) {
			Formexposed_Protect::page( $msg );
			return;
		}
		$views = array( 'quarantine' => 'admin-quarantine.php', 'rescue' => 'admin-rescue.php', 'forms' => 'admin-forms.php', 'storage' => 'admin-storage.php' );
		include __DIR__ . '/' . ( isset( $views[ $tab ] ) ? $views[ $tab ] : 'admin-page.php' );
	}

	/** The tab of the settings page that is shown (only selects the view, changes nothing). */
	public static function tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which tab is shown; nothing is changed.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return in_array( $tab, array( 'quarantine', 'rescue', 'forms', 'storage', 'protect' ), true ) ? $tab : '';
	}

	/** The tabs of the settings page (since 1.4.0). */
	public static function nav( $current ) {
		$tabs = array(
			''           => __( 'Overview', 'formexposed' ),
			'quarantine' => __( 'Quarantine', 'formexposed' ),
			'rescue'     => __( 'Rescued enquiries', 'formexposed' ),
			'forms'      => __( 'Form settings', 'formexposed' ),
			'storage'    => __( 'Stored enquiries', 'formexposed' ),
			'protect'    => __( 'Form protection', 'formexposed' ),
		);
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px">';
		foreach ( $tabs as $k => $label ) {
			$n = 'rescue' === $k ? Formexposed_Rescue::count_waiting() : 0;
			echo '<a class="nav-tab' . ( $k === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::page_url( '' === $k ? array() : array( 'tab' => $k ) ) ) . '">' . esc_html( $label ) . ( $n ? ' <span style="color:#b32d2e">(' . (int) $n . ')</span>' : '' ) . '</a>';
		}
		echo '</nav>';
	}

	/** Level -> colour, headline, sentence. One place. */
	public static function level_text( $level ) {
		switch ( $level ) {
			case 'exposed':
				return array( '#b32d2e', __( 'Exposed', 'formexposed' ), __( 'Bots can submit your contact form without any obstacle. Expect spam - and fix it with the guide below.', 'formexposed' ) );
			case 'partial':
				return array( '#996800', __( 'Partially protected', 'formexposed' ), __( 'Some protection is in place, but a determined bot gets through. The guide below shows what is missing.', 'formexposed' ) );
			case 'protected':
				return array( '#00750f', __( 'Protected', 'formexposed' ), __( 'Your contact form has the protection a bot would need to defeat. Keep it that way - monitoring tells you if it ever changes.', 'formexposed' ) );
			default:
				return array( '#50575e', __( 'No contact form found', 'formexposed' ), __( 'The check loaded your public pages and found no submittable contact form. If you have one on a page that is not linked from the start page, check that page directly on formexposed.com.', 'formexposed' ) );
		}
	}
}
