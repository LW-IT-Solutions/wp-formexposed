<?php
/**
 * formexposed - inventory and form settings check (since 1.4.0).
 *
 *   Inventory   slug and version of the ACTIVE plugins on the watch list - the
 *               form, CAPTCHA and mail plugins formexposed.com compares with a
 *               vulnerability database. The list is fixed here (DEFAULT_SLUGS);
 *               the list the service sends with its answers (option
 *               formexposed_watch_slugs) can only narrow it, never add a slug.
 *               Nothing else of the plugin list is sent.
 *   Form check  settings of the forms of Contact Form 7, WPForms, Gravity Forms
 *               and Elementor Pro that often cost enquiries or expose data:
 *               sender = the visitor's address (fails DMARC), no notification,
 *               freemail or outside recipients, file fields without a type
 *               limit, upload folders that list their files, auto-replies, the
 *               way WordPress sends mail, places still using reCAPTCHA.
 *               formexposed.com receives only the COUNTS (Formexposed_Core::api_stats,
 *               field "config"); form names and details stay on this site
 *               (Settings -> formexposed -> Form settings). Never an address,
 *               never content.
 *
 * Every form plugin is read through its own data and each one is wrapped: a
 * missing plugin or an unexpected structure only means "nothing found there".
 * The upload folders are checked with a request to this site itself
 * (loopback), at most every 12 hours.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formexposed_Health {

	/** Options of other plugins this class reads (or, where said, changes). */
	const OPTION_EASY_WP_SMTP = 'easy_wp_smtp';
	const OPTION_FLUENTMAIL_SETTINGS = 'fluentmail-settings';
	const OPTION_POSTMAN_OPTIONS = 'postman_options';
	const OPTION_SWPSMTP_OPTIONS = 'swpsmtp_options';
	const OPTION_WP_MAIL_SMTP = 'wp_mail_smtp';

	const OPT_SLUGS = 'formexposed_watch_slugs';
	const OPT_SCAN  = 'formexposed_health';     // the last form check, for the settings page and WP-CLI
	const T_LISTING = 'formexposed_listing';    // upload folder check, 12 hours
	const MAX_FORMS = 200;                      // per form plugin

	/** The watch list: identical to FE_LUECKEN_SLUGS on formexposed.com (cfg/luecken_lib.php). Authoritative - the service can only narrow it. */
	const DEFAULT_SLUGS = array(
		'contact-form-7', 'wpforms-lite', 'wpforms', 'gravityforms', 'ninja-forms', 'ninja-forms-uploads', 'forminator', 'fluentform', 'fluentformpro',
		'formidable', 'formidable-pro', 'elementor', 'elementor-pro', 'everest-forms', 'happyforms', 'caldera-forms', 'weforms', 'wpcf7-redirect',
		'contact-form-cfdb7', 'advanced-cf7-db', 'flamingo', 'cf7-conditional-fields', 'contact-form-7-honeypot', 'jetpack',
		'advanced-nocaptcha-recaptcha', 'simple-cloudflare-turnstile', 'hcaptcha-for-forms-and-more', 'altcha-spam-protection', 'really-simple-captcha',
		'akismet', 'antispam-bee', 'zero-spam', 'cleantalk-spam-protect', 'wp-armour-extended',
		'wp-mail-smtp', 'post-smtp', 'fluent-smtp', 'easy-wp-smtp', 'wp-mail-logging', 'check-email',
		'formexposed',
	);

	/** Free mail providers (plus FREEMAIL_RE for hotmail.*, yahoo.*, gmx.* ...). */
	const FREEMAIL = array(
		'gmail.com', 'googlemail.com', 'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'mail.com',
		'proton.me', 'protonmail.com', 'pm.me', 'tutanota.com', 'tuta.io', 'zohomail.com', 'yandex.com', 'yandex.ru', 'mail.ru',
		'web.de', 't-online.de', 'freenet.de', 'arcor.de',
		'wp.pl', 'o2.pl', 'onet.pl', 'onet.eu', 'op.pl', 'poczta.onet.pl', 'vp.pl', 'interia.pl', 'interia.eu', 'gazeta.pl', 'tlen.pl',
	);
	const FREEMAIL_RE = '/^(hotmail|yahoo|ymail|gmx|live|outlook|aol)\.[a-z]{2,3}(\.[a-z]{2})?$/';

	/** Upload folders of form plugins, below wp-content/uploads. */
	const UPLOAD_DIRS = array( 'wpcf7_uploads', 'wpforms', 'gravity_forms', 'forminator', 'fluentform', 'formidable', 'ninja-forms', 'elementor/forms', 'everest_forms_uploads', 'happyforms' );

	/** Contact Form 7 mail-tag of a form field ([your-email]); special tags start with "_". */
	const CF7_TAG = '/\[\s*[a-zA-Z][0-9a-zA-Z:._-]*\s*\]/';
	/** Gravity Forms merge tag of a field ({Email:2}, {Name (First):1.3}). */
	const GF_FIELD_TAG = '/\{[^{}]*:\d+(\.\d+)?(:[^{}]*)?\}/';

	/** Per request: the forms of Gravity Forms and the Elementor form widgets are read once (form check, reCAPTCHA, settings page). */
	private static $gf_cache = null;
	private static $el_cache = null;

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_forms_check', array( __CLASS__, 'action_check' ) );
		}
	}

	/** "Check again" on the Form settings tab: read the forms and request the upload folders now. */
	public static function action_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_forms_check' );
		self::scan( 'fresh' );
		set_transient( 'formexposed_msg', array( 'ok', __( 'Checked again.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'forms' ) ) );
		exit;
	}

	// ------------------------------------------------------------------ inventory

	/** Active plugins (site and network): slug (directory name) => plugin file. */
	public static function active() {
		$files = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $files as $f ) {
			$f    = (string) $f;
			$slug = false !== strpos( $f, '/' ) ? substr( $f, 0, strpos( $f, '/' ) ) : preg_replace( '/\.php$/', '', $f );
			if ( '' !== $slug ) {
				$out[ strtolower( $slug ) ] = $f;
			}
		}
		return $out;
	}

	/**
	 * The slugs whose versions are reported. The built-in list (DEFAULT_SLUGS)
	 * is authoritative: the list formexposed.com sends with its answers can
	 * only NARROW it - its slugs that are not built in are ignored. No list or
	 * an empty one from the service = the whole built-in list.
	 */
	public static function slugs() {
		$l = get_option( self::OPT_SLUGS );
		if ( ! is_array( $l ) || ! $l ) {
			return self::DEFAULT_SLUGS;
		}
		return array_values( array_intersect( self::DEFAULT_SLUGS, $l ) );
	}

	/** The list from the answer of POST /api/plugin/stats (cleaned; an empty one removes the narrowing). */
	public static function set_slugs( $list ) {
		$out = array();
		foreach ( array_slice( (array) $list, 0, 500 ) as $s ) {
			$s = strtolower( (string) $s );
			if ( preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $s ) ) {
				$out[] = $s;
			}
		}
		if ( $out ) {
			update_option( self::OPT_SLUGS, array_values( array_unique( $out ) ), false );
		} else {
			delete_option( self::OPT_SLUGS );
		}
	}

	/** {slug: version} of the active plugins on the watch list. */
	public static function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$alle  = get_plugins();
		$watch = array_flip( self::slugs() );
		$out   = array();
		foreach ( self::active() as $slug => $file ) {
			$v = isset( $alle[ $file ]['Version'] ) ? (string) $alle[ $file ]['Version'] : '';
			if ( isset( $watch[ $slug ] ) && preg_match( '/^[0-9][0-9a-z.+_-]{0,30}$/i', $v ) ) {
				$out[ $slug ] = $v;
			}
		}
		ksort( $out );
		return $out;
	}

	// ------------------------------------------------------------------ form check

	/**
	 * Reads the forms and stores the result (option OPT_SCAN).
	 * $listing: 'cache' = upload folders only from the last check (no request),
	 * 'check' = request them if the last check is older than 12 hours, 'fresh' = request now.
	 */
	public static function scan( $listing = 'check' ) {
		$forms = array();
		foreach ( array( 'cf7_forms', 'wpforms_forms', 'gf_forms', 'elementor_forms' ) as $m ) {
			try {
				$forms = array_merge( $forms, (array) call_user_func( array( __CLASS__, $m ) ) );
			} catch ( Throwable $e ) {
				continue;   // unexpected data in one plugin: nothing found there, the others still count
			}
		}
		$counts = array( 'forms' => count( $forms ), 'from_visitor' => 0, 'notifications_off' => 0, 'recipient_freemail' => 0, 'recipient_external' => 0, 'upload_no_types' => 0, 'uploads_listing' => 0, 'autoresponder' => 0 );
		$items  = array();
		foreach ( $forms as $f ) {
			if ( $f['from_visitor'] ) {
				$counts['from_visitor']++;
				$items[] = self::item( 'from_visitor', $f );
			}
			if ( ! $f['notify'] ) {
				$counts['notifications_off']++;
				$items[] = self::item( 'notifications_off', $f );
			}
			$frei = array();
			$raus = array();
			foreach ( $f['recipients'] as $a ) {
				$dom = strtolower( (string) substr( (string) strrchr( $a, '@' ), 1 ) );
				if ( self::is_freemail( $dom ) ) {
					$frei[] = $dom;
				} elseif ( '' !== $dom && ! self::is_own( $dom ) ) {
					$raus[] = $dom;
				}
			}
			if ( $frei ) {
				$counts['recipient_freemail']++;
				$items[] = self::item( 'recipient_freemail', $f, implode( ', ', array_unique( $frei ) ) );
			} elseif ( $raus ) {
				$counts['recipient_external']++;
				$items[] = self::item( 'recipient_external', $f, implode( ', ', array_unique( $raus ) ) );
			}
			if ( $f['upload_no_types'] > 0 ) {
				$counts['upload_no_types'] += (int) $f['upload_no_types'];
				$items[] = self::item( 'upload_no_types', $f, (string) (int) $f['upload_no_types'] );
			}
			if ( $f['autoresponder'] ) {
				$counts['autoresponder']++;
				$items[] = self::item( 'autoresponder', $f );
			}
		}
		$dirs = self::listing( $listing );
		foreach ( $dirs as $d ) {
			if ( 'listing' === $d['state'] ) {
				$counts['uploads_listing']++;
				$items[] = array( 'kind' => 'uploads_listing', 'plugin' => '', 'id' => 0, 'form' => self::uploads_rel() . $d['dir'] . '/', 'detail' => $d['url'], 'edit' => '' );
			}
		}
		$r = array(
			'at'        => time(),
			'counts'    => $counts,
			'plugins'   => self::form_plugins(),
			'mailer'    => self::mailer(),
			'recaptcha' => Formexposed_Recaptcha::places(),
			'pow'       => Formexposed_Pow::on() ? 1 : 0,
			'items'     => array_slice( $items, 0, 300 ),
			'listing'   => $dirs,
			'forms'     => array_map( static function ( $f ) {
				return array( $f['plugin'], $f['id'], $f['title'], $f['edit'] );
			}, array_slice( $forms, 0, 300 ) ),
		);
		update_option( self::OPT_SCAN, $r, false );
		return $r;
	}

	/** The upload folder relative to the WordPress folder, for display ("wp-content/uploads/" on most sites). */
	public static function uploads_rel() {
		$dir  = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
		$root = wp_normalize_path( ABSPATH );
		return trailingslashit( 0 === strpos( $dir, $root ) ? substr( $dir, strlen( $root ) ) : $dir );
	}

	/** The last stored check (or an empty one). */
	public static function last() {
		$r = get_option( self::OPT_SCAN );
		return is_array( $r ) && isset( $r['counts'] ) ? $r : array();
	}

	/** What formexposed.com receives: counts and short ids, no names, no addresses. */
	public static function payload( $scan ) {
		return array_merge( $scan['counts'], array(
			'plugins'   => $scan['plugins'],
			'mailer'    => $scan['mailer'],
			'recaptcha' => $scan['recaptcha'],
			'pow'       => (int) $scan['pow'],
		) );
	}

	private static function item( $kind, $f, $detail = '' ) {
		return array( 'kind' => $kind, 'plugin' => $f['plugin'], 'id' => $f['id'], 'form' => $f['title'], 'detail' => $detail, 'edit' => $f['edit'] );
	}

	/** Short ids of the active form plugins. */
	public static function form_plugins() {
		$a   = self::active();
		$out = array();
		if ( Formexposed_Guard::cf7() ) {
			$out[] = 'cf7';
		}
		if ( defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' ) ) {
			$out[] = 'wpforms';
		}
		if ( class_exists( 'GFForms' ) ) {
			$out[] = 'gravityforms';
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$out[] = 'elementor';
		}
		foreach ( array( 'ninja-forms' => 'ninja_forms', 'fluentform' => 'fluentform', 'forminator' => 'forminator', 'formidable' => 'formidable' ) as $slug => $id ) {
			if ( isset( $a[ $slug ] ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * One record per form: plugin, id, title, edit (URL), notify (an e-mail to
	 * the site owner is sent), from_visitor, recipients (addresses of that
	 * e-mail - used here, never sent), autoresponder, upload_no_types (fields).
	 */
	private static function cf7_forms() {
		if ( ! Formexposed_Guard::cf7() || ! function_exists( 'wpcf7_contact_form' ) ) {
			return array();
		}
		$out   = array();
		$admin = (string) get_option( 'admin_email' );
		$ids   = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'numberposts' => self::MAX_FORMS, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $ids as $id ) {
			$f = wpcf7_contact_form( $id );
			if ( ! $f || ! method_exists( $f, 'prop' ) ) {
				continue;
			}
			$mail   = (array) $f->prop( 'mail' );
			$mail2  = (array) $f->prop( 'mail_2' );
			$rcpt   = isset( $mail['recipient'] ) ? (string) $mail['recipient'] : '';
			$aus    = ( method_exists( $f, 'is_true' ) && ( $f->is_true( 'skip_mail' ) || $f->is_true( 'demo_mode' ) ) ) || '' === trim( $rcpt );
			$dateien = 0;
			if ( method_exists( $f, 'scan_form_tags' ) ) {
				foreach ( (array) $f->scan_form_tags( array( 'basetype' => 'file' ) ) as $tag ) {
					if ( is_object( $tag ) && method_exists( $tag, 'has_option' ) && ! $tag->has_option( 'filetypes' ) ) {
						$dateien++;
					}
				}
			}
			$out[] = array(
				'plugin'          => 'cf7',
				'id'              => (int) $id,
				'title'           => method_exists( $f, 'title' ) ? (string) $f->title() : 'Contact Form 7',
				'edit'            => admin_url( 'admin.php?page=wpcf7&post=' . (int) $id . '&action=edit' ),
				'notify'          => ! $aus,
				'from_visitor'    => ! $aus && (bool) preg_match( self::CF7_TAG, self::address_part( isset( $mail['sender'] ) ? (string) $mail['sender'] : '' ) ),
				'recipients'      => $aus ? array() : self::emails( str_replace( '[_site_admin_email]', $admin, $rcpt ) ),
				'autoresponder'   => ! empty( $mail2['active'] ) && (bool) preg_match( self::CF7_TAG, isset( $mail2['recipient'] ) ? (string) $mail2['recipient'] : '' ),
				'upload_no_types' => $dateien,
			);
		}
		return $out;
	}

	private static function wpforms_forms() {
		if ( ! defined( 'WPFORMS_VERSION' ) && ! function_exists( 'wpforms' ) ) {
			return array();
		}
		$out   = array();
		$admin = (string) get_option( 'admin_email' );
		$lite  = function_exists( 'wpforms' ) && is_object( wpforms() ) && method_exists( wpforms(), 'is_pro' ) && ! wpforms()->is_pro();
		foreach ( get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'numberposts' => self::MAX_FORMS, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $p ) {
			$d = function_exists( 'wpforms_decode' ) ? wpforms_decode( $p->post_content ) : json_decode( $p->post_content, true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			$s      = isset( $d['settings'] ) && is_array( $d['settings'] ) ? $d['settings'] : array();
			$notes  = array();
			if ( ! empty( $s['notification_enable'] ) ) {
				if ( ! empty( $s['notifications'] ) && is_array( $s['notifications'] ) ) {
					$notes = $s['notifications'];
				} elseif ( ! empty( $s['notification_email'] ) ) {   // forms from before WPForms 1.4.3
					$notes = array( 1 => array( 'email' => $s['notification_email'], 'sender_address' => isset( $s['notification_fromaddress'] ) ? $s['notification_fromaddress'] : '' ) );
				}
				if ( $lite ) {
					$notes = array_slice( $notes, 0, 1, true );   // WPForms Lite sends the first notification only
				}
			}
			$melden = false;
			$vom    = false;
			$auto   = false;
			$rcpt   = array();
			foreach ( $notes as $n ) {
				if ( ! is_array( $n ) || empty( $n['email'] ) || ( isset( $n['enable'] ) && 0 === (int) $n['enable'] ) ) {
					continue;
				}
				$to = (string) $n['email'];
				if ( false !== strpos( $to, '{field_id=' ) ) {
					$auto = true;
				}
				$rest = trim( (string) preg_replace( '/\{field_id=[^}]*\}/', '', $to ), " ,\t" );
				if ( '' === $rest ) {
					continue;   // only to the visitor: an auto-reply
				}
				$melden = true;
				$rcpt   = array_merge( $rcpt, self::emails( str_replace( '{admin_email}', $admin, $rest ) ) );
				if ( false !== strpos( isset( $n['sender_address'] ) ? (string) $n['sender_address'] : '', '{field_id=' ) ) {
					$vom = true;
				}
			}
			$dateien = 0;
			foreach ( isset( $d['fields'] ) && is_array( $d['fields'] ) ? $d['fields'] : array() as $feld ) {
				if ( is_array( $feld ) && isset( $feld['type'] ) && 'file-upload' === $feld['type'] && '' === trim( isset( $feld['extensions'] ) ? (string) $feld['extensions'] : '' ) ) {
					$dateien++;
				}
			}
			$out[] = array(
				'plugin'          => 'wpforms',
				'id'              => (int) $p->ID,
				'title'           => ! empty( $s['form_title'] ) ? (string) $s['form_title'] : (string) $p->post_title,
				'edit'            => admin_url( 'admin.php?page=wpforms-builder&view=settings&form_id=' . (int) $p->ID ),
				'notify'          => $melden,
				'from_visitor'    => $vom,
				'recipients'      => array_values( array_unique( $rcpt ) ),
				'autoresponder'   => $auto,
				'upload_no_types' => $dateien,
			);
		}
		return $out;
	}

	/** The active forms of Gravity Forms (at most MAX_FORMS), read once per request. */
	public static function gf_all_forms() {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return array();
		}
		if ( null === self::$gf_cache ) {
			self::$gf_cache = array_slice( (array) GFAPI::get_forms( true, false ), 0, self::MAX_FORMS );
		}
		return self::$gf_cache;
	}

	private static function gf_forms() {
		$out   = array();
		$admin = (string) get_option( 'admin_email' );
		foreach ( self::gf_all_forms() as $form ) {
			if ( ! is_array( $form ) || empty( $form['id'] ) ) {
				continue;
			}
			$melden = false;
			$vom    = false;
			$auto   = false;
			$rcpt   = array();
			foreach ( isset( $form['notifications'] ) && is_array( $form['notifications'] ) ? $form['notifications'] : array() as $n ) {
				if ( ! is_array( $n ) || ( isset( $n['isActive'] ) && ! $n['isActive'] ) || ( isset( $n['event'] ) && 'form_submission' !== $n['event'] ) ) {
					continue;
				}
				$typ = isset( $n['toType'] ) ? (string) $n['toType'] : 'email';
				if ( 'field' === $typ ) {
					$auto = true;   // "send to a field": to the visitor
					continue;
				}
				$to = '';
				if ( 'routing' === $typ ) {
					foreach ( isset( $n['routing'] ) && is_array( $n['routing'] ) ? $n['routing'] : array() as $regel ) {
						$to .= ',' . ( is_array( $regel ) && isset( $regel['email'] ) ? (string) $regel['email'] : '' );
					}
				} else {
					$to = isset( $n['to'] ) ? (string) $n['to'] : '';
				}
				if ( preg_match( self::GF_FIELD_TAG, $to ) ) {
					$auto = true;
					$to   = (string) preg_replace( self::GF_FIELD_TAG, '', $to );
				}
				$to = trim( $to, " ,\t" );
				if ( '' === $to ) {
					continue;
				}
				$melden = true;
				$rcpt   = array_merge( $rcpt, self::emails( str_replace( '{admin_email}', $admin, $to ) ) );
				$from   = isset( $n['from'] ) ? (string) $n['from'] : '';
				if ( preg_match( self::GF_FIELD_TAG, $from ) || ( isset( $n['fromType'] ) && 'field' === $n['fromType'] ) ) {
					$vom = true;
				}
			}
			$dateien = 0;
			foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $feld ) {
				if ( 'fileupload' === self::prop( $feld, 'type' ) && '' === trim( (string) self::prop( $feld, 'allowedExtensions' ) ) ) {
					$dateien++;
				}
			}
			$out[] = array(
				'plugin'          => 'gravityforms',
				'id'              => (int) $form['id'],
				'title'           => isset( $form['title'] ) ? (string) $form['title'] : 'Gravity Forms',
				'edit'            => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id=' . (int) $form['id'] ),
				'notify'          => $melden,
				'from_visitor'    => $vom,
				'recipients'      => array_values( array_unique( $rcpt ) ),
				'autoresponder'   => $auto,
				'upload_no_types' => $dateien,
			);
		}
		return $out;
	}

	private static function elementor_forms() {
		$out   = array();
		$admin = (string) get_option( 'admin_email' );
		foreach ( self::elementor_widgets() as $w ) {
			$s       = $w['settings'];
			$aktion  = isset( $s['submit_actions'] ) ? (array) $s['submit_actions'] : array( 'email' );   // Elementor stores only what differs from the default
			$melden  = in_array( 'email', $aktion, true );
			$to      = isset( $s['email_to'] ) && '' !== trim( (string) $s['email_to'] ) ? (string) $s['email_to'] : $admin;
			$dateien = 0;
			foreach ( isset( $s['form_fields'] ) ? (array) $s['form_fields'] : array() as $feld ) {
				if ( is_array( $feld ) && isset( $feld['field_type'] ) && 'upload' === $feld['field_type'] && '' === trim( isset( $feld['file_types'] ) ? (string) $feld['file_types'] : '' ) ) {
					$dateien++;
				}
			}
			$name  = ! empty( $s['form_name'] ) ? (string) $s['form_name'] : 'Elementor';
			$out[] = array(
				'plugin'          => 'elementor',
				'id'              => (int) $w['post_id'],
				'title'           => $name . ' (' . $w['post_title'] . ')',
				'edit'            => admin_url( 'post.php?post=' . (int) $w['post_id'] . '&action=elementor' ),
				'notify'          => $melden,
				'from_visitor'    => $melden && false !== strpos( isset( $s['email_from'] ) ? (string) $s['email_from'] : '', '[field' ),
				'recipients'      => $melden ? self::emails( $to ) : array(),
				'autoresponder'   => in_array( 'email2', $aktion, true ),
				'upload_no_types' => $dateien,
			);
		}
		return $out;
	}

	/**
	 * Elementor Pro form widgets: array(post_id, post_title, settings), from the
	 * _elementor_data of published pages and templates. Also used for reCAPTCHA.
	 */
	public static function elementor_widgets() {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return array();
		}
		if ( null !== self::$el_cache ) {
			return self::$el_cache;
		}
		global $wpdb;
		// Elementor keeps its widgets only in this post meta; a LIKE on it is the one way to find the pages with a form.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = '_elementor_data' AND m.meta_value LIKE %s AND p.post_status IN ('publish', 'private') AND p.post_type <> 'revision'
			 ORDER BY p.ID ASC LIMIT %d",
			'%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%',
			self::MAX_FORMS
		) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$data = json_decode( (string) get_post_meta( (int) $r->ID, '_elementor_data', true ), true );
			if ( is_array( $data ) ) {
				self::walk( $data, (int) $r->ID, (string) $r->post_title, $out, 0 );
			}
		}
		self::$el_cache = $out;
		return $out;
	}

	private static function walk( $elements, $id, $title, &$out, $tiefe ) {
		if ( $tiefe > 30 ) {
			return;
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || count( $out ) >= self::MAX_FORMS ) {
				continue;
			}
			if ( isset( $el['widgetType'] ) && 'form' === $el['widgetType'] ) {
				$out[] = array( 'post_id' => $id, 'post_title' => $title, 'settings' => isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array() );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $id, $title, $out, $tiefe + 1 );
			}
		}
	}

	/** Property of a Gravity Forms field (object with magic properties, or array). */
	private static function prop( $x, $k ) {
		if ( is_array( $x ) ) {
			return isset( $x[ $k ] ) ? $x[ $k ] : '';
		}
		if ( is_object( $x ) ) {
			return isset( $x->$k ) ? $x->$k : '';
		}
		return '';
	}

	// ------------------------------------------------------------------ addresses

	/** "Name <a@b.c>" -> a@b.c (or the whole string). */
	private static function address_part( $s ) {
		return preg_match( '/<([^>]*)>/', (string) $s, $m ) ? $m[1] : (string) $s;
	}

	/** All e-mail addresses in a string, lower case. */
	private static function emails( $s ) {
		preg_match_all( "/[a-z0-9._%+'-]+@[a-z0-9.-]+\.[a-z]{2,}/i", (string) $s, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
	}

	public static function is_freemail( $dom ) {
		$dom = strtolower( trim( (string) $dom ) );
		return in_array( $dom, self::FREEMAIL, true ) || (bool) preg_match( self::FREEMAIL_RE, $dom );
	}

	/** The domain of this site or one above/below it (wp.example.com and example.com belong together). */
	public static function is_own( $dom ) {
		$site = preg_replace( '/^www\./', '', strtolower( Formexposed_Core::host() ) );
		$dom  = strtolower( trim( (string) $dom ) );
		if ( '' === $dom || '' === $site ) {
			return false;
		}
		return $dom === $site || substr( $site, -strlen( '.' . $dom ) ) === '.' . $dom || substr( $dom, -strlen( '.' . $site ) ) === '.' . $site;
	}

	// ------------------------------------------------------------------ mail route

	/** How WordPress sends mail: php (no SMTP plugin) or the mailer of WP Mail SMTP, FluentSMTP, Post SMTP, Easy WP SMTP. */
	public static function mailer() {
		$a = self::active();
		if ( isset( $a['wp-mail-smtp'] ) || isset( $a['wp-mail-smtp-pro'] ) ) {
			$o = get_option( self::OPTION_WP_MAIL_SMTP );
			return self::mailer_id( is_array( $o ) && isset( $o['mail']['mailer'] ) ? $o['mail']['mailer'] : 'mail' );
		}
		if ( isset( $a['fluent-smtp'] ) ) {
			$o = get_option( self::OPTION_FLUENTMAIL_SETTINGS );
			$k = is_array( $o ) && isset( $o['misc']['default_connection'] ) ? (string) $o['misc']['default_connection'] : '';
			return self::mailer_id( '' !== $k && isset( $o['connections'][ $k ]['provider_settings']['provider'] ) ? $o['connections'][ $k ]['provider_settings']['provider'] : 'mail' );
		}
		if ( isset( $a['post-smtp'] ) ) {
			$o = get_option( self::OPTION_POSTMAN_OPTIONS );
			return self::mailer_id( is_array( $o ) && isset( $o['transport_type'] ) ? $o['transport_type'] : 'default' );
		}
		if ( isset( $a['easy-wp-smtp'] ) ) {
			$o = get_option( self::OPTION_EASY_WP_SMTP );   // since Easy WP SMTP 2.0, same structure as WP Mail SMTP
			if ( is_array( $o ) && isset( $o['mail']['mailer'] ) ) {
				return self::mailer_id( $o['mail']['mailer'] );
			}
			$o = get_option( self::OPTION_SWPSMTP_OPTIONS );   // before 2.0: SMTP only
			return is_array( $o ) && ! empty( $o['smtp_settings']['host'] ) ? 'smtp' : 'php';
		}
		return 'php';
	}

	private static function mailer_id( $m ) {
		$m   = strtolower( (string) $m );
		$map = array(
			'mail' => 'php', 'default' => 'php', 'php' => 'php', 'smtp' => 'smtp', 'other' => 'smtp',
			'gmail' => 'gmail', 'gmail_api' => 'gmail', 'google' => 'gmail', 'outlook' => 'outlook', 'office365' => 'outlook', 'microsoft' => 'outlook',
			'sendgrid' => 'sendgrid', 'sendgrid_api' => 'sendgrid', 'mailgun' => 'mailgun', 'mailgun_api' => 'mailgun',
			'sendinblue' => 'sendinblue', 'sendinblue_api' => 'sendinblue', 'brevo' => 'brevo', 'brevo_api' => 'brevo',
			'amazonses' => 'amazonses', 'ses' => 'amazonses', 'amazon_ses' => 'amazonses', 'postmark' => 'postmark', 'postmark_api' => 'postmark',
			'sparkpost' => 'sparkpost', 'sparkpost_api' => 'sparkpost', 'zoho' => 'zoho', 'elasticemail' => 'elasticemail', 'elasticmail' => 'elasticemail',
			'elasticemail_api' => 'elasticemail', 'smtpcom' => 'smtpcom', 'smtp_com' => 'smtpcom',
		);
		return isset( $map[ $m ] ) ? $map[ $m ] : 'other';
	}

	// ------------------------------------------------------------------ upload folders

	/**
	 * Upload folders of form plugins that exist here: does the folder list its
	 * files? One GET to this site's own URL per folder (only if the uploads live
	 * on this host). Result: list of array(dir, url, state = listing|ok|unknown|skipped).
	 */
	public static function listing( $mode = 'check' ) {
		$c = get_transient( self::T_LISTING );
		if ( 'fresh' !== $mode && is_array( $c ) ) {
			return $c;
		}
		if ( 'cache' === $mode ) {
			return array();
		}
		$up   = wp_upload_dir( null, false );
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out  = array();
		if ( ! empty( $up['basedir'] ) && ! empty( $up['baseurl'] ) ) {
			$eigen = strtolower( (string) wp_parse_url( $up['baseurl'], PHP_URL_HOST ) ) === $host;
			$tot   = false;
			foreach ( self::UPLOAD_DIRS as $dir ) {
				if ( ! is_dir( trailingslashit( $up['basedir'] ) . $dir ) ) {
					continue;
				}
				$url = trailingslashit( $up['baseurl'] ) . $dir . '/';
				if ( ! $eigen ) {
					$out[] = array( 'dir' => $dir, 'url' => $url, 'state' => 'skipped' );   // uploads on a CDN: not ours to request
					continue;
				}
				if ( $tot ) {
					$out[] = array( 'dir' => $dir, 'url' => $url, 'state' => 'unknown' );   // the site did not answer itself once: no more waiting
					continue;
				}
				$r = wp_remote_get( $url, array(
					'timeout'             => 5,
					'redirection'         => 0,
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress' own filter for requests to the site itself
					'limit_response_size' => 65536,
				) );
				if ( is_wp_error( $r ) ) {
					$tot   = true;
					$out[] = array( 'dir' => $dir, 'url' => $url, 'state' => 'unknown' );
					continue;
				}
				$liste = 200 === (int) wp_remote_retrieve_response_code( $r )
					&& preg_match( '~<title>\s*Index of|<h1>\s*Index of|Directory listing for|\[To Parent Directory\]~i', (string) wp_remote_retrieve_body( $r ) );
				$out[] = array( 'dir' => $dir, 'url' => $url, 'state' => $liste ? 'listing' : 'ok' );
			}
		}
		set_transient( self::T_LISTING, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	// ------------------------------------------------------------------ texts

	/** Title, why it matters, how to fix it - by kind. */
	public static function kind_text( $kind ) {
		switch ( $kind ) {
			case 'from_visitor':
				return array(
					__( 'The notification e-mail pretends to come from the visitor', 'formexposed' ),
					__( 'The sender (From) of the e-mail is the address the visitor typed in. Mail providers check the sender\'s domain (SPF, DKIM, DMARC): an e-mail "from" gmail.com that your server sends fails that check - it lands in spam or is rejected, and the enquiry is lost.', 'formexposed' ),
					__( 'Use an address of your own domain as sender and put the visitor\'s address into Reply-To. Then "Reply" still answers the visitor.', 'formexposed' ),
				);
			case 'notifications_off':
				return array(
					__( 'No e-mail when the form is submitted', 'formexposed' ),
					__( 'The form sends no notification to you. Enquiries are only stored in the database (or nowhere) - easy to miss for days.', 'formexposed' ),
					__( 'Switch the notification on in the form\'s settings, or make sure someone checks the stored entries every day.', 'formexposed' ),
				);
			case 'recipient_freemail':
				return array(
					__( 'Enquiries go to a free mail account', 'formexposed' ),
					__( 'Gmail, Outlook.com, GMX and the like are strict with e-mail sent by web servers, so enquiries end up in spam more often. And the personal data of your visitors lands at a consumer mail service without a data processing agreement.', 'formexposed' ),
					__( 'Use an address at your own domain as recipient (forward it to the free account if you must).', 'formexposed' ),
				);
			case 'recipient_external':
				return array(
					__( 'Enquiries go to an address outside your domain', 'formexposed' ),
					__( 'That is fine for an agency or a CRM - as long as the receiving server accepts mail from your web server and you have a data processing agreement with that provider.', 'formexposed' ),
					__( 'Check that this recipient is intended. The daily delivery check of formexposed tells you when these e-mails stop arriving.', 'formexposed' ),
				);
			case 'upload_no_types':
				return array(
					__( 'File field without a limit on file types', 'formexposed' ),
					__( 'The field accepts every file type WordPress allows (Contact Form 7: its broad default list of documents, images, audio and video). Office files with macros and archives are the classic way to send malware to a company.', 'formexposed' ),
					__( 'Allow only the types you need, for example pdf, jpg and png.', 'formexposed' ),
				);
			case 'uploads_listing':
				return array(
					__( 'Upload folder lists its files', 'formexposed' ),
					__( 'Opening the folder in a browser shows every file visitors uploaded - CVs, IDs, invoices. Anyone can download them.', 'formexposed' ),
					__( 'Switch off directory listings (Apache: "Options -Indexes" in .htaccess; nginx: "autoindex off"), or put an empty index.php into the folder. Ask your host if unsure.', 'formexposed' ),
				);
			case 'autoresponder':
				return array(
					__( 'The form sends an auto-reply to the address typed in', 'formexposed' ),
					__( 'Bots use auto-replies to send spam through your server: they enter the victim\'s address and put their message into the form. Your domain then sends it - and ends up on blocklists.', 'formexposed' ),
					__( 'Keep the auto-reply short and do not repeat what the visitor wrote. Keep the protection layer on. If you do not need the auto-reply, switch it off.', 'formexposed' ),
				);
		}
		return array( $kind, '', '' );
	}

	/** Where to change it, by form plugin. */
	public static function fix_hint( $kind, $plugin ) {
		$h = array(
			'from_visitor'      => array(
				'cf7'          => __( 'Contact -> the form -> Mail: From = [_site_title] <wordpress@your-domain>; Additional headers: Reply-To: [your-email]', 'formexposed' ),
				'wpforms'      => __( 'WPForms -> the form -> Settings -> Notifications: From Email = {admin_email}; Reply-To = the e-mail field', 'formexposed' ),
				'gravityforms' => __( 'Forms -> the form -> Settings -> Notifications: From Email = {admin_email}; Reply To = the e-mail field', 'formexposed' ),
				'elementor'    => __( 'Edit with Elementor -> the form -> Actions After Submit -> Email: From Email = an address of your domain; Reply-To = the e-mail field', 'formexposed' ),
			),
			'notifications_off' => array(
				'cf7'          => __( 'Contact -> the form -> Mail: enter a recipient; Additional Settings: remove skip_mail / demo_mode', 'formexposed' ),
				'wpforms'      => __( 'WPForms -> the form -> Settings -> Notifications: switch notifications on', 'formexposed' ),
				'gravityforms' => __( 'Forms -> the form -> Settings -> Notifications: activate the admin notification', 'formexposed' ),
				'elementor'    => __( 'Edit with Elementor -> the form -> Actions After Submit: add "Email"', 'formexposed' ),
			),
			'upload_no_types'   => array(
				'cf7'          => __( 'Contact -> the form: add filetypes:pdf|jpg|png to the [file] tag', 'formexposed' ),
				'wpforms'      => __( 'WPForms -> the form -> File Upload field -> Allowed File Extensions', 'formexposed' ),
				'gravityforms' => __( 'Forms -> the form -> File Upload field -> Allowed file extensions', 'formexposed' ),
				'elementor'    => __( 'Edit with Elementor -> the form -> Upload field -> Allowed File Types', 'formexposed' ),
			),
		);
		if ( isset( $h[ $kind ][ $plugin ] ) ) {
			return $h[ $kind ][ $plugin ];
		}
		$recipient = array(
			'cf7'          => __( 'Contact -> the form -> Mail -> To', 'formexposed' ),
			'wpforms'      => __( 'WPForms -> the form -> Settings -> Notifications -> Send To Email Address', 'formexposed' ),
			'gravityforms' => __( 'Forms -> the form -> Settings -> Notifications -> Send To', 'formexposed' ),
			'elementor'    => __( 'Edit with Elementor -> the form -> Email -> To', 'formexposed' ),
		);
		$auto = array(
			'cf7'          => __( 'Contact -> the form -> Mail (2)', 'formexposed' ),
			'wpforms'      => __( 'WPForms -> the form -> Settings -> Notifications (the one sent to the e-mail field)', 'formexposed' ),
			'gravityforms' => __( 'Forms -> the form -> Settings -> Notifications (the one sent to a field)', 'formexposed' ),
			'elementor'    => __( 'Edit with Elementor -> the form -> Actions After Submit -> Email 2', 'formexposed' ),
		);
		if ( in_array( $kind, array( 'recipient_freemail', 'recipient_external' ), true ) && isset( $recipient[ $plugin ] ) ) {
			return $recipient[ $plugin ];
		}
		if ( 'autoresponder' === $kind && isset( $auto[ $plugin ] ) ) {
			return $auto[ $plugin ];
		}
		return '';
	}

	/** Readable name of a mailer id. */
	public static function mailer_text( $m ) {
		return 'php' === $m ? __( 'PHP mail() of the web server - no SMTP plugin', 'formexposed' ) : ( 'smtp' === $m ? __( 'SMTP server (SMTP plugin)', 'formexposed' ) : sprintf( /* translators: %s: mail service */ __( 'mail service %s (SMTP plugin)', 'formexposed' ), $m ) );
	}
}
