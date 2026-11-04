<?php
/**
 * Plugin Name: BusinessDay App Connector
 * Description: Cached content feeds and app-specific config (banners, ads, paywall copy) for the BusinessDay mobile app. Reads entitlement/premium status from AeroPaywall's connector-plugin in-process; never duplicates its gating logic and never touches its code.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * License: Proprietary
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BD_APP_CONNECTOR_VERSION', '0.1.0');
define('BD_APP_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-cache.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-aeropaywall-bridge.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-settings.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-feed-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-config-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-todays-paper-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-editions-homepage-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-off-the-clock-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-your-news-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-games.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-cache-invalidation.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-admin-ui.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-captcha-widget.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-market-pulse-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-podcasts-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-videos-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-channel-shorts-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-newsletter-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-toons-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-events-api.php';
require_once BD_APP_CONNECTOR_DIR . 'includes/class-bd-universal-links.php';

// Registered directly (not inside plugins_loaded) — the AASA file must be served on `init`,
// before most of plugins_loaded's own callbacks even run, and it's a static, always-on responder
// with no settings/admin surface, unlike everything else below.
BD_Universal_Links::instance();

add_action('plugins_loaded', function () {
    BD_App_Feed_Api::instance();
    BD_App_Config_Api::instance();
    BD_App_Todays_Paper_Api::instance();
    BD_Editions_Homepage_Api::instance();
    BD_Off_The_Clock_Api::instance();
    BD_Your_News_Api::instance();
    BD_App_Games::instance();
    BD_App_Cache_Invalidation::instance();
    BD_Captcha_Widget::instance();
    BD_Market_Pulse_Api::instance();
    BD_Podcasts_Api::instance();
    BD_Videos_Api::instance();
    BD_Channel_Shorts_Api::instance();
    BD_Newsletter_Api::instance();
    BD_Toons_Api::instance();
    BD_Events_Api::instance();

    if (is_admin()) {
        BD_App_Admin_Ui::instance();
    }
});
