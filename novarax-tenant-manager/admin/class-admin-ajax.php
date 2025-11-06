<?php
/**
 * Admin AJAX Handler Class
 * 
 * Handles all AJAX requests from the admin interface including
 * real-time validation, quick actions, and dynamic updates.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Admin_Ajax {
    
    /**
     * Constructor - Register AJAX handlers
     */
    public function __construct() {
        // Username availability check
        add_action('wp_ajax_novarax_check_username', array($this, 'check_username_availability'));
        
        // Email availability check
        add_action('wp_ajax_novarax_check_email', array($this, 'check_email_availability'));
        
        // Quick actions
        add_action('wp_ajax_novarax_activate_tenant', array($this, 'activate_tenant'));
        add_action('wp_ajax_novarax_suspend_tenant', array($this, 'suspend_tenant'));
        add_action('wp_ajax_novarax_delete_tenant', array($this, 'delete_tenant'));
        add_action('wp_ajax_novarax_provision_tenant', array($this, 'provision_tenant'));
        
        // Get tenant info
        add_action('wp_ajax_novarax_get_tenant_info', array($this, 'get_tenant_info'));
        
        // Dashboard stats
        add_action('wp_ajax_novarax_get_dashboard_stats', array($this, 'get_dashboard_stats'));
        
        // Export data
        add_action('wp_ajax_novarax_export_tenants', array($this, 'export_tenants'));
        
        // Test email
        add_action('wp_ajax_novarax_send_test_email', array($this, 'send_test_email'));
        
        // Clean logs
        add_action('wp_ajax_novarax_clean_logs', array($this, 'clean_logs'));
        
        // Provisioning queue
        add_action('wp_ajax_novarax_get_queue_status', array($this, 'get_queue_status'));
        add_action('wp_ajax_novarax_process_queue', array($this, 'process_queue'));
    }
    
    /**
     * Check username availability
     */
    public function check_username_availability() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['username'])) {
            wp_send_json_error(array(
                'message' => __('Username is required', 'novarax-tenant-manager'),
            ));
        }
        
        $username = sanitize_text_field($_POST['username']);
        $validator = new NovaRax_Tenant_Validator();
        
        $result = $validator->check_username_availability($username);
        
        if ($result['available']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Check email availability
     */
    public function check_email_availability() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['email'])) {
            wp_send_json_error(array(
                'message' => __('Email is required', 'novarax-tenant-manager'),
            ));
        }
        
        $email = sanitize_email($_POST['email']);
        
        if (!is_email($email)) {
            wp_send_json_error(array(
                'message' => __('Invalid email format', 'novarax-tenant-manager'),
            ));
        }
        
        if (email_exists($email)) {
            wp_send_json_error(array(
                'message' => __('Email already exists', 'novarax-tenant-manager'),
            ));
        }
        
        wp_send_json_success(array(
            'message' => __('Email is available', 'novarax-tenant-manager'),
        ));
    }
    
    /**
     * Activate tenant
     */
    public function activate_tenant() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['tenant_id'])) {
            wp_send_json_error(array(
                'message' => __('Tenant ID is required', 'novarax-tenant-manager'),
            ));
        }
        
        $tenant_id = intval($_POST['tenant_id']);
        $tenant_ops = new NovaRax_Tenant_Operations();
        
        $success = $tenant_ops->update_tenant_status($tenant_id, 'active');
        
        if ($success) {
            wp_send_json_success(array(
                'message' => __('Tenant activated successfully', 'novarax-tenant-manager'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to activate tenant', 'novarax-tenant-manager'),
            ));
        }
    }
    
    /**
     * Suspend tenant
     */
    public function suspend_tenant() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['tenant_id'])) {
            wp_send_json_error(array(
                'message' => __('Tenant ID is required', 'novarax-tenant-manager'),
            ));
        }
        
        $tenant_id = intval($_POST['tenant_id']);
        $tenant_ops = new NovaRax_Tenant_Operations();
        
        $success = $tenant_ops->update_tenant_status($tenant_id, 'suspended');
        
        if ($success) {
            // Send suspension email
            $tenant = $tenant_ops->get_tenant($tenant_id);
            if ($tenant) {
                NovaRax_Email_Notifications::send_suspension_email(
                    $tenant->user_id,
                    $tenant_id,
                    __('Manual suspension by administrator', 'novarax-tenant-manager')
                );
            }
            
            wp_send_json_success(array(
                'message' => __('Tenant suspended successfully', 'novarax-tenant-manager'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to suspend tenant', 'novarax-tenant-manager'),
            ));
        }
    }
    
    /**
     * Delete tenant
     */
    public function delete_tenant() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['tenant_id'])) {
            wp_send_json_error(array(
                'message' => __('Tenant ID is required', 'novarax-tenant-manager'),
            ));
        }
        
        $tenant_id = intval($_POST['tenant_id']);
        $hard_delete = isset($_POST['hard_delete']) && $_POST['hard_delete'] === 'true';
        
        $tenant_ops = new NovaRax_Tenant_Operations();
        $result = $tenant_ops->delete_tenant($tenant_id, $hard_delete);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $hard_delete 
                    ? __('Tenant permanently deleted', 'novarax-tenant-manager')
                    : __('Tenant suspended', 'novarax-tenant-manager'),
                'backup_path' => isset($result['backup_path']) ? $result['backup_path'] : null,
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['error'],
            ));
        }
    }
    
    /**
     * Provision tenant
     */
    public function provision_tenant() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['tenant_id'])) {
            wp_send_json_error(array(
                'message' => __('Tenant ID is required', 'novarax-tenant-manager'),
            ));
        }
        
        $tenant_id = intval($_POST['tenant_id']);
        $tenant_ops = new NovaRax_Tenant_Operations();
        
        // Check if already provisioned
        $tenant = $tenant_ops->get_tenant($tenant_id);
        
        if (!$tenant) {
            wp_send_json_error(array(
                'message' => __('Tenant not found', 'novarax-tenant-manager'),
            ));
        }
        
        if ($tenant->status === 'active') {
            wp_send_json_error(array(
                'message' => __('Tenant is already provisioned', 'novarax-tenant-manager'),
            ));
        }
        
        // Add to provisioning queue
        $queue = new NovaRax_Provisioning_Queue();
        $added = $queue->add_to_queue($tenant_id, 1); // High priority
        
        if ($added) {
            wp_send_json_success(array(
                'message' => __('Tenant added to provisioning queue', 'novarax-tenant-manager'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to add tenant to queue', 'novarax-tenant-manager'),
            ));
        }
    }
    
    /**
     * Get tenant info
     */
    public function get_tenant_info() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['tenant_id'])) {
            wp_send_json_error(array(
                'message' => __('Tenant ID is required', 'novarax-tenant-manager'),
            ));
        }
        
        $tenant_id = intval($_POST['tenant_id']);
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = $tenant_ops->get_tenant($tenant_id);
        
        if (!$tenant) {
            wp_send_json_error(array(
                'message' => __('Tenant not found', 'novarax-tenant-manager'),
            ));
        }
        
        // Get user info
        $user = get_userdata($tenant->user_id);
        
        wp_send_json_success(array(
            'tenant' => $tenant,
            'user' => array(
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
            ),
        ));
    }
    
    /**
     * Get dashboard statistics
     */
    public function get_dashboard_stats() {
        NovaRax_Security::check_ajax_nonce();
        
        $tenant_ops = new NovaRax_Tenant_Operations();
        
        $stats = array(
            'total_tenants' => $tenant_ops->get_tenant_count(),
            'active_tenants' => $tenant_ops->get_tenant_count(array('status' => 'active')),
            'pending_tenants' => $tenant_ops->get_tenant_count(array('status' => 'pending')),
            'suspended_tenants' => $tenant_ops->get_tenant_count(array('status' => 'suspended')),
        );
        
        // Get provisioning queue stats
        $queue = new NovaRax_Provisioning_Queue();
        $queue_stats = $queue->get_statistics();
        
        $stats['queue'] = $queue_stats;
        
        // Get log stats
        $log_stats = NovaRax_Logger::get_statistics();
        $stats['logs'] = $log_stats;
        
        wp_send_json_success($stats);
    }
    
    /**
     * Export tenants to CSV
     */
    public function export_tenants() {
        NovaRax_Security::check_ajax_nonce();
        
        $tenant_ops = new NovaRax_Tenant_Operations();
        
        // Get filters
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null;
        
        $args = array(
            'limit' => 999999, // All tenants
            'offset' => 0,
        );
        
        if ($status && $status !== 'all') {
            $args['status'] = $status;
        }
        
        $tenants = $tenant_ops->get_tenants($args);
        
        if (empty($tenants)) {
            wp_send_json_error(array(
                'message' => __('No tenants to export', 'novarax-tenant-manager'),
            ));
        }
        
        // Create CSV
        $filename = 'novarax-tenants-' . date('Y-m-d-H-i-s') . '.csv';
        $upload_dir = wp_upload_dir();
        $filepath = $upload_dir['basedir'] . '/novarax-exports/' . $filename;
        
        // Create directory if it doesn't exist
        wp_mkdir_p(dirname($filepath));
        
        $fp = fopen($filepath, 'w');
        
        // CSV headers
        fputcsv($fp, array(
            'ID',
            'Account Name',
            'Username',
            'Subdomain',
            'Company',
            'Email',
            'Phone',
            'Status',
            'Storage Used',
            'Storage Limit',
            'Created Date',
        ));
        
        // CSV rows
        foreach ($tenants as $tenant) {
            fputcsv($fp, array(
                $tenant->id,
                $tenant->account_name,
                $tenant->tenant_username,
                $tenant->subdomain,
                $tenant->company_name,
                $tenant->billing_email,
                $tenant->phone_number,
                $tenant->status,
                size_format($tenant->storage_used, 2),
                size_format($tenant->storage_limit, 2),
                $tenant->created_at,
            ));
        }
        
        fclose($fp);
        
        wp_send_json_success(array(
            'message' => __('Export completed', 'novarax-tenant-manager'),
            'download_url' => $upload_dir['baseurl'] . '/novarax-exports/' . $filename,
            'filename' => $filename,
            'count' => count($tenants),
        ));
    }
    
    /**
     * Send test email
     */
    public function send_test_email() {
        NovaRax_Security::check_ajax_nonce();
        
        if (!isset($_POST['email'])) {
            wp_send_json_error(array(
                'message' => __('Email address is required', 'novarax-tenant-manager'),
            ));
        }
        
        $email = sanitize_email($_POST['email']);
        
        if (!is_email($email)) {
            wp_send_json_error(array(
                'message' => __('Invalid email address', 'novarax-tenant-manager'),
            ));
        }
        
        $sent = NovaRax_Email_Notifications::send_test_email($email);
        
        if ($sent) {
            wp_send_json_success(array(
                'message' => __('Test email sent successfully', 'novarax-tenant-manager'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to send test email', 'novarax-tenant-manager'),
            ));
        }
    }
    
    /**
     * Clean old logs
     */
    public function clean_logs() {
        NovaRax_Security::check_ajax_nonce();
        
        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
        
        $deleted = NovaRax_Logger::clean_old_logs($days);
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Cleaned %d old log entries', 'novarax-tenant-manager'),
                $deleted
            ),
            'deleted' => $deleted,
        ));
    }
    
    /**
     * Get provisioning queue status
     */
    public function get_queue_status() {
        NovaRax_Security::check_ajax_nonce();
        
        $queue = new NovaRax_Provisioning_Queue();
        $stats = $queue->get_statistics();
        $items = $queue->get_queue();
        
        wp_send_json_success(array(
            'stats' => $stats,
            'items' => $items,
        ));
    }
    
    /**
     * Process provisioning queue manually
     */
    public function process_queue() {
        NovaRax_Security::check_ajax_nonce();
        
        $queue = new NovaRax_Provisioning_Queue();
        
        if ($queue->is_processing()) {
            wp_send_json_error(array(
                'message' => __('Queue is already being processed', 'novarax-tenant-manager'),
            ));
        }
        
        $triggered = $queue->trigger_processing();
        
        if ($triggered) {
            wp_send_json_success(array(
                'message' => __('Queue processing triggered', 'novarax-tenant-manager'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to trigger queue processing', 'novarax-tenant-manager'),
            ));
        }
    }
}