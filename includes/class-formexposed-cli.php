<?php
/**
 * formexposed - WP-CLI commands (since 1.2.0), for agencies that look after
 * many sites:
 *
 *   wp plugin install <zip> --activate && wp formexposed connect fxc_...
 *
 * `wp option update formexposed_agency_code fxc_...` works as well (the plugin
 * uses the code at once), but prints no result.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formexposed_CLI {

	/**
	 * Connects this site to an agency's formexposed account.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : The rollout code from formexposed.com -> Agency -> WordPress rollout (fxc_...).
	 *
	 * ## EXAMPLES
	 *
	 *     wp formexposed connect fxc_0123456789abcdef01234567
	 */
	public function connect( $args ) {
		$code = strtolower( trim( (string) $args[0] ) );
		if ( ! preg_match( Formexposed_Core::CODE_RE, $code ) ) {
			WP_CLI::error( 'This is not a formexposed rollout code (fxc_ followed by 24 characters).' );
		}
		$r = Formexposed_Core::claim( $code );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Connected %s to "%s"%s.', $r['host'], $r['workspace'], ! empty( $r['verified'] ) ? ', verified' : '' ) );
	}

	/**
	 * Shows the state of formexposed on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp formexposed status
	 */
	public function status() {
		$linked  = (int) get_option( Formexposed_Core::OPT_LINKED, 0 );
		$plugins = Formexposed_Guard::plugins();
		WP_CLI::line( 'Site:        ' . Formexposed_Core::host() );
		WP_CLI::line( 'Connected:   ' . ( $linked ? 'yes, since ' . gmdate( 'Y-m-d H:i', $linked ) . ' UTC (daily counts are sent)' : 'no' ) );
		WP_CLI::line( 'Protection:  ' . ( Formexposed_Guard::on() ? 'on' : 'off' ) . ' - form plugins: ' . ( $plugins ? implode( ', ', $plugins ) : 'none of the supported ones' ) );
		WP_CLI::line( 'Blocked:     ' . Formexposed_Stats::total_blocked() . ' submissions in the last 14 days, ' . Formexposed_Quarantine::count_open() . ' waiting in the quarantine' );
		$sw = get_option( 'formexposed_swarm' );
		WP_CLI::line( 'Swarm:       ' . ( get_option( 'formexposed_swarm_off' ) ? 'off' : ( is_array( $sw ) ? count( (array) $sw['links'] ) . ' domains on the list' : 'on, no list yet' ) ) );
		// 1.4.0
		WP_CLI::line( 'Proof of work: ' . ( Formexposed_Pow::on() ? 'on' : ( Formexposed_Pow::enabled() ? 'switched on, but the protection layer is off' : 'off' ) ) );
		$rc  = Formexposed_Recaptcha::places();
		$bak = Formexposed_Recaptcha::backup();
		WP_CLI::line( 'reCAPTCHA:   ' . ( $rc ? 'in use: ' . implode( ', ', $rc ) : 'not in use' ) . ( $bak ? ' (moved to proof of work on ' . gmdate( 'Y-m-d', (int) $bak['at'] ) . ', undo possible)' : '' ) );
		$last = get_option( Formexposed_Rescue::OPT_LAST );
		WP_CLI::line( 'Rescue net:  ' . ( Formexposed_Rescue::on() ? 'on' : 'off' ) . ', ' . Formexposed_Rescue::count_waiting() . ' enquiries waiting (e-mail failed)'
			. ( is_array( $last ) && ! empty( $last['at'] ) ? '; last report ' . gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC (' . ( $last['ok'] ? 'answered, alerted ' . (int) $last['alerted'] : 'failed: ' . $last['error'] ) . ')' : '' ) );
		$tage = Formexposed_Retention::days();
		$src  = Formexposed_Retention::sources();
		$alle = 0;
		$alt  = 0;
		foreach ( $src as $z ) {
			$alle += $z['total'];
			$alt  += $z['older'];
		}
		WP_CLI::line( 'Retention:   ' . ( $tage ? 'delete after ' . $tage . ' days' : 'off' ) . ' - ' . $alle . ' stored enquiries in ' . count( $src ) . ' places (' . implode( ', ', array_keys( $src ) ) . '), ' . $alt . ' older than ' . ( $tage ? $tage : 365 ) . ' days, ' . Formexposed_Retention::deleted_30d() . ' deleted in 30 days' );
		$m = Formexposed_Maintenance::state();
		WP_CLI::line( 'Maintenance: ' . ( $m['on'] ? 'on (' . $m['source'] . ')' . ( $m['sent_at'] ? ', reported, quiet until ' . $m['until'] : ', not reported' ) : 'off' ) );
		$h = Formexposed_Health::last();
		if ( $h ) {
			$c = $h['counts'];
			WP_CLI::line( 'Form check:  ' . $c['forms'] . ' forms, ' . $c['from_visitor'] . ' from the visitor\'s address, ' . $c['notifications_off'] . ' without notification, ' . $c['recipient_freemail'] . ' to freemail, '
				. $c['recipient_external'] . ' to outside domains, ' . $c['upload_no_types'] . ' file fields without type limit, ' . $c['uploads_listing'] . ' upload folders listing, ' . $c['autoresponder'] . ' auto-replies; mail: ' . $h['mailer']
				. ' (' . gmdate( 'Y-m-d H:i', (int) $h['at'] ) . ' UTC)' );
		} else {
			WP_CLI::line( 'Form check:  not run yet' );
		}
		$inv = Formexposed_Health::inventory();
		WP_CLI::line( 'Inventory:   ' . ( $inv ? implode( ', ', array_map( static function ( $s, $v ) {
			return $s . ' ' . $v;
		}, array_keys( $inv ), $inv ) ) : 'none of the watched plugins active' ) );
		$l = Formexposed_Core::last();
		WP_CLI::line( 'Last check:  ' . ( ! empty( $l['status'] ) ? $l['status'] . ( isset( $l['level'] ) ? ' (' . $l['level'] . ')' : '' ) : 'none' ) );
	}
}
