<?php
/**
 * formexposed - content and origin filters of the protection layer (since 1.5.0).
 *
 * The layer itself stops bots by how they send (no page, no interaction, too
 * fast). These filters stop what people or better bots send:
 *
 *   disposable  an e-mail address at a throw-away mail service (built-in list
 *               of well-known services plus your own additions) - on by default
 *   scripts     a message written mainly in a writing system you never get
 *               real enquiries in (Cyrillic, Chinese/Japanese/Korean, Arabic,
 *               Hebrew, Greek, Thai, Devanagari) - judged by the letters, not
 *               by guessing the language; off by default
 *   words       your own list of blocked words or phrases; off by default
 *   countries   only from these countries / never from these countries. The
 *               country comes from Cloudflare (CF-IPCountry), from a GeoIP
 *               module of the web server, or from the filter
 *               formexposed_client_country. Without a source it never blocks.
 *               No address leaves this site for a lookup.
 *
 * Every stopped message goes to the quarantine like all others, with its
 * reason - one click delivers it if a filter was too strict.
 *
 * Option formexposed_filters: array(disposable, extra_disposable, scripts[],
 * words, country_mode off|allow|block, countries).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formexposed_Filters {

	const OPT          = 'formexposed_filters';
	const CANDIDATE    = 'formexposed_filters_candidate';
	const OBSERVATIONS = 'formexposed_filter_observations';

	/** Writing systems that can be blocked, with their Unicode pattern. */
	const SCRIPTS = array(
		'cyrillic'   => '\p{Cyrillic}',
		'cjk'        => '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}',
		'arabic'     => '\p{Arabic}',
		'hebrew'     => '\p{Hebrew}',
		'greek'      => '\p{Greek}',
		'thai'       => '\p{Thai}',
		'devanagari' => '\p{Devanagari}',
	);

	/** Well-known throw-away mail services. Your own additions come from the settings. */
	const DISPOSABLE = array(
		'mailinator.com',
		'mailinator.net',
		'mailinator2.com',
		'guerrillamail.com',
		'guerrillamail.net',
		'guerrillamail.org',
		'guerrillamail.biz',
		'guerrillamail.de',
		'guerrillamailblock.com',
		'grr.la',
		'sharklasers.com',
		'spam4.me',
		'pokemail.net',
		'10minutemail.com',
		'10minutemail.net',
		'20minutemail.com',
		'temp-mail.org',
		'temp-mail.io',
		'tempmail.net',
		'tempmailo.com',
		'tempr.email',
		'tempinbox.com',
		'tempail.com',
		'mytemp.email',
		'yopmail.com',
		'yopmail.fr',
		'yopmail.net',
		'trashmail.com',
		'trashmail.de',
		'trashmail.net',
		'trashmail.at',
		'trash-mail.com',
		'getnada.com',
		'maildrop.cc',
		'dispostable.com',
		'throwawaymail.com',
		'fakeinbox.com',
		'mintemail.com',
		'mailnesia.com',
		'mailcatch.com',
		'mohmal.com',
		'emailondeck.com',
		'spamgourmet.com',
		'moakt.com',
		'burnermail.io',
		'discard.email',
		'discardmail.com',
		'discardmail.de',
		'mailpoof.com',
		'inboxkitten.com',
		'mail.tm',
		'mail.gw',
		'1secmail.com',
		'1secmail.net',
		'1secmail.org',
		'emailfake.com',
		'getairmail.com',
		'harakirimail.com',
		'spambog.com',
		'spambog.de',
		'wegwerfmail.de',
		'wegwerfmail.net',
		'wegwerfemail.de',
		'einrot.com',
		'spoofmail.de',
		'byom.de',
		'0815.ru',
		'sofort-mail.de',
		'mailforspam.com',
		'jetable.org',
		'deadaddress.com',
		'mailexpire.com',
		'incognitomail.org',
		'anonymbox.com',
		'spambox.us',
		'dropmail.me',
		'minuteinbox.com',
	);

	public static function settings( $source = null ) {
		$o = null === $source ? get_option( self::OPT ) : $source;
		$o = is_array( $o ) ? $o : array();
		return array(
			'disposable'       => ! isset( $o['disposable'] ) || ! empty( $o['disposable'] ),
			'extra_disposable' => isset( $o['extra_disposable'] ) ? (string) $o['extra_disposable'] : '',
			'scripts'          => isset( $o['scripts'] ) && is_array( $o['scripts'] ) ? array_values( array_intersect( array_keys( self::SCRIPTS ), $o['scripts'] ) ) : array(),
			'words'            => isset( $o['words'] ) ? (string) $o['words'] : '',
			'country_mode'     => isset( $o['country_mode'] ) && in_array( $o['country_mode'], array( 'allow', 'block' ), true ) ? $o['country_mode'] : 'off',
			'countries'        => isset( $o['countries'] ) ? (string) $o['countries'] : '',
		);
	}

	/** Lines of a text setting, trimmed, lower case, without empty ones. */
	private static function lines( $text ) {
		return array_values(
			array_filter(
				array_map(
					static function ( $z ) {
						return strtolower( trim( $z ) );
					},
					preg_split( '/[\r\n,]+/', (string) $text )
				)
			)
		);
	}

	/** Country codes from the setting (ISO 3166-1 alpha-2, T1 = Tor at Cloudflare). */
	public static function country_list( $text ) {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $c ) {
							return strtoupper( trim( $c ) );
						},
						preg_split( '/[\s,;]+/', (string) $text )
					),
					static function ( $c ) {
						return (bool) preg_match( '/^([A-Z]{2}|T1)$/', $c );
					}
				)
			)
		);
	}

	/**
	 * The visitor's country, or '' when there is no reliable source. Cloudflare only
	 * counts when the request came through Cloudflare (CF-Ray present).
	 */
	public static function client_country() {
		$c = (string) apply_filters( 'formexposed_client_country', '' );
		if ( '' === $c && ! empty( $_SERVER['HTTP_CF_RAY'] ) && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$c = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		}
		foreach ( array( 'GEOIP_COUNTRY_CODE', 'MM_COUNTRY_CODE' ) as $k ) {   // set by the web server's GeoIP module, not by the visitor
			if ( '' === $c && ! empty( $_SERVER[ $k ] ) ) {
				$c = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			}
		}
		$c = strtoupper( trim( $c ) );
		return preg_match( '/^([A-Z]{2}|T1)$/', $c ) && 'XX' !== $c ? $c : '';
	}

	/** Where the country would come from - for the settings page. */
	public static function country_source() {
		if ( has_filter( 'formexposed_client_country' ) ) {
			return 'filter';
		}
		if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return 'cloudflare';
		}
		if ( ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) || ! empty( $_SERVER['MM_COUNTRY_CODE'] ) ) {
			return 'geoip';
		}
		return '';
	}

	/** Is the domain of an address (or a parent domain of it) a throw-away service? */
	public static function is_disposable( $address, $extra = '' ) {
		$at = strrpos( (string) $address, '@' );
		if ( false === $at ) {
			return false;
		}
		$dom   = strtolower( rtrim( trim( substr( $address, $at + 1 ) ), '.' ) );
		$liste = array_flip( array_merge( self::DISPOSABLE, self::lines( $extra ) ) );
		for ( $x = $dom; '' !== $x && false !== strpos( $x, '.' ); $x = (string) substr( $x, strpos( $x, '.' ) + 1 ) ) {
			if ( isset( $liste[ $x ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is a text written mainly in one of the blocked writing systems? At least 20
	 * letters, and more than half of them in the blocked systems together.
	 */
	public static function blocked_script( $text, $scripts ) {
		if ( ! $scripts || ! function_exists( 'preg_match_all' ) ) {
			return false;
		}
		$alle = preg_match_all( '/\p{L}/u', (string) $text );
		if ( ! $alle || $alle < 20 ) {
			return false;
		}
		$klasse = '';
		foreach ( $scripts as $s ) {
			if ( isset( self::SCRIPTS[ $s ] ) ) {
				$klasse .= self::SCRIPTS[ $s ];
			}
		}
		if ( '' === $klasse ) {
			return false;
		}
		$n = preg_match_all( '/[' . $klasse . ']/u', (string) $text );
		return $n > $alle / 2;
	}

	/** Does the text contain one of the blocked words? Whole words, case does not matter. */
	public static function blocked_word( $text, $words ) {
		$text = (string) $text;
		foreach ( self::lines( $words ) as $w ) {
			if ( mb_strlen( $w ) < 2 ) {
				continue;
			}
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/iu', $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The check, called by Formexposed_Guard after the field checks. $fields: list of
	 * array(value, kind) as the guard collects them, $pairs: array(label, value) of
	 * everything a person filled in. Returns a reason key or null.
	 */
	public static function check( $fields, $pairs ) {
		$active    = self::evaluate( $fields, $pairs, self::settings() );
		$candidate = self::candidate();
		if ( $candidate ) {
			$reason = self::evaluate( $fields, $pairs, $candidate['settings'] );
			if ( $reason && ! $active ) {
				self::observe( $reason, $candidate['hash'] );
			}
		}
		return $active;
	}

	public static function candidate() {
		$c = get_option( self::CANDIDATE, array() );
		if ( ! is_array( $c ) || empty( $c['settings'] ) || empty( $c['hash'] ) ) {
			return null;
		}
		if ( (int) ( $c['expires'] ?? 0 ) <= time() ) {
			delete_option( self::CANDIDATE );
			delete_option( self::OBSERVATIONS );
			return null;}
		return $c;
	}

	public static function observe( $reason, $hash ) {
		if ( ! in_array( $reason, array( 'disposable', 'script', 'keyword', 'country' ), true ) ) {
			return;
		}
		global $wpdb;
		$lock = 'fe_filter_observe_' . substr( hash( 'sha256', home_url() ), 0, 24 );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,1)', $lock ) ) !== 1 ) {
			return;
		}
		try {
			wp_cache_delete( self::OBSERVATIONS, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$o = get_option( self::OBSERVATIONS, array() );
			if ( ! is_array( $o ) || ( $o['hash'] ?? '' ) !== $hash ) {
				$o = array(
					'hash' => $hash,
					'days' => array(),
				);
			}
			$day = gmdate( 'Y-m-d' );
			foreach ( array_keys( $o['days'] ) as $d ) {
				if ( $d < gmdate( 'Y-m-d', time() - 13 * DAY_IN_SECONDS ) ) {
					unset( $o['days'][ $d ] );
				}
			}
			$o['days'][ $day ][ $reason ] = min( 1000000, (int) ( $o['days'][ $day ][ $reason ] ?? 0 ) + 1 );
			update_option( self::OBSERVATIONS, $o, false );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
	}

	public static function store_candidate( $settings ) {
		$s = self::settings( $settings );
		update_option(
			self::CANDIDATE,
			array(
				'settings' => $s,
				'hash'     => hash( 'sha256', wp_json_encode( $s ) ),
				'expires'  => time() + 7 * DAY_IN_SECONDS,
			),
			false
		);
		delete_option( self::OBSERVATIONS );
	}

	public static function evaluate( $fields, $pairs, $s ) {
		if ( $s['disposable'] ) {
			foreach ( $fields as $f ) {
				if ( 'email' === $f[1] && self::is_disposable( wp_unslash( (string) $f[0] ), $s['extra_disposable'] ) ) {
					return 'disposable';
				}
			}
		}
		$text = implode(
			"\n",
			array_map(
				static function ( $p ) {
					return (string) $p[1];
				},
				(array) $pairs
			)
		);
		if ( $s['scripts'] && self::blocked_script( $text, $s['scripts'] ) ) {
			return 'script';
		}
		if ( '' !== trim( $s['words'] ) && self::blocked_word( $text, $s['words'] ) ) {
			return 'keyword';
		}
		if ( 'off' !== $s['country_mode'] ) {
			$land  = self::client_country();
			$liste = self::country_list( $s['countries'] );
			if ( '' !== $land && $liste ) {
				$drin = in_array( $land, $liste, true );
				if ( ( 'allow' === $s['country_mode'] && ! $drin ) || ( 'block' === $s['country_mode'] && $drin ) ) {
					return 'country';
				}
			}
		}
		return null;
	}

	public static function action_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_filters' );
		$p = wp_unslash( $_POST );
		if ( ! empty( $p['discard_candidate'] ) ) {
			delete_option( self::CANDIDATE );
			delete_option( self::OBSERVATIONS );
			wp_safe_redirect( Formexposed_Core::page_url() );
			exit;
		}
		if ( ! empty( $p['activate_candidate'] ) ) {
			$c = self::candidate();
			if ( ! $c ) {
				wp_die( esc_html__( 'Observation expired. Start a new test.', 'formexposed' ) );
			}
			update_option( self::OPT, $c['settings'], false );
			delete_option( self::CANDIDATE );
			delete_option( self::OBSERVATIONS );
			wp_safe_redirect( Formexposed_Core::page_url() );
			exit;
		}
		$scripts  = isset( $p['scripts'] ) && is_array( $p['scripts'] ) ? array_values( array_intersect( array_keys( self::SCRIPTS ), array_map( 'sanitize_key', $p['scripts'] ) ) ) : array();
		$settings = array(
			'disposable'       => ! empty( $p['disposable'] ),
			'extra_disposable' => isset( $p['extra_disposable'] ) ? implode( "\n", array_slice( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n,]+/', (string) $p['extra_disposable'] ) ) ), 0, 500 ) ) : '',
			'scripts'          => $scripts,
			'words'            => isset( $p['words'] ) ? implode( "\n", array_slice( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n]+/', (string) $p['words'] ) ) ), 0, 500 ) ) : '',
			'country_mode'     => isset( $p['country_mode'] ) && in_array( $p['country_mode'], array( 'allow', 'block' ), true ) ? $p['country_mode'] : 'off',
			'countries'        => isset( $p['countries'] ) ? implode( ', ', self::country_list( (string) $p['countries'] ) ) : '',
		);
		if ( ( $p['filter_mode'] ?? 'active' ) === 'observe' ) {
			self::store_candidate( $settings );
		} else {
			update_option( self::OPT, $settings, false );
			delete_option( self::CANDIDATE );
			delete_option( self::OBSERVATIONS );}
		set_transient( 'formexposed_msg', array( 'ok', __( 'Saved.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url() );
		exit;
	}
}
