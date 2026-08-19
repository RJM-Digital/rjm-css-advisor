<?php
/**
 * Plugin Name:       RJM CSS Advisor
 * Plugin URI:        https://github.com/RJM-Digital/import-template-coach
 * Description:       AI-powered Custom CSS code generation for every ACF component. Describe your styling goal and the plugin writes the exact CSS for you.
 * Version:           1.5.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            RJM Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       rjm-css-advisor
 */

defined( 'ABSPATH' ) || exit;

define( 'RJM_CSS_ADVISOR_VERSION', '1.5.1' );
define( 'RJM_CSS_ADVISOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'RJM_CSS_ADVISOR_URL', plugin_dir_url( __FILE__ ) );

require_once RJM_CSS_ADVISOR_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
require_once RJM_CSS_ADVISOR_DIR . 'includes/class-settings.php';
require_once RJM_CSS_ADVISOR_DIR . 'includes/class-github-client.php';
require_once RJM_CSS_ADVISOR_DIR . 'includes/class-chat-history.php';
require_once RJM_CSS_ADVISOR_DIR . 'includes/class-acf-integration.php';
require_once RJM_CSS_ADVISOR_DIR . 'includes/class-ajax-handler.php';

/**
 * Initialise the plugin.
 */
function rjm_css_advisor_init() {
	RJM_CSS_Advisor_Settings::init();
	RJM_CSS_Advisor_Chat_History::init();

	// REST requests are not is_admin(), so the streaming route registers unconditionally.
	RJM_CSS_Advisor_Ajax_Handler::init_rest();

	if ( is_admin() ) {
		RJM_CSS_Advisor_ACF_Integration::init();
		RJM_CSS_Advisor_Ajax_Handler::init();
	}
}
add_action( 'plugins_loaded', 'rjm_css_advisor_init' );

register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( RJM_CSS_Advisor_Chat_History::CRON_HOOK );
} );

// Pull updates from GitHub Releases instead of WordPress.org.
$rjm_css_advisor_update_checker = \YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://github.com/RJM-Digital/rjm-css-advisor/',
	__FILE__,
	'rjm-css-advisor'
);
$rjm_css_advisor_update_checker->setBranch( 'main' );
$rjm_css_advisor_update_checker->getVcsApi()->enableReleaseAssets();
