<?php
/**
 * Database Manager Class
 * 
 * Handles all database operations including table creation, tenant database provisioning,
 * and database connections management.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Database_Manager {
    
    /**
     * Database table names
     *
     * @var array
     */
    private $tables = array();
    
    /**
     * WordPress database object
     *
     * @var wpdb
     */
    private $wpdb;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Define table names
        $this->tables = array(
            'tenants' => $wpdb->prefix . 'novarax_tenants',
            'modules' => $wpdb->prefix . 'novarax_modules',
            'tenant_modules' => $wpdb->prefix . 'novarax_tenant_modules',
            'api_keys' => $wpdb->prefix . 'novarax_api_keys',
            'audit_logs' => $wpdb->prefix . 'novarax_audit_logs',
            'usage_stats' => $wpdb->prefix . 'novarax_usage_stats',
        );
    }
    
    /**
     * Get table name
     *
     * @param string $table Table identifier
     * @return string Full table name
     */
    public function get_table_name($table) {
        return isset($this->tables[$table]) ? $this->tables[$table] : '';
    }
    
    /**
     * Create all custom database tables
     *
     * @return bool Success status
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $this->wpdb->get_charset_collate();
        $created = true;
        
        // Create tenants table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['tenants']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            tenant_username VARCHAR(100) NOT NULL,
            account_name VARCHAR(255) NOT NULL,
            company_name VARCHAR(255) DEFAULT NULL,
            subdomain VARCHAR(100) NOT NULL,
            database_name VARCHAR(100) NOT NULL,
            status ENUM('active', 'suspended', 'cancelled', 'pending') DEFAULT 'pending',
            storage_used BIGINT(20) DEFAULT 0,
            storage_limit BIGINT(20) DEFAULT 5368709120,
            user_limit INT DEFAULT 10,
            phone_number VARCHAR(50) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            billing_email VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT NULL,
            metadata LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_username (tenant_username),
            UNIQUE KEY unique_subdomain (subdomain),
            UNIQUE KEY unique_database (database_name),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            FOREIGN KEY (user_id) REFERENCES {$this->wpdb->prefix}users(ID) ON DELETE CASCADE
        ) $charset_collate;";
        
        if (!dbDelta($sql)) {
            $created = false;
            NovaRax_Logger::log('Failed to create tenants table', 'error');
        }
        
        // Create modules table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['modules']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            module_name VARCHAR(255) NOT NULL,
            module_slug VARCHAR(100) NOT NULL,
            plugin_path VARCHAR(255) NOT NULL,
            product_id BIGINT(20) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            version VARCHAR(50) DEFAULT NULL,
            requires_php VARCHAR(10) DEFAULT NULL,
            requires_modules TEXT DEFAULT NULL,
            icon_url VARCHAR(500) DEFAULT NULL,
            status ENUM('active', 'inactive', 'deprecated') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slug (module_slug),
            KEY idx_product (product_id),
            KEY idx_status (status)
        ) $charset_collate;";
        
        if (!dbDelta($sql)) {
            $created = false;
            NovaRax_Logger::log('Failed to create modules table', 'error');
        }
        
        // Create tenant_modules table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['tenant_modules']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT(20) UNSIGNED NOT NULL,
            module_id BIGINT(20) UNSIGNED NOT NULL,
            subscription_id BIGINT(20) DEFAULT NULL,
            status ENUM('active', 'inactive', 'expired', 'cancelled') DEFAULT 'active',
            activated_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            last_checked DATETIME DEFAULT NULL,
            grace_period_ends DATETIME DEFAULT NULL,
            usage_data LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_tenant_module (tenant_id, module_id),
            KEY idx_tenant (tenant_id),
            KEY idx_module (module_id),
            KEY idx_subscription (subscription_id),
            KEY idx_status (status),
            FOREIGN KEY (tenant_id) REFERENCES {$this->tables['tenants']}(id) ON DELETE CASCADE,
            FOREIGN KEY (module_id) REFERENCES {$this->tables['modules']}(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        if (!dbDelta($sql)) {
            $created = false;
            NovaRax_Logger::log('Failed to create tenant_modules table', 'error');
        }
        
        // Create api_keys table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['api_keys']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT(20) UNSIGNED NOT NULL,
            api_key VARCHAR(255) NOT NULL,
            secret_hash VARCHAR(255) NOT NULL,
            permissions TEXT DEFAULT NULL,
            rate_limit INT DEFAULT 1000,
            last_used DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            status ENUM('active', 'revoked') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_api_key (api_key),
            KEY idx_tenant (tenant_id),
            KEY idx_status (status),
            FOREIGN KEY (tenant_id) REFERENCES {$this->tables['tenants']}(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        if (!dbDelta($sql)) {
            $created = false;
            NovaRax_Logger::log('Failed to create api_keys table', 'error');
        }
        
        // Create audit_logs table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['audit_logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT(20) UNSIGNED DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            action VARCHAR(255) NOT NULL,
            entity_type VARCHAR(100) DEFAULT NULL,
            entity_id BIGINT(20) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            details LONGTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tenant (tenant_id),
            KEY idx_user (user_id),
            KEY idx_action (action),
            KEY idx_created (created_at)
        ) $charset_collate;";
        
        if (!dbDelta($sql)) {
            $created = false;
            NovaRax_Logger::log('Failed to create audit_logs table', 'error');
        }
        
        // Create usage_stats table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['usage_stats']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT(20) UNSIGNED NOT NULL,
            module_id BIGINT(20) UNSIGNED DEFAULT NULL,
            stat_date DATE NOT NULL,
            active_users INT DEFAULT 0,
            api_calls INT DEFAULT 0,
            storage_used BIGINT(20) DEFAULT 0,
            page_views INT DEFAULT 0,
            custom_metrics LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_tenant_module_date (tenant_id, module_id, stat_date),
            KEY idx_tenant (tenant_id),
            KEY idx_date (stat_date),
            FOREIGN KEY (tenant_id) REFERENCES {$this->tables['tenants']}(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        if (!dbDelta($sql)) {
            $created = false;
            NovaRax_Logger::log('Failed to create usage_stats table', 'error');
        }
        
        if ($created) {
            NovaRax_Logger::log('All database tables created successfully', 'info');
        }
        
        return $created;
    }
    
    /**
     * Create a new tenant database
     *
     * @param string $database_name Database name
     * @param string $username Database username (optional, will be generated)
     * @return array Result with 'success', 'database', 'username', 'password'
     */
    public function create_tenant_database($database_name, $username = null) {
        try {
            // Sanitize database name
            $database_name = $this->sanitize_database_name($database_name);
            
            // Generate username and password if not provided
            if (!$username) {
                $username = substr($database_name, 0, 16); // MySQL username limit
            }
            $password = wp_generate_password(32, true, true);
            
            // Connect to MySQL as root/admin
            $mysqli = $this->get_mysql_connection();
            
            if (!$mysqli) {
                throw new Exception('Failed to connect to MySQL server');
            }
            
            // Create database
            $sql = "CREATE DATABASE IF NOT EXISTS `{$database_name}` 
                    DEFAULT CHARACTER SET utf8mb4 
                    DEFAULT COLLATE utf8mb4_unicode_ci";
            
            if (!$mysqli->query($sql)) {
                throw new Exception('Failed to create database: ' . $mysqli->error);
            }
            
            // Create database user
            $sql = "CREATE USER IF NOT EXISTS '{$username}'@'localhost' 
                    IDENTIFIED BY '{$password}'";
            
            if (!$mysqli->query($sql)) {
                throw new Exception('Failed to create database user: ' . $mysqli->error);
            }
            
            // Grant privileges
            $sql = "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER 
                    ON `{$database_name}`.* 
                    TO '{$username}'@'localhost'";
            
            if (!$mysqli->query($sql)) {
                throw new Exception('Failed to grant privileges: ' . $mysqli->error);
            }
            
            // Flush privileges
            $mysqli->query('FLUSH PRIVILEGES');
            
            // Import WordPress core schema
            $this->import_wordpress_schema($database_name, $username, $password);
            
            $mysqli->close();
            
            NovaRax_Logger::log("Tenant database created: {$database_name}", 'info');
            
            return array(
                'success' => true,
                'database' => $database_name,
                'username' => $username,
                'password' => $password,
            );
            
        } catch (Exception $e) {
            NovaRax_Logger::log('Database creation failed: ' . $e->getMessage(), 'error');
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Import WordPress core schema to tenant database
     *
     * @param string $database_name Database name
     * @param string $username Database username
     * @param string $password Database password
     * @return bool Success status
     */
    private function import_wordpress_schema($database_name, $username, $password) {
        try {
            // Create new wpdb instance for tenant database
            $tenant_db = new wpdb($username, $password, $database_name, DB_HOST);
            
            // Get WordPress schema from wp-admin/includes/schema.php
            require_once(ABSPATH . 'wp-admin/includes/schema.php');
            
            // Get the SQL queries
            $queries = wp_get_db_schema();
            
            // Replace table prefix
            $queries = str_replace($this->wpdb->prefix, 'wp_', $queries);
            
            // Split queries and execute
            $queries = explode(';', $queries);
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (empty($query)) {
                    continue;
                }
                
                $tenant_db->query($query);
            }
            
            NovaRax_Logger::log("WordPress schema imported to: {$database_name}", 'info');
            
            return true;
            
        } catch (Exception $e) {
            NovaRax_Logger::log('Schema import failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Delete a tenant database and user
     *
     * @param string $database_name Database name
     * @param string $username Database username
     * @return bool Success status
     */
    public function delete_tenant_database($database_name, $username) {
        try {
            $mysqli = $this->get_mysql_connection();
            
            if (!$mysqli) {
                throw new Exception('Failed to connect to MySQL server');
            }
            
            // Drop database
            $sql = "DROP DATABASE IF EXISTS `{$database_name}`";
            if (!$mysqli->query($sql)) {
                throw new Exception('Failed to drop database: ' . $mysqli->error);
            }
            
            // Drop user
            $sql = "DROP USER IF EXISTS '{$username}'@'localhost'";
            if (!$mysqli->query($sql)) {
                throw new Exception('Failed to drop user: ' . $mysqli->error);
            }
            
            $mysqli->query('FLUSH PRIVILEGES');
            $mysqli->close();
            
            NovaRax_Logger::log("Tenant database deleted: {$database_name}", 'info');
            
            return true;
            
        } catch (Exception $e) {
            NovaRax_Logger::log('Database deletion failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Get MySQL connection with elevated privileges
     *
     * @return mysqli|false
     */
    private function get_mysql_connection() {
        // Get credentials from wp-config or environment
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $root_user = defined('DB_ROOT_USER') ? DB_ROOT_USER : 'root';
        $root_pass = defined('DB_ROOT_PASSWORD') ? DB_ROOT_PASSWORD : '';
        
        $mysqli = new mysqli($host, $root_user, $root_pass);
        
        if ($mysqli->connect_error) {
            NovaRax_Logger::log('MySQL connection failed: ' . $mysqli->connect_error, 'error');
            return false;
        }
        
        return $mysqli;
    }
    
    /**
     * Sanitize database name
     *
     * @param string $name Raw database name
     * @return string Sanitized database name
     */
    private function sanitize_database_name($name) {
        // Remove any characters that aren't alphanumeric or underscore
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        
        // Ensure it starts with a letter
        if (!preg_match('/^[a-zA-Z]/', $name)) {
            $name = 'db_' . $name;
        }
        
        // Limit length (MySQL database name max is 64 chars)
        $name = substr($name, 0, 64);
        
        return $name;
    }
    
    /**
     * Check if database exists
     *
     * @param string $database_name Database name
     * @return bool
     */
    public function database_exists($database_name) {
        $mysqli = $this->get_mysql_connection();
        
        if (!$mysqli) {
            return false;
        }
        
        $result = $mysqli->query("SHOW DATABASES LIKE '{$database_name}'");
        $exists = $result && $result->num_rows > 0;
        
        $mysqli->close();
        
        return $exists;
    }
    
    /**
     * Get database size in bytes
     *
     * @param string $database_name Database name
     * @return int Size in bytes
     */
    public function get_database_size($database_name) {
        $mysqli = $this->get_mysql_connection();
        
        if (!$mysqli) {
            return 0;
        }
        
        $sql = "SELECT SUM(data_length + index_length) AS size 
                FROM information_schema.TABLES 
                WHERE table_schema = '{$database_name}'";
        
        $result = $mysqli->query($sql);
        
        if ($result && $row = $result->fetch_assoc()) {
            $size = (int) $row['size'];
        } else {
            $size = 0;
        }
        
        $mysqli->close();
        
        return $size;
    }
    
    /**
     * Backup tenant database
     *
     * @param string $database_name Database name
     * @param string $backup_path Path to save backup
     * @return bool|string Backup file path on success, false on failure
     */
    public function backup_tenant_database($database_name, $backup_path = null) {
        if (!$backup_path) {
            $upload_dir = wp_upload_dir();
            $backup_dir = $upload_dir['basedir'] . '/novarax-tenants/backups';
            wp_mkdir_p($backup_dir);
            $backup_path = $backup_dir . '/' . $database_name . '_' . date('Y-m-d_H-i-s') . '.sql';
        }
        
        try {
            $mysqli = $this->get_mysql_connection();
            
            if (!$mysqli) {
                throw new Exception('Failed to connect to MySQL');
            }
            
            // Select database
            $mysqli->select_db($database_name);
            
            // Get all tables
            $tables = array();
            $result = $mysqli->query("SHOW TABLES");
            
            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }
            
            $sql_dump = "-- NovaRax Tenant Database Backup\n";
            $sql_dump .= "-- Database: {$database_name}\n";
            $sql_dump .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
            
            // Dump each table
            foreach ($tables as $table) {
                // Get table structure
                $result = $mysqli->query("SHOW CREATE TABLE `{$table}`");
                $row = $result->fetch_row();
                $sql_dump .= "\n\n" . $row[1] . ";\n\n";
                
                // Get table data
                $result = $mysqli->query("SELECT * FROM `{$table}`");
                
                while ($row = $result->fetch_assoc()) {
                    $sql_dump .= "INSERT INTO `{$table}` VALUES(";
                    $values = array();
                    foreach ($row as $value) {
                        $values[] = "'" . $mysqli->real_escape_string($value) . "'";
                    }
                    $sql_dump .= implode(',', $values) . ");\n";
                }
            }
            
            $mysqli->close();
            
            // Write to file
            file_put_contents($backup_path, $sql_dump);
            
            NovaRax_Logger::log("Database backup created: {$backup_path}", 'info');
            
            return $backup_path;
            
        } catch (Exception $e) {
            NovaRax_Logger::log('Database backup failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}