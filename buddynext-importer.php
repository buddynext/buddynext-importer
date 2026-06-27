<?php
/**
 * Plugin Name:       BuddyNext Importer
 * Plugin URI:        https://github.com/buddynext/buddynext-importer
 * Description:       Migrate a BuddyPress, BuddyBoss, FluentCommunity, PeepSo or Ultimate Member community into BuddyNext - members, profile fields, groups/spaces and activity - through the BuddyNext service layer.
 * Version:           0.1.0-dev
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Wbcom Designs
 * Author URI:        https://wbcomdesigns.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       buddynext-importer
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * One-time transition tool. It is built against the BuddyNext service layer
 * plus an "import mode" that suppresses side effects (notifications, emails,
 * webhooks, realtime) for the duration of a run, and exposes two run surfaces:
 * WP-CLI for developers/large sites and an admin page with a REST-driven
 * progress monitor for site owners. See README.md + docs/build-plan.md.
 */

const BUDDYNEXT_IMPORTER_VERSION = '0.1.0-dev';

define( 'BUDDYNEXT_IMPORTER_FILE', __FILE__ );
define( 'BUDDYNEXT_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUDDYNEXT_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once BUDDYNEXT_IMPORTER_DIR . 'includes/Autoloader.php';
\BuddyNextImporter\Autoloader::register();

register_activation_hook( __FILE__, array( \BuddyNextImporter\Core\Activator::class, 'activate' ) );

add_action( 'plugins_loaded', array( \BuddyNextImporter\Plugin::class, 'boot' ), 20 );
