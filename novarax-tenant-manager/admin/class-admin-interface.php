<?php
/**
 * Admin Interface Class
 * 
 * Handles all admin menu structure, page routing, and admin area functionality.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Admin_Interface {
    
    /**
     * Menu slug
     *
     * @var string
     */
    private $menu_slug = 'novarax-tenants';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        // Register admin bar menu
        add_action('admin_bar_menu', array($this, 'register_admin_bar_menu'), 100);
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Handle form submissions
        add_action('admin_init', array($this, 'handle_form_submissions'));
        
        // Add admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
    }
    
    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        // Main menu
        add_menu_page(
            __('NovaRax Tenants', 'novarax-tenant-manager'),
            __('NovaRax', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug,
            array($this, 'render_dashboard_page'),
            'dashicons-groups',
            30
        );
        
        // Dashboard submenu
        add_submenu_page(
            $this->menu_slug,
            __('Dashboard', 'novarax-tenant-manager'),
            __('Dashboard', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug,
            array($this, 'render_dashboard_page')
        );
        
        // All Tenants submenu
        add_submenu_page(
            $this->menu_slug,
            __('All Tenants', 'novarax-tenant-manager'),
            __('All Tenants', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug . '-list',
            array($this, 'render_tenants_list_page')
        );
        
        // Add New Tenant submenu
        add_submenu_page(
            $this->menu_slug,
            __('Add New Tenant', 'novarax-tenant-manager'),
            __('Add New', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug . '-add',
            array($this, 'render_add_tenant_page')
        );
        
        // Modules submenu
        add_submenu_page(
            $this->menu_slug,
            __('Modules', 'novarax-tenant-manager'),
            __('Modules', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug . '-modules',
            array($this, 'render_modules_page')
        );
        
        // Analytics submenu
        add_submenu_page(
            $this->menu_slug,
            __('Analytics', 'novarax-tenant-manager'),
            __('Analytics', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug . '-analytics',
            array($this, 'render_analytics_page')
        );
        
        // Settings submenu
        add_submenu_page(
            $this->menu_slug,
            __('Settings', 'novarax-tenant-manager'),
            __('Settings', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug . '-settings',
            array($this, 'render_settings_page')
        );
        
        // Hidden submenu for editing tenants
        add_submenu_page(
            null, // Hidden from menu
            __('Edit Tenant', 'novarax-tenant-manager'),
            __('Edit Tenant', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug . '-edit',
            array($this, 'render_edit_tenant_page')
        );
        
        // Hidden submenu for viewing tenant details
        add_submenu_page(
            null, // Hidden from menu
            __('View Tenant', 'novarax-tenant-manager'),
            __('View Tenant', 'novarax-tenant-manager'),
            'manage_options',
            $this->menu_slug . '-view',
            array($this, 'render_view_tenant_page')
        );
    }
    
    /**
     * Register admin bar menu
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function register_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get tenant count
        $tenant_ops = new NovaRax_Tenant_Operations();
        $total_count = $tenant_ops->get_tenant_count();
        $active_count = $tenant_ops->get_tenant_count(array('status' => 'active'));
        
        // Add parent menu
        $wp_admin_bar->add_node(array(
            'id' => 'novarax-tenants',
            'title' => '<span class="ab-icon dashicons-groups"></span><span class="ab-label">NovaRax (' . $active_count . ')</span>',
            'href' => admin_url('admin.php?page=' . $this->menu_slug),
        ));
        
        // Add submenu items
        $wp_admin_bar->add_node(array(
            'parent' => 'novarax-tenants',
            'id' => 'novarax-dashboard',
            'title' => __('Dashboard', 'novarax-tenant-manager'),
            'href' => admin_url('admin.php?page=' . $this->menu_slug),
        ));
        
        $wp_admin_bar->add_node(array(
            'parent' => 'novarax-tenants',
            'id' => 'novarax-add-tenant',
            'title' => __('Add New Tenant', 'novarax-tenant-manager'),
            'href' => admin_url('admin.php?page=' . $this->menu_slug . '-add'),
        ));
        
        $wp_admin_bar->add_node(array(
            'parent' => 'novarax-tenants',
            'id' => 'novarax-all-tenants',
            'title' => __('All Tenants', 'novarax-tenant-manager') . ' (' . $total_count . ')',
            'href' => admin_url('admin.php?page=' . $this->menu_slug . '-list'),
        ));
    }
    
    /**
     * Enqueue admin assets
     *
     * @param string $hook Current page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'novarax-tenants') === false) {
            return;
        }
        
        // Enqueue WordPress color picker
        wp_enqueue_style('wp-color-picker');
        
        // Enqueue Chart.js for analytics
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            array(),
            '3.9.1',
            true
        );
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'novarax-tm-admin',
            NOVARAX_TM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            NOVARAX_TM_VERSION
        );
        
        // Enqueue admin JavaScript
        wp_enqueue_script(
            'novarax-tm-admin',
            NOVARAX_TM_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wp-color-picker'),
            NOVARAX_TM_VERSION,
            true
        );
        
        // Enqueue tenant form JavaScript
        if (strpos($hook, 'add') !== false || strpos($hook, 'edit') !== false) {
            wp_enqueue_script(
                'novarax-tm-tenant-form',
                NOVARAX_TM_PLUGIN_URL . 'assets/js/tenant-form.js',
                array('jquery'),
                NOVARAX_TM_VERSION,
                true
            );
        }
        
        // Localize script
        wp_localize_script('novarax-tm-admin', 'novaraxTM', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('novarax_tm_ajax'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this tenant? This action cannot be undone.', 'novarax-tenant-manager'),
                'confirmSuspend' => __('Are you sure you want to suspend this tenant?', 'novarax-tenant-manager'),
                'confirmActivate' => __('Are you sure you want to activate this tenant?', 'novarax-tenant-manager'),
                'provisioning' => __('Provisioning tenant...', 'novarax-tenant-manager'),
                'success' => __('Operation completed successfully.', 'novarax-tenant-manager'),
                'error' => __('An error occurred. Please try again.', 'novarax-tenant-manager'),
                'checking' => __('Checking availability...', 'novarax-tenant-manager'),
                'available' => __('Available!', 'novarax-tenant-manager'),
                'notAvailable' => __('Not available', 'novarax-tenant-manager'),
            ),
        ));
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        $ajax = new NovaRax_Admin_Ajax();
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        // Check if it's our form submission
        if (!isset($_POST['novarax_tm_action'])) {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['novarax_tm_nonce']) || !wp_verify_nonce($_POST['novarax_tm_nonce'], 'novarax_tm_action')) {
            wp_die(__('Security check failed', 'novarax-tenant-manager'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action', 'novarax-tenant-manager'));
        }
        
        $action = sanitize_text_field($_POST['novarax_tm_action']);
        
        switch ($action) {
            case 'create_tenant':
                $this->handle_create_tenant();
                break;
                
            case 'update_tenant':
                $this->handle_update_tenant();
                break;
                
            case 'delete_tenant':
                $this->handle_delete_tenant();
                break;
                
            case 'update_settings':
                $this->handle_update_settings();
                break;
        }
    }
    
    /**
     * Handle create tenant form submission
     */
    private function handle_create_tenant() {
        $tenant_ops = new NovaRax_Tenant_Operations();
        $validator = new NovaRax_Tenant_Validator();
        
        // Get form data
        $data = array(
            'full_name' => sanitize_text_field($_POST['full_name']),
            'email' => sanitize_email($_POST['email']),
            'username' => sanitize_user($_POST['username'], true),
            'password' => $_POST['password'], // Don't sanitize password
            'company_name' => sanitize_text_field($_POST['company_name']),
            'phone_number' => sanitize_text_field($_POST['phone_number']),
            'address' => sanitize_textarea_field($_POST['address']),
        );
        
        // Create tenant
        $result = $tenant_ops->create_tenant($data);
        
        if ($result['success']) {
            // Redirect with success message
            $redirect_url = add_query_arg(
                array(
                    'page' => $this->menu_slug . '-view',
                    'tenant_id' => $result['tenant_id'],
                    'message' => 'created',
                ),
                admin_url('admin.php')
            );
            
            wp_redirect($redirect_url);
            exit;
        } else {
            // Store error and redirect back
            set_transient('novarax_tm_error_' . get_current_user_id(), $result['error'], 45);
            
            $redirect_url = add_query_arg(
                array(
                    'page' => $this->menu_slug . '-add',
                    'error' => 'create_failed',
                ),
                admin_url('admin.php')
            );
            
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Handle update tenant form submission
     */
    private function handle_update_tenant() {
        if (!isset($_POST['tenant_id'])) {
            return;
        }
        
        $tenant_id = intval($_POST['tenant_id']);
        $tenant_ops = new NovaRax_Tenant_Operations();
        
        // Get form data
        $data = array(
            'account_name' => sanitize_text_field($_POST['account_name']),
            'company_name' => sanitize_text_field($_POST['company_name']),
            'phone_number' => sanitize_text_field($_POST['phone_number']),
            'address' => sanitize_textarea_field($_POST['address']),
            'billing_email' => sanitize_email($_POST['billing_email']),
        );
        
        // Update tenant
        $success = $tenant_ops->update_tenant($tenant_id, $data);
        
        if ($success) {
            $redirect_url = add_query_arg(
                array(
                    'page' => $this->menu_slug . '-view',
                    'tenant_id' => $tenant_id,
                    'message' => 'updated',
                ),
                admin_url('admin.php')
            );
        } else {
            $redirect_url = add_query_arg(
                array(
                    'page' => $this->menu_slug . '-edit',
                    'tenant_id' => $tenant_id,
                    'error' => 'update_failed',
                ),
                admin_url('admin.php')
            );
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle delete tenant
     */
    private function handle_delete_tenant() {
        if (!isset($_POST['tenant_id'])) {
            return;
        }
        
        $tenant_id = intval($_POST['tenant_id']);
        $hard_delete = isset($_POST['hard_delete']) && $_POST['hard_delete'] === '1';
        
        $tenant_ops = new NovaRax_Tenant_Operations();
        $result = $tenant_ops->delete_tenant($tenant_id, $hard_delete);
        
        if ($result['success']) {
            $redirect_url = add_query_arg(
                array(
                    'page' => $this->menu_slug . '-list',
                    'message' => 'deleted',
                ),
                admin_url('admin.php')
            );
        } else {
            $redirect_url = add_query_arg(
                array(
                    'page' => $this->menu_slug . '-list',
                    'error' => 'delete_failed',
                ),
                admin_url('admin.php')
            );
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle settings update
     */
    private function handle_update_settings() {
        // Update settings
        $settings = array(
            'novarax_tm_tenant_storage_limit',
            'novarax_tm_tenant_user_limit',
            'novarax_tm_subdomain_suffix',
            'novarax_tm_auto_provision',
            'novarax_tm_grace_period_days',
            'novarax_tm_from_email',
            'novarax_tm_from_name',
            'novarax_tm_email_logo',
            'novarax_tm_email_primary_color',
        );
        
        foreach ($settings as $setting) {
            if (isset($_POST[$setting])) {
                $value = $_POST[$setting];
                
                // Sanitize based on type
                if (strpos($setting, '_limit') !== false || strpos($setting, '_days') !== false) {
                    $value = intval($value);
                } elseif (strpos($setting, 'email') !== false && strpos($setting, 'logo') === false) {
                    $value = sanitize_email($value);
                } elseif (strpos($setting, 'url') !== false || strpos($setting, 'logo') !== false) {
                    $value = esc_url_raw($value);
                } elseif ($setting === 'novarax_tm_auto_provision') {
                    $value = ($value === '1');
                } else {
                    $value = sanitize_text_field($value);
                }
                
                update_option($setting, $value);
            }
        }
        
        $redirect_url = add_query_arg(
            array(
                'page' => $this->menu_slug . '-settings',
                'message' => 'settings_saved',
            ),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        // Check for success messages
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            
            $messages = array(
                'created' => __('Tenant created successfully!', 'novarax-tenant-manager'),
                'updated' => __('Tenant updated successfully!', 'novarax-tenant-manager'),
                'deleted' => __('Tenant deleted successfully!', 'novarax-tenant-manager'),
                'settings_saved' => __('Settings saved successfully!', 'novarax-tenant-manager'),
            );
            
            if (isset($messages[$message])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$message]) . '</p></div>';
            }
        }
        
        // Check for error messages
        if (isset($_GET['error'])) {
            $error_key = sanitize_text_field($_GET['error']);
            
            // Check transient for detailed error
            $error = get_transient('novarax_tm_error_' . get_current_user_id());
            
            if ($error) {
                delete_transient('novarax_tm_error_' . get_current_user_id());
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
            } else {
                $errors = array(
                    'create_failed' => __('Failed to create tenant. Please try again.', 'novarax-tenant-manager'),
                    'update_failed' => __('Failed to update tenant. Please try again.', 'novarax-tenant-manager'),
                    'delete_failed' => __('Failed to delete tenant. Please try again.', 'novarax-tenant-manager'),
                );
                
                if (isset($errors[$error_key])) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($errors[$error_key]) . '</p></div>';
                }
            }
        }
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    
    /**
     * Render tenants list page
     */
    public function render_tenants_list_page() {
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/tenant-list.php';
    }
    
    /**
     * Render add tenant page
     */
    public function render_add_tenant_page() {
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/tenant-add.php';
    }
    
    /**
     * Render edit tenant page
     */
    public function render_edit_tenant_page() {
        if (!isset($_GET['tenant_id'])) {
            wp_die(__('Invalid tenant ID', 'novarax-tenant-manager'));
        }
        
        $tenant_id = intval($_GET['tenant_id']);
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/tenant-edit.php';
    }
    
    /**
     * Render view tenant page
     */
    public function render_view_tenant_page() {
        if (!isset($_GET['tenant_id'])) {
            wp_die(__('Invalid tenant ID', 'novarax-tenant-manager'));
        }
        
        $tenant_id = intval($_GET['tenant_id']);
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/tenant-view.php';
    }
    
    /**
     * Render modules page
     */
    public function render_modules_page() {
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/modules.php';
    }
    
    /**
     * Render analytics page
     */
    public function render_analytics_page() {
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/analytics.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        include NOVARAX_TM_PLUGIN_DIR . 'admin/views/settings.php';
    }
}