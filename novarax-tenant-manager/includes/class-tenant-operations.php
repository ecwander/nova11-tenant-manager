<?php
/**
 * Tenant Operations Class
 * 
 * Handles all tenant CRUD operations, provisioning, status management,
 * and tenant lifecycle operations.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Tenant_Operations {
    
    /**
     * Database manager instance
     *
     * @var NovaRax_Database_Manager
     */
    private $db_manager;
    
    /**
     * WordPress database object
     *
     * @var wpdb
     */
    private $wpdb;
    
    /**
     * Tenants table name
     *
     * @var string
     */
    private $table_tenants;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->db_manager = new NovaRax_Database_Manager();
        $this->table_tenants = $this->db_manager->get_table_name('tenants');
    }
    
    /**
     * Create a new tenant
     *
     * @param array $args Tenant data
     * @return array Result with 'success', 'tenant_id', or 'error'
     */
    public function create_tenant($args) {
        try {
            // Validate required fields
            $required = array('full_name', 'email', 'username', 'password');
            foreach ($required as $field) {
                if (empty($args[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }
            
            // Validate input
            $validator = new NovaRax_Tenant_Validator();
            $validation = $validator->validate_tenant_data($args);
            
            if (!$validation['valid']) {
                throw new Exception(implode(', ', $validation['errors']));
            }
            
            // Start transaction
            $this->wpdb->query('START TRANSACTION');
            
            // Create WordPress user
            $user_id = $this->create_wordpress_user($args);
            
            if (is_wp_error($user_id)) {
                throw new Exception($user_id->get_error_message());
            }
            
            // Generate subdomain from username
            $subdomain = sanitize_title($args['username']);
            $subdomain_suffix = get_option('novarax_tm_subdomain_suffix', '.app.novarax.ae');
            $full_subdomain = $subdomain . $subdomain_suffix;
            
            // Generate database name
            $db_prefix = get_option('novarax_tm_tenant_db_prefix', 'novarax_tenant_');
            $database_name = $db_prefix . $subdomain;
            
            // Insert tenant record
            $tenant_data = array(
                'user_id' => $user_id,
                'tenant_username' => $args['username'],
                'account_name' => isset($args['account_name']) ? $args['account_name'] : $args['full_name'],
                'company_name' => isset($args['company_name']) ? $args['company_name'] : null,
                'subdomain' => $full_subdomain,
                'database_name' => $database_name,
                'status' => 'pending',
                'storage_limit' => get_option('novarax_tm_tenant_storage_limit', 5368709120),
                'user_limit' => get_option('novarax_tm_tenant_user_limit', 10),
                'phone_number' => isset($args['phone_number']) ? $args['phone_number'] : null,
                'address' => isset($args['address']) ? $args['address'] : null,
                'billing_email' => $args['email'],
                'created_at' => current_time('mysql'),
                'metadata' => isset($args['metadata']) ? json_encode($args['metadata']) : null,
            );
            
            $inserted = $this->wpdb->insert(
                $this->table_tenants,
                $tenant_data,
                array(
                    '%d', // user_id
                    '%s', // tenant_username
                    '%s', // account_name
                    '%s', // company_name
                    '%s', // subdomain
                    '%s', // database_name
                    '%s', // status
                    '%d', // storage_limit
                    '%d', // user_limit
                    '%s', // phone_number
                    '%s', // address
                    '%s', // billing_email
                    '%s', // created_at
                    '%s', // metadata
                )
            );
            
            if (!$inserted) {
                throw new Exception('Failed to insert tenant record');
            }
            
            $tenant_id = $this->wpdb->insert_id;
            
            // Commit transaction
            $this->wpdb->query('COMMIT');
            
            // Log the creation
            $this->log_audit('tenant_created', 'tenant', $tenant_id, array(
                'username' => $args['username'],
                'email' => $args['email'],
            ));
            
            // Trigger provisioning if auto-provision is enabled
            if (get_option('novarax_tm_auto_provision', true)) {
                $this->queue_provisioning($tenant_id);
            }
            
            // Send welcome email
            NovaRax_Email_Notifications::send_welcome_email($user_id, $tenant_id, $args['password']);
            
            NovaRax_Logger::log("Tenant created successfully: ID {$tenant_id}, Username: {$args['username']}", 'info');
            
            return array(
                'success' => true,
                'tenant_id' => $tenant_id,
                'user_id' => $user_id,
                'subdomain' => $full_subdomain,
                'message' => 'Tenant created successfully',
            );
            
        } catch (Exception $e) {
            // Rollback transaction
            $this->wpdb->query('ROLLBACK');
            
            // Clean up user if created
            if (isset($user_id) && !is_wp_error($user_id)) {
                wp_delete_user($user_id);
            }
            
            NovaRax_Logger::log('Tenant creation failed: ' . $e->getMessage(), 'error');
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Create WordPress user for tenant
     *
     * @param array $args User data
     * @return int|WP_Error User ID on success, WP_Error on failure
     */
    private function create_wordpress_user($args) {
        $user_data = array(
            'user_login' => $args['username'],
            'user_email' => $args['email'],
            'user_pass' => $args['password'],
            'display_name' => $args['full_name'],
            'first_name' => isset($args['first_name']) ? $args['first_name'] : '',
            'last_name' => isset($args['last_name']) ? $args['last_name'] : '',
            'role' => 'tenant',
        );
        
        $user_id = wp_insert_user($user_data);
        
        if (!is_wp_error($user_id)) {
            // Add additional user meta
            update_user_meta($user_id, 'novarax_tenant', true);
            
            if (isset($args['phone_number'])) {
                update_user_meta($user_id, 'billing_phone', $args['phone_number']);
            }
        }
        
        return $user_id;
    }
    
    /**
     * Queue tenant for provisioning
     *
     * @param int $tenant_id Tenant ID
     * @return bool Success status
     */
    private function queue_provisioning($tenant_id) {
        $queue = new NovaRax_Provisioning_Queue();
        return $queue->add_to_queue($tenant_id);
    }
    
    /**
     * Provision a tenant (create database and configure)
     *
     * @param int $tenant_id Tenant ID
     * @return array Result with 'success' or 'error'
     */
    public function provision_tenant($tenant_id) {
        try {
            $tenant = $this->get_tenant($tenant_id);
            
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }
            
            if ($tenant->status === 'active') {
                throw new Exception('Tenant is already provisioned');
            }
            
            // Update status to provisioning
            $this->update_tenant_status($tenant_id, 'pending');
            
            // Create tenant database
            $db_result = $this->db_manager->create_tenant_database(
                $tenant->database_name,
                substr($tenant->database_name, 0, 16)
            );
            
            if (!$db_result['success']) {
                throw new Exception('Failed to create database: ' . $db_result['error']);
            }
            
            // Store database credentials securely
            $this->store_database_credentials($tenant_id, $db_result);
            
            // Create wp-config.php for tenant
            $this->create_tenant_config($tenant, $db_result);
            
            // Initialize tenant WordPress installation
            $this->initialize_tenant_wordpress($tenant, $db_result);
            
            // Update tenant status to active
            $this->update_tenant_status($tenant_id, 'active');
            
            // Log the provisioning
            $this->log_audit('tenant_provisioned', 'tenant', $tenant_id, array(
                'subdomain' => $tenant->subdomain,
                'database' => $tenant->database_name,
            ));
            
            // Send activation email
            NovaRax_Email_Notifications::send_activation_email($tenant->user_id, $tenant_id);
            
            NovaRax_Logger::log("Tenant provisioned successfully: ID {$tenant_id}", 'info');
            
            return array(
                'success' => true,
                'message' => 'Tenant provisioned successfully',
                'subdomain' => $tenant->subdomain,
            );
            
        } catch (Exception $e) {
            // Update status to failed
            if (isset($tenant_id)) {
                $this->update_tenant_status($tenant_id, 'suspended');
                
                // Store error in metadata
                $this->update_tenant_metadata($tenant_id, array(
                    'provisioning_error' => $e->getMessage(),
                    'provisioning_failed_at' => current_time('mysql'),
                ));
            }
            
            NovaRax_Logger::log('Tenant provisioning failed: ' . $e->getMessage(), 'error');
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Store database credentials securely
     *
     * @param int $tenant_id Tenant ID
     * @param array $credentials Database credentials
     */
    private function store_database_credentials($tenant_id, $credentials) {
        // Encrypt credentials before storing
        $encrypted = NovaRax_Security::encrypt(json_encode(array(
            'database' => $credentials['database'],
            'username' => $credentials['username'],
            'password' => $credentials['password'],
        )));
        
        $this->update_tenant_metadata($tenant_id, array(
            'db_credentials' => $encrypted,
        ));
    }
    
    /**
     * Create wp-config.php for tenant
     *
     * @param object $tenant Tenant object
     * @param array $db_credentials Database credentials
     * @return bool Success status
     */
    private function create_tenant_config($tenant, $db_credentials) {
        $codebase_path = get_option('novarax_tm_tenant_codebase_path', '/var/www/tenant-dashboard');
        $config_path = $codebase_path . '/wp-config.php';
        
        // This is handled dynamically in the tenant bootstrap MU-plugin
        // But we can create a tenant-specific config file if needed
        
        return true;
    }
    
    /**
     * Initialize tenant WordPress installation
     *
     * @param object $tenant Tenant object
     * @param array $db_credentials Database credentials
     * @return bool Success status
     */
    private function initialize_tenant_wordpress($tenant, $db_credentials) {
        // Create new wpdb instance for tenant database
        $tenant_db = new wpdb(
            $db_credentials['username'],
            $db_credentials['password'],
            $db_credentials['database'],
            DB_HOST
        );
        
        // Set site options
        $site_url = 'https://' . $tenant->subdomain;
        
        $tenant_db->insert($tenant_db->prefix . 'options', array(
            'option_name' => 'siteurl',
            'option_value' => $site_url,
            'autoload' => 'yes',
        ));
        
        $tenant_db->insert($tenant_db->prefix . 'options', array(
            'option_name' => 'home',
            'option_value' => $site_url,
            'autoload' => 'yes',
        ));
        
        $tenant_db->insert($tenant_db->prefix . 'options', array(
            'option_name' => 'blogname',
            'option_value' => $tenant->account_name,
            'autoload' => 'yes',
        ));
        
        // Store tenant ID in options
        $tenant_db->insert($tenant_db->prefix . 'options', array(
            'option_name' => 'novarax_tenant_id',
            'option_value' => $tenant->id,
            'autoload' => 'yes',
        ));
        
        return true;
    }
    
    /**
     * Get tenant by ID
     *
     * @param int $tenant_id Tenant ID
     * @return object|null Tenant object or null
     */
    public function get_tenant($tenant_id) {
        $tenant = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_tenants} WHERE id = %d",
                $tenant_id
            )
        );
        
        if ($tenant && $tenant->metadata) {
            $tenant->metadata = json_decode($tenant->metadata, true);
        }
        
        return $tenant;
    }
    
    /**
     * Get tenant by username
     *
     * @param string $username Tenant username
     * @return object|null Tenant object or null
     */
    public function get_tenant_by_username($username) {
        $tenant = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_tenants} WHERE tenant_username = %s",
                $username
            )
        );
        
        if ($tenant && $tenant->metadata) {
            $tenant->metadata = json_decode($tenant->metadata, true);
        }
        
        return $tenant;
    }
    
    /**
     * Get tenant by subdomain
     *
     * @param string $subdomain Subdomain
     * @return object|null Tenant object or null
     */
    public function get_tenant_by_subdomain($subdomain) {
        $tenant = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_tenants} WHERE subdomain = %s",
                $subdomain
            )
        );
        
        if ($tenant && $tenant->metadata) {
            $tenant->metadata = json_decode($tenant->metadata, true);
        }
        
        return $tenant;
    }
    
    /**
     * Get tenant by user ID
     *
     * @param int $user_id User ID
     * @return object|null Tenant object or null
     */
    public function get_tenant_by_user_id($user_id) {
        $tenant = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_tenants} WHERE user_id = %d",
                $user_id
            )
        );
        
        if ($tenant && $tenant->metadata) {
            $tenant->metadata = json_decode($tenant->metadata, true);
        }
        
        return $tenant;
    }
    
    /**
     * Update tenant
     *
     * @param int $tenant_id Tenant ID
     * @param array $data Tenant data to update
     * @return bool Success status
     */
    public function update_tenant($tenant_id, $data) {
        // Remove fields that shouldn't be updated directly
        unset($data['id'], $data['user_id'], $data['created_at']);
        
        // Handle metadata separately
        if (isset($data['metadata'])) {
            $this->update_tenant_metadata($tenant_id, $data['metadata']);
            unset($data['metadata']);
        }
        
        if (empty($data)) {
            return true;
        }
        
        $updated = $this->wpdb->update(
            $this->table_tenants,
            $data,
            array('id' => $tenant_id),
            null,
            array('%d')
        );
        
        if ($updated !== false) {
            $this->log_audit('tenant_updated', 'tenant', $tenant_id, $data);
            NovaRax_Logger::log("Tenant updated: ID {$tenant_id}", 'info');
            return true;
        }
        
        return false;
    }
    
    /**
     * Update tenant status
     *
     * @param int $tenant_id Tenant ID
     * @param string $status New status (active, suspended, cancelled, pending)
     * @return bool Success status
     */
    public function update_tenant_status($tenant_id, $status) {
        $allowed_statuses = array('active', 'suspended', 'cancelled', 'pending');
        
        if (!in_array($status, $allowed_statuses)) {
            return false;
        }
        
        $updated = $this->wpdb->update(
            $this->table_tenants,
            array('status' => $status),
            array('id' => $tenant_id),
            array('%s'),
            array('%d')
        );
        
        if ($updated !== false) {
            $this->log_audit('tenant_status_changed', 'tenant', $tenant_id, array(
                'new_status' => $status,
            ));
            
            NovaRax_Logger::log("Tenant status updated: ID {$tenant_id}, Status: {$status}", 'info');
            return true;
        }
        
        return false;
    }
    
    /**
     * Update tenant metadata
     *
     * @param int $tenant_id Tenant ID
     * @param array $metadata Metadata to add/update
     * @return bool Success status
     */
    public function update_tenant_metadata($tenant_id, $metadata) {
        $tenant = $this->get_tenant($tenant_id);
        
        if (!$tenant) {
            return false;
        }
        
        $existing_metadata = $tenant->metadata ?: array();
        $new_metadata = array_merge($existing_metadata, $metadata);
        
        return $this->wpdb->update(
            $this->table_tenants,
            array('metadata' => json_encode($new_metadata)),
            array('id' => $tenant_id),
            array('%s'),
            array('%d')
        );
    }
    
    /**
     * Delete tenant (soft delete)
     *
     * @param int $tenant_id Tenant ID
     * @param bool $hard_delete If true, permanently delete; otherwise suspend
     * @return array Result with 'success' or 'error'
     */
    public function delete_tenant($tenant_id, $hard_delete = false) {
        try {
            $tenant = $this->get_tenant($tenant_id);
            
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }
            
            if ($hard_delete) {
                // Create backup before deletion
                $backup_path = $this->db_manager->backup_tenant_database($tenant->database_name);
                
                if (!$backup_path) {
                    throw new Exception('Failed to create backup');
                }
                
                // Delete tenant database
                $db_deleted = $this->db_manager->delete_tenant_database(
                    $tenant->database_name,
                    substr($tenant->database_name, 0, 16)
                );
                
                if (!$db_deleted) {
                    throw new Exception('Failed to delete database');
                }
                
                // Delete WordPress user
                wp_delete_user($tenant->user_id);
                
                // Delete tenant record
                $this->wpdb->delete(
                    $this->table_tenants,
                    array('id' => $tenant_id),
                    array('%d')
                );
                
                $this->log_audit('tenant_deleted', 'tenant', $tenant_id, array(
                    'backup_path' => $backup_path,
                ));
                
                NovaRax_Logger::log("Tenant permanently deleted: ID {$tenant_id}", 'info');
                
                return array(
                    'success' => true,
                    'message' => 'Tenant permanently deleted',
                    'backup_path' => $backup_path,
                );
                
            } else {
                // Soft delete - just suspend
                $this->update_tenant_status($tenant_id, 'cancelled');
                
                NovaRax_Logger::log("Tenant suspended: ID {$tenant_id}", 'info');
                
                return array(
                    'success' => true,
                    'message' => 'Tenant suspended',
                );
            }
            
        } catch (Exception $e) {
            NovaRax_Logger::log('Tenant deletion failed: ' . $e->getMessage(), 'error');
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Get all tenants with filters
     *
     * @param array $args Query arguments
     * @return array Tenants array
     */
    public function get_tenants($args = array()) {
        $defaults = array(
            'status' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
            'search' => null,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        
        if ($args['status']) {
            $where[] = $this->wpdb->prepare('status = %s', $args['status']);
        }
        
        if ($args['search']) {
            $search = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $where[] = $this->wpdb->prepare(
                '(tenant_username LIKE %s OR account_name LIKE %s OR company_name LIKE %s OR billing_email LIKE %s)',
                $search, $search, $search, $search
            );
        }
        
        $where_clause = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}");
        
        $query = "SELECT * FROM {$this->table_tenants} 
                  WHERE {$where_clause} 
                  ORDER BY {$orderby} 
                  LIMIT {$args['offset']}, {$args['limit']}";
        
        $tenants = $this->wpdb->get_results($query);
        
        foreach ($tenants as &$tenant) {
            if ($tenant->metadata) {
                $tenant->metadata = json_decode($tenant->metadata, true);
            }
        }
        
        return $tenants;
    }
    
    /**
     * Get tenant count
     *
     * @param array $args Query arguments
     * @return int Tenant count
     */
    public function get_tenant_count($args = array()) {
        $where = array('1=1');
        
        if (isset($args['status'])) {
            $where[] = $this->wpdb->prepare('status = %s', $args['status']);
        }
        
        if (isset($args['search'])) {
            $search = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $where[] = $this->wpdb->prepare(
                '(tenant_username LIKE %s OR account_name LIKE %s OR company_name LIKE %s OR billing_email LIKE %s)',
                $search, $search, $search, $search
            );
        }
        
        $where_clause = implode(' AND ', $where);
        
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_tenants} WHERE {$where_clause}"
        );
    }
    
    /**
     * Update tenant last login time
     *
     * @param int $tenant_id Tenant ID
     * @return bool Success status
     */
    public function update_last_login($tenant_id) {
        return $this->wpdb->update(
            $this->table_tenants,
            array('last_login' => current_time('mysql')),
            array('id' => $tenant_id),
            array('%s'),
            array('%d')
        );
    }
    
    /**
     * Log audit trail
     *
     * @param string $action Action performed
     * @param string $entity_type Entity type
     * @param int $entity_id Entity ID
     * @param array $details Additional details
     */
    private function log_audit($action, $entity_type, $entity_id, $details = array()) {
        $audit_table = $this->db_manager->get_table_name('audit_logs');
        
        $this->wpdb->insert(
            $audit_table,
            array(
                'tenant_id' => $entity_type === 'tenant' ? $entity_id : null,
                'user_id' => get_current_user_id(),
                'action' => $action,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'ip_address' => NovaRax_Security::get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                'details' => json_encode($details),
                'created_at' => current_time('mysql'),
            )
        );
    }
}