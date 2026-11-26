<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formexposed - proof of work (since 1.4.0), one more layer of the protection
 * layer. An invisible replacement for a CAPTCHA in the style of ALTCHA - our
 * own implementation: no third-party script, no request to anyone else, no
 * cookie.
 *
 *   1. When a visitor first uses a form, the page asks THIS site for a
 *      challenge (admin-ajax, action formexposed_pow - a POST, so page caches
 *      never freeze it): a random salt with an expiry, the SHA-256 hash of
 *      salt + a secret number, and an HMAC signature of that hash.
 *   2. The browser tries the numbers 0 ... MAX until sha256(salt + n) matches,
 *      in small steps so the page stays responsive, and puts the answer into
 *      the hidden field formexposed_pow. A submit that comes before the answer
 *      waits for it (well under a second on a phone).
 *   3. On submission this site checks the signature, the expiry, the hash and
 *      that the answer was not used before (transient). A submission without a
 *      valid answer is stopped with the reason "pow" and waits in the
 *      quarantine like every other stopped one.
 *
 * What it costs a bot: every submission needs a fresh search. A person sees
 * nothing and solves nothing, and there is no time limit a screen reader user
 * could miss - an answer that expires while the form is open is replaced
 * before sending.
 *
 * The HMAC key is derived from this site's WordPress salts; it never leaves
 * the server. Switched on in Settings -> formexposed (only together with the
 * protection layer), or by the one-click move away from reCAPTCHA - both run
 * self_test() first, which also asks admin-ajax.php the way a visitor would:
 * a security plugin that blocks it for visitors would otherwise make every
 * form refuse its messages.
 */

class Formexposed_Pow {

	const OPT    = 'formexposed_pow';   // array('on' => bool, 'since' => int)
	const MAX    = 50000;               // the highest number a browser may have to try
	const TTL    = 3600;                // seconds a challenge stays valid
	const ACTION = 'formexposed_pow';   // admin-ajax action that hands out challenges
	const CHUNK  = 4000;                // hashes per step in the browser: about 3 ms on a desktop, 20 ms on a slow phone
	const GRACE  = 86400;               // first day after switching on: pages from a page cache may still lack the field

	/** The answer of the current submission; marked as used once the submission passed every check. */
	private static $pending = null;

	public static function init() {
		// Already with the protection layer alone: the self-test asks this endpoint before the proof is
		// switched on. A challenge is stateless and cheap; it reveals nothing.
		if ( Formexposed_Guard::on() ) {
			add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
			// Public on purpose and without a nonce: visitors (and cached pages) fetch a signed task; the handler stores
		// nothing and changes nothing, the answer is checked with its signature when the form is sent.
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		}
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_pow', array( __CLASS__, 'action_toggle' ) );
		}
	}

	/** Switched on in the settings (the protection layer may still be off). */
	public static function enabled() {
		$o = get_option( self::OPT );
		return is_array( $o ) && ! empty( $o['on'] );
	}

	/** In effect: switched on AND the protection layer is on - the proof is one of its checks. */
	public static function on() {
		return self::enabled() && Formexposed_Guard::on();
	}

	/** Switching changes every form: the pages in a page cache still show the old one, so they are emptied. */
	public static function set( $on ) {
		$vorher = self::enabled();
		update_option( self::OPT, array( 'on' => (bool) $on, 'since' => time() ), false );
		if ( $vorher !== (bool) $on ) {
			self::purge_page_caches();
		}
	}

	/**
	 * The first day after switching on, a submission that has NO field
	 * formexposed_pow at all is let through: it comes from a page that a page
	 * cache or CDN still serves from before the switch. An answer that is
	 * there, even empty, must be right. The other checks apply as always.
	 */
	public static function in_grace() {
		$o = get_option( self::OPT );
		return is_array( $o ) && ! empty( $o['on'] ) && isset( $o['since'] ) && time() - (int) $o['since'] < self::GRACE;
	}

	/** Empties the page caches of the common caching plugins (best effort). Returns how many were asked. */
	public static function purge_page_caches() {
		$aufrufe = array();
		// WP Rocket, W3 Total Cache, WP Super Cache, SiteGround Optimizer
		foreach ( array( 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_cache' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$aufrufe[] = $fn;
			}
		}
		// LiteSpeed Cache, Cache Enabler, Breeze, Hummingbird
		foreach ( array( 'litespeed_purge_all', 'cache_enabler_clear_complete_cache', 'breeze_clear_all_cache', 'wphb_clear_page_cache' ) as $hook ) {
			if ( has_action( $hook ) ) {
				$aufrufe[] = static function () use ( $hook ) {
					do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the purge action of that cache plugin, from the fixed list above
				};
			}
		}
		// WP Fastest Cache
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && is_object( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$aufrufe[] = static function () {
				$GLOBALS['wp_fastest_cache']->deleteCache( true );
			};
		}
		$n = 0;
		foreach ( $aufrufe as $a ) {
			try {
				call_user_func( $a );
				$n++;
			} catch ( Throwable $e ) {
				continue;   // a cache plugin that fails must not stop the switch
			}
		}
		return $n;
	}

	/** Difficulty. 50,000 means about 25,000 hashes on average - see readme.txt for measured times. */
	public static function max_number() {
		return max( 1000, min( 1000000, (int) apply_filters( 'formexposed_pow_max', self::MAX ) ) );
	}

	/** HMAC key for the signatures: from the WordPress salts of this site, never sent anywhere. */
	private static function key() {
		return hash_hmac( 'sha256', 'formexposed-pow', wp_salt( 'auth' ) );
	}

	/** A fresh challenge (ALTCHA format). The secret number is not part of it. */
	public static function challenge() {
		$max    = self::max_number();
		$salt   = bin2hex( random_bytes( 8 ) ) . '?expires=' . ( time() + self::TTL );
		$number = random_int( 0, $max );
		$ch     = hash( 'sha256', $salt . $number );
		return array(
			'algorithm' => 'SHA-256',
			'challenge' => $ch,
			'maxnumber' => $max,
			'salt'      => $salt,
			'signature' => hash_hmac( 'sha256', $ch, self::key() ),
		);
	}

	/** admin-ajax: hands out one challenge. Stateless - nothing is stored until an answer comes back. */
	public static function ajax() {
		nocache_headers();
		wp_send_json( self::challenge() );
	}

	/**
	 * Checks an answer from the field formexposed_pow: the JSON
	 * {algorithm, challenge, number, salt, signature}. Returns true, or a short
	 * reason (missing, format, expired, signature, hash, used) for tests.
	 */
	public static function verify( $value ) {
		self::$pending = null;
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return 'missing';
		}
		if ( strlen( $value ) > 1000 ) {
			return 'format';
		}
		// Since 1.9.0 plain JSON (before: base64 of it). Some form plugins hand it over still slashed.
		$j = '{' === substr( $value, 0, 1 ) ? json_decode( $value, true ) : null;
		if ( ! is_array( $j ) && '{' === substr( $value, 0, 1 ) ) {
			$j = json_decode( wp_unslash( $value ), true );
		}
		if ( ! is_array( $j ) || ! isset( $j['algorithm'], $j['challenge'], $j['number'], $j['salt'], $j['signature'] ) || 'SHA-256' !== $j['algorithm'] ) {
			return 'format';
		}
		$ch   = (string) $j['challenge'];
		$sig  = (string) $j['signature'];
		$salt = (string) $j['salt'];
		$num  = $j['number'];
		if ( ! ( is_int( $num ) || ( is_string( $num ) && ctype_digit( $num ) ) ) || (int) $num < 0 || (int) $num > 1000000
			|| ! preg_match( '/^[a-f0-9]{64}$/', $ch ) || ! preg_match( '/^[a-f0-9]{64}$/', $sig )
			|| ! preg_match( '/^[a-f0-9]{8,64}\?expires=(\d{9,11})$/', $salt, $m ) ) {
			return 'format';
		}
		$bis = (int) $m[1];
		if ( $bis < time() ) {
			return 'expired';
		}
		if ( $bis > time() + self::TTL + 300 ) {
			return 'format';   // no challenge of ours lives that long
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $ch, self::key() ), $sig ) ) {
			return 'signature';
		}
		if ( ! hash_equals( $ch, hash( 'sha256', $salt . (int) $num ) ) ) {
			return 'hash';
		}
		$id = 'formexposed_pow_' . substr( $ch, 0, 32 );
		if ( false !== get_transient( $id ) ) {
			return 'used';
		}
		self::$pending = array( $id, $bis );
		return true;
	}

	/**
	 * The submission passed every check: its answer cannot be used again.
	 * Only then - a person whose message was refused for another reason (a
	 * mistyped e-mail domain) keeps a valid answer. Two parallel submissions
	 * with the same answer could both pass; one search still buys at most a
	 * handful of messages, never a flood.
	 */
	public static function consume() {
		if ( null === self::$pending ) {
			return;
		}
		set_transient( self::$pending[0], 1, max( 60, self::$pending[1] - time() + 60 ) );
		self::$pending = null;
	}

	/** Search the number like a browser would (for the self-test). */
	public static function solve( $c ) {
		$max = (int) $c['maxnumber'];
		for ( $n = 0; $n <= $max; $n++ ) {
			if ( hash( 'sha256', $c['salt'] . $n ) === $c['challenge'] ) {
				return $n;
			}
		}
		return null;
	}

	/** The answer a browser puts into the field. */
	public static function answer( $c, $n ) {
		return wp_json_encode( array( 'algorithm' => $c['algorithm'], 'challenge' => $c['challenge'], 'number' => (int) $n, 'salt' => $c['salt'], 'signature' => $c['signature'] ) );
	}

	/**
	 * Self-test on this server: make a challenge, solve it, check the answer,
	 * check that it cannot be used twice and that a forged one is refused.
	 * Returns true or WP_Error. Runs before the proof is switched on.
	 */
	public static function self_test() {
		$c = self::challenge();
		$n = self::solve( $c );
		if ( null === $n ) {
			return new WP_Error( 'pow_solve', __( 'Self-test of the proof of work failed: the challenge could not be solved.', 'formexposed' ) );
		}
		$gut = self::answer( $c, $n );
		if ( true !== self::verify( $gut ) ) {
			return new WP_Error( 'pow_verify', __( 'Self-test of the proof of work failed: a correct answer was refused. Nothing was changed.', 'formexposed' ) );
		}
		self::consume();
		$c['signature'] = str_repeat( '0', 64 );
		if ( 'used' !== self::verify( $gut ) || true === self::verify( self::answer( $c, $n ) ) ) {
			return new WP_Error( 'pow_replay', __( 'Self-test of the proof of work failed: a used or forged answer was accepted (is the object cache working?). Nothing was changed.', 'formexposed' ) );
		}
		self::$pending = null;
		// Visitors fetch the challenge from admin-ajax.php without being logged in. If a security plugin or a
		// server rule blocks that, every form would be refused - so ask it once like a visitor would. No
		// answer at all (a host without loopback requests) or a password prompt (staging) cannot be judged.
		$r = wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'timeout'     => 10,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress' own filter for requests to the site itself
			'body'        => array( 'action' => self::ACTION ),
		) );
		if ( ! is_wp_error( $r ) ) {
			$code = (int) wp_remote_retrieve_response_code( $r );
			$j    = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( 401 !== $code && ( 200 !== $code || ! is_array( $j ) || empty( $j['challenge'] ) ) ) {
				/* translators: %d: HTTP status code */
				return new WP_Error( 'pow_ajax', sprintf( __( 'Visitors cannot fetch the challenge from admin-ajax.php (answer: HTTP %d). A security plugin or a server rule blocks it - with the proof of work on, every form would be refused. Nothing was changed.', 'formexposed' ), $code ) );
			}
		}
		return true;
	}

	/** Configuration for the inline script of the protection layer (Formexposed_Guard::script). */
	public static function script_config() {
		return array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::ACTION,
			'ttl'    => self::TTL,
			'chunk'  => self::CHUNK,
		);
	}

	/** Settings page: switch on (after the self-test) or off. */
	public static function action_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_pow' );
		if ( ! empty( $_POST['pow_on'] ) ) {
			if ( ! Formexposed_Guard::on() ) {
				set_transient( 'formexposed_msg', array( 'error', __( 'Turn on the protection layer first - the proof of work is one of its checks.', 'formexposed' ) ), 120 );
			} else {
				$t = self::self_test();
				if ( is_wp_error( $t ) ) {
					set_transient( 'formexposed_msg', array( 'error', $t->get_error_message() ), 120 );
				} else {
					self::set( true );
					set_transient( 'formexposed_msg', array( 'ok', __( 'The invisible proof of work is on. Self-test passed. If a CDN caches your pages, clear its cache too - for the first day, messages from old cached pages are still let through.', 'formexposed' ) ), 120 );
				}
			}
		} else {
			self::set( false );
			set_transient( 'formexposed_msg', array( 'ok', __( 'The proof of work is off.', 'formexposed' ) ), 120 );
		}
		wp_safe_redirect( Formexposed_Core::page_url() );
		exit;
	}
}
