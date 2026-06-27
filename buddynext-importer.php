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
 * One-time transition tool. It hard-requires BuddyNext active and is built
 * entirely against the BuddyNext service layer plus an "import mode" that
 * suppresses side effects (notifications, emails, webhooks, realtime) for the
 * duration of a run. See README.md for the source-adapter architecture and the
 * resumable, batched migration pipeline.
 *
 * Bootstrap is intentionally not wired yet - this is the repository scaffold.
 */

const BUDDYNEXT_IMPORTER_VERSION = '0.1.0-dev';
