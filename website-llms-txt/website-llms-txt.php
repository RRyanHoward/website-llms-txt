<?php
/**
 * Plugin Name: Website LLMs.txt
 * Description: Manages and automatically generates LLMS.txt files for LLM/AI consumption and integrates with SEO plugins (Yoast SEO, RankMath)
 * Version: 6.1.2
 * Author: Website LLM
 * Author URI: https://www.websitellm.com
 * Text Domain: website-llms-txt
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LLMS_VERSION', '6.1.2');
define('LLMS_PLUGIN_FILE', __FILE__);
define('LLMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LLMS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize plugin
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-core.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-cache-manager.php';

// Initialize the plugin
function llms_init() {
    new LLMS_Core();
    new LLMS_Cache_Manager();
}

// Hook the initialization function
add_action('plugins_loaded', 'llms_init');