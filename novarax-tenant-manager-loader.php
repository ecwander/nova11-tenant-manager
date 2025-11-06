<?php
/**
 * Plugin Name: NovaRax Tenant Manager (Loader)
 * Plugin URI: https://novarax.ae
 * Description: Loads the NovaRax Tenant Manager MU-plugin from subdirectory
 * Version: 1.0.0
 * Author: NovaRax Development Team
 * Author URI: https://novarax.ae
 * 
 * This is a loader file that WordPress can read from mu-plugins root.
 * It loads the actual plugin from the subdirectory.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if the main plugin file exists
$plugin_file = __DIR__ . '/novarax-tenant-manager/novarax-tenant-manager.php';

if (file_exists($plugin_file)) {
    require_once $plugin_file;
} else {
    // Log error if plugin file not found
    if (function_exists('error_log')) {
        error_log('NovaRax Tenant Manager: Main plugin file not found at ' . $plugin_file);
    }
    
    // Show admin notice
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><strong>NovaRax Tenant Manager Error:</strong> Main plugin file not found. Please check the installation.</p>
        </div>
        <?php
    });
}