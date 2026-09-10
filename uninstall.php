<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the four options and any leftover per-user import transients.
 * Imported drafts and their `_lsi_share_*` meta are real content and are
 * left untouched.
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'lsi_title_source', 'lsi_ai_prompt', 'lsi_attribution_enabled', 'lsi_attribution_template' ) as $option ) {
	delete_option( $option );
}

global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_lsi\_pending\_%'
	    OR option_name LIKE '\_transient\_timeout\_lsi\_pending\_%'"
);
