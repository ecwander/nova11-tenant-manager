<?php
/**
 * Logger Class
 * 
 * Handles logging of all system events, errors, warnings, and information
 * to both database and file system with log rotation.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Logger {
    
    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    /**
     * Log to database flag
     *
     * @var bool
     */
    private static $log_to_database = true;
    
    /**
     * Log to file flag
     *
     * @var bool
     */
    private static $log_to_file = true;
    
    /**
     * Log directory path
     *
     * @var string
     */
    private static $log_directory = null;
    
    /**
     * Maximum log file size (in bytes) - 10MB
     *
     * @var int
     */
    private static $max_file_size = 10485760;
    
    /**
     * Maximum number of log files to keep
     *
     * @var int
     */
    private static $max_files = 5;
    
    /**
     * Initialize logger
     */
    public static function init() {
        // Set log directory
        $upload_dir = wp_upload_dir();
        self::$log_directory = $upload_dir['basedir'] . '/novarax-logs';
        
        // Create log directory if it doesn't exist
        if (!file_exists(self::$log_directory)) {
            wp_mkdir_p(self::$log_directory);
            
            // Create .htaccess to protect logs
            $htaccess = self::$log_directory . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
            
            // Create index.php to prevent directory listing
            $index = self::$log_directory . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
        
        // Get settings
        self::$log_to_database = get_option('novarax_tm_log_to_database', true);
        self::$log_to_file = get_option('novarax_tm_log_to_file', true);
    }
    
    /**
     * Log a message
     *
     * @param string $message Log message
     * @param string $level Log level (debug, info, warning, error, critical)
     * @param array $context Additional context data
     * @return bool Success
     */
    public static function log($message, $level = self::LEVEL_INFO, $context = array()) {
        // Initialize if not already done
        if (self::$log_directory === null) {
            self::init();
        }
        
        // Validate log level
        $valid_levels = array(
            self::LEVEL_DEBUG,
            self::LEVEL_INFO,
            self::LEVEL_WARNING,
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL
        );
        
        if (!in_array($level, $valid_levels)) {
            $level = self::LEVEL_INFO;
        }
        
        // Prepare log entry
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'user_id' => get_current_user_id(),
            'ip_address' => self::get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        );
        
        // Log to database
        if (self::$log_to_database) {
            self::log_to_database($log_entry);
        }
        
        // Log to file
        if (self::$log_to_file) {
            self::log_to_file($log_entry);
        }
        
        // For critical errors, also log to WordPress debug log
        if ($level === self::LEVEL_CRITICAL && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[NovaRax Critical] ' . $message);
        }
        
        return true;
    }
    
    /**
     * Log to database
     *
     * @param array $log_entry Log entry data
     * @return bool Success
     */
    private static function log_to_database($log_entry) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'novarax_logs';
        
        // Create logs table if it doesn't exist
        self::maybe_create_logs_table();
        
        $inserted = $wpdb->insert(
            $table,
            array(
                'log_level' => $log_entry['level'],
                'message' => $log_entry['message'],
                'context' => json_encode($log_entry['context']),
                'user_id' => $log_entry['user_id'],
                'ip_address' => $log_entry['ip_address'],
                'user_agent' => $log_entry['user_agent'],
                'request_uri' => $log_entry['request_uri'],
                'created_at' => $log_entry['timestamp'],
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
        
        return $inserted !== false;
    }
    
    /**
     * Log to file
     *
     * @param array $log_entry Log entry data
     * @return bool Success
     */
    private static function log_to_file($log_entry) {
        // Get log file path
        $log_file = self::get_log_file_path($log_entry['level']);
        
        // Check if rotation is needed
        self::maybe_rotate_log($log_file);
        
        // Format log entry
        $formatted = self::format_log_entry($log_entry);
        
        // Write to file
        $result = file_put_contents($log_file, $formatted . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        return $result !== false;
    }
    
    /**
     * Format log entry for file
     *
     * @param array $log_entry Log entry data
     * @return string Formatted log entry
     */
    private static function format_log_entry($log_entry) {
        $parts = array(
            '[' . $log_entry['timestamp'] . ']',
            strtoupper($log_entry['level']),
            $log_entry['message'],
        );
        
        // Add user info if available
        if ($log_entry['user_id']) {
            $user = get_userdata($log_entry['user_id']);
            if ($user) {
                $parts[] = 'User: ' . $user->user_login;
            }
        }
        
        // Add IP address
        if ($log_entry['ip_address']) {
            $parts[] = 'IP: ' . $log_entry['ip_address'];
        }
        
        // Add context if available
        if (!empty($log_entry['context'])) {
            $parts[] = 'Context: ' . json_encode($log_entry['context']);
        }
        
        return implode(' | ', $parts);
    }
    
    /**
     * Get log file path
     *
     * @param string $level Log level
     * @return string File path
     */
    private static function get_log_file_path($level) {
        $filename = 'novarax-' . $level . '-' . date('Y-m-d') . '.log';
        return self::$log_directory . '/' . $filename;
    }
    
    /**
     * Maybe rotate log file
     *
     * @param string $log_file Log file path
     */
    private static function maybe_rotate_log($log_file) {
        if (!file_exists($log_file)) {
            return;
        }
        
        // Check file size
        $size = filesize($log_file);
        
        if ($size < self::$max_file_size) {
            return;
        }
        
        // Rotate the file
        $base = pathinfo($log_file, PATHINFO_FILENAME);
        $extension = pathinfo($log_file, PATHINFO_EXTENSION);
        $directory = pathinfo($log_file, PATHINFO_DIRNAME);
        
        // Shift existing rotated files
        for ($i = self::$max_files - 1; $i > 0; $i--) {
            $old_file = $directory . '/' . $base . '.' . $i . '.' . $extension;
            $new_file = $directory . '/' . $base . '.' . ($i + 1) . '.' . $extension;
            
            if (file_exists($old_file)) {
                if ($i === self::$max_files - 1) {
                    // Delete the oldest file
                    unlink($old_file);
                } else {
                    rename($old_file, $new_file);
                }
            }
        }
        
        // Rotate current file to .1
        $rotated = $directory . '/' . $base . '.1.' . $extension;
        rename($log_file, $rotated);
    }
    
    /**
     * Get client IP address
     *
     * @return string IP address
     */
    private static function get_client_ip() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            // Cloudflare
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Handle multiple IPs (get the first one)
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }
        
        return trim($ip);
    }
    
    /**
     * Create logs table if it doesn't exist
     */
    private static function maybe_create_logs_table() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'novarax_logs';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        
        if ($table_exists) {
            return;
        }
        
        // Create table
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            request_uri TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_level (log_level),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Convenience methods for different log levels
     */
    
    /**
     * Log debug message
     *
     * @param string $message Message
     * @param array $context Context
     */
    public static function debug($message, $context = array()) {
        return self::log($message, self::LEVEL_DEBUG, $context);
    }
    
    /**
     * Log info message
     *
     * @param string $message Message
     * @param array $context Context
     */
    public static function info($message, $context = array()) {
        return self::log($message, self::LEVEL_INFO, $context);
    }
    
    /**
     * Log warning message
     *
     * @param string $message Message
     * @param array $context Context
     */
    public static function warning($message, $context = array()) {
        return self::log($message, self::LEVEL_WARNING, $context);
    }
    
    /**
     * Log error message
     *
     * @param string $message Message
     * @param array $context Context
     */
    public static function error($message, $context = array()) {
        return self::log($message, self::LEVEL_ERROR, $context);
    }
    
    /**
     * Log critical message
     *
     * @param string $message Message
     * @param array $context Context
     */
    public static function critical($message, $context = array()) {
        return self::log($message, self::LEVEL_CRITICAL, $context);
    }
    
    /**
     * Get logs from database
     *
     * @param array $args Query arguments
     * @return array Log entries
     */
    public static function get_logs($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'level' => null,
            'user_id' => null,
            'limit' => 100,
            'offset' => 0,
            'start_date' => null,
            'end_date' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $table = $wpdb->prefix . 'novarax_logs';
        
        $where = array('1=1');
        
        if ($args['level']) {
            $where[] = $wpdb->prepare('log_level = %s', $args['level']);
        }
        
        if ($args['user_id']) {
            $where[] = $wpdb->prepare('user_id = %d', $args['user_id']);
        }
        
        if ($args['start_date']) {
            $where[] = $wpdb->prepare('created_at >= %s', $args['start_date']);
        }
        
        if ($args['end_date']) {
            $where[] = $wpdb->prepare('created_at <= %s', $args['end_date']);
        }
        
        $where_clause = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}");
        
        $query = "SELECT * FROM {$table} 
                  WHERE {$where_clause} 
                  ORDER BY {$orderby} 
                  LIMIT {$args['offset']}, {$args['limit']}";
        
        $logs = $wpdb->get_results($query);
        
        // Decode context JSON
        foreach ($logs as &$log) {
            if ($log->context) {
                $log->context = json_decode($log->context, true);
            }
        }
        
        return $logs;
    }
    
    /**
     * Get log count
     *
     * @param array $args Query arguments
     * @return int Count
     */
    public static function get_log_count($args = array()) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'novarax_logs';
        
        $where = array('1=1');
        
        if (isset($args['level'])) {
            $where[] = $wpdb->prepare('log_level = %s', $args['level']);
        }
        
        if (isset($args['user_id'])) {
            $where[] = $wpdb->prepare('user_id = %d', $args['user_id']);
        }
        
        if (isset($args['start_date'])) {
            $where[] = $wpdb->prepare('created_at >= %s', $args['start_date']);
        }
        
        if (isset($args['end_date'])) {
            $where[] = $wpdb->prepare('created_at <= %s', $args['end_date']);
        }
        
        $where_clause = implode(' AND ', $where);
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_clause}");
    }
    
    /**
     * Clean old logs
     *
     * @param int $days Keep logs from last X days
     * @return int Number of deleted logs
     */
    public static function clean_old_logs($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'novarax_logs';
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $date
            )
        );
        
        self::info("Cleaned {$deleted} old log entries", array('days' => $days));
        
        return $deleted;
    }
    
    /**
     * Clear all logs
     *
     * @return bool Success
     */
    public static function clear_all_logs() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'novarax_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE {$table}");
        
        // Also delete log files
        self::delete_log_files();
        
        return $result !== false;
    }
    
    /**
     * Delete log files
     */
    private static function delete_log_files() {
        if (!self::$log_directory || !file_exists(self::$log_directory)) {
            return;
        }
        
        $files = glob(self::$log_directory . '/*.log');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    
    /**
     * Export logs to CSV
     *
     * @param array $args Query arguments
     * @return string|false CSV file path or false on failure
     */
    public static function export_logs_to_csv($args = array()) {
        $logs = self::get_logs($args);
        
        if (empty($logs)) {
            return false;
        }
        
        $filename = 'novarax-logs-export-' . date('Y-m-d-H-i-s') . '.csv';
        $filepath = self::$log_directory . '/' . $filename;
        
        $fp = fopen($filepath, 'w');
        
        // CSV headers
        fputcsv($fp, array(
            'ID',
            'Timestamp',
            'Level',
            'Message',
            'User ID',
            'IP Address',
            'User Agent',
            'Request URI'
        ));
        
        // CSV rows
        foreach ($logs as $log) {
            fputcsv($fp, array(
                $log->id,
                $log->created_at,
                $log->log_level,
                $log->message,
                $log->user_id,
                $log->ip_address,
                $log->user_agent,
                $log->request_uri
            ));
        }
        
        fclose($fp);
        
        return $filepath;
    }
    
    /**
     * Get statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'novarax_logs';
        
        $stats = array(
            'total' => 0,
            'by_level' => array(),
            'today' => 0,
            'this_week' => 0,
            'this_month' => 0,
        );
        
        // Total count
        $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        
        // Count by level
        $levels = $wpdb->get_results(
            "SELECT log_level, COUNT(*) as count 
             FROM {$table} 
             GROUP BY log_level"
        );
        
        foreach ($levels as $level) {
            $stats['by_level'][$level->log_level] = (int) $level->count;
        }
        
        // Today
        $stats['today'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
             WHERE DATE(created_at) = CURDATE()"
        );
        
        // This week
        $stats['this_week'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
             WHERE YEARWEEK(created_at) = YEARWEEK(NOW())"
        );
        
        // This month
        $stats['this_month'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
             WHERE YEAR(created_at) = YEAR(NOW()) 
             AND MONTH(created_at) = MONTH(NOW())"
        );
        
        return $stats;
    }
}