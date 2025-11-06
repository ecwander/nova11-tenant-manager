<?php
/**
 * Tenant Validator Class
 * 
 * Validates tenant data, checks for duplicates, sanitizes inputs,
 * and ensures data integrity before tenant creation.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Tenant_Validator {
    
    /**
     * WordPress database object
     *
     * @var wpdb
     */
    private $wpdb;
    
    /**
     * Validation errors
     *
     * @var array
     */
    private $errors = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    /**
     * Validate complete tenant data
     *
     * @param array $data Tenant data to validate
     * @return array Result with 'valid' boolean and 'errors' array
     */
    public function validate_tenant_data($data) {
        $this->errors = array();
        
        // Required fields validation
        $this->validate_required_fields($data);
        
        // Individual field validations
        if (isset($data['username'])) {
            $this->validate_username($data['username']);
        }
        
        if (isset($data['email'])) {
            $this->validate_email($data['email']);
        }
        
        if (isset($data['password'])) {
            $this->validate_password($data['password']);
        }
        
        if (isset($data['phone_number']) && !empty($data['phone_number'])) {
            $this->validate_phone_number($data['phone_number']);
        }
        
        if (isset($data['subdomain']) && !empty($data['subdomain'])) {
            $this->validate_subdomain($data['subdomain']);
        }
        
        if (isset($data['company_name']) && !empty($data['company_name'])) {
            $this->validate_company_name($data['company_name']);
        }
        
        return array(
            'valid' => empty($this->errors),
            'errors' => $this->errors,
        );
    }
    
    /**
     * Validate required fields
     *
     * @param array $data Data to validate
     */
    private function validate_required_fields($data) {
        $required_fields = array(
            'full_name' => __('Full Name', 'novarax-tenant-manager'),
            'email' => __('Email Address', 'novarax-tenant-manager'),
            'username' => __('Username', 'novarax-tenant-manager'),
            'password' => __('Password', 'novarax-tenant-manager'),
        );
        
        foreach ($required_fields as $field => $label) {
            if (empty($data[$field])) {
                $this->errors[] = sprintf(__('%s is required.', 'novarax-tenant-manager'), $label);
            }
        }
    }
    
    /**
     * Validate username
     *
     * @param string $username Username to validate
     * @return bool Is valid
     */
    public function validate_username($username) {
        // Check if empty
        if (empty($username)) {
            $this->errors[] = __('Username cannot be empty.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check length
        if (strlen($username) < 3) {
            $this->errors[] = __('Username must be at least 3 characters long.', 'novarax-tenant-manager');
            return false;
        }
        
        if (strlen($username) > 60) {
            $this->errors[] = __('Username cannot exceed 60 characters.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check format (alphanumeric, underscore, hyphen only)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            $this->errors[] = __('Username can only contain letters, numbers, underscores, and hyphens.', 'novarax-tenant-manager');
            return false;
        }
        
        // Must start with a letter
        if (!preg_match('/^[a-zA-Z]/', $username)) {
            $this->errors[] = __('Username must start with a letter.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check for reserved words
        $reserved = array(
            'admin', 'administrator', 'root', 'system', 'wp-admin', 
            'wordpress', 'api', 'www', 'mail', 'ftp', 'smtp',
            'novarax', 'app', 'dashboard', 'login', 'register'
        );
        
        if (in_array(strtolower($username), $reserved)) {
            $this->errors[] = __('This username is reserved and cannot be used.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check if username already exists in WordPress
        if (username_exists($username)) {
            $this->errors[] = __('This username is already taken.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check if username exists in tenants table
        if ($this->username_exists_in_tenants($username)) {
            $this->errors[] = __('This username is already registered as a tenant.', 'novarax-tenant-manager');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate email address
     *
     * @param string $email Email to validate
     * @return bool Is valid
     */
    public function validate_email($email) {
        // Check if empty
        if (empty($email)) {
            $this->errors[] = __('Email address cannot be empty.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check format
        if (!is_email($email)) {
            $this->errors[] = __('Please enter a valid email address.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check if email already exists
        if (email_exists($email)) {
            $this->errors[] = __('This email address is already registered.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check for disposable email domains (optional but recommended)
        if ($this->is_disposable_email($email)) {
            $this->errors[] = __('Disposable email addresses are not allowed.', 'novarax-tenant-manager');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @return bool Is valid
     */
    public function validate_password($password) {
        // Check if empty
        if (empty($password)) {
            $this->errors[] = __('Password cannot be empty.', 'novarax-tenant-manager');
            return false;
        }
        
        // Minimum length
        if (strlen($password) < 12) {
            $this->errors[] = __('Password must be at least 12 characters long.', 'novarax-tenant-manager');
            return false;
        }
        
        // Maximum length
        if (strlen($password) > 128) {
            $this->errors[] = __('Password cannot exceed 128 characters.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check complexity
        $has_lowercase = preg_match('/[a-z]/', $password);
        $has_uppercase = preg_match('/[A-Z]/', $password);
        $has_number = preg_match('/[0-9]/', $password);
        $has_special = preg_match('/[^a-zA-Z0-9]/', $password);
        
        $complexity_score = $has_lowercase + $has_uppercase + $has_number + $has_special;
        
        if ($complexity_score < 3) {
            $this->errors[] = __('Password must contain at least 3 of the following: lowercase letters, uppercase letters, numbers, and special characters.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check for common passwords
        if ($this->is_common_password($password)) {
            $this->errors[] = __('This password is too common. Please choose a more secure password.', 'novarax-tenant-manager');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate phone number
     *
     * @param string $phone Phone number to validate
     * @return bool Is valid
     */
    public function validate_phone_number($phone) {
        // Remove all non-numeric characters for validation
        $numeric_phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check length (8-15 digits is standard)
        if (strlen($numeric_phone) < 8 || strlen($numeric_phone) > 15) {
            $this->errors[] = __('Please enter a valid phone number.', 'novarax-tenant-manager');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate subdomain
     *
     * @param string $subdomain Subdomain to validate
     * @return bool Is valid
     */
    public function validate_subdomain($subdomain) {
        // Check if empty
        if (empty($subdomain)) {
            $this->errors[] = __('Subdomain cannot be empty.', 'novarax-tenant-manager');
            return false;
        }
        
        // Remove domain suffix if provided
        $subdomain_suffix = get_option('novarax_tm_subdomain_suffix', '.app.novarax.ae');
        $subdomain = str_replace($subdomain_suffix, '', $subdomain);
        
        // Check length
        if (strlen($subdomain) < 3) {
            $this->errors[] = __('Subdomain must be at least 3 characters long.', 'novarax-tenant-manager');
            return false;
        }
        
        if (strlen($subdomain) > 63) {
            $this->errors[] = __('Subdomain cannot exceed 63 characters.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check format (RFC 1123 subdomain rules)
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', strtolower($subdomain))) {
            $this->errors[] = __('Subdomain can only contain lowercase letters, numbers, and hyphens. Must start and end with a letter or number.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check if subdomain already exists
        if ($this->subdomain_exists($subdomain . $subdomain_suffix)) {
            $this->errors[] = __('This subdomain is already in use.', 'novarax-tenant-manager');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate company name
     *
     * @param string $company_name Company name to validate
     * @return bool Is valid
     */
    public function validate_company_name($company_name) {
        // Check length
        if (strlen($company_name) > 255) {
            $this->errors[] = __('Company name cannot exceed 255 characters.', 'novarax-tenant-manager');
            return false;
        }
        
        // Check for potentially malicious content
        if (preg_match('/<script|<iframe|javascript:/i', $company_name)) {
            $this->errors[] = __('Company name contains invalid characters.', 'novarax-tenant-manager');
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if username exists in tenants table
     *
     * @param string $username Username to check
     * @return bool Exists
     */
    private function username_exists_in_tenants($username) {
        $db_manager = new NovaRax_Database_Manager();
        $table = $db_manager->get_table_name('tenants');
        
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE tenant_username = %s",
                $username
            )
        );
        
        return $count > 0;
    }
    
    /**
     * Check if subdomain exists
     *
     * @param string $subdomain Subdomain to check
     * @return bool Exists
     */
    private function subdomain_exists($subdomain) {
        $db_manager = new NovaRax_Database_Manager();
        $table = $db_manager->get_table_name('tenants');
        
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE subdomain = %s",
                $subdomain
            )
        );
        
        return $count > 0;
    }
    
    /**
     * Check if email is from a disposable email service
     *
     * @param string $email Email to check
     * @return bool Is disposable
     */
    private function is_disposable_email($email) {
        // Common disposable email domains
        $disposable_domains = array(
            'tempmail.com', 'throwaway.email', 'guerrillamail.com',
            '10minutemail.com', 'mailinator.com', 'trashmail.com',
            'yopmail.com', 'fakeinbox.com', 'maildrop.cc'
        );
        
        $domain = substr(strrchr($email, "@"), 1);
        
        return in_array(strtolower($domain), $disposable_domains);
    }
    
    /**
     * Check if password is too common
     *
     * @param string $password Password to check
     * @return bool Is common
     */
    private function is_common_password($password) {
        // Top 100 most common passwords (partial list)
        $common_passwords = array(
            '123456', 'password', '123456789', '12345678', '12345',
            '1234567', 'password1', '123123', '1234567890', '000000',
            'qwerty', '1234', 'abc123', 'password123', 'iloveyou',
            'welcome', 'monkey', '1234567890', 'admin', 'letmein',
            'dragon', 'sunshine', 'princess', 'football', 'qwerty123'
        );
        
        return in_array(strtolower($password), $common_passwords);
    }
    
    /**
     * Sanitize tenant data
     *
     * @param array $data Data to sanitize
     * @return array Sanitized data
     */
    public function sanitize_tenant_data($data) {
        $sanitized = array();
        
        // Username
        if (isset($data['username'])) {
            $sanitized['username'] = sanitize_user($data['username'], true);
        }
        
        // Email
        if (isset($data['email'])) {
            $sanitized['email'] = sanitize_email($data['email']);
        }
        
        // Password (don't sanitize, will be hashed)
        if (isset($data['password'])) {
            $sanitized['password'] = $data['password'];
        }
        
        // Text fields
        $text_fields = array('full_name', 'account_name', 'company_name', 'phone_number', 'address');
        foreach ($text_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = sanitize_text_field($data[$field]);
            }
        }
        
        // Subdomain
        if (isset($data['subdomain'])) {
            $sanitized['subdomain'] = sanitize_title($data['subdomain']);
        }
        
        // First and last name
        if (isset($data['first_name'])) {
            $sanitized['first_name'] = sanitize_text_field($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $sanitized['last_name'] = sanitize_text_field($data['last_name']);
        }
        
        // Metadata (if array)
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $sanitized['metadata'] = array_map('sanitize_text_field', $data['metadata']);
        }
        
        return $sanitized;
    }
    
    /**
     * Get validation errors
     *
     * @return array Errors
     */
    public function get_errors() {
        return $this->errors;
    }
    
    /**
     * Clear errors
     */
    public function clear_errors() {
        $this->errors = array();
    }
    
    /**
     * Check if username is available
     *
     * @param string $username Username to check
     * @return array Result with 'available' boolean and 'message'
     */
    public function check_username_availability($username) {
        if (empty($username)) {
            return array(
                'available' => false,
                'message' => __('Username cannot be empty.', 'novarax-tenant-manager'),
            );
        }
        
        // Sanitize
        $username = sanitize_user($username, true);
        
        // Validate format
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            return array(
                'available' => false,
                'message' => __('Username can only contain letters, numbers, underscores, and hyphens.', 'novarax-tenant-manager'),
            );
        }
        
        // Check if exists
        if (username_exists($username) || $this->username_exists_in_tenants($username)) {
            return array(
                'available' => false,
                'message' => __('This username is already taken.', 'novarax-tenant-manager'),
            );
        }
        
        // Check reserved words
        $reserved = array(
            'admin', 'administrator', 'root', 'system', 'wp-admin', 
            'wordpress', 'api', 'www', 'mail', 'ftp', 'smtp',
            'novarax', 'app', 'dashboard', 'login', 'register'
        );
        
        if (in_array(strtolower($username), $reserved)) {
            return array(
                'available' => false,
                'message' => __('This username is reserved.', 'novarax-tenant-manager'),
            );
        }
        
        return array(
            'available' => true,
            'message' => __('Username is available!', 'novarax-tenant-manager'),
            'subdomain_preview' => $username . get_option('novarax_tm_subdomain_suffix', '.app.novarax.ae'),
        );
    }
    
    /**
     * Generate suggested usernames if taken
     *
     * @param string $username Base username
     * @return array Suggested usernames
     */
    public function suggest_usernames($username) {
        $suggestions = array();
        $base = sanitize_user($username, true);
        
        // Try with numbers
        for ($i = 1; $i <= 5; $i++) {
            $suggestion = $base . $i;
            if (!username_exists($suggestion) && !$this->username_exists_in_tenants($suggestion)) {
                $suggestions[] = $suggestion;
            }
        }
        
        // Try with year
        $year = date('Y');
        $suggestion = $base . $year;
        if (!username_exists($suggestion) && !$this->username_exists_in_tenants($suggestion)) {
            $suggestions[] = $suggestion;
        }
        
        // Try with random suffix
        for ($i = 0; $i < 3; $i++) {
            $suffix = wp_generate_password(4, false);
            $suggestion = $base . '_' . $suffix;
            if (!username_exists($suggestion) && !$this->username_exists_in_tenants($suggestion)) {
                $suggestions[] = $suggestion;
            }
        }
        
        return array_slice($suggestions, 0, 5);
    }
}