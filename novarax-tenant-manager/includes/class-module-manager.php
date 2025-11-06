<?php
/**
 * Module Manager Class
 * 
 * Manages modules (plugins) including registration, activation/deactivation,
 * license checking, and tenant-module relationships.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Module_Manager {
    
    /**
     * WordPress database object
     *
     * @var wpdb
     */
    private $wpdb;
    
    /**
     * Database manager instance
     *
     * @var NovaRax_Database_Manager
     */
    private $db_manager;
    
    /**
     * Table names
     *
     * @var array
     */
    private $tables;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->db_manager = new NovaRax_Database_Manager();
        
        $this->tables = array(
            'modules' => $this->db_manager->get_table_name('modules'),
            'tenant_modules' => $this->db_manager->get_table_name('tenant_modules'),
        );
    }
    
    /**
     * Register a new module
     *
     * @param array $data Module data
     * @return int|false Module ID or false on failure
     */
    public function register_module($data) {
        $defaults = array(
            'module_name' => '',
            'module_slug' => '',
            'plugin_path' => '',
            'product_id' => null,
            'description' => '',
            'version' => '1.0.0',
            'requires_php' => '7.4',
            'requires_modules' => null,
            'icon_url' => '',
            'status' => 'active',
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (empty($data['module_name']) || empty($data['module_slug']) || empty($data['plugin_path'])) {
            return false;
        }
        
        // Check if module already exists
        $existing = $this->get_module_by_slug($data['module_slug']);
        if ($existing) {
            // Update instead of insert
            return $this->update_module($existing->id, $data);
        }
        
        // Convert requires_modules array to JSON
        if (is_array($data['requires_modules'])) {
            $data['requires_modules'] = json_encode($data['requires_modules']);
        }
        
        $inserted = $this->wpdb->insert(
            $this->tables['modules'],
            array(
                'module_name' => $data['module_name'],
                'module_slug' => $data['module_slug'],
                'plugin_path' => $data['plugin_path'],
                'product_id' => $data['product_id'],
                'description' => $data['description'],
                'version' => $data['version'],
                'requires_php' => $data['requires_php'],
                'requires_modules' => $data['requires_modules'],
                'icon_url' => $data['icon_url'],
                'status' => $data['status'],
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($inserted) {
            $module_id = $this->wpdb->insert_id;
            NovaRax_Logger::info("Module registered: {$data['module_slug']} (ID: {$module_id})");
            return $module_id;
        }
        
        return false;
    }
    
    /**
     * Update module
     *
     * @param int $module_id Module ID
     * @param array $data Module data to update
     * @return bool Success status
     */
    public function update_module($module_id, $data) {
        // Convert requires_modules array to JSON
        if (isset($data['requires_modules']) && is_array($data['requires_modules'])) {
            $data['requires_modules'] = json_encode($data['requires_modules']);
        }
        
        $updated = $this->wpdb->update(
            $this->tables['modules'],
            $data,
            array('id' => $module_id),
            null,
            array('%d')
        );
        
        if ($updated !== false) {
            NovaRax_Logger::info("Module updated: ID {$module_id}");
            return true;
        }
        
        return false;
    }
    
    /**
     * Get module by ID
     *
     * @param int $module_id Module ID
     * @return object|null Module object
     */
    public function get_module($module_id) {
        $module = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['modules']} WHERE id = %d",
                $module_id
            )
        );
        
        if ($module && $module->requires_modules) {
            $module->requires_modules = json_decode($module->requires_modules, true);
        }
        
        return $module;
    }
    
    /**
     * Get module by slug
     *
     * @param string $module_slug Module slug
     * @return object|null Module object
     */
    public function get_module_by_slug($module_slug) {
        $module = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['modules']} WHERE module_slug = %s",
                $module_slug
            )
        );
        
        if ($module && $module->requires_modules) {
            $module->requires_modules = json_decode($module->requires_modules, true);
        }
        
        return $module;
    }
    
    /**
     * Get module by product ID
     *
     * @param int $product_id WooCommerce product ID
     * @return object|null Module object
     */
    public function get_module_by_product_id($product_id) {
        $module = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['modules']} WHERE product_id = %d",
                $product_id
            )
        );
        
        if ($module && $module->requires_modules) {
            $module->requires_modules = json_decode($module->requires_modules, true);
        }
        
        return $module;
    }
    
    /**
     * Get all modules
     *
     * @param array $args Query arguments
     * @return array Modules
     */
    public function get_all_modules($args = array()) {
        $defaults = array(
            'status' => 'active',
            'orderby' => 'module_name',
            'order' => 'ASC',
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        
        if ($args['status']) {
            $where[] = $this->wpdb->prepare('status = %s', $args['status']);
        }
        
        $where_clause = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}");
        
        $query = "SELECT * FROM {$this->tables['modules']} 
                  WHERE {$where_clause} 
                  ORDER BY {$orderby}";
        
        $modules = $this->wpdb->get_results($query);
        
        foreach ($modules as &$module) {
            if ($module->requires_modules) {
                $module->requires_modules = json_decode($module->requires_modules, true);
            }
        }
        
        return $modules;
    }
    
    /**
     * Activate module for tenant
     *
     * @param int $tenant_id Tenant ID
     * @param int $module_id Module ID
     * @param int $subscription_id Subscription ID (optional)
     * @param string $expires_at Expiration date (optional)
     * @return bool Success status
     */
    public function activate_module_for_tenant($tenant_id, $module_id, $subscription_id = null, $expires_at = null) {
        // Check if already activated
        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['tenant_modules']} 
                 WHERE tenant_id = %d AND module_id = %d",
                $tenant_id,
                $module_id
            )
        );
        
        if ($existing) {
            // Update existing record
            return $this->update_tenant_module($existing->id, array(
                'subscription_id' => $subscription_id,
                'status' => 'active',
                'activated_at' => current_time('mysql'),
                'expires_at' => $expires_at,
                'last_checked' => current_time('mysql'),
            ));
        }
        
        // Calculate grace period end date
        $grace_period_days = get_option('novarax_tm_grace_period_days', 7);
        $grace_period_ends = null;
        
        if ($expires_at) {
            $grace_period_ends = date('Y-m-d H:i:s', strtotime($expires_at . " +{$grace_period_days} days"));
        }
        
        // Insert new activation
        $inserted = $this->wpdb->insert(
            $this->tables['tenant_modules'],
            array(
                'tenant_id' => $tenant_id,
                'module_id' => $module_id,
                'subscription_id' => $subscription_id,
                'status' => 'active',
                'activated_at' => current_time('mysql'),
                'expires_at' => $expires_at,
                'last_checked' => current_time('mysql'),
                'grace_period_ends' => $grace_period_ends,
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($inserted) {
            NovaRax_Logger::info("Module {$module_id} activated for tenant {$tenant_id}");
            return true;
        }
        
        return false;
    }
    
    /**
     * Deactivate module for tenant
     *
     * @param int $tenant_id Tenant ID
     * @param int $module_id Module ID
     * @return bool Success status
     */
    public function deactivate_module_for_tenant($tenant_id, $module_id) {
        $updated = $this->wpdb->update(
            $this->tables['tenant_modules'],
            array(
                'status' => 'inactive',
                'last_checked' => current_time('mysql'),
            ),
            array(
                'tenant_id' => $tenant_id,
                'module_id' => $module_id,
            ),
            array('%s', '%s'),
            array('%d', '%d')
        );
        
        if ($updated !== false) {
            NovaRax_Logger::info("Module {$module_id} deactivated for tenant {$tenant_id}");
            return true;
        }
        
        return false;
    }
    
    /**
     * Update tenant module
     *
     * @param int $tenant_module_id Tenant module ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public function update_tenant_module($tenant_module_id, $data) {
        $updated = $this->wpdb->update(
            $this->tables['tenant_modules'],
            $data,
            array('id' => $tenant_module_id),
            null,
            array('%d')
        );
        
        return $updated !== false;
    }
    
    /**
     * Update tenant module status
     *
     * @param int $tenant_module_id Tenant module ID
     * @param string $status New status
     * @return bool Success status
     */
    public function update_tenant_module_status($tenant_module_id, $status) {
        return $this->update_tenant_module($tenant_module_id, array(
            'status' => $status,
            'last_checked' => current_time('mysql'),
        ));
    }
    
    /**
     * Get tenant active modules
     *
     * @param int $tenant_id Tenant ID
     * @return array Module objects
     */
    public function get_tenant_active_modules($tenant_id) {
        $query = "SELECT m.*, tm.* 
                  FROM {$this->tables['modules']} m
                  INNER JOIN {$this->tables['tenant_modules']} tm ON m.id = tm.module_id
                  WHERE tm.tenant_id = %d 
                  AND tm.status IN ('active', 'expired')
                  ORDER BY m.module_name ASC";
        
        $modules = $this->wpdb->get_results(
            $this->wpdb->prepare($query, $tenant_id)
        );
        
        return $modules;
    }
    
    /**
     * Get tenant modules by subscription
     *
     * @param int $subscription_id Subscription ID
     * @return array Module objects
     */
    public function get_tenant_modules_by_subscription($subscription_id) {
        $query = "SELECT * FROM {$this->tables['tenant_modules']} 
                  WHERE subscription_id = %d";
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, $subscription_id)
        );
    }
    
    /**
     * Check if tenant has module access
     *
     * @param int $tenant_id Tenant ID
     * @param int $module_id Module ID
     * @return bool Has access
     */
    public function tenant_has_module_access($tenant_id, $module_id) {
        $module = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['tenant_modules']} 
                 WHERE tenant_id = %d AND module_id = %d",
                $tenant_id,
                $module_id
            )
        );
        
        if (!$module) {
            return false;
        }
        
        // Check status
        if ($module->status === 'active') {
            // Check if expired
            if ($module->expires_at) {
                $now = current_time('timestamp');
                $expires = strtotime($module->expires_at);
                
                if ($now > $expires) {
                    // Check grace period
                    if ($module->grace_period_ends) {
                        $grace_ends = strtotime($module->grace_period_ends);
                        
                        if ($now > $grace_ends) {
                            // Grace period expired, deactivate
                            $this->deactivate_module_for_tenant($tenant_id, $module_id);
                            return false;
                        }
                        
                        // Still in grace period
                        return true;
                    }
                    
                    // No grace period, expired
                    $this->update_tenant_module_status($module->id, 'expired');
                    return false;
                }
            }
            
            return true;
        }
        
        // Expired status with grace period
        if ($module->status === 'expired' && $module->grace_period_ends) {
            $now = current_time('timestamp');
            $grace_ends = strtotime($module->grace_period_ends);
            
            if ($now <= $grace_ends) {
                return true;
            }
            
            // Grace period ended
            $this->deactivate_module_for_tenant($tenant_id, $module_id);
            return false;
        }
        
        return false;
    }
    
    /**
     * Get tenant module by slug
     *
     * @param int $tenant_id Tenant ID
     * @param string $module_slug Module slug
     * @return object|null Module object
     */
    public function get_tenant_module_by_slug($tenant_id, $module_slug) {
        $query = "SELECT tm.* 
                  FROM {$this->tables['tenant_modules']} tm
                  INNER JOIN {$this->tables['modules']} m ON tm.module_id = m.id
                  WHERE tm.tenant_id = %d AND m.module_slug = %s";
        
        return $this->wpdb->get_row(
            $this->wpdb->prepare($query, $tenant_id, $module_slug)
        );
    }
    
    /**
     * Check expired modules and update status
     * Should be run by cron job daily
     *
     * @return int Number of modules updated
     */
    public function check_expired_modules() {
        $query = "SELECT * FROM {$this->tables['tenant_modules']} 
                  WHERE status = 'active' 
                  AND expires_at IS NOT NULL 
                  AND expires_at < NOW()";
        
        $expired_modules = $this->wpdb->get_results($query);
        $count = 0;
        
        foreach ($expired_modules as $module) {
            // Update to expired status
            $this->update_tenant_module_status($module->id, 'expired');
            $count++;
            
            NovaRax_Logger::info("Module {$module->module_id} expired for tenant {$module->tenant_id}");
        }
        
        // Check grace period ended
        $query = "SELECT * FROM {$this->tables['tenant_modules']} 
                  WHERE status = 'expired' 
                  AND grace_period_ends IS NOT NULL 
                  AND grace_period_ends < NOW()";
        
        $grace_ended = $this->wpdb->get_results($query);
        
        foreach ($grace_ended as $module) {
            // Deactivate
            $this->deactivate_module_for_tenant($module->tenant_id, $module->module_id);
            $count++;
            
            NovaRax_Logger::info("Module {$module->module_id} grace period ended for tenant {$module->tenant_id}");
        }
        
        if ($count > 0) {
            NovaRax_Logger::info("Checked expired modules: {$count} modules updated");
        }
        
        return $count;
    }
    
    /**
     * Get module statistics
     *
     * @return array Statistics
     */
    public function get_statistics() {
        $stats = array(
            'total_modules' => 0,
            'active_modules' => 0,
            'total_activations' => 0,
            'active_activations' => 0,
        );
        
        // Total modules
        $stats['total_modules'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['modules']}"
        );
        
        // Active modules
        $stats['active_modules'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['modules']} WHERE status = 'active'"
        );
        
        // Total activations
        $stats['total_activations'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['tenant_modules']}"
        );
        
        // Active activations
        $stats['active_activations'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['tenant_modules']} WHERE status = 'active'"
        );
        
        return $stats;
    }
}