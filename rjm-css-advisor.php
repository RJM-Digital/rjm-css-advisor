<?php
/**
 * Plugin Name:       RJM CSS Advisor
 * Plugin URI:        https://github.com/RJM-Digital/import-template-coach
 * Description:       AI-powered Custom CSS code generation for every ACF component. Describe your styling goal and the plugin writes the exact CSS for you.
 * Version:           1.9.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            RJM Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       rjm-css-advisor
 */

defined( 'ABSPATH' ) || exit;

define( 'RJM_CSS_ADVISOR_VERSION', '1.9.1' );
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

/**
 * Allow wp_safe_redirect() to send admins back to the requested host.
 *
 * On headless/proxy setups the siteurl/home option can differ from the
 * hostname actually used to reach wp-admin, so self_admin_url() redirects
 * (e.g. the update checker's "Check for updates" link) fail WordPress core's
 * host check and silently fall back to the dashboard instead of Plugins.
 *
 * @param array $hosts
 * @return array
 */
function rjm_css_advisor_allow_current_redirect_host( $hosts ) {
	$current_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$current_host = preg_replace( '/:\d+$/', '', $current_host );

	if ( $current_host && preg_match( '/^[a-z0-9.-]+$/i', $current_host ) ) {
		$hosts[] = $current_host;
	}

	return $hosts;
}
add_filter( 'allowed_redirect_hosts', 'rjm_css_advisor_allow_current_redirect_host' );

// Pull updates from GitHub Releases instead of WordPress.org.
$rjm_css_advisor_update_checker = \YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://github.com/RJM-Digital/rjm-css-advisor/',
	__FILE__,
	'rjm-css-advisor'
);
$rjm_css_advisor_update_checker->setBranch( 'main' );
$rjm_css_advisor_update_checker->getVcsApi()->enableReleaseAssets();
