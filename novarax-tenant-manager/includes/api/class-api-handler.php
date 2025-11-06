<?php
/**
 * API Handler Class
 * 
 * Handles REST API initialization and route registration.
 * Provides secure communication between tenant dashboards and master admin.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_API_Handler {
    
    /**
     * API namespace
     *
     * @var string
     */
    private $namespace = 'novarax/v1';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register all API routes
     */
    public function register_routes() {
        // Session validation endpoint
        register_rest_route($this->namespace, '/validate-session', array(
            'methods' => 'POST',
            'callback' => array($this, 'validate_session'),
            'permission_callback' => '__return_true', // Public endpoint with internal validation
        ));
        
        // License check endpoint
        register_rest_route($this->namespace, '/check-license', array(
            'methods' => 'POST',
            'callback' => array($this, 'check_license'),
            'permission_callback' => array($this, 'verify_api_request'),
        ));
        
        // Tenant info endpoint
        register_rest_route($this->namespace, '/tenant-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_tenant_info'),
            'permission_callback' => array($this, 'verify_api_request'),
        ));
        
        // Module status endpoint
        register_rest_route($this->namespace, '/module-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_module_status'),
            'permission_callback' => array($this, 'verify_api_request'),
        ));
        
        // Verify tenant access endpoint
        register_rest_route($this->namespace, '/verify-access', array(
            'methods' => 'POST',
            'callback' => array($this, 'verify_tenant_access'),
            'permission_callback' => array($this, 'verify_api_request'),
        ));
        
        // Update tenant activity endpoint
        register_rest_route($this->namespace, '/activity', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_activity'),
            'permission_callback' => array($this, 'verify_api_request'),
        ));
    }
    
    /**
     * Verify API request permission
     * Checks for valid authentication
     *
     * @param WP_REST_Request $request Request object
     * @return bool
     */
    public function verify_api_request($request) {
        // Get authentication header
        $auth_header = $request->get_header('Authorization');
        
        if (!$auth_header) {
            return false;
        }
        
        // Extract token (Bearer token or API key)
        if (strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            return $this->verify_jwt_token($token);
        } elseif (strpos($auth_header, 'ApiKey ') === 0) {
            $api_key = substr($auth_header, 7);
            return $this->verify_api_key($api_key);
        }
        
        return false;
    }
    
    /**
     * Verify JWT token
     *
     * @param string $token JWT token
     * @return bool
     */
    private function verify_jwt_token($token) {
        $payload = NovaRax_Security::verify_jwt($token);
        
        if (!$payload) {
            return false;
        }
        
        // Check if user exists and is active
        if (isset($payload['user_id'])) {
            $user = get_userdata($payload['user_id']);
            return $user && !empty($user->ID);
        }
        
        return false;
    }
    
    /**
     * Verify API key
     *
     * @param string $api_key API key
     * @return bool
     */
    private function verify_api_key($api_key) {
        global $wpdb;
        $db_manager = new NovaRax_Database_Manager();
        $table = $db_manager->get_table_name('api_keys');
        
        $key_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE api_key = %s AND status = 'active'",
                $api_key
            )
        );
        
        if (!$key_record) {
            return false;
        }
        
        // Check expiration
        if ($key_record->expires_at && strtotime($key_record->expires_at) < time()) {
            return false;
        }
        
        // Update last used
        $wpdb->update(
            $table,
            array('last_used' => current_time('mysql')),
            array('id' => $key_record->id),
            array('%s'),
            array('%d')
        );
        
        return true;
    }
    
    /**
     * Validate session endpoint
     * Checks if a user session is valid and returns user/tenant info
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function validate_session($request) {
        $token = $request->get_param('token');
        $subdomain = $request->get_param('subdomain');
        
        if (!$token) {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'Token is required',
            ), 400);
        }
        
        // Verify JWT token
        $payload = NovaRax_Security::verify_jwt($token);
        
        if (!$payload) {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'Invalid or expired token',
            ), 401);
        }
        
        // Get user info
        $user_id = $payload['user_id'];
        $user = get_userdata($user_id);
        
        if (!$user) {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'User not found',
            ), 404);
        }
        
        // Get tenant info
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = null;
        
        if ($subdomain) {
            $tenant = $tenant_ops->get_tenant_by_subdomain($subdomain);
        } else {
            $tenant = $tenant_ops->get_tenant_by_user_id($user_id);
        }
        
        if (!$tenant) {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'Tenant not found',
            ), 404);
        }
        
        // Verify user belongs to this tenant
        if ($tenant->user_id != $user_id) {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'User does not have access to this tenant',
            ), 403);
        }
        
        // Check tenant status
        if ($tenant->status !== 'active') {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'Tenant account is not active',
                'status' => $tenant->status,
            ), 403);
        }
        
        // Update last login
        $tenant_ops->update_last_login($tenant->id);
        
        return new WP_REST_Response(array(
            'valid' => true,
            'user' => array(
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
            ),
            'tenant' => array(
                'id' => $tenant->id,
                'username' => $tenant->tenant_username,
                'account_name' => $tenant->account_name,
                'subdomain' => $tenant->subdomain,
                'status' => $tenant->status,
            ),
        ), 200);
    }
    
    /**
     * Check license endpoint
     * Verifies if a tenant has access to a specific module
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function check_license($request) {
        $tenant_id = $request->get_param('tenant_id');
        $module_slug = $request->get_param('module_slug');
        
        if (!$tenant_id || !$module_slug) {
            return new WP_REST_Response(array(
                'error' => 'tenant_id and module_slug are required',
            ), 400);
        }
        
        $module_manager = new NovaRax_Module_Manager();
        
        // Get module
        $module = $module_manager->get_module_by_slug($module_slug);
        
        if (!$module) {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'Module not found',
            ), 404);
        }
        
        // Check if tenant has access
        $has_access = $module_manager->tenant_has_module_access($tenant_id, $module->id);
        
        if (!$has_access) {
            return new WP_REST_Response(array(
                'valid' => false,
                'message' => 'No active license for this module',
                'module' => $module_slug,
            ), 403);
        }
        
        // Get tenant module details
        $tenant_module = $module_manager->get_tenant_module_by_slug($tenant_id, $module_slug);
        
        return new WP_REST_Response(array(
            'valid' => true,
            'module' => array(
                'slug' => $module->module_slug,
                'name' => $module->module_name,
                'version' => $module->version,
                'status' => $tenant_module->status,
                'activated_at' => $tenant_module->activated_at,
                'expires_at' => $tenant_module->expires_at,
            ),
        ), 200);
    }
    
    /**
     * Get tenant info endpoint
     * Returns detailed tenant information
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_tenant_info($request) {
        $tenant_id = $request->get_param('tenant_id');
        $subdomain = $request->get_param('subdomain');
        
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = null;
        
        if ($tenant_id) {
            $tenant = $tenant_ops->get_tenant($tenant_id);
        } elseif ($subdomain) {
            $tenant = $tenant_ops->get_tenant_by_subdomain($subdomain);
        } else {
            return new WP_REST_Response(array(
                'error' => 'tenant_id or subdomain is required',
            ), 400);
        }
        
        if (!$tenant) {
            return new WP_REST_Response(array(
                'error' => 'Tenant not found',
            ), 404);
        }
        
        // Get user info
        $user = get_userdata($tenant->user_id);
        
        // Get active modules
        $module_manager = new NovaRax_Module_Manager();
        $active_modules = $module_manager->get_tenant_active_modules($tenant->id);
        
        return new WP_REST_Response(array(
            'tenant' => array(
                'id' => $tenant->id,
                'username' => $tenant->tenant_username,
                'account_name' => $tenant->account_name,
                'company_name' => $tenant->company_name,
                'subdomain' => $tenant->subdomain,
                'status' => $tenant->status,
                'storage_used' => $tenant->storage_used,
                'storage_limit' => $tenant->storage_limit,
                'user_limit' => $tenant->user_limit,
                'created_at' => $tenant->created_at,
                'last_login' => $tenant->last_login,
            ),
            'user' => array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
            ),
            'modules' => array_map(function($module) {
                return array(
                    'slug' => $module->module_slug,
                    'name' => $module->module_name,
                    'status' => $module->status,
                    'expires_at' => $module->expires_at,
                );
            }, $active_modules),
        ), 200);
    }
    
    /**
     * Get module status endpoint
     * Returns status of all modules for a tenant
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_module_status($request) {
        $tenant_id = $request->get_param('tenant_id');
        
        if (!$tenant_id) {
            return new WP_REST_Response(array(
                'error' => 'tenant_id is required',
            ), 400);
        }
        
        $module_manager = new NovaRax_Module_Manager();
        $active_modules = $module_manager->get_tenant_active_modules($tenant_id);
        
        $modules = array();
        
        foreach ($active_modules as $module) {
            $has_access = $module_manager->tenant_has_module_access($tenant_id, $module->module_id);
            
            $modules[] = array(
                'slug' => $module->module_slug,
                'name' => $module->module_name,
                'status' => $module->status,
                'has_access' => $has_access,
                'activated_at' => $module->activated_at,
                'expires_at' => $module->expires_at,
                'grace_period_ends' => $module->grace_period_ends,
            );
        }
        
        return new WP_REST_Response(array(
            'tenant_id' => $tenant_id,
            'modules' => $modules,
            'checked_at' => current_time('mysql'),
        ), 200);
    }
    
    /**
     * Verify tenant access endpoint
     * Quick check if tenant can access the dashboard
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function verify_tenant_access($request) {
        $tenant_id = $request->get_param('tenant_id');
        $user_id = $request->get_param('user_id');
        
        if (!$tenant_id || !$user_id) {
            return new WP_REST_Response(array(
                'access' => false,
                'message' => 'tenant_id and user_id are required',
            ), 400);
        }
        
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = $tenant_ops->get_tenant($tenant_id);
        
        if (!$tenant) {
            return new WP_REST_Response(array(
                'access' => false,
                'message' => 'Tenant not found',
            ), 404);
        }
        
        // Check if user belongs to tenant
        if ($tenant->user_id != $user_id) {
            return new WP_REST_Response(array(
                'access' => false,
                'message' => 'User does not belong to this tenant',
            ), 403);
        }
        
        // Check tenant status
        if ($tenant->status !== 'active') {
            return new WP_REST_Response(array(
                'access' => false,
                'message' => 'Tenant account is ' . $tenant->status,
                'status' => $tenant->status,
            ), 403);
        }
        
        return new WP_REST_Response(array(
            'access' => true,
            'tenant_id' => $tenant->id,
            'status' => $tenant->status,
        ), 200);
    }
    
    /**
     * Update activity endpoint
     * Records tenant activity for analytics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function update_activity($request) {
        $tenant_id = $request->get_param('tenant_id');
        $activity_type = $request->get_param('type');
        $data = $request->get_param('data');
        
        if (!$tenant_id) {
            return new WP_REST_Response(array(
                'error' => 'tenant_id is required',
            ), 400);
        }
        
        // Update last activity
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant_ops->update_last_login($tenant_id);
        
        // Log activity if needed
        if ($activity_type) {
            NovaRax_Logger::info("Tenant activity: {$activity_type}", array(
                'tenant_id' => $tenant_id,
                'data' => $data,
            ));
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'timestamp' => current_time('mysql'),
        ), 200);
    }
}