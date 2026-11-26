<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formexposed - counts, never content (since 1.2.0).
 *
 * One option, formexposed_stats, with one entry per day (UTC) for the last
 * DAYS days:
 *   blocked      submissions the protection layer stopped, per reason
 *                (honeypot, token, too_fast, no_interaction, line_break,
 *                mail_domain, rate_limit, swarm, pow (since 1.4.0), disposable, script,
 *                keyword, country (since 1.5.0), other)
 *   submissions  submissions the form plugins accepted (since 1.3.0) - a site
 *                that suddenly gets none may have a broken form
 *   mail_sent    e-mails WordPress handed over successfully (wp_mail_succeeded)
 *   mail_failed  e-mails that failed (wp_mail_failed), and mail_error: the kind
 *                of the last failure (smtp_connect, smtp_auth, recipient, other)
 *   rc           answers of Google's reCAPTCHA check (since 1.3.0): total, how
 *                many said "success" with exactly 0.9, how many carried a score
 *                without an action. Above its free quota Google answers every
 *                check with success and 0.9 - the CAPTCHA then lets everything in.
 *   links        domains of links in blocked messages (since 1.3.0, only while
 *                the site takes part in the swarm protection) - no addresses,
 *                no message text
 *   retention_deleted  stored enquiries the plugin deleted because they were
 *                older than the storage limit or than the quarantine/rescue
 *                period (since 1.4.0, Formexposed_Retention) - a number
 *
 * No e-mail addresses, no subjects, no message text, no IP addresses. The
 * counts leave this site only once a day and only while it is connected to a
 * formexposed account (Formexposed_Core::daily).
 */

class Formexposed_Stats {

	const OPT     = 'formexposed_stats';
	const DAYS    = 14;
	const REASONS = array( 'honeypot', 'token', 'too_fast', 'no_interaction', 'line_break', 'mail_domain', 'rate_limit', 'swarm', 'pow', 'disposable', 'script', 'keyword', 'country', 'other' );
	const LINKS_PER_DAY = 50;

	public static function init() {
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'mail_sent' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'mail_failed' ) );
	}

	/** A submission the protection layer stopped. */
	public static function blocked( $reason ) {
		self::bump( 'blocked', in_array( $reason, self::REASONS, true ) ? $reason : 'other' );
	}

	/** A submission a form plugin accepted. */
	public static function quarantine_released() {
		self::bump( 'quarantine_released' );
	}

	public static function submission() {
		self::bump( 'submissions' );
	}

	public static function mail_sent() {
		self::bump( 'mail_sent' );
	}

	/** Only the kind of the error is kept - the message may contain addresses. */
	public static function mail_failed( $error = null ) {
		self::bump( 'mail_failed', null, self::error_kind( $error ) );
	}

	/** Kind of a wp_mail error: smtp_auth, smtp_connect, recipient or other. Also used by the rescue net. */
	public static function error_kind( $error ) {
		$t = is_wp_error( $error ) ? strtolower( $error->get_error_message() ) : '';
		if ( false !== strpos( $t, 'authenticat' ) || false !== strpos( $t, 'password' ) || false !== strpos( $t, 'username' ) ) {
			return 'smtp_auth';
		}
		if ( false !== strpos( $t, 'connect' ) || false !== strpos( $t, 'timed out' ) || false !== strpos( $t, 'timeout' ) ) {
			return 'smtp_connect';
		}
		if ( false !== strpos( $t, 'recipient' ) || false !== strpos( $t, 'address' ) ) {
			return 'recipient';
		}
		return 'other';
	}

	/** Stored enquiries deleted by the storage limit or the quarantine/rescue clean-up. */
	public static function retention_deleted( $n ) {
		if ( (int) $n > 0 ) {
			self::bump( 'retention_deleted', null, null, (int) $n );
		}
	}

	/** One answer of Google's reCAPTCHA check (siteverify), reduced to three numbers. */
	public static function recaptcha( $success, $score, $has_action ) {
		self::bump( 'rc', 'total' );
		if ( $success && null !== $score && abs( (float) $score - 0.9 ) < 0.0001 ) {
			self::bump( 'rc', 's09' );
		}
		if ( null !== $score && ! $has_action ) {
			self::bump( 'rc', 'noaction' );
		}
	}

	/** Domains of links found in a blocked message (swarm protection). */
	public static function links( $hosts ) {
		if ( ! $hosts || get_option( 'formexposed_swarm_off' ) ) {
			return;
		}
		$s   = self::load();
		$day = gmdate( 'Y-m-d' );
		self::day( $s, $day );
		foreach ( array_slice( array_unique( $hosts ), 0, 10 ) as $h ) {
			if ( ! isset( $s[ $day ]['links'][ $h ] ) && count( $s[ $day ]['links'] ) >= self::LINKS_PER_DAY ) {
				continue;
			}
			$s[ $day ]['links'][ $h ] = ( isset( $s[ $day ]['links'][ $h ] ) ? (int) $s[ $day ]['links'][ $h ] : 0 ) + 1;
		}
		update_option( self::OPT, self::window( $s ), false );
	}

	/** The stored days, oldest first, in the shape POST /api/plugin/stats expects. */
	public static function days() {
		$out = array();
		foreach ( self::window( self::load() ) as $day => $d ) {
			$row = array(
				'day'         => $day,
				'quarantine_released' => (int) ( $d['quarantine_released'] ?? 0 ),
				'blocked'     => isset( $d['blocked'] ) && is_array( $d['blocked'] ) ? array_map( 'intval', $d['blocked'] ) : array(),
				'submissions' => isset( $d['submissions'] ) ? (int) $d['submissions'] : 0,
				'mail_sent'   => isset( $d['mail_sent'] ) ? (int) $d['mail_sent'] : 0,
				'mail_failed' => isset( $d['mail_failed'] ) ? (int) $d['mail_failed'] : 0,
				'recaptcha'   => array(
					'total'    => isset( $d['rc']['total'] ) ? (int) $d['rc']['total'] : 0,
					's09'      => isset( $d['rc']['s09'] ) ? (int) $d['rc']['s09'] : 0,
					'noaction' => isset( $d['rc']['noaction'] ) ? (int) $d['rc']['noaction'] : 0,
				),
				'retention_deleted' => isset( $d['retention_deleted'] ) ? (int) $d['retention_deleted'] : 0,
			);
			if ( ! empty( $d['mail_error'] ) ) {
				$row['mail_error'] = (string) $d['mail_error'];
			}
			if ( ! empty( $d['links'] ) && is_array( $d['links'] ) && ! get_option( 'formexposed_swarm_off' ) ) {
				$row['links'] = array_map( 'intval', $d['links'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	/** Short name of a reason for the chart on the settings page (the quarantine explains each one in full). */
	public static function reason_label( $reason ) {
		$labels = array(
			'honeypot'       => __( 'Trap field', 'formexposed' ),
			'token'          => __( 'Form token', 'formexposed' ),
			'too_fast'       => __( 'Sent too fast', 'formexposed' ),
			'no_interaction' => __( 'No interaction', 'formexposed' ),
			'line_break'     => __( 'Header injection', 'formexposed' ),
			'mail_domain'    => __( 'Mail domain', 'formexposed' ),
			'rate_limit'     => __( 'Rate limit', 'formexposed' ),
			'swarm'          => __( 'Swarm list', 'formexposed' ),
			'pow'            => __( 'Proof of work', 'formexposed' ),
			'disposable'     => __( 'Throw-away address', 'formexposed' ),
			'script'         => __( 'Writing system', 'formexposed' ),
			'keyword'        => __( 'Blocked word', 'formexposed' ),
			'country'        => __( 'Country', 'formexposed' ),
			'fc_block'       => __( 'Hosted protection: blocked', 'formexposed' ),
			'fc_flag'        => __( 'Hosted protection: flagged', 'formexposed' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : __( 'Other', 'formexposed' );
	}

	/** Blocked submissions in the stored days. */
	public static function total_blocked() {
		$n = 0;
		foreach ( self::days() as $d ) {
			$n += array_sum( $d['blocked'] );
		}
		return $n;
	}

	// ------------------------------------------------------------------ store

	private static function load() {
		$s = get_option( self::OPT );
		return is_array( $s ) ? $s : array();
	}

	private static function day( &$s, $day ) {
		if ( ! isset( $s[ $day ] ) || ! is_array( $s[ $day ] ) ) {
			$s[ $day ] = array();
		}
		$s[ $day ] += array( 'blocked' => array(), 'submissions' => 0, 'mail_sent' => 0, 'mail_failed' => 0, 'rc' => array(), 'links' => array(), 'retention_deleted' => 0 );
	}

	private static function bump( $field, $sub = null, $error = null, $by = 1 ) {
		$s   = self::load();
		$day = gmdate( 'Y-m-d' );
		self::day( $s, $day );
		if ( null !== $sub ) {
			$s[ $day ][ $field ][ $sub ] = ( isset( $s[ $day ][ $field ][ $sub ] ) ? (int) $s[ $day ][ $field ][ $sub ] : 0 ) + $by;
		} else {
			$s[ $day ][ $field ] = ( isset( $s[ $day ][ $field ] ) ? (int) $s[ $day ][ $field ] : 0 ) + $by;
		}
		if ( null !== $error ) {
			$s[ $day ]['mail_error'] = $error;
		}
		update_option( self::OPT, self::window( $s ), false );
	}

	/** Only the last DAYS days, oldest first. */
	private static function window( $s ) {
		$ab = gmdate( 'Y-m-d', time() - ( self::DAYS - 1 ) * DAY_IN_SECONDS );
		foreach ( array_keys( $s ) as $day ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) || (string) $day < $ab ) {
				unset( $s[ $day ] );
			}
		}
		ksort( $s );
		return $s;
	}
}
