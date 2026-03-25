<?php
/**
 * Plugin Name: ContentSpy Connect
 * Plugin URI: https://contentspy.ai
 * Description: Connect your WordPress site to ContentSpy AI for automated content publishing and competitive intelligence.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * Author: ContentSpy AI
 * License: Proprietary
 * Text Domain: contentspy-connect
 */

defined('ABSPATH') || exit;

define('CONTENTSPY_VERSION', '1.0.0');
define('CONTENTSPY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONTENTSPY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CONTENTSPY_LOG_TABLE', 'contentspy_logs');
define('CONTENTSPY_API_NAMESPACE', 'contentspy/v1');

// Autoload includes
require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-settings.php';
require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-security.php';
require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-logger.php';
require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-updater.php';
require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-admin.php';

// Bootstrap
add_action('plugins_loaded', ['ContentSpy_Bootstrap', 'init']);

class ContentSpy_Bootstrap {
    public static function init(): void {
        new ContentSpy_Settings();
        new ContentSpy_REST_API();
        new ContentSpy_Admin();
        new ContentSpy_Updater();
    }
}

register_activation_hook(__FILE__, 'contentspy_activate');
register_deactivation_hook(__FILE__, 'contentspy_deactivate');

function contentspy_activate(): void {
    ContentSpy_Logger::create_table();
    flush_rewrite_rules();
}

function contentspy_deactivate(): void {
    flush_rewrite_rules();
}
