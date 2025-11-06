<?php
/**
 * Security Class
 * 
 * Handles encryption, IP detection, nonce validation, CSRF protection,
 * and other security utilities.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Security {
    
    /**
     * Encryption key
     *
     * @var string
     */
    private static $encryption_key = null;
    
    /**
     * Encryption method
     *
     * @var string
     */
    private static $cipher_method = 'AES-256-CBC';
    
    /**
     * Rate limit cache key prefix
     *
     * @var string
     */
    private static $rate_limit_prefix = 'novarax_rate_limit_';
    
    /**
     * Initialize security
     */
    public static function init() {
        // Get encryption key from wp-config or generate one
        if (defined('NOVARAX_ENCRYPTION_KEY')) {
            self::$encryption_key = NOVARAX_ENCRYPTION_KEY;
        } else {
            // Use WordPress SECURE_AUTH_KEY as fallback
            self::$encryption_key = SECURE_AUTH_KEY;
        }
        
        // Set up security headers
        add_action('send_headers', array(__CLASS__, 'set_security_headers'));
    }
    
    /**
     * Encrypt data
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data (base64 encoded)
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        // Generate initialization vector
        $iv_length = openssl_cipher_iv_length(self::$cipher_method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        // Encrypt
        $encrypted = openssl_encrypt(
            $data,
            self::$cipher_method,
            self::$encryption_key,
            0,
            $iv
        );
        
        // Combine IV and encrypted data
        $result = base64_encode($iv . $encrypted);
        
        return $result;
    }
    
    /**
     * Decrypt data
     *
     * @param string $encrypted_data Encrypted data (base64 encoded)
     * @return string|false Decrypted data or false on failure
     */
    public static function decrypt($encrypted_data) {
        if (empty($encrypted_data)) {
            return '';
        }
        
        // Decode base64
        $data = base64_decode($encrypted_data);
        
        if ($data === false) {
            return false;
        }
        
        // Extract IV
        $iv_length = openssl_cipher_iv_length(self::$cipher_method);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        
        // Decrypt
        $decrypted = openssl_decrypt(
            $encrypted,
            self::$cipher_method,
            self::$encryption_key,
            0,
            $iv
        );
        
        return $decrypted;
    }
    
    /**
     * Hash sensitive data
     *
     * @param string $data Data to hash
     * @return string Hashed data
     */
    public static function hash($data) {
        return hash_hmac('sha256', $data, self::$encryption_key);
    }
    
    /**
     * Verify hashed data
     *
     * @param string $data Original data
     * @param string $hash Hash to verify
     * @return bool Valid
     */
    public static function verify_hash($data, $hash) {
        return hash_equals(self::hash($data), $hash);
    }
    
    /**
     * Get client IP address
     *
     * @return string IP address
     */
    public static function get_client_ip() {
        $ip = '';
        
        // Check for Cloudflare
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        // Check for proxy
        elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        // Check for other proxy headers
        elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        // Direct connection
        elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Handle multiple IPs (get the first one)
        if (strpos($ip, ',') !== false) {
            $ips = explode(',', $ip);
            $ip = trim($ips[0]);
        }
        
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Check if IP is blocked
     *
     * @param string $ip IP address
     * @return bool Is blocked
     */
    public static function is_ip_blocked($ip = null) {
        if (!$ip) {
            $ip = self::get_client_ip();
        }
        
        // Get blocked IPs from options
        $blocked_ips = get_option('novarax_blocked_ips', array());
        
        if (empty($blocked_ips)) {
            return false;
        }
        
        // Check exact match
        if (in_array($ip, $blocked_ips)) {
            return true;
        }
        
        // Check CIDR ranges
        foreach ($blocked_ips as $blocked) {
            if (strpos($blocked, '/') !== false) {
                if (self::ip_in_range($ip, $blocked)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Block IP address
     *
     * @param string $ip IP address
     * @param string $reason Reason for blocking
     * @return bool Success
     */
    public static function block_ip($ip, $reason = '') {
        $blocked_ips = get_option('novarax_blocked_ips', array());
        
        if (!in_array($ip, $blocked_ips)) {
            $blocked_ips[] = $ip;
            update_option('novarax_blocked_ips', $blocked_ips);
            
            NovaRax_Logger::warning("IP blocked: {$ip}", array('reason' => $reason));
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Unblock IP address
     *
     * @param string $ip IP address
     * @return bool Success
     */
    public static function unblock_ip($ip) {
        $blocked_ips = get_option('novarax_blocked_ips', array());
        
        $key = array_search($ip, $blocked_ips);
        
        if ($key !== false) {
            unset($blocked_ips[$key]);
            update_option('novarax_blocked_ips', array_values($blocked_ips));
            
            NovaRax_Logger::info("IP unblocked: {$ip}");
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if IP is in CIDR range
     *
     * @param string $ip IP address
     * @param string $range CIDR range (e.g., 192.168.1.0/24)
     * @return bool In range
     */
    public static function ip_in_range($ip, $range) {
        list($subnet, $mask) = explode('/', $range);
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);
        
        $subnet_long &= $mask_long;
        
        return ($ip_long & $mask_long) === $subnet_long;
    }
    
    /**
     * Rate limiting check
     *
     * @param string $identifier Identifier (IP, user ID, etc.)
     * @param int $max_requests Maximum requests allowed
     * @param int $period Time period in seconds
     * @return bool Allowed (true) or rate limited (false)
     */
    public static function check_rate_limit($identifier, $max_requests = 60, $period = 3600) {
        $cache_key = self::$rate_limit_prefix . md5($identifier);
        
        // Get current count
        $data = get_transient($cache_key);
        
        if ($data === false) {
            // First request
            set_transient($cache_key, 1, $period);
            return true;
        }
        
        // Increment count
        $count = (int) $data + 1;
        
        if ($count > $max_requests) {
            // Rate limit exceeded
            NovaRax_Logger::warning("Rate limit exceeded", array(
                'identifier' => $identifier,
                'count' => $count,
                'max' => $max_requests,
            ));
            
            return false;
        }
        
        // Update count
        set_transient($cache_key, $count, $period);
        
        return true;
    }
    
    /**
     * Generate secure random token
     *
     * @param int $length Token length
     * @return string Random token
     */
    public static function generate_token($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Generate API key
     *
     * @return string API key
     */
    public static function generate_api_key() {
        return 'novarax_' . self::generate_token(40);
    }
    
    /**
     * Generate API secret
     *
     * @return string API secret
     */
    public static function generate_api_secret() {
        return self::generate_token(64);
    }
    
    /**
     * Validate nonce
     *
     * @param string $nonce Nonce value
     * @param string $action Action name
     * @return bool Valid
     */
    public static function verify_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, $action) !== false;
    }
    
    /**
     * Check AJAX nonce
     *
     * @param string $action Action name
     * @return bool Valid
     */
    public static function check_ajax_nonce($action = 'novarax_tm_ajax') {
        $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '';
        
        if (!self::verify_nonce($nonce, $action)) {
            wp_send_json_error(array(
                'message' => __('Invalid security token. Please refresh the page and try again.', 'novarax-tenant-manager'),
            ));
            exit;
        }
        
        return true;
    }
    
    /**
     * Sanitize input data
     *
     * @param mixed $data Data to sanitize
     * @param string $type Data type (text, email, url, int, float, bool, array)
     * @return mixed Sanitized data
     */
    public static function sanitize($data, $type = 'text') {
        switch ($type) {
            case 'email':
                return sanitize_email($data);
                
            case 'url':
                return esc_url_raw($data);
                
            case 'int':
                return intval($data);
                
            case 'float':
                return floatval($data);
                
            case 'bool':
                return (bool) $data;
                
            case 'array':
                if (!is_array($data)) {
                    return array();
                }
                return array_map('sanitize_text_field', $data);
                
            case 'html':
                return wp_kses_post($data);
                
            case 'text':
            default:
                return sanitize_text_field($data);
        }
    }
    
    /**
     * Escape output for display
     *
     * @param mixed $data Data to escape
     * @param string $context Context (html, attr, js, url)
     * @return mixed Escaped data
     */
    public static function escape($data, $context = 'html') {
        switch ($context) {
            case 'attr':
                return esc_attr($data);
                
            case 'js':
                return esc_js($data);
                
            case 'url':
                return esc_url($data);
                
            case 'textarea':
                return esc_textarea($data);
                
            case 'html':
            default:
                return esc_html($data);
        }
    }
    
    /**
     * Prevent SQL injection
     *
     * @param string $value Value to prepare
     * @return string Prepared value
     */
    public static function prepare_sql($value) {
        global $wpdb;
        return $wpdb->prepare('%s', $value);
    }
    
    /**
     * Check for XSS attempts
     *
     * @param string $data Data to check
     * @return bool Contains XSS
     */
    public static function contains_xss($data) {
        $xss_patterns = array(
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<iframe\b[^>]*>.*?<\/iframe>/is',
            '/javascript:/i',
            '/on\w+\s*=/i', // Event handlers like onclick=
            '/<embed\b[^>]*>/i',
            '/<object\b[^>]*>/i',
        );
        
        foreach ($xss_patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect brute force attempts
     *
     * @param string $identifier Identifier (username, email, IP)
     * @param int $max_attempts Maximum attempts allowed
     * @param int $lockout_period Lockout period in seconds
     * @return array Status with 'locked', 'attempts', 'remaining'
     */
    public static function check_brute_force($identifier, $max_attempts = 5, $lockout_period = 900) {
        $cache_key = 'novarax_brute_force_' . md5($identifier);
        
        $data = get_transient($cache_key);
        
        if ($data === false) {
            // First attempt
            $data = array(
                'attempts' => 0,
                'locked_until' => null,
            );
        }
        
        // Check if locked
        if ($data['locked_until'] && time() < $data['locked_until']) {
            $remaining = $data['locked_until'] - time();
            
            return array(
                'locked' => true,
                'attempts' => $data['attempts'],
                'remaining' => $remaining,
            );
        }
        
        // Reset if lockout expired
        if ($data['locked_until'] && time() >= $data['locked_until']) {
            $data = array(
                'attempts' => 0,
                'locked_until' => null,
            );
        }
        
        return array(
            'locked' => false,
            'attempts' => $data['attempts'],
            'remaining' => $max_attempts - $data['attempts'],
        );
    }
    
    /**
     * Record failed login attempt
     *
     * @param string $identifier Identifier (username, email, IP)
     * @param int $max_attempts Maximum attempts allowed
     * @param int $lockout_period Lockout period in seconds
     * @return array Status
     */
    public static function record_failed_attempt($identifier, $max_attempts = 5, $lockout_period = 900) {
        $cache_key = 'novarax_brute_force_' . md5($identifier);
        
        $data = get_transient($cache_key);
        
        if ($data === false) {
            $data = array(
                'attempts' => 0,
                'locked_until' => null,
            );
        }
        
        $data['attempts']++;
        
        if ($data['attempts'] >= $max_attempts) {
            $data['locked_until'] = time() + $lockout_period;
            
            NovaRax_Logger::warning("Account locked due to multiple failed attempts", array(
                'identifier' => $identifier,
                'attempts' => $data['attempts'],
            ));
            
            // Block IP if too many attempts
            if ($data['attempts'] > $max_attempts * 2) {
                self::block_ip(self::get_client_ip(), 'Multiple brute force attempts');
            }
        }
        
        set_transient($cache_key, $data, $lockout_period);
        
        return self::check_brute_force($identifier, $max_attempts, $lockout_period);
    }
    
    /**
     * Clear failed attempts
     *
     * @param string $identifier Identifier
     */
    public static function clear_failed_attempts($identifier) {
        $cache_key = 'novarax_brute_force_' . md5($identifier);
        delete_transient($cache_key);
    }
    
    /**
     * Set security headers
     */
    public static function set_security_headers() {
        // Only set headers for our plugin pages
        if (!is_admin() && !defined('REST_REQUEST')) {
            return;
        }
        
        // X-Content-Type-Options
        header('X-Content-Type-Options: nosniff');
        
        // X-Frame-Options
        header('X-Frame-Options: SAMEORIGIN');
        
        // X-XSS-Protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer-Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content-Security-Policy (basic)
        if (get_option('novarax_tm_enable_csp', false)) {
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';");
        }
    }
    
    /**
     * Generate JWT token
     *
     * @param array $payload Token payload
     * @param int $expiration Expiration time in seconds (default 24 hours)
     * @return string JWT token
     */
    public static function generate_jwt($payload, $expiration = 86400) {
        $header = array(
            'typ' => 'JWT',
            'alg' => 'HS256'
        );
        
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiration;
        
        $base64_header = self::base64_url_encode(json_encode($header));
        $base64_payload = self::base64_url_encode(json_encode($payload));
        
        $signature = hash_hmac(
            'sha256',
            $base64_header . '.' . $base64_payload,
            self::$encryption_key,
            true
        );
        
        $base64_signature = self::base64_url_encode($signature);
        
        return $base64_header . '.' . $base64_payload . '.' . $base64_signature;
    }
    
    /**
     * Verify JWT token
     *
     * @param string $token JWT token
     * @return array|false Payload or false if invalid
     */
    public static function verify_jwt($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        list($base64_header, $base64_payload, $base64_signature) = $parts;
        
        // Verify signature
        $signature = hash_hmac(
            'sha256',
            $base64_header . '.' . $base64_payload,
            self::$encryption_key,
            true
        );
        
        $expected_signature = self::base64_url_encode($signature);
        
        if (!hash_equals($expected_signature, $base64_signature)) {
            return false;
        }
        
        // Decode payload
        $payload = json_decode(self::base64_url_decode($base64_payload), true);
        
        // Check expiration
        if (isset($payload['exp']) && time() > $payload['exp']) {
            return false;
        }
        
        return $payload;
    }
    
    /**
     * Base64 URL encode
     *
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private static function base64_url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     *
     * @param string $data Data to decode
     * @return string Decoded data
     */
    private static function base64_url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Check if request is from trusted source
     *
     * @return bool Is trusted
     */
    public static function is_trusted_request() {
        // Check if request is from localhost
        $ip = self::get_client_ip();
        
        if (in_array($ip, array('127.0.0.1', '::1'))) {
            return true;
        }
        
        // Check if request is from admin
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Log security event
     *
     * @param string $event Event name
     * @param array $details Event details
     */
    public static function log_security_event($event, $details = array()) {
        $details['ip'] = self::get_client_ip();
        $details['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        NovaRax_Logger::warning("Security event: {$event}", $details);
    }
}

// Initialize security on load
NovaRax_Security::init();