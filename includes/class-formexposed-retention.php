<?php
/**
 * formexposed - storage limit for stored enquiries (since 1.4.0).
 *
 * GDPR Art. 5(1)(e): personal data is kept no longer than necessary. Many
 * form plugins store every enquiry forever. The setting (Settings ->
 * formexposed -> Stored enquiries) deletes them after 90, 180 or 365 days -
 * off by default - once a day, and only through the official ways:
 *
 *   flamingo      Flamingo (Contact Form 7): Flamingo_Inbound_Message::delete()
 *   gravityforms  Gravity Forms: GFAPI::delete_entry() (it also has its own
 *                 per-form setting: Settings -> Personal Data)
 *   ninja_forms   Ninja Forms submissions (post type nf_sub): wp_delete_post()
 *   quarantine    our quarantine, 14 days, always
 *   rescue        our rescued enquiries, 30 days, always
 *
 * Only counted, never deleted by us (no official way we could test): WPForms
 * (entries, Pro), Fluent Forms, Forminator (has its own retention setting),
 * Formidable. The settings page shows where to delete them.
 *
 * At most BATCH entries per source and BUDGET seconds per day, as the last
 * step of the daily event, so a large backlog never keeps the daily counts
 * from going out; the rest follows the next day. Nothing is logged except the
 * number of deleted entries per day (option formexposed_retention_log, and
 * retention_deleted in the daily counts - sent with the next day's counts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Counting stored enquiries of other plugins by date over every post status, and reading the tables of form
// plugins that have no WordPress API. Only on the settings page and in the daily job; the numbers must be
// current, so nothing is cached. Table and column names go through %i (WordPress 6.2+).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Formexposed_Retention {

	const OPT     = 'formexposed_retention';       // 0 (off), 90, 180 or 365 days
	const OPT_LOG = 'formexposed_retention_log';   // UTC day => number deleted, 31 days
	const CHOICES = array( 0, 90, 180, 365 );
	const BATCH   = 500;
	const BUDGET  = 15;                             // seconds per run; a backlog continues the next day

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_formexposed_retention', array( __CLASS__, 'action_save' ) );
		}
	}

	public static function days() {
		$d = (int) get_option( self::OPT, 0 );
		return in_array( $d, self::CHOICES, true ) ? $d : 0;
	}

	public static function set_days( $d ) {
		update_option( self::OPT, in_array( (int) $d, self::CHOICES, true ) ? (int) $d : 0, false );
	}

	/** Entries before this time count as "older": the chosen limit, or 365 days while it is off. */
	public static function cutoff( $days = null ) {
		$days = null === $days ? self::days() : (int) $days;
		return time() - ( $days > 0 ? $days : 365 ) * DAY_IN_SECONDS;
	}

	/**
	 * Stored enquiries per source: array(total, older, auto). A source appears
	 * when its plugin is active or it still holds entries.
	 */
	public static function sources() {
		$cut    = self::cutoff();
		$an     = self::days() > 0;
		$aktiv  = Formexposed_Health::active();
		$out    = array();
		$posten = array(
			'flamingo'    => array( 'flamingo_inbound', 'flamingo', true ),
			'ninja_forms' => array( 'nf_sub', 'ninja-forms', true ),
		);
		foreach ( $posten as $q => $x ) {
			$n = self::count_posts( $x[0], $cut );
			if ( $n[0] || isset( $aktiv[ $x[1] ] ) ) {
				$out[ $q ] = array( 'total' => $n[0], 'older' => $n[1], 'auto' => $an && $x[2] ? 1 : 0 );
			}
		}
		$gf = self::gf_count( $cut );
		if ( null !== $gf && ( $gf[0] || class_exists( 'GFForms' ) ) ) {
			$out['gravityforms'] = array( 'total' => $gf[0], 'older' => $gf[1], 'auto' => $an ? 1 : 0 );
		}
		$tabellen = array(
			'wpforms'    => array( 'wpforms_entries', 'date', true ),
			'fluentform' => array( 'fluentform_submissions', 'created_at', false ),
			'forminator' => array( 'frmt_form_entry', 'date_created', false ),
			'formidable' => array( 'frm_items', 'created_at', true ),
		);
		foreach ( $tabellen as $q => $x ) {
			$n = self::count_table( $x[0], $x[1], $cut, $x[2] );
			if ( null !== $n ) {
				$out[ $q ] = array( 'total' => $n[0], 'older' => $n[1], 'auto' => 0 );
			}
		}
		$n                 = Formexposed_Quarantine::counts( $cut );
		$out['quarantine'] = array( 'total' => $n[0], 'older' => $n[1], 'auto' => 1 );
		$n                 = Formexposed_Rescue::counts( $cut );
		$out['rescue']     = array( 'total' => $n[0], 'older' => $n[1], 'auto' => 1 );
		return $out;
	}

	/** What formexposed.com receives: numbers only. */
	public static function payload() {
		return array( 'days' => self::days(), 'sources' => self::sources(), 'deleted_30d' => self::deleted_30d() );
	}

	/** Daily (Formexposed_Core::daily): delete what is older than the limit. Returns the number deleted. */
	public static function run() {
		$days = self::days();
		if ( $days <= 0 ) {
			return 0;
		}
		$cut = self::cutoff( $days );
		$bis = microtime( true ) + self::BUDGET;
		$n   = self::delete_posts( 'flamingo_inbound', $cut, class_exists( 'Flamingo_Inbound_Message' ), $bis );
		$n  += self::gf_delete( $cut, $bis );
		$n  += self::delete_posts( 'nf_sub', $cut, false, $bis );
		self::log( $n );
		return $n;
	}

	/** Count deleted entries (also the quarantine and rescue clean-up) - per day, and in the daily counts. */
	public static function log( $n ) {
		$n = (int) $n;
		if ( $n <= 0 ) {
			return;
		}
		$l   = get_option( self::OPT_LOG );
		$l   = is_array( $l ) ? $l : array();
		$tag = gmdate( 'Y-m-d' );
		$l[ $tag ] = ( isset( $l[ $tag ] ) ? (int) $l[ $tag ] : 0 ) + $n;
		$ab  = gmdate( 'Y-m-d', time() - 31 * DAY_IN_SECONDS );
		foreach ( array_keys( $l ) as $k ) {
			if ( (string) $k < $ab ) {
				unset( $l[ $k ] );
			}
		}
		update_option( self::OPT_LOG, $l, false );
		Formexposed_Stats::retention_deleted( $n );
	}

	public static function deleted_30d() {
		$l  = get_option( self::OPT_LOG );
		$ab = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$n  = 0;
		foreach ( is_array( $l ) ? $l : array() as $tag => $z ) {
			if ( (string) $tag >= $ab ) {
				$n += (int) $z;
			}
		}
		return $n;
	}

	// ------------------------------------------------------------------ sources

	private static function count_posts( $type, $cut ) {
		global $wpdb;
		$alle = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
		if ( ! $alle ) {
			return array( 0, 0 );
		}
		$alt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_date_gmt < %s AND post_date_gmt > '1971-01-01'", $type, gmdate( 'Y-m-d H:i:s', $cut ) ) );
		return array( $alle, $alt );
	}

	/** Oldest first, at most BATCH and until $bis. Flamingo through its own class, the rest with wp_delete_post(). */
	private static function delete_posts( $type, $cut, $flamingo, $bis ) {
		global $wpdb;
		if ( microtime( true ) > $bis ) {
			return 0;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_date_gmt < %s AND post_date_gmt > '1971-01-01' ORDER BY ID ASC LIMIT %d", $type, gmdate( 'Y-m-d H:i:s', $cut ), self::BATCH ) );
		$n   = 0;
		foreach ( (array) $ids as $id ) {
			if ( microtime( true ) > $bis ) {
				break;
			}
			if ( $flamingo ) {
				$m  = new Flamingo_Inbound_Message( (int) $id );
				$ok = $m->delete();
			} else {
				$ok = (bool) wp_delete_post( (int) $id, true );
			}
			if ( $ok ) {
				$n++;
			}
		}
		return $n;
	}

	/** Table of a form plugin: array(total, older) or null when the table does not exist. $gmt: the column is in UTC. */
	private static function count_table( $suffix, $col, $cut, $gmt ) {
		global $wpdb;
		$t = $wpdb->prefix . $suffix;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) !== $t ) {
			return null;
		}
		$grenze = $gmt ? gmdate( 'Y-m-d H:i:s', $cut ) : wp_date( 'Y-m-d H:i:s', $cut );
		return array(
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ),
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i < %s', $t, $col, $grenze ) ),
		);
	}

	/** Gravity Forms: dates in search criteria are in the site's time zone. */
	private static function gf_date( $ts ) {
		return wp_date( 'Y-m-d H:i:s', $ts );
	}

	private static function gf_count( $cut ) {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'count_entries' ) ) {
			return null;
		}
		$alle = 0;
		$alt  = 0;
		try {
			foreach ( array( 'active', 'spam', 'trash' ) as $st ) {
				$alle += (int) GFAPI::count_entries( 0, array( 'status' => $st ) );
				$alt  += (int) GFAPI::count_entries( 0, array( 'status' => $st, 'end_date' => self::gf_date( $cut ) ) );
			}
		} catch ( Throwable $e ) {
			return null;
		}
		return array( $alle, $alt );
	}

	private static function gf_delete( $cut, $bis ) {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_entry_ids' ) || ! method_exists( 'GFAPI', 'delete_entry' ) ) {
			return 0;
		}
		$n = 0;
		try {
			foreach ( array( 'active', 'spam', 'trash' ) as $st ) {
				if ( $n >= self::BATCH || microtime( true ) > $bis ) {
					break;
				}
				$ids = GFAPI::get_entry_ids( 0, array( 'status' => $st, 'end_date' => self::gf_date( $cut ) ), array(), array( 'offset' => 0, 'page_size' => self::BATCH - $n ) );
				foreach ( (array) $ids as $id ) {
					if ( microtime( true ) > $bis ) {
						break;
					}
					if ( true === GFAPI::delete_entry( (int) $id ) ) {
						$n++;
					}
				}
			}
		} catch ( Throwable $e ) {
			return $n;
		}
		return $n;
	}

	// ------------------------------------------------------------------ settings

	public static function action_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formexposed' ) );
		}
		check_admin_referer( 'formexposed_retention' );
		self::set_days( isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0 );
		$d = self::days();
		set_transient( 'formexposed_msg', array( 'ok', $d
			/* translators: %d: number of days */
			? sprintf( __( 'Saved. Stored enquiries older than %d days are deleted once a day, starting tonight.', 'formexposed' ), $d )
			: __( 'Saved. Stored enquiries are not deleted automatically.', 'formexposed' ) ), 120 );
		wp_safe_redirect( Formexposed_Core::page_url( array( 'tab' => 'storage' ) ) );
		exit;
	}

	/** Name and hint (own setting of the plugin, or where to delete by hand) per source. */
	public static function source_text( $q ) {
		switch ( $q ) {
			case 'flamingo':
				return array( 'Flamingo (Contact Form 7)', '' );
			case 'gravityforms':
				return array( 'Gravity Forms', __( 'Gravity Forms also has its own setting per form: Settings -> Personal Data -> Retention Policy.', 'formexposed' ) );
			case 'ninja_forms':
				return array( 'Ninja Forms', __( 'Files uploaded with the Ninja Forms File Uploads add-on stay in the uploads folder - delete them there.', 'formexposed' ) );
			case 'wpforms':
				return array( 'WPForms', __( 'Not deleted by formexposed. Delete old entries under WPForms -> Entries.', 'formexposed' ) );
			case 'fluentform':
				return array( 'Fluent Forms', __( 'Not deleted by formexposed. Delete old entries under Fluent Forms -> Entries.', 'formexposed' ) );
			case 'forminator':
				return array( 'Forminator', __( 'Not deleted by formexposed. Forminator has its own setting: Forminator -> Settings -> Data -> Submissions retention.', 'formexposed' ) );
			case 'formidable':
				return array( 'Formidable Forms', __( 'Not deleted by formexposed. Delete old entries under Formidable -> Entries.', 'formexposed' ) );
			case 'quarantine':
				return array( __( 'formexposed quarantine', 'formexposed' ), __( 'Always deleted after 14 days.', 'formexposed' ) );
			case 'rescue':
				return array( __( 'formexposed rescued enquiries', 'formexposed' ), __( 'Always deleted after 30 days.', 'formexposed' ) );
		}
		return array( $q, '' );
	}

	/** A paragraph for the privacy policy guide, while a limit is set. */
	public static function privacy_paragraph() {
		$d = self::days();
		/* translators: %d: number of days */
		return $d ? sprintf( __( 'Messages sent through our contact forms and stored on this website by Flamingo, Gravity Forms or Ninja Forms are deleted automatically after %d days.', 'formexposed' ), $d ) : '';
	}
}
