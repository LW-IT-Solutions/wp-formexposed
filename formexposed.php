<?php
/**
 * Plugin Name:       formexposed – Contact form spam check
 * Plugin URI:        https://formexposed.com/
 * Description:       Checks whether the contact forms on this site are protected against bots and spam, adds a free protection layer and connects the hosted form protection of formexposed.
 * Version:           1.10.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            LW IT Solutions
 * Author URI:        https://www.lukaswojcik.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formexposed
 *
 * What the plugin does and which data it sends is described in readme.txt ("External services")
 * and at the top of every file in includes/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FORMEXPOSED_VERSION', '1.10.1' );
define( 'FORMEXPOSED_FILE', __FILE__ );

// The addresses of the services (includes/services.json). wp-config.php may override each constant
// (development, staging): FORMEXPOSED_API, FORMEXPOSED_PROTECT_API, FORMEXPOSED_PROTECT_NAME.
$formexposed_services = wp_json_file_decode( __DIR__ . '/includes/services.json', array( 'associative' => true ) );
$formexposed_services = is_array( $formexposed_services ) ? $formexposed_services : array();
if ( ! defined( 'FORMEXPOSED_API' ) ) {
	define( 'FORMEXPOSED_API', isset( $formexposed_services['api'] ) ? (string) $formexposed_services['api'] : '' );
}
if ( ! defined( 'FORMEXPOSED_PROTECT_API' ) ) {
	define( 'FORMEXPOSED_PROTECT_API', isset( $formexposed_services['protect_api'] ) ? (string) $formexposed_services['protect_api'] : '' );
}
if ( ! defined( 'FORMEXPOSED_PROTECT_NAME' ) ) {
	define( 'FORMEXPOSED_PROTECT_NAME', isset( $formexposed_services['protect_name'] ) ? (string) $formexposed_services['protect_name'] : 'formconfirmed' );
}
unset( $formexposed_services );

require_once __DIR__ . '/includes/class-formexposed.php';
require_once __DIR__ . '/includes/class-formexposed-guard.php';
require_once __DIR__ . '/includes/class-formexposed-filters.php';
require_once __DIR__ . '/includes/class-formexposed-stats.php';
require_once __DIR__ . '/includes/class-formexposed-form-metrics.php';
require_once __DIR__ . '/includes/class-formexposed-journey.php';
require_once __DIR__ . '/includes/class-formexposed-watch.php';
require_once __DIR__ . '/includes/class-formexposed-quarantine.php';
require_once __DIR__ . '/includes/class-formexposed-rescue.php';
require_once __DIR__ . '/includes/class-formexposed-pow.php';
require_once __DIR__ . '/includes/class-formexposed-recaptcha.php';
require_once __DIR__ . '/includes/class-formexposed-health.php';
require_once __DIR__ . '/includes/class-formexposed-retention.php';
require_once __DIR__ . '/includes/class-formexposed-maintenance.php';
require_once __DIR__ . '/includes/class-formexposed-protect.php';

register_activation_hook( __FILE__, array( 'Formexposed_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Formexposed_Core', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Formexposed_Core', 'init' ) );
add_action( 'plugins_loaded', array( 'Formexposed_Guard', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Stats', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Form_Metrics', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Journey', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Watch', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Quarantine', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Rescue', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Pow', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Recaptcha', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Retention', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Maintenance', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Health', 'init' ), 20 );
add_action( 'plugins_loaded', array( 'Formexposed_Protect', 'init' ), 20 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-formexposed-cli.php';
	WP_CLI::add_command( 'formexposed', 'Formexposed_CLI' );
}
