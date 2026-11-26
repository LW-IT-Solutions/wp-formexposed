<?php
/**
 * formexposed - quarantine instead of the bin (since 1.3.0).
 *
 * Every submission the protection layer stops is kept for DAYS days in a table
 * of THIS site ({prefix}formexposed_quarantine) - with the reason in plain
 * words. A person who was stopped by mistake is not lost: one click delivers
 * the message to the site's address (Reply-To: the sender's e-mail). Nothing
 * of it is sent to formexposed.com.
 *
 * Limits: at most PER_DAY entries a day (an attack must not fill the
 * database), at most MAX in total, every entry deleted after DAYS days.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Own tables ({prefix}formexposed_*): WordPress has no API for them. Table names go through %i (WordPress 6.2+).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class Formexposed_Quarantine {

	const DAYS       = 14;
	const PER_DAY    = 200;
	const MAX        = 2000;
	const DB_VERSION = '1';
	const OPT_DB     = 'formexposed_q_db';
	const OPT_TO     = 'formexposed_q_recipient';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'formexposed_quarantine';
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
  reason varchar(40) NOT NULL,
  fields longtext NOT NULL,
  reply_to varchar(190) NOT NULL DEFAULT '',
  released_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	/** The table exists in the current version (install() ran). */
	public static function ready() {
		return get_option( self::OPT_DB ) === self::DB_VERSION;
	}

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_q_release', array( __CLASS__, 'action_release' ) );
			add_action( 'admin_post_formexposed_q_delete', array( __CLASS__, 'action_delete' ) );
			add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
		}
	}

	/**
	 * Keep a stopped submission. $pairs: list of array(label, value).
	 * $reason: the key of the daily counts (honeypot, token, ...).
	 */
	public static function store( $source, $form, $reason, $pairs ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return false;
		}
		$t     = self::table();
		$heute = gmdate( 'Y-m-d 00:00:00' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $t, $heute ) ) >= self::PER_DAY ) {
			return false;
		}
		$felder = array();
		$antw   = '';
		foreach ( array_slice( (array) $pairs, 0, 30 ) as $p ) {
			$label = mb_substr( trim( (string) $p[0] ), 0, 100 );
			$wert  = mb_substr( (string) $p[1], 0, 5000 );
			if ( '' === $label && '' === trim( $wert ) ) {
				continue;
			}
			$felder[] = array( $label, $wert );
			if ( '' === $antw && is_email( trim( $wert ) ) ) {
				$antw = trim( $wert );
			}
		}
		return (bool) $wpdb->insert( $t, array(
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'source'     => mb_substr( (string) $source, 0, 20 ),
			'form'       => mb_substr( (string) $form, 0, 190 ),
			'reason'     => mb_substr( (string) $reason, 0, 40 ),
			'fields'     => wp_json_encode( $felder ),
			'reply_to'   => mb_substr( $antw, 0, 190 ),
		) );
	}

	/** Daily: older than DAYS days goes, and never more than MAX entries. Since 1.4.0 counted (Formexposed_Retention::log). */
	public static function purge() {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t   = self::table();
		$weg = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $t, gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS ) ) );
		$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
		if ( $n > self::MAX ) {
			$weg += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i ORDER BY id ASC LIMIT %d', $t, $n - self::MAX ) );
		}
		Formexposed_Retention::log( $weg );
		return $weg;
	}

	/** Stored entries, and those older than $cut (timestamp) - for the storage overview (since 1.4.0). */
	public static function counts( $cut ) {
		global $wpdb;
		$t = self::table();
		if ( ! self::ready() ) {
			return array( 0, 0 );
		}
		return array(
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ),
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s', $t, gmdate( 'Y-m-d H:i:s', $cut ) ) ),
		);
	}

	public static function count_open() {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE released_at IS NULL', $t ) );
	}

	public static function entries( $limit = 50, $offset = 0 ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $t, $limit, $offset ), ARRAY_A );
	}

	/** Where released messages go. */
	public static function recipient() {
		$to = (string) get_option( self::OPT_TO );
		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	/** The reason in words a site owner understands. */
	public static function reason_text( $key ) {
		switch ( $key ) {
			case 'honeypot':
				return __( 'A hidden trap field was filled in - usually a bot, sometimes a browser autofill.', 'formexposed' );
			case 'token':
				return __( 'The form token was missing or altered - the message did not come through the page.', 'formexposed' );
			case 'too_fast':
				return __( 'Sent less than 3 seconds after the page loaded.', 'formexposed' );
			case 'no_interaction':
				return __( 'No typing or clicking on the page before sending.', 'formexposed' );
			case 'line_break':
				return __( 'A line break in a single-line field (name, e-mail, subject) - a typical trick to add mail headers.', 'formexposed' );
			case 'mail_domain':
				return __( 'The e-mail address belongs to a domain that cannot receive mail.', 'formexposed' );
			case 'rate_limit':
				return __( 'More than 5 messages from the same connection within 10 minutes.', 'formexposed' );
			case 'swarm':
				return __( 'Contains a link to a domain that formexposed has seen in spam on several other sites.', 'formexposed' );
			case 'pow':
				return __( 'The invisible proof of work was missing, wrong or used twice - usually a bot that does not run the page\'s script.', 'formexposed' );
			case 'fc_block':
				/* translators: %s: product name of the hosted form protection */
				return sprintf( __( 'Blocked by the hosted form protection (%s).', 'formexposed' ), FORMEXPOSED_PROTECT_NAME );
			case 'fc_flag':
				/* translators: %s: product name of the hosted form protection */
				return sprintf( __( 'Flagged as suspicious by the hosted form protection (%s) - held for review.', 'formexposed' ), FORMEXPOSED_PROTECT_NAME );
			case 'fc_unavailable':
				/* translators: %s: product name of the hosted form protection */
				return sprintf( __( 'The hosted form protection (%s) gave no clear answer (not reachable, error, or the browser of the visitor could not reach it) and no fallback check passed - held, not delivered unchecked.', 'formexposed' ), FORMEXPOSED_PROTECT_NAME );
			default:
				return __( 'Stopped by the protection layer.', 'formexposed' );
		}
	}

	// ------------------------------------------------------------------ actions

	/** Deliver one held-back message to the site's address. @return array(ok, text) */
	public static function release( $id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return array( false, __( 'This entry no longer exists.', 'formexposed' ) );
		}
		if ( ! empty( $row['released_at'] ) ) {
			return array( false, __( 'This message has already been released.', 'formexposed' ) );
		}
		$zeilen = array();
		foreach ( (array) json_decode( (string) $row['fields'], true ) as $f ) {
			$zeilen[] = $f[0] . ': ' . $f[1];
		}
		$text = sprintf( /* translators: 1: form name, 2: date, 3: reason */ __( "This message to \"%1\$s\" was held back by formexposed on %2\$s (reason: %3\$s) and has now been delivered by hand.", 'formexposed' ),
			$row['form'], get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ), self::reason_text( $row['reason'] ) )
			. "\n\n" . implode( "\n", $zeilen ) . "\n";
		$kopf = array();
		$antw = str_replace( array( "\r", "\n" ), '', (string) $row['reply_to'] );
		if ( is_email( $antw ) ) {
			$kopf[] = 'Reply-To: ' . $antw;
		}
		/* translators: 1: site name, 2: form name */
		if ( ! wp_mail( self::recipient(), sprintf( __( '[%1$s] Released message: %2$s', 'formexposed' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $row['form'] ), $text, $kopf ) ) {
			return array( false, __( 'WordPress could not send the e-mail. Check the mail settings of this site.', 'formexposed' ) );
		}
		$wpdb->update( $t, array( 'released_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $id ) );
		Formexposed_Stats::quarantine_released();
		/* translators: %s: e-mail address */
		return array( true, sprintf( __( 'Delivered to %s.', 'formexposed' ), self::recipient() ) );
	}

	public static function action_release() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_q_release' );
		$r = self::release( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		set_transient( 'formexposed_msg', array( $r[0] ? 'ok' : 'error', $r[1] ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'quarantine' ) ) );
		exit;
	}

	public static function action_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_q_delete' );
		global $wpdb;
		$t = self::table();
		if ( isset( $_GET['all'] ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $t ) );
		} else {
			$wpdb->delete( $t, array( 'id' => isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ) );
		}
		set_transient( 'formexposed_msg', array( 'ok', __( 'Deleted.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'quarantine' ) ) );
		exit;
	}

	/**
	 * Paragraphs for the site's privacy policy (Settings -> Privacy -> Policy
	 * guide). Since 1.4.0 also the rescue net, and - while switched on - the
	 * proof of work and the storage limit.
	 */
	public static function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$p = array(
			__( 'Messages sent through our contact forms are checked for spam on this server. Messages that are held back as suspected spam are stored on this server for up to 14 days so that a message stopped by mistake can still be delivered, and are then deleted automatically. The quarantine itself is not sent to anyone.', 'formexposed' ),
			Formexposed_Rescue::privacy_paragraph(),
			Formexposed_Protect::privacy_paragraph(),
		);
		if ( Formexposed_Pow::on() ) {
			$p[] = __( 'To keep automated spam out, your browser solves a small calculation task when you use one of our forms (proof of work). It runs on this website only: no cookie is set and no data is sent to third parties for it.', 'formexposed' );
		}
		if ( get_option( 'formexposed_metrics_on' ) ) {
			$p[] = __( 'We optionally count form views, starts, validation errors and submit attempts without field contents, cookies or persistent visitor identifiers. Counts are kept locally for 14 days and, while connected, transferred daily to formexposed.com, where the account retention period applies. Client success signals do not establish mailbox or CRM delivery. A short local IP-derived rate-limit key expires after a minute and is not transferred.', 'formexposed' );
			if ( get_option( 'formexposed_field_metrics_on' ) ) {
				$p[] = __( 'Optional detailed counts identify a field by its position and a validation category only. No field names, entered values or individual visitor histories are collected.', 'formexposed' );
			}
		}
		if ( get_option( 'formexposed_recovery_on' ) ) {
			$p[] = __( 'After an offline or supported form error, a helper offers a return to the existing open form and an optional email contact. The helper reads no field values, stores no drafts and performs no automatic resend.', 'formexposed' );
		}
		if ( Formexposed_Core::linked() ) {
			$p[] = __( 'This site is connected to formexposed.com (LW IT Solutions), which monitors the protection of our forms. It receives daily counts (for example blocked and accepted submissions, sent and failed e-mails) and technical details about the form, CAPTCHA and mail plugins in use. With swarm protection on, it also receives the domain names of links found in stopped messages. It receives no e-mail addresses, names or message content.', 'formexposed' );
		}
		$p[]  = Formexposed_Retention::privacy_paragraph();
		$html = '';
		foreach ( array_filter( $p ) as $absatz ) {
			$html .= '<p>' . $absatz . '</p>';
		}
		wp_add_privacy_policy_content( 'formexposed', wp_kses_post( $html ) );
	}
}
