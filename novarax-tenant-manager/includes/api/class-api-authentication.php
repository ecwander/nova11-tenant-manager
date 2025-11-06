<?php
/**
 * API Authentication Class
 * 
 * Handles API key generation, validation, and management.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_API_Authentication {
    
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
     * API keys table name
     *
     * @var string
     */
    private $table;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->db_manager = new NovaRax_Database_Manager();
        $this->table = $this->db_manager->get_table_name('api_keys');
    }
    
    /**
     * Generate API key for tenant
     *
     * @param int $tenant_id Tenant ID
     * @param array $permissions Array of allowed permissions
     * @param int $expires_days Days until expiration (0 = never)
     * @return array API key data or error
     */
    public function generate_api_key($tenant_id, $permissions = array(), $expires_days = 0) {
        // Generate unique API key
        $api_key = NovaRax_Security::generate_api_key();
        $secret = NovaRax_Security::generate_api_secret();
        $secret_hash = NovaRax_Security::hash($secret);
        
        // Calculate expiration
        $expires_at = null;
        if ($expires_days > 0) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_days} days"));
        }
        
        // Default permissions if none provided
        if (empty($permissions)) {
            $permissions = array(
                'check_license',
                'get_tenant_info',
                'get_module_status',
                'verify_access',
                'update_activity',
            );
        }
        
        // Insert into database
        $inserted = $this->wpdb->insert(
            $this->table,
            array(
                'tenant_id' => $tenant_id,
                'api_key' => $api_key,
                'secret_hash' => $secret_hash,
                'permissions' => json_encode($permissions),
                'rate_limit' => 1000, // 1000 requests per hour
                'expires_at' => $expires_at,
                'status' => 'active',
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if (!$inserted) {
            return array(
                'success' => false,
                'error' => 'Failed to create API key',
            );
        }
        
        $key_id = $this->wpdb->insert_id;
        
        NovaRax_Logger::info("API key generated for tenant {$tenant_id}", array(
            'key_id' => $key_id,
        ));
        
        return array(
            'success' => true,
            'api_key' => $api_key,
            'secret' => $secret, // Only returned once!
            'expires_at' => $expires_at,
            'permissions' => $permissions,
            'message' => 'Store the secret securely. It cannot be retrieved again.',
        );
    }
    
    /**
     * Validate API key
     *
     * @param string $api_key API key
     * @param string $secret API secret (optional for validation)
     * @return bool|object False or key record
     */
    public function validate_api_key($api_key, $secret = null) {
        $key_record = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE api_key = %s",
                $api_key
            )
        );
        
        if (!$key_record) {
            return false;
        }
        
        // Check status
        if ($key_record->status !== 'active') {
            return false;
        }
        
        // Check expiration
        if ($key_record->expires_at && strtotime($key_record->expires_at) < time()) {
            // Expire the key
            $this->revoke_api_key($api_key);
            return false;
        }
        
        // Verify secret if provided
        if ($secret && !NovaRax_Security::verify_hash($secret, $key_record->secret_hash)) {
            return false;
        }
        
        // Check rate limit
        if (!$this->check_rate_limit($key_record->id)) {
            return false;
        }
        
        // Update last used
        $this->wpdb->update(
            $this->table,
            array('last_used' => current_time('mysql')),
            array('id' => $key_record->id),
            array('%s'),
            array('%d')
        );
        
        return $key_record;
    }
    
    /**
     * Check rate limit for API key
     *
     * @param int $key_id API key ID
     * @return bool Within limit
     */
    private function check_rate_limit($key_id) {
        $cache_key = 'novarax_api_rate_limit_' . $key_id;
        
        $count = get_transient($cache_key);
        
        if ($count === false) {
            // First request in this hour
            set_transient($cache_key, 1, 3600);
            return true;
        }
        
        // Get rate limit from database
        $key_record = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT rate_limit FROM {$this->table} WHERE id = %d",
                $key_id
            )
        );
        
        $limit = $key_record ? $key_record->rate_limit : 1000;
        
        if ($count >= $limit) {
            NovaRax_Logger::warning("API rate limit exceeded", array(
                'key_id' => $key_id,
                'count' => $count,
                'limit' => $limit,
            ));
            return false;
        }
        
        // Increment count
        set_transient($cache_key, $count + 1, 3600);
        
        return true;
    }
    
    /**
     * Revoke API key
     *
     * @param string $api_key API key
     * @return bool Success
     */
    public function revoke_api_key($api_key) {
        $updated = $this->wpdb->update(
            $this->table,
            array('status' => 'revoked'),
            array('api_key' => $api_key),
            array('%s'),
            array('%s')
        );
        
        if ($updated) {
            NovaRax_Logger::info("API key revoked: {$api_key}");
            return true;
        }
        
        return false;
    }
    
    /**
     * Get API keys for tenant
     *
     * @param int $tenant_id Tenant ID
     * @return array API keys
     */
    public function get_tenant_api_keys($tenant_id) {
        $keys = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, api_key, permissions, rate_limit, last_used, expires_at, status, created_at 
                 FROM {$this->table} 
                 WHERE tenant_id = %d 
                 ORDER BY created_at DESC",
                $tenant_id
            )
        );
        
        foreach ($keys as &$key) {
            $key->permissions = json_decode($key->permissions, true);
        }
        
        return $keys;
    }
    
    /**
     * Check if API key has permission
     *
     * @param string $api_key API key
     * @param string $permission Permission to check
     * @return bool Has permission
     */
    public function has_permission($api_key, $permission) {
        $key_record = $this->validate_api_key($api_key);
        
        if (!$key_record) {
            return false;
        }
        
        $permissions = json_decode($key_record->permissions, true);
        
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }
    
    /**
     * Generate JWT token for user
     *
     * @param int $user_id User ID
     * @param int $tenant_id Tenant ID
     * @param int $expiration Expiration in seconds (default 24 hours)
     * @return string JWT token
     */
    public function generate_jwt_token($user_id, $tenant_id, $expiration = 86400) {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        $payload = array(
            'user_id' => $user_id,
            'tenant_id' => $tenant_id,
            'username' => $user->user_login,
            'email' => $user->user_email,
        );
        
        return NovaRax_Security::generate_jwt($payload, $expiration);
    }
    
    /**
     * Validate JWT token
     *
     * @param string $token JWT token
     * @return array|false Payload or false
     */
    public function validate_jwt_token($token) {
        return NovaRax_Security::verify_jwt($token);
    }
}