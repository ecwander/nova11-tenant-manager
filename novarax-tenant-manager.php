<?php
/**
 * Plugin Name: NovaRax Tenant Manager
 * Plugin URI: https://novarax.ae
 * Description: Multi-tenant management system for NovaRax SaaS platform. Handles tenant creation, database provisioning, subdomain management, and lifecycle operations.
 * Version: 1.0.0
 * Author: NovaRax Development Team
 * Author URI: https://novarax.ae
 * License: Proprietary
 * Text Domain: novarax-tenant-manager
 * Domain Path: /languages
 * 
 * @package NovaRax\TenantManager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NOVARAX_TM_VERSION', '1.0.0');
define('NOVARAX_TM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NOVARAX_TM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOVARAX_TM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Minimum requirements
define('NOVARAX_TM_MIN_PHP_VERSION', '8.0');
define('NOVARAX_TM_MIN_WP_VERSION', '6.0');

/**
 * Main plugin class - Singleton pattern
 */
class NovaRax_Tenant_Manager {
    
    /**
     * Single instance of the class
     *
     * @var NovaRax_Tenant_Manager
     */
    private static $instance = null;
    
    /**
     * Database manager instance
     *
     * @var NovaRax_Database_Manager
     */
    public $database;
    
    /**
     * Tenant operations instance
     *
     * @var NovaRax_Tenant_Operations
     */
    public $tenant_operations;
    
    /**
     * Admin interface instance
     *
     * @var NovaRax_Admin_Interface
     */
    public $admin;
    
    /**
     * API handler instance
     *
     * @var NovaRax_API_Handler
     */
    public $api;
    
    /**
     * Get singleton instance
     *
     * @return NovaRax_Tenant_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Initialize the plugin
     */
    private function __construct() {
        // Check requirements
        if (!$this->check_requirements()) {
            return;
        }
        
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize components
        $this->init_components();
        
        // Setup hooks
        $this->setup_hooks();
    }
    
    /**
     * Check if system meets minimum requirements
     *
     * @return bool
     */
    private function check_requirements() {
        global $wp_version;
        
        // Check PHP version
        if (version_compare(PHP_VERSION, NOVARAX_TM_MIN_PHP_VERSION, '<')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                printf(
                    __('NovaRax Tenant Manager requires PHP %s or higher. You are running PHP %s.', 'novarax-tenant-manager'),
                    NOVARAX_TM_MIN_PHP_VERSION,
                    PHP_VERSION
                );
                echo '</p></div>';
            });
            return false;
        }
        
        // Check WordPress version
        if (version_compare($wp_version, NOVARAX_TM_MIN_WP_VERSION, '<')) {
            add_action('admin_notices', function() use ($wp_version) {
                echo '<div class="error"><p>';
                printf(
                    __('NovaRax Tenant Manager requires WordPress %s or higher. You are running WordPress %s.', 'novarax-tenant-manager'),
                    NOVARAX_TM_MIN_WP_VERSION,
                    $wp_version
                );
                echo '</p></div>';
            });
            return false;
        }
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                _e('NovaRax Tenant Manager requires WooCommerce to be installed and activated.', 'novarax-tenant-manager');
                echo '</p></div>';
            });
            return false;
        }
        
        return true;
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Core classes
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-database-manager.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-tenant-operations.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-tenant-validator.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-subdomain-manager.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-provisioning-queue.php';
        
        // Admin classes
        require_once NOVARAX_TM_PLUGIN_DIR . 'admin/class-admin-interface.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'admin/class-tenant-list-table.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'admin/class-admin-ajax.php';
        
        // API classes (optional - only load if files exist)
        if (file_exists(NOVARAX_TM_PLUGIN_DIR . 'includes/api/class-api-handler.php')) {
            require_once NOVARAX_TM_PLUGIN_DIR . 'includes/api/class-api-handler.php';
            require_once NOVARAX_TM_PLUGIN_DIR . 'includes/api/class-api-authentication.php';
           // require_once NOVARAX_TM_PLUGIN_DIR . 'includes/api/class-api-endpoints.php';
        }
        
        // WooCommerce integration (only if WooCommerce is active)
        if (class_exists('WooCommerce')) {
            if (file_exists(NOVARAX_TM_PLUGIN_DIR . 'includes/integrations/class-woocommerce-integration.php')) {
                require_once NOVARAX_TM_PLUGIN_DIR . 'includes/integrations/class-woocommerce-integration.php';
            }
        }
        
        // Module Manager (optional)
        if (file_exists(NOVARAX_TM_PLUGIN_DIR . 'includes/class-module-manager.php')) {
            require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-module-manager.php';
        }
        
        // Utilities
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-logger.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-email-notifications.php';
        require_once NOVARAX_TM_PLUGIN_DIR . 'includes/class-security.php';
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize database manager
        $this->database = new NovaRax_Database_Manager();
        
        // Initialize tenant operations
        $this->tenant_operations = new NovaRax_Tenant_Operations();
        
        // Initialize admin interface - ALWAYS initialize, let the class handle is_admin() check
        $this->admin = new NovaRax_Admin_Interface();
        
        // Initialize API handler (only if class exists)
        if (class_exists('NovaRax_API_Handler')) {
            $this->api = new NovaRax_API_Handler();
        } else {
            $this->api = null; // Explicitly set to null if not available
        }
        
        // Initialize WooCommerce integration (only if WooCommerce is active and class exists)
        if (class_exists('WooCommerce') && class_exists('NovaRax_WooCommerce_Integration')) {
            new NovaRax_WooCommerce_Integration();
        }
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Init hook
        add_action('init', array($this, 'init'));
        
        // Load text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create custom database tables
        $this->database->create_tables();
        
        // Create tenant role
        $this->create_tenant_role();
        
        // Set default options
        $this->set_default_options();
        
        // Create necessary directories
        $this->create_directories();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log activation
        NovaRax_Logger::log('Plugin activated successfully', 'info');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        NovaRax_Logger::log('Plugin deactivated', 'info');
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Register custom post types if needed
        // Register taxonomies if needed
        
        // Initialize REST API routes (only if API handler exists)
        if ($this->api && method_exists($this->api, 'register_routes')) {
            $this->api->register_routes();
        }
    }
    
    /**
     * Create custom tenant role
     */
    private function create_tenant_role() {
        // Remove role if exists (for updates)
        remove_role('tenant');
        
        // Add tenant role with customer capabilities + tenant-specific
        add_role(
            'tenant',
            __('Tenant', 'novarax-tenant-manager'),
            array(
                'read' => true,
                'edit_posts' => false,
                'delete_posts' => false,
                'publish_posts' => false,
                'upload_files' => false,
                // WooCommerce capabilities
                'view_order' => true,
                'pay_for_order' => true,
                'view_subscription' => true,
                'edit_subscription' => true,
                // Custom tenant capabilities
                'access_tenant_dashboard' => true,
                'manage_tenant_subscriptions' => true,
                'view_tenant_modules' => true,
            )
        );
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = array(
            'novarax_tm_tenant_storage_limit' => 5368709120, // 5GB in bytes
            'novarax_tm_tenant_user_limit' => 10,
            'novarax_tm_subdomain_suffix' => '.app.novarax.ae',
            'novarax_tm_tenant_db_prefix' => 'novarax_tenant_',
            'novarax_tm_auto_provision' => true,
            'novarax_tm_grace_period_days' => 7,
            'novarax_tm_enable_notifications' => true,
            'novarax_tm_database_host' => DB_HOST,
            'novarax_tm_tenant_codebase_path' => '/var/www/tenant-dashboard',
        );
        
        foreach ($defaults as $option => $value) {
            if (false === get_option($option)) {
                add_option($option, $value);
            }
        }
    }
    
    /**
     * Create necessary directories
     */
    private function create_directories() {
        $upload_dir = wp_upload_dir();
        $tenant_dir = $upload_dir['basedir'] . '/novarax-tenants';
        
        if (!file_exists($tenant_dir)) {
            wp_mkdir_p($tenant_dir);
            
            // Create .htaccess for security
            $htaccess = $tenant_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
        }
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'novarax-tenant-manager',
            false,
            dirname(NOVARAX_TM_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'novarax-tenant') === false) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'novarax-tm-admin',
            NOVARAX_TM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            NOVARAX_TM_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'novarax-tm-admin',
            NOVARAX_TM_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            NOVARAX_TM_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('novarax-tm-admin', 'novaraxTM', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('novarax_tm_ajax'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this tenant? This action cannot be undone.', 'novarax-tenant-manager'),
                'provisioning' => __('Provisioning tenant...', 'novarax-tenant-manager'),
                'success' => __('Operation completed successfully.', 'novarax-tenant-manager'),
                'error' => __('An error occurred. Please try again.', 'novarax-tenant-manager'),
            ),
        ));
    }
    
    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version() {
        return NOVARAX_TM_VERSION;
    }
}

/**
 * Get the main plugin instance
 *
 * @return NovaRax_Tenant_Manager
 */
function novarax_tenant_manager() {
    return NovaRax_Tenant_Manager::get_instance();
}

// Initialize the plugin on plugins_loaded hook
// This ensures WordPress is fully loaded before we register admin menus
add_action('plugins_loaded', 'novarax_tenant_manager', 10);