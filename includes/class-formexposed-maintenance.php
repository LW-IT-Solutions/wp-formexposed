<?php
/**
 * formexposed - maintenance mode detection (since 1.4.0).
 *
 * While the site is in maintenance, its forms are offline on purpose. The
 * plugin tells formexposed.com, which then keeps its alerts quiet and pauses
 * the form checks (POST /api/plugin/maintenance): "on" every hour while the
 * maintenance lasts (the service keeps quiet for 2 hours per "on"), "off" once
 * when it ends. Only while the site is connected. Nothing else is sent.
 *
 * Detected (best effort - each plugin keeps its switch in its own option, read
 * only while that plugin is active, so a forgotten option never mutes alerts):
 *   wordpress           WordPress itself during updates (wp_is_maintenance_mode)
 *   wp-maintenance-mode WP Maintenance Mode / LightStart: wpmm_settings[general][status] = 1
 *   seedprod            SeedProd Coming Soon & Maintenance (coming-soon, seedprod-coming-soon-pro-5):
 *                       seedprod_settings (JSON) enable_coming_soon_mode / enable_maintenance_mode,
 *                       older versions seed_csp4_settings_content[status] 1 or 2
 *   maintenance         Maintenance (WebFactory): maintenance_options[state] = 1
 *   under-construction  Under Construction (WebFactory): ucp_options[status] = 1
 *   cmp                 CMP - Coming Soon & Maintenance: niteoCS_status not 0
 *   elementor           Elementor: elementor_maintenance_mode_mode = maintenance or coming_soon
 * Other setups can report through the filter formexposed_maintenance (return a
 * short name while the site is in maintenance, '' otherwise).
 *
 * WP-Cron: formexposed_hourly; right after one of the options above changes, a
 * single formexposed_maintenance_check event checks at once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formexposed_Maintenance {

	/** Options of other plugins this class reads (or, where said, changes). */
	const OPTION_ELEMENTOR_MAINTENANCE_MODE_MODE = 'elementor_maintenance_mode_mode';
	const OPTION_MAINTENANCE_OPTIONS = 'maintenance_options';
	const OPTION_NITEOCS_STATUS = 'niteoCS_status';
	const OPTION_SEED_CSP4_SETTINGS_CONTENT = 'seed_csp4_settings_content';
	const OPTION_SEEDPROD_SETTINGS = 'seedprod_settings';
	const OPTION_UCP_OPTIONS = 'ucp_options';
	const OPTION_WPMM_SETTINGS = 'wpmm_settings';

	const OPT      = 'formexposed_maint';          // array(on, source, sent_at, until, changed_at)
	const CRON     = 'formexposed_hourly';
	const CRON_NOW = 'formexposed_maintenance_check';   // not "formexposed_maintenance": that is the filter for own setups
	const OPTIONS  = array( 'wpmm_settings', 'seedprod_settings', 'seed_csp4_settings_content', 'maintenance_options', 'ucp_options', 'niteoCS_status', 'elementor_maintenance_mode_mode' );

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_action( self::CRON_NOW, array( __CLASS__, 'tick' ) );
		foreach ( self::OPTIONS as $o ) {
			add_action( 'update_option_' . $o, array( __CLASS__, 'soon' ), 99, 0 );
			add_action( 'add_option_' . $o, array( __CLASS__, 'soon' ), 99, 0 );
		}
		add_action( 'activated_plugin', array( __CLASS__, 'soon' ), 99, 0 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'soon' ), 99, 0 );
	}

	/** The name of what puts the site into maintenance right now, or ''. */
	public static function detect() {
		$src = '';
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			$src = 'wordpress';
		}
		$a = Formexposed_Health::active();
		if ( '' === $src && isset( $a['wp-maintenance-mode'] ) ) {
			$o = get_option( self::OPTION_WPMM_SETTINGS );
			if ( is_array( $o ) && isset( $o['general']['status'] ) && 1 === (int) $o['general']['status'] ) {
				$src = 'wp-maintenance-mode';
			}
		}
		if ( '' === $src && ( isset( $a['coming-soon'] ) || isset( $a['seedprod-coming-soon-pro-5'] ) ) ) {
			$o = get_option( self::OPTION_SEEDPROD_SETTINGS );
			$o = is_string( $o ) ? json_decode( $o, true ) : $o;
			$v = get_option( self::OPTION_SEED_CSP4_SETTINGS_CONTENT );
			if ( ( is_array( $o ) && ( ! empty( $o['enable_coming_soon_mode'] ) || ! empty( $o['enable_maintenance_mode'] ) ) )
				|| ( is_array( $v ) && isset( $v['status'] ) && in_array( (int) $v['status'], array( 1, 2 ), true ) ) ) {
				$src = 'seedprod';
			}
		}
		if ( '' === $src && isset( $a['maintenance'] ) ) {
			$o = get_option( self::OPTION_MAINTENANCE_OPTIONS );
			if ( is_array( $o ) && ! empty( $o['state'] ) ) {
				$src = 'maintenance';
			}
		}
		if ( '' === $src && isset( $a['under-construction-page'] ) ) {
			$o = get_option( self::OPTION_UCP_OPTIONS );
			if ( is_array( $o ) && ! empty( $o['status'] ) ) {
				$src = 'under-construction';
			}
		}
		if ( '' === $src && isset( $a['cmp-coming-soon-maintenance'] ) ) {
			$o = (string) get_option( self::OPTION_NITEOCS_STATUS, '0' );
			if ( '' !== $o && '0' !== $o ) {
				$src = 'cmp';
			}
		}
		if ( '' === $src && isset( $a['elementor'] ) && in_array( (string) get_option( self::OPTION_ELEMENTOR_MAINTENANCE_MODE_MODE ), array( 'maintenance', 'coming_soon' ), true ) ) {
			$src = 'elementor';
		}
		$f = apply_filters( 'formexposed_maintenance', $src );
		return is_string( $f ) ? sanitize_key( $f ) : $src;
	}

	public static function state() {
		$s = get_option( self::OPT );
		return is_array( $s ) ? $s + array( 'on' => false, 'source' => '', 'sent_at' => 0, 'until' => null, 'changed_at' => 0 ) : array( 'on' => false, 'source' => '', 'sent_at' => 0, 'until' => null, 'changed_at' => 0 );
	}

	/** Hourly and after a change: compare, report while connected. */
	public static function tick() {
		$src = self::detect();
		$s   = self::state();
		$an  = '' !== $src;
		if ( $an !== (bool) $s['on'] ) {
			$s['changed_at'] = time();
		}
		$s['source'] = $src;
		if ( ! Formexposed_Core::linked() ) {
			$s['on']      = $an;
			$s['sent_at'] = 0;
			update_option( self::OPT, $s, false );
			return;
		}
		if ( $an && ( ! $s['on'] || ! $s['sent_at'] || time() - (int) $s['sent_at'] >= 50 * MINUTE_IN_SECONDS ) ) {
			$r = Formexposed_Core::api_maintenance( true );
			if ( ! is_wp_error( $r ) ) {
				$s['sent_at'] = time();
				$s['until']   = isset( $r['until'] ) ? (string) $r['until'] : null;
			}
		} elseif ( ! $an && $s['on'] && $s['sent_at'] ) {
			$r = Formexposed_Core::api_maintenance( false );
			if ( is_wp_error( $r ) ) {
				update_option( self::OPT, $s, false );   // still "on": the next hour tries "off" again
				return;
			}
			$s['sent_at'] = 0;
			$s['until']   = isset( $r['until'] ) ? $r['until'] : null;
		}
		$s['on'] = $an;
		update_option( self::OPT, $s, false );
	}

	/** One of the switches changed: check in a few seconds (after the request that changed it). */
	public static function soon() {
		if ( ! Formexposed_Core::$stopping && ! wp_next_scheduled( self::CRON_NOW ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_NOW );
		}
	}

	public static function source_text( $src ) {
		$n = array(
			'wordpress'           => __( 'WordPress update', 'formexposed' ),
			'wp-maintenance-mode' => 'WP Maintenance Mode / LightStart',
			'seedprod'            => 'SeedProd Coming Soon',
			'maintenance'         => 'Maintenance (WebFactory)',
			'under-construction'  => 'Under Construction',
			'cmp'                 => 'CMP Coming Soon & Maintenance',
			'elementor'           => __( 'Elementor maintenance mode', 'formexposed' ),
		);
		return isset( $n[ $src ] ) ? $n[ $src ] : $src;
	}
}
