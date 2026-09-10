<?php
/**
 * Plugin Name:       LinkedIn Shares Importer
 * Plugin URI:        https://github.com/ma6/onygo.26
 * Description:       Import your LinkedIn data export as draft posts, dated to the original LinkedIn publish time. Upload the export ZIP (or Shares_*.csv from it), review a table of every share, and pick which ones to import — shares with two or more paragraphs from the last three years are preselected. Titles are generated with the WordPress AI Client when a provider is connected (Settings → Connections), otherwise the draft is left needing a title. An optional "Originally posted on LinkedIn" line is appended to each post.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Martin Gude
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linkedin-shares-importer
 * Update URI:        false
 *
 * Self-contained by design — no dependency on the Onygo theme or Neon, so the
 * whole `plugins/linkedin-shares-importer/` folder can be lifted into its own
 * repository unchanged. wp-admin is not a Neon surface: this plugin renders
 * with core's own admin styles and adds only a few rules for the review table.
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LSI_VERSION', '0.1.1' );
define( 'LSI_FILE', __FILE__ );
define( 'LSI_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSI_URL', plugin_dir_url( __FILE__ ) );

require_once LSI_DIR . 'inc/class-lsi-share.php';
require_once LSI_DIR . 'inc/class-lsi-csv.php';
require_once LSI_DIR . 'inc/class-lsi-title.php';
require_once LSI_DIR . 'inc/class-lsi-settings.php';
require_once LSI_DIR . 'inc/class-lsi-importer.php';
require_once LSI_DIR . 'inc/class-lsi-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		LSI_Settings::init();
		LSI_Admin::init();
	}
);
