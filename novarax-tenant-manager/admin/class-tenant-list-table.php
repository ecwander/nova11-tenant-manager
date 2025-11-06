<?php
/**
 * Tenant List Table Class
 * 
 * Extends WP_List_Table to display tenants in WordPress-style table format
 * with sorting, filtering, bulk actions, and pagination.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load WP_List_Table if not already loaded
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NovaRax_Tenant_List_Table extends WP_List_Table {
    
    /**
     * Tenant operations instance
     *
     * @var NovaRax_Tenant_Operations
     */
    private $tenant_ops;
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => __('Tenant', 'novarax-tenant-manager'),
            'plural' => __('Tenants', 'novarax-tenant-manager'),
            'ajax' => false,
        ));
        
        $this->tenant_ops = new NovaRax_Tenant_Operations();
    }
    
    /**
     * Get columns
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'cb' => '<input type="checkbox" />',
            'account_name' => __('Account Name', 'novarax-tenant-manager'),
            'tenant_username' => __('Username', 'novarax-tenant-manager'),
            'subdomain' => __('Subdomain', 'novarax-tenant-manager'),
            'company_name' => __('Company', 'novarax-tenant-manager'),
            'billing_email' => __('Email', 'novarax-tenant-manager'),
            'status' => __('Status', 'novarax-tenant-manager'),
            'storage_used' => __('Storage', 'novarax-tenant-manager'),
            'created_at' => __('Created', 'novarax-tenant-manager'),
        );
    }
    
    /**
     * Get sortable columns
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'account_name' => array('account_name', false),
            'tenant_username' => array('tenant_username', false),
            'company_name' => array('company_name', false),
            'status' => array('status', false),
            'created_at' => array('created_at', true), // Default sort
        );
    }
    
    /**
     * Get bulk actions
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'activate' => __('Activate', 'novarax-tenant-manager'),
            'suspend' => __('Suspend', 'novarax-tenant-manager'),
            'delete' => __('Delete', 'novarax-tenant-manager'),
        );
    }
    
    /**
     * Get views (status filter links)
     *
     * @return array
     */
    protected function get_views() {
        $current = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        
        $counts = array(
            'all' => $this->tenant_ops->get_tenant_count(),
            'active' => $this->tenant_ops->get_tenant_count(array('status' => 'active')),
            'pending' => $this->tenant_ops->get_tenant_count(array('status' => 'pending')),
            'suspended' => $this->tenant_ops->get_tenant_count(array('status' => 'suspended')),
            'cancelled' => $this->tenant_ops->get_tenant_count(array('status' => 'cancelled')),
        );
        
        $status_links = array();
        $base_url = admin_url('admin.php?page=novarax-tenants-list');
        
        foreach ($counts as $status => $count) {
            $label = ucfirst($status);
            $class = ($current === $status) ? 'current' : '';
            
            if ($status === 'all') {
                $url = $base_url;
            } else {
                $url = add_query_arg('status', $status, $base_url);
            }
            
            $status_links[$status] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url($url),
                $class,
                $label,
                $count
            );
        }
        
        return $status_links;
    }
    
    /**
     * Column checkbox
     *
     * @param array $item
     * @return string
     */
    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="tenant_ids[]" value="%d" />',
            $item->id
        );
    }
    
    /**
     * Column account name (with row actions)
     *
     * @param object $item
     * @return string
     */
    protected function column_account_name($item) {
        $view_url = add_query_arg(
            array(
                'page' => 'novarax-tenants-view',
                'tenant_id' => $item->id,
            ),
            admin_url('admin.php')
        );
        
        $edit_url = add_query_arg(
            array(
                'page' => 'novarax-tenants-edit',
                'tenant_id' => $item->id,
            ),
            admin_url('admin.php')
        );
        
        $delete_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'delete_tenant',
                    'tenant_id' => $item->id,
                ),
                admin_url('admin.php')
            ),
            'delete_tenant_' . $item->id
        );
        
        $actions = array(
            'view' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($view_url),
                __('View', 'novarax-tenant-manager')
            ),
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_url),
                __('Edit', 'novarax-tenant-manager')
            ),
        );
        
        // Add status-specific actions
        if ($item->status === 'active') {
            $actions['suspend'] = sprintf(
                '<a href="#" class="novarax-suspend-tenant" data-tenant-id="%d">%s</a>',
                $item->id,
                __('Suspend', 'novarax-tenant-manager')
            );
        } elseif ($item->status === 'suspended' || $item->status === 'pending') {
            $actions['activate'] = sprintf(
                '<a href="#" class="novarax-activate-tenant" data-tenant-id="%d">%s</a>',
                $item->id,
                __('Activate', 'novarax-tenant-manager')
            );
        }
        
        // Add provision action if pending
        if ($item->status === 'pending') {
            $actions['provision'] = sprintf(
                '<a href="#" class="novarax-provision-tenant" data-tenant-id="%d">%s</a>',
                $item->id,
                __('Provision Now', 'novarax-tenant-manager')
            );
        }
        
        $actions['delete'] = sprintf(
            '<a href="#" class="novarax-delete-tenant" data-tenant-id="%d" style="color:#b32d2e;">%s</a>',
            $item->id,
            __('Delete', 'novarax-tenant-manager')
        );
        
        return sprintf(
            '<strong><a href="%s">%s</a></strong>%s',
            esc_url($view_url),
            esc_html($item->account_name),
            $this->row_actions($actions)
        );
    }
    
    /**
     * Column username
     *
     * @param object $item
     * @return string
     */
    protected function column_tenant_username($item) {
        return sprintf(
            '<code>%s</code>',
            esc_html($item->tenant_username)
        );
    }
    
    /**
     * Column subdomain
     *
     * @param object $item
     * @return string
     */
    protected function column_subdomain($item) {
        $url = 'https://' . $item->subdomain;
        return sprintf(
            '<a href="%s" target="_blank">%s <span class="dashicons dashicons-external" style="font-size:12px;"></span></a>',
            esc_url($url),
            esc_html($item->subdomain)
        );
    }
    
    /**
     * Column company name
     *
     * @param object $item
     * @return string
     */
    protected function column_company_name($item) {
        return esc_html($item->company_name ?: '—');
    }
    
    /**
     * Column email
     *
     * @param object $item
     * @return string
     */
    protected function column_billing_email($item) {
        return sprintf(
            '<a href="mailto:%s">%s</a>',
            esc_attr($item->billing_email),
            esc_html($item->billing_email)
        );
    }
    
    /**
     * Column status
     *
     * @param object $item
     * @return string
     */
    protected function column_status($item) {
        $status_colors = array(
            'active' => '#46b450',
            'pending' => '#f0b849',
            'suspended' => '#dc3232',
            'cancelled' => '#999',
        );
        
        $color = isset($status_colors[$item->status]) ? $status_colors[$item->status] : '#999';
        
        return sprintf(
            '<span class="novarax-status-badge" style="background-color:%s;">%s</span>',
            esc_attr($color),
            esc_html(ucfirst($item->status))
        );
    }
    
    /**
     * Column storage used
     *
     * @param object $item
     * @return string
     */
    protected function column_storage_used($item) {
        $used = $item->storage_used;
        $limit = $item->storage_limit;
        $percentage = $limit > 0 ? ($used / $limit) * 100 : 0;
        
        $color = '#46b450';
        if ($percentage > 80) {
            $color = '#dc3232';
        } elseif ($percentage > 60) {
            $color = '#f0b849';
        }
        
        return sprintf(
            '<div class="novarax-storage-bar">
                <div class="novarax-storage-bar-fill" style="width:%d%%;background-color:%s;"></div>
            </div>
            <small>%s / %s (%d%%)</small>',
            (int) $percentage,
            esc_attr($color),
            size_format($used, 2),
            size_format($limit, 2),
            (int) $percentage
        );
    }
    
    /**
     * Column created date
     *
     * @param object $item
     * @return string
     */
    protected function column_created_at($item) {
        $date = mysql2date(get_option('date_format'), $item->created_at);
        $time = mysql2date(get_option('time_format'), $item->created_at);
        
        return sprintf(
            '<span title="%s">%s</span><br><small>%s</small>',
            esc_attr($item->created_at),
            esc_html($date),
            esc_html($time)
        );
    }
    
    /**
     * Default column handler
     *
     * @param object $item
     * @param string $column_name
     * @return string
     */
    protected function column_default($item, $column_name) {
        return isset($item->$column_name) ? esc_html($item->$column_name) : '—';
    }
    
    /**
     * Prepare items
     */
    public function prepare_items() {
        // Get parameters
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        
        // Build query args
        $args = array(
            'limit' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
            'orderby' => $orderby,
            'order' => $order,
        );
        
        if ($search) {
            $args['search'] = $search;
        }
        
        if ($status && $status !== 'all') {
            $args['status'] = $status;
        }
        
        // Get items
        $this->items = $this->tenant_ops->get_tenants($args);
        
        // Get total count
        $total_items = $this->tenant_ops->get_tenant_count($args);
        
        // Set pagination
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ));
        
        // Set columns
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array($columns, $hidden, $sortable);
    }
    
    /**
     * Display extra table navigation
     *
     * @param string $which
     */
    protected function extra_tablenav($which) {
        if ($which === 'top') {
            ?>
            <div class="alignleft actions">
                <button type="button" class="button" id="novarax-refresh-list">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Refresh', 'novarax-tenant-manager'); ?>
                </button>
                
                <button type="button" class="button" id="novarax-export-csv">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Export CSV', 'novarax-tenant-manager'); ?>
                </button>
            </div>
            <?php
        }
    }
    
    /**
     * Display message when no items
     */
    public function no_items() {
        if (isset($_REQUEST['s']) && !empty($_REQUEST['s'])) {
            _e('No tenants found matching your search.', 'novarax-tenant-manager');
        } else {
            printf(
                __('No tenants yet. <a href="%s">Create your first tenant</a>!', 'novarax-tenant-manager'),
                admin_url('admin.php?page=novarax-tenants-add')
            );
        }
    }
    
    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        // Check for nonce
        if (!isset($_POST['_wpnonce'])) {
            return;
        }
        
        $action = $this->current_action();
        
        if (!$action) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
            wp_die(__('Security check failed', 'novarax-tenant-manager'));
        }
        
        // Get selected tenant IDs
        $tenant_ids = isset($_POST['tenant_ids']) ? array_map('intval', $_POST['tenant_ids']) : array();
        
        if (empty($tenant_ids)) {
            return;
        }
        
        $success_count = 0;
        
        foreach ($tenant_ids as $tenant_id) {
            switch ($action) {
                case 'activate':
                    if ($this->tenant_ops->update_tenant_status($tenant_id, 'active')) {
                        $success_count++;
                    }
                    break;
                    
                case 'suspend':
                    if ($this->tenant_ops->update_tenant_status($tenant_id, 'suspended')) {
                        $success_count++;
                    }
                    break;
                    
                case 'delete':
                    $result = $this->tenant_ops->delete_tenant($tenant_id, false);
                    if ($result['success']) {
                        $success_count++;
                    }
                    break;
            }
        }
        
        // Redirect with message
        $redirect_url = add_query_arg(
            array(
                'page' => 'novarax-tenants-list',
                'message' => 'bulk_' . $action,
                'count' => $success_count,
            ),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect_url);
        exit;
    }
}