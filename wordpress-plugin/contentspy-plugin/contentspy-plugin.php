<?php
/**
 * Plugin Name: ContentSpy AI
 * Description: Bridge between your WordPress site and ContentSpy AI SaaS
 * Version: 1.0.0
 * Author: ContentSpy AI
 */

defined('ABSPATH') || exit;

define('CONTENTSPY_VERSION', '1.0.0');
define('CONTENTSPY_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-api.php';
require_once CONTENTSPY_PLUGIN_DIR . 'includes/class-webhook.php';
