<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formexposed - rescue net for enquiries whose e-mail failed (since 1.4.0).
 *
 * When the e-mail a form sends to the site owner cannot be handed over (SMTP
 * login wrong, mail server down, no mail() on the server), the visitor often
 * still sees "thank you" - and the enquiry is gone. The rescue net keeps a
 * copy of exactly that e-mail in a table of THIS site
 * ({prefix}formexposed_rescue): recipients, subject, text and headers;
 * attachments only by file name (Contact Form 7 deletes uploaded files right
 * after sending). Settings -> formexposed -> Rescued enquiries lists them;
 * "Send again" hands the stored e-mail to wp_mail() once more and removes it
 * when that works.
 *
 * What counts as a form e-mail: a failure (wp_mail_failed) during a request in
 * which Contact Form 7, WPForms, Gravity Forms or Elementor Pro processed a
 * submission. Contact Form 7 names its templates: only "mail" is kept, never
 * the auto-reply "mail_2". For the others an e-mail that goes only to an
 * address the visitor typed in is an auto-reply and is not kept. Contact
 * Form 7 also reports its own failure (wpcf7_mail_failed) - that catches
 * mailers that replace wp_mail() without firing wp_mail_failed.
 *
 * The headers come from the wp_mail() call itself (filter wp_mail): the ones
 * in wp_mail_failed have lost From, Reply-To and Content-Type.
 *
 * Kept DAYS days, at most PER_DAY a day and MAX in total. While the site is
 * connected, formexposed.com is told (POST /api/plugin/mailfail: the kind of
 * the error and whether the enquiry was kept - no address, no content), at
 * most once an hour and never inside the visitor's request: a single WP-Cron
 * event runs right after it.
 */

// Own tables ({prefix}formexposed_*): WordPress has no API for them. Table names go through %i (WordPress 6.2+).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class Formexposed_Rescue {

	const DAYS       = 30;
	const PER_DAY    = 200;
	const MAX        = 2000;
	const DB_VERSION = '1';
	const OPT_DB     = 'formexposed_r_db';
	const OPT_OFF    = 'formexposed_rescue_off';      // set: do not keep failed form e-mails
	const OPT_SENT   = 'formexposed_mailfail_at';     // last time formexposed.com was told (at most hourly)
	const OPT_LAST   = 'formexposed_mailfail_last';   // what formexposed.com answered then
	const CRON       = 'formexposed_mailfail';

	/** The submission processed in this request: array(source, form, visitor) or null. */
	private static $ctx = null;
	/** wp_mail() arguments seen during that submission, by mail_key(). */
	private static $mails = array();
	/** Contact Form 7: the composed "mail" template, for mailers without wp_mail_failed. */
	private static $cf7_mail = null;
	/** Keys of the e-mails already kept for this submission. */
	private static $kept = array();
	/** formexposed.com is already told about this request. */
	private static $reported = false;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'formexposed_rescue';
	}

	/** Creates or updates the table (activation, update). */
	public static function install() {
		if ( get_option( self::OPT_DB ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  source varchar(20) NOT NULL,
  form varchar(190) NOT NULL DEFAULT '',
  recipient text NOT NULL,
  subject text NOT NULL,
  message longtext NOT NULL,
  headers text NOT NULL,
  attachments text NOT NULL,
  error_kind varchar(20) NOT NULL DEFAULT 'other',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_try_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::OPT_DB, self::DB_VERSION, true );   // autoloaded: the admin notice asks for it on every screen
	}

	public static function init() {
		add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'cf7_start' ), 1, 1 );
		add_filter( 'wpcf7_mail_components', array( __CLASS__, 'cf7_components' ), PHP_INT_MAX, 3 );
		add_action( 'wpcf7_mail_failed', array( __CLASS__, 'cf7_failed' ), 10, 1 );
		add_action( 'wpforms_process', array( __CLASS__, 'wpforms_start' ), 1, 3 );
		add_action( 'wpforms_process_entry_emails', array( __CLASS__, 'wpforms_async_start' ), 1, 0 );   // WPForms "Optimize Email Sending": sent later by Action Scheduler
		add_action( 'gform_entry_created', array( __CLASS__, 'gform_start' ), 1, 2 );
		add_action( 'elementor_pro/forms/process', array( __CLASS__, 'elementor_start' ), 1, 2 );
		// The submission is over (also when a plugin stopped half-way): e-mails sent later in the same
		// request - an Action Scheduler batch, a cron run - are none of ours.
		foreach ( array( 'wpcf7_submit', 'wpforms_process_complete', 'wpforms_process_after', 'wpforms_process_entry_emails', 'gform_after_submission', 'elementor_pro/forms/new_record' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'finish' ), PHP_INT_MAX, 0 );
		}
		add_filter( 'wp_mail', array( __CLASS__, 'capture' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed' ), 10, 1 );
		add_action( self::CRON, array( __CLASS__, 'report' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_r_resend', array( __CLASS__, 'action_resend' ) );
			add_action( 'admin_post_formexposed_r_delete', array( __CLASS__, 'action_delete' ) );
			add_action( 'admin_post_formexposed_r_setting', array( __CLASS__, 'action_setting' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
			add_action( 'admin_post_formexposed_rescue_hide', array( __CLASS__, 'action_hide' ) );
		}
	}

	/** Keep failed form e-mails? On unless switched off in the settings. */
	public static function on() {
		return ! get_option( self::OPT_OFF );
	}

	// ------------------------------------------------------------------ the submission

	private static function start( $source, $form ) {
		self::$ctx      = array(
			'source'  => $source,
			'form'    => mb_substr( trim( (string) $form ), 0, 190 ),
			'visitor' => self::visitor_addresses(),
		);
		self::$mails    = array();
		self::$cf7_mail = null;
		self::$kept     = array();
	}

	/** For tests and for other code that sends a form's e-mail itself. */
	public static function begin( $source, $form ) {
		self::start( (string) $source, (string) $form );
	}

	public static function finish() {
		self::$ctx = null;
	}

	public static function cf7_start( $contact_form = null ) {
		self::start( 'cf7', is_object( $contact_form ) && method_exists( $contact_form, 'title' ) ? $contact_form->title() : 'Contact Form 7' );
	}

	public static function wpforms_start( $fields = array(), $entry = array(), $form_data = array() ) {
		self::start( 'wpforms', isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : 'WPForms' );
	}

	public static function wpforms_async_start() {
		self::start( 'wpforms', 'WPForms' );
	}

	public static function gform_start( $entry = array(), $form = array() ) {
		if ( is_array( $entry ) && isset( $entry['status'] ) && 'spam' === $entry['status'] ) {
			return;
		}
		self::start( 'gravityforms', isset( $form['title'] ) ? $form['title'] : 'Gravity Forms' );
	}

	public static function elementor_start( $record = null, $handler = null ) {
		$name = is_object( $record ) && method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		self::start( 'elementor', '' !== $name ? $name : 'Elementor' );
	}

	/** The e-mail addresses the visitor typed in (to tell an auto-reply from the enquiry). Compared, never stored. */
	private static function visitor_addresses() {
		$out  = array();
		$walk = static function ( $v, $tiefe ) use ( &$walk, &$out ) {
			if ( count( $out ) >= 20 || $tiefe > 4 ) {
				return;
			}
			if ( is_array( $v ) ) {
				foreach ( $v as $x ) {
					$walk( $x, $tiefe + 1 );
				}
			} elseif ( is_string( $v ) && strlen( $v ) <= 254 ) {
				$a = trim( html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ) );
				if ( is_email( $a ) ) {
					$out[] = strtolower( sanitize_email( $a ) );
				}
			}
		};
		// The form plugin verifies its own request. Every value is read through FILTER_SANITIZE_SPECIAL_CHARS; only a value that
		// is a whole e-mail address (is_email) counts, kept through sanitize_email(); addresses are only compared, never stored.
		$walk( filter_input_array( INPUT_POST, FILTER_SANITIZE_SPECIAL_CHARS, false ), 0 );
		return array_values( array_unique( $out ) );
	}

	// ------------------------------------------------------------------ the e-mail

	/** wp_mail(), last filter: remember the arguments as they are sent. Changes nothing. */
	public static function capture( $atts ) {
		if ( null === self::$ctx || ! is_array( $atts ) || count( self::$mails ) >= 10 ) {
			return $atts;
		}
		$to      = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$message = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$headers = self::header_list( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		// WordPress' own filter, asked the way wp_mail() asks it.
		if ( ! self::header( $headers, 'content-type' ) && 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$headers[] = 'Content-Type: text/html; charset=UTF-8';   // set by a filter (WPForms, some SMTP plugins): keep it for "send again"
		}
		self::$mails[ self::mail_key( $to, $subject, $message ) ] = array(
			'to'          => self::recipients( $to ),
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => self::file_names( isset( $atts['attachments'] ) ? $atts['attachments'] : array() ),
		);
		return $atts;
	}

	/** wp_mail() failed. Keep it if it is the enquiry of the submission processed right now. */
	public static function failed( $error ) {
		if ( null === self::$ctx || ! is_wp_error( $error ) ) {
			return;
		}
		// Contact Form 7 names the template it sends: only "mail" is the enquiry, "mail_2" is the auto-reply.
		if ( 'cf7' === self::$ctx['source'] && class_exists( 'WPCF7_Mail' ) && method_exists( 'WPCF7_Mail', 'get_current_template_name' ) && 'mail' !== WPCF7_Mail::get_current_template_name() ) {
			return;
		}
		$d       = $error->get_error_data();
		$d       = is_array( $d ) ? $d : array();
		$to      = isset( $d['to'] ) ? $d['to'] : '';
		$subject = isset( $d['subject'] ) ? (string) $d['subject'] : '';
		$message = isset( $d['message'] ) ? (string) $d['message'] : '';
		$key     = self::mail_key( $to, $subject, $message );
		$mail    = isset( self::$mails[ $key ] ) ? self::$mails[ $key ] : array(
			'to'          => self::recipients( $to ),
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => self::header_list( isset( $d['headers'] ) ? $d['headers'] : array() ),
			'attachments' => self::file_names( isset( $d['attachments'] ) ? $d['attachments'] : array() ),
		);
		if ( 'cf7' !== self::$ctx['source'] && self::auto_reply( $mail['to'] ) ) {
			return;
		}
		self::keep( $key, $mail, Formexposed_Stats::error_kind( $error ) );
	}

	/** Contact Form 7: the composed "mail" template (the filter runs for every template). */
	public static function cf7_components( $components, $contact_form = null, $mail = null ) {
		if ( null !== self::$ctx && is_array( $components ) && is_object( $mail ) && method_exists( $mail, 'name' ) && 'mail' === $mail->name() ) {
			$tpl                        = is_object( $contact_form ) && method_exists( $contact_form, 'prop' ) ? (array) $contact_form->prop( 'mail' ) : array();
			self::$cf7_mail             = $components;
			self::$cf7_mail['use_html'] = ! empty( $tpl['use_html'] );
		}
		return $components;
	}

	/** Contact Form 7 says its mail failed - kept here if wp_mail_failed did not already do it. */
	public static function cf7_failed( $contact_form = null ) {
		if ( null === self::$ctx || 'cf7' !== self::$ctx['source'] || self::$kept || ! is_array( self::$cf7_mail ) ) {
			return;
		}
		$c       = self::$cf7_mail;
		$headers = array();
		if ( ! empty( $c['sender'] ) ) {
			$headers[] = 'From: ' . str_replace( array( "\r", "\n" ), '', (string) $c['sender'] );
		}
		if ( ! empty( $c['use_html'] ) ) {
			$headers[] = 'Content-Type: text/html';
		}
		foreach ( explode( "\n", str_replace( "\r\n", "\n", isset( $c['additional_headers'] ) ? (string) $c['additional_headers'] : '' ) ) as $h ) {
			if ( '' !== trim( $h ) ) {
				$headers[] = trim( $h );
			}
		}
		$mail = array(
			'to'          => self::recipients( isset( $c['recipient'] ) ? $c['recipient'] : '' ),
			'subject'     => isset( $c['subject'] ) ? (string) $c['subject'] : '',
			'message'     => isset( $c['body'] ) ? (string) $c['body'] : '',
			'headers'     => $headers,
			'attachments' => self::file_names( isset( $c['attachments'] ) ? $c['attachments'] : array() ),
		);
		self::keep( self::mail_key( $mail['to'], $mail['subject'], $mail['message'] ), $mail, 'other' );
	}

	/** Every recipient is an address the visitor typed in (and none is the site's own): an auto-reply. */
	private static function auto_reply( $to ) {
		$visitor = self::$ctx['visitor'];
		if ( ! $visitor || ! $to ) {
			return false;
		}
		$admin = strtolower( (string) get_option( 'admin_email' ) );
		foreach ( $to as $r ) {
			$a = strtolower( self::address( $r ) );
			if ( $a === $admin || ! in_array( $a, $visitor, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function keep( $key, $mail, $kind ) {
		if ( in_array( $key, self::$kept, true ) ) {
			return;
		}
		self::$kept[] = $key;
		$ok           = self::on() && self::store( self::$ctx['source'], self::$ctx['form'], $mail, $kind );
		self::schedule_report( $kind, $ok );
	}

	/** Store one failed e-mail. $mail: to (list), subject, message, headers (list), attachments (file names). */
	public static function store( $source, $form, $mail, $kind ) {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			return false;
		}
		$t = self::table();
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $t, gmdate( 'Y-m-d 00:00:00' ) ) ) >= self::PER_DAY ) {
			return false;
		}
		return (bool) $wpdb->insert( $t, array(
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'source'      => mb_substr( (string) $source, 0, 20 ),
			'form'        => mb_substr( (string) $form, 0, 190 ),
			'recipient'   => wp_json_encode( array_slice( array_values( (array) $mail['to'] ), 0, 20 ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'subject'     => mb_substr( (string) $mail['subject'], 0, 1000 ),
			'message'     => mb_substr( (string) $mail['message'], 0, 200000 ),
			'headers'     => wp_json_encode( array_slice( array_values( (array) $mail['headers'] ), 0, 30 ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'attachments' => wp_json_encode( array_slice( array_values( (array) $mail['attachments'] ), 0, 20 ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'error_kind'  => in_array( $kind, array( 'smtp_connect', 'smtp_auth', 'recipient', 'other' ), true ) ? $kind : 'other',
		) );
	}

	// ------------------------------------------------------------------ tell formexposed.com

	/** At most once an hour, only while connected, and after the visitor's request: one WP-Cron event. */
	private static function schedule_report( $kind, $kept ) {
		if ( self::$reported || ! Formexposed_Core::linked() ) {
			return;
		}
		self::$reported = true;
		if ( time() - (int) get_option( self::OPT_SENT, 0 ) < HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::OPT_SENT, time(), false );
		wp_schedule_single_event( time(), self::CRON, array( $kind, $kept ? 1 : 0 ) );
		// WordPress 6.9 and later spawn WP-Cron at the end of every request; before, only at its start.
		if ( ! has_action( 'shutdown', '_wp_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			add_action( 'shutdown', 'spawn_cron' );
		}
	}

	/** WP-Cron: POST /api/plugin/mailfail. */
	public static function report( $kind = 'other', $kept = 0 ) {
		if ( ! Formexposed_Core::linked() ) {
			return;
		}
		$r = Formexposed_Core::api_mailfail( (string) $kind, (bool) $kept );
		update_option( self::OPT_LAST, array(
			'at'      => time(),
			'kind'    => (string) $kind,
			'rescued' => $kept ? 1 : 0,
			'ok'      => ! is_wp_error( $r ),
			'alerted' => is_array( $r ) && isset( $r['alerted'] ) ? (int) $r['alerted'] : 0,
			'error'   => is_wp_error( $r ) ? $r->get_error_code() : '',
		), false );
	}

	// ------------------------------------------------------------------ list, send again, tidy up

	public static function count_waiting() {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			return 0;
		}
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
	}

	public static function entries( $limit = 50, $offset = 0 ) {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			return array();
		}
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $t, $limit, $offset ), ARRAY_A );
	}

	/** The ids of the oldest waiting entries ("Send all again"). */
	private static function oldest( $limit ) {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			return array();
		}
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id ASC LIMIT %d', $t, $limit ), ARRAY_A );
	}

	/** Stored entries, and those older than $cut (timestamp) - for the storage overview. */
	public static function counts( $cut ) {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			return array( 0, 0 );
		}
		$t = self::table();
		return array(
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ),
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s', $t, gmdate( 'Y-m-d H:i:s', $cut ) ) ),
		);
	}

	/** Daily: older than DAYS days goes, never more than MAX entries. Returns the number deleted. */
	public static function purge() {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			return 0;
		}
		$t   = self::table();
		$n   = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $t, gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS ) ) );
		$all = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
		if ( $all > self::MAX ) {
			$n += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i ORDER BY id ASC LIMIT %d', $t, $all - self::MAX ) );
		}
		Formexposed_Retention::log( $n );
		return $n;
	}

	/** Hand one kept e-mail to wp_mail() again; removed when that works. @return array(ok, text) */
	public static function resend( $id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return array( false, __( 'This entry no longer exists.', 'formexposed' ) );
		}
		$to      = self::json_list( $row['recipient'] );
		$headers = self::json_list( $row['headers'] );
		$files   = self::json_list( $row['attachments'] );
		$message = (string) $row['message'];
		if ( $files ) {
			$message .= self::files_note( $files, $headers );
		}
		if ( ! $to ) {
			return array( false, __( 'This entry has no recipient.', 'formexposed' ) );
		}
		$fehler = null;
		$fang   = static function ( $e ) use ( &$fehler ) {
			$fehler = $e;
		};
		add_action( 'wp_mail_failed', $fang );
		$ok = wp_mail( $to, (string) $row['subject'], $message, $headers );
		remove_action( 'wp_mail_failed', $fang );
		if ( $ok ) {
			$wpdb->delete( $t, array( 'id' => (int) $id ) );
			/* translators: %s: e-mail address(es) */
			return array( true, sprintf( __( 'Sent again to %s and removed from the list.', 'formexposed' ), implode( ', ', array_map( array( __CLASS__, 'address' ), $to ) ) ) );
		}
		$kind = Formexposed_Stats::error_kind( $fehler );
		$wpdb->update( $t, array( 'attempts' => (int) $row['attempts'] + 1, 'last_try_at' => gmdate( 'Y-m-d H:i:s' ), 'error_kind' => $kind ), array( 'id' => (int) $id ) );
		/* translators: %s: kind of mail error */
		return array( false, sprintf( __( 'Still not sent (%s). Check the mail settings of this site, then try again.', 'formexposed' ), self::kind_text( $kind ) ) );
	}

	/**
	 * "Send all again": oldest first, at most $limit, and stop at the first
	 * failure - while mail is still broken every further attempt would only wait
	 * for the same timeout. @return array(number sent, error text or '')
	 */
	public static function resend_oldest( $limit = 20 ) {
		$gut = 0;
		foreach ( self::oldest( (int) $limit ) as $e ) {
			$r = self::resend( (int) $e['id'] );
			if ( ! $r[0] ) {
				return array( $gut, $r[1] );
			}
			$gut++;
		}
		return array( $gut, '' );
	}

	/** The names of files that were attached - the files themselves are gone. */
	private static function files_note( $files, $headers ) {
		$liste = implode( ', ', array_map( 'strval', $files ) );
		$html  = false !== stripos( (string) self::header( $headers, 'content-type' ), 'html' );
		/* translators: %s: list of file names */
		$text = sprintf( __( 'Attached files (not kept by the site, ask the sender again): %s', 'formexposed' ), $liste );
		return $html ? '<p><em>' . esc_html( $text ) . '</em></p>' : "\n\n-- \n" . $text . "\n";
	}

	public static function kind_text( $kind ) {
		switch ( $kind ) {
			case 'smtp_auth':
				return __( 'the mail server refused the login', 'formexposed' );
			case 'smtp_connect':
				return __( 'the mail server could not be reached', 'formexposed' );
			case 'recipient':
				return __( 'a recipient address was refused', 'formexposed' );
			default:
				return __( 'the e-mail could not be handed over', 'formexposed' );
		}
	}

	public static function action_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_r_resend' );
		if ( isset( $_GET['all'] ) ) {
			list( $gut, $fehlt ) = self::resend_oldest( 20 );
			/* translators: %d: number of e-mails */
			$text = sprintf( _n( '%d enquiry sent again.', '%d enquiries sent again.', $gut, 'formexposed' ), $gut );
			$r    = array( '' === $fehlt, '' === $fehlt ? $text : $text . ' ' . __( 'Stopped at the first one that failed:', 'formexposed' ) . ' ' . $fehlt );
		} else {
			$r = self::resend( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		}
		set_transient( 'formexposed_msg', array( $r[0] ? 'ok' : 'error', $r[1] ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'rescue' ) ) );
		exit;
	}

	public static function action_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_r_delete' );
		global $wpdb;
		$t = self::table();
		if ( isset( $_GET['all'] ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $t ) );
		} else {
			$wpdb->delete( $t, array( 'id' => isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ) );
		}
		set_transient( 'formexposed_msg', array( 'ok', __( 'Deleted.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'rescue' ) ) );
		exit;
	}

	public static function action_setting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_r_setting' );
		if ( ! empty( $_POST['keep_on'] ) ) {
			delete_option( self::OPT_OFF );
		} else {
			update_option( self::OPT_OFF, 1, false );
		}
		set_transient( 'formexposed_msg', array( 'ok', __( 'Saved.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'rescue' ) ) );
		exit;
	}

	/** While enquiries wait (they are the site owner's customers): on the dashboard, the plugins page and the formexposed page. */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = self::count_waiting();
		if ( ! $n ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'settings_page_formexposed' ), true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether the notice is shown on this page.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'formexposed' === $page && 'rescue' === Formexposed_Core::tab() ) {
			return;
		}
		if ( get_transient( 'formexposed_rescue_hide_' . get_current_user_id() ) ) {
			return;
		}
		$hide = wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_rescue_hide' ), 'formexposed_rescue_hide' );
		/* translators: %d: number of enquiries */
		echo '<div class="notice notice-error"><p><strong>formexposed:</strong> ' . esc_html( sprintf( _n( '%d enquiry from a form could not be sent by e-mail. It is kept on this site.', '%d enquiries from forms could not be sent by e-mail. They are kept on this site.', $n, 'formexposed' ), $n ) )
			. ' <a href="' . esc_url( Formexposed_Core::page_url( array( 'tab' => 'rescue' ) ) ) . '">' . esc_html__( 'See them and send them again', 'formexposed' ) . '</a>'
			. ' · <a href="' . esc_url( $hide ) . '">' . esc_html__( 'Hide for a day', 'formexposed' ) . '</a></p></div>';
	}

	/** "Hide for a day" of the notice above, for the current user only. */
	public static function action_hide() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_rescue_hide' );
		set_transient( 'formexposed_rescue_hide_' . get_current_user_id(), 1, DAY_IN_SECONDS );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/** A paragraph for the privacy policy guide (Formexposed_Quarantine::privacy_text). */
	public static function privacy_paragraph() {
		return __( 'If the e-mail with a message sent through our contact forms cannot be delivered to us, a copy of that e-mail (recipient, subject, text; attached files only by name) is kept on this server for up to 30 days so that it can be sent again, and is then deleted automatically.', 'formexposed' );
	}

	// ------------------------------------------------------------------ helpers

	/** "Name <a@b.c>" -> a@b.c */
	public static function address( $s ) {
		$s = (string) $s;
		return preg_match( '/<([^>]+)>/', $s, $m ) ? trim( $m[1] ) : trim( $s );
	}

	/** wp_mail's "to" (string or list) -> list of recipients. */
	private static function recipients( $to ) {
		$out = array();
		foreach ( is_array( $to ) ? $to : explode( ',', (string) $to ) as $r ) {
			$r = trim( str_replace( array( "\r", "\n" ), '', (string) $r ) );
			if ( '' !== $r ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	private static function mail_key( $to, $subject, $message ) {
		return md5( implode( ',', self::recipients( $to ) ) . "\n" . (string) $subject . "\n" . (string) $message );
	}

	/** Headers as a list of "Name: value" lines (string, list, or name => value). */
	private static function header_list( $h ) {
		$out = array();
		$raw = is_array( $h ) ? $h : explode( "\n", str_replace( "\r\n", "\n", (string) $h ) );
		foreach ( $raw as $name => $zeile ) {
			if ( ! is_scalar( $zeile ) ) {
				continue;
			}
			$zeile = trim( (string) $zeile );
			if ( is_string( $name ) && '' !== $name && false === strpos( $zeile, ':' ) ) {
				$zeile = $name . ': ' . $zeile;
			}
			if ( '' !== $zeile && false !== strpos( $zeile, ':' ) ) {
				$out[] = str_replace( array( "\r", "\n" ), '', $zeile );
			}
		}
		return $out;
	}

	/** The value of a header in a list, or ''. */
	private static function header( $list, $name ) {
		foreach ( (array) $list as $zeile ) {
			$p = explode( ':', (string) $zeile, 2 );
			if ( 2 === count( $p ) && strtolower( trim( $p[0] ) ) === $name ) {
				return trim( $p[1] );
			}
		}
		return '';
	}

	/** Attachments -> file names only. */
	private static function file_names( $a ) {
		$out = array();
		foreach ( is_array( $a ) ? $a : explode( "\n", str_replace( "\r\n", "\n", (string) $a ) ) as $k => $f ) {
			$f = is_string( $f ) ? $f : ( is_string( $k ) ? $k : '' );
			if ( '' !== trim( $f ) ) {
				$out[] = mb_substr( wp_basename( trim( $f ) ), 0, 190 );
			}
		}
		return $out;
	}

	private static function json_list( $s ) {
		$l = json_decode( (string) $s, true );
		return is_array( $l ) ? array_values( array_filter( array_map( 'strval', $l ), 'strlen' ) ) : array();
	}

	/** Reply-To of a kept e-mail (for the list). */
	public static function reply_to( $row ) {
		return self::header( self::json_list( $row['headers'] ), 'reply-to' );
	}

	/** List helper for the view. */
	public static function row_list( $row, $col ) {
		return self::json_list( isset( $row[ $col ] ) ? $row[ $col ] : '' );
	}
}
