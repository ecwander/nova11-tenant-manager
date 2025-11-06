<?php
/**
 * Provisioning Queue Class
 * 
 * Handles asynchronous tenant provisioning using WordPress cron system.
 * Ensures provisioning doesn't block user requests and can handle failures gracefully.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Provisioning_Queue {
    
    /**
     * Queue option key
     *
     * @var string
     */
    private $queue_key = 'novarax_provisioning_queue';
    
    /**
     * Processing option key
     *
     * @var string
     */
    private $processing_key = 'novarax_provisioning_processing';
    
    /**
     * Maximum retry attempts
     *
     * @var int
     */
    private $max_retries = 3;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register cron hooks
        add_action('novarax_process_provisioning_queue', array($this, 'process_queue'));
        
        // Schedule cron event if not already scheduled
        if (!wp_next_scheduled('novarax_process_provisioning_queue')) {
            wp_schedule_event(time(), 'every_minute', 'novarax_process_provisioning_queue');
        }
        
        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
    }
    
    /**
     * Add custom cron schedule
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedule($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'novarax-tenant-manager'),
        );
        
        return $schedules;
    }
    
    /**
     * Add tenant to provisioning queue
     *
     * @param int $tenant_id Tenant ID
     * @param int $priority Priority (lower number = higher priority)
     * @return bool Success
     */
    public function add_to_queue($tenant_id, $priority = 10) {
        $queue = $this->get_queue();
        
        // Check if already in queue
        foreach ($queue as $item) {
            if ($item['tenant_id'] === $tenant_id) {
                NovaRax_Logger::debug("Tenant already in queue: ID {$tenant_id}");
                return true;
            }
        }
        
        // Add to queue
        $queue[] = array(
            'tenant_id' => $tenant_id,
            'priority' => $priority,
            'added_at' => time(),
            'attempts' => 0,
            'status' => 'pending',
            'last_error' => null,
        );
        
        // Sort by priority
        usort($queue, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
        
        // Save queue
        $saved = update_option($this->queue_key, $queue, false);
        
        if ($saved) {
            NovaRax_Logger::info("Tenant added to provisioning queue: ID {$tenant_id}");
            
            // Trigger immediate processing if not already processing
            if (!$this->is_processing()) {
                wp_schedule_single_event(time(), 'novarax_process_provisioning_queue');
            }
        }
        
        return $saved;
    }
    
    /**
     * Remove tenant from queue
     *
     * @param int $tenant_id Tenant ID
     * @return bool Success
     */
    public function remove_from_queue($tenant_id) {
        $queue = $this->get_queue();
        
        $new_queue = array_filter($queue, function($item) use ($tenant_id) {
            return $item['tenant_id'] !== $tenant_id;
        });
        
        if (count($new_queue) !== count($queue)) {
            update_option($this->queue_key, array_values($new_queue), false);
            NovaRax_Logger::info("Tenant removed from queue: ID {$tenant_id}");
            return true;
        }
        
        return false;
    }
    
    /**
     * Get provisioning queue
     *
     * @return array Queue items
     */
    public function get_queue() {
        $queue = get_option($this->queue_key, array());
        
        if (!is_array($queue)) {
            $queue = array();
        }
        
        return $queue;
    }
    
    /**
     * Get queue count
     *
     * @return int Count
     */
    public function get_queue_count() {
        return count($this->get_queue());
    }
    
    /**
     * Check if currently processing
     *
     * @return bool Is processing
     */
    public function is_processing() {
        $processing = get_transient($this->processing_key);
        return $processing !== false;
    }
    
    /**
     * Process provisioning queue
     */
    public function process_queue() {
        // Check if already processing
        if ($this->is_processing()) {
            NovaRax_Logger::debug('Provisioning queue already processing');
            return;
        }
        
        // Set processing flag (30 minute timeout)
        set_transient($this->processing_key, true, 1800);
        
        try {
            $queue = $this->get_queue();
            
            if (empty($queue)) {
                delete_transient($this->processing_key);
                return;
            }
            
            NovaRax_Logger::info("Processing provisioning queue: " . count($queue) . " items");
            
            // Process each item
            $processed = 0;
            $failed = 0;
            
            foreach ($queue as $key => $item) {
                // Skip if max retries reached
                if ($item['attempts'] >= $this->max_retries) {
                    NovaRax_Logger::error("Max retries reached for tenant: ID {$item['tenant_id']}");
                    
                    // Mark as failed
                    $queue[$key]['status'] = 'failed';
                    $failed++;
                    continue;
                }
                
                // Process the item
                $result = $this->process_item($item);
                
                if ($result['success']) {
                    // Remove from queue
                    unset($queue[$key]);
                    $processed++;
                    
                    NovaRax_Logger::info("Tenant provisioned successfully: ID {$item['tenant_id']}");
                } else {
                    // Update attempts and error
                    $queue[$key]['attempts']++;
                    $queue[$key]['last_error'] = $result['error'];
                    $queue[$key]['status'] = 'retrying';
                    $failed++;
                    
                    NovaRax_Logger::warning("Tenant provisioning failed (attempt {$queue[$key]['attempts']}): ID {$item['tenant_id']}", array(
                        'error' => $result['error'],
                    ));
                }
                
                // Prevent timeout - process only 5 at a time
                if ($processed + $failed >= 5) {
                    break;
                }
            }
            
            // Save updated queue
            update_option($this->queue_key, array_values($queue), false);
            
            NovaRax_Logger::info("Queue processing complete", array(
                'processed' => $processed,
                'failed' => $failed,
                'remaining' => count($queue),
            ));
            
        } catch (Exception $e) {
            NovaRax_Logger::error('Queue processing error: ' . $e->getMessage());
        } finally {
            // Clear processing flag
            delete_transient($this->processing_key);
        }
    }
    
    /**
     * Process a single queue item
     *
     * @param array $item Queue item
     * @return array Result with 'success' and 'error'
     */
    private function process_item($item) {
        try {
            $tenant_ops = new NovaRax_Tenant_Operations();
            
            // Get tenant
            $tenant = $tenant_ops->get_tenant($item['tenant_id']);
            
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }
            
            // Check if already provisioned
            if ($tenant->status === 'active') {
                return array(
                    'success' => true,
                    'message' => 'Already provisioned',
                );
            }
            
            // Provision the tenant
            $result = $tenant_ops->provision_tenant($item['tenant_id']);
            
            return $result;
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Get item status
     *
     * @param int $tenant_id Tenant ID
     * @return array|false Item data or false if not in queue
     */
    public function get_item_status($tenant_id) {
        $queue = $this->get_queue();
        
        foreach ($queue as $item) {
            if ($item['tenant_id'] === $tenant_id) {
                return $item;
            }
        }
        
        return false;
    }
    
    /**
     * Retry failed item
     *
     * @param int $tenant_id Tenant ID
     * @return bool Success
     */
    public function retry_item($tenant_id) {
        $queue = $this->get_queue();
        
        foreach ($queue as $key => $item) {
            if ($item['tenant_id'] === $tenant_id) {
                // Reset attempts
                $queue[$key]['attempts'] = 0;
                $queue[$key]['status'] = 'pending';
                $queue[$key]['last_error'] = null;
                
                update_option($this->queue_key, $queue, false);
                
                NovaRax_Logger::info("Retrying tenant provisioning: ID {$tenant_id}");
                
                // Trigger processing
                wp_schedule_single_event(time(), 'novarax_process_provisioning_queue');
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Clear failed items from queue
     *
     * @return int Number of items cleared
     */
    public function clear_failed_items() {
        $queue = $this->get_queue();
        
        $new_queue = array_filter($queue, function($item) {
            return $item['status'] !== 'failed' || $item['attempts'] < $this->max_retries;
        });
        
        $cleared = count($queue) - count($new_queue);
        
        if ($cleared > 0) {
            update_option($this->queue_key, array_values($new_queue), false);
            NovaRax_Logger::info("Cleared {$cleared} failed items from queue");
        }
        
        return $cleared;
    }
    
    /**
     * Clear entire queue
     *
     * @return bool Success
     */
    public function clear_queue() {
        delete_option($this->queue_key);
        NovaRax_Logger::warning('Provisioning queue cleared');
        return true;
    }
    
    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public function get_statistics() {
        $queue = $this->get_queue();
        
        $stats = array(
            'total' => count($queue),
            'pending' => 0,
            'retrying' => 0,
            'failed' => 0,
            'oldest_item' => null,
            'is_processing' => $this->is_processing(),
        );
        
        foreach ($queue as $item) {
            if ($item['status'] === 'pending') {
                $stats['pending']++;
            } elseif ($item['status'] === 'retrying') {
                $stats['retrying']++;
            } elseif ($item['status'] === 'failed') {
                $stats['failed']++;
            }
            
            // Track oldest item
            if ($stats['oldest_item'] === null || $item['added_at'] < $stats['oldest_item']) {
                $stats['oldest_item'] = $item['added_at'];
            }
        }
        
        // Calculate age of oldest item
        if ($stats['oldest_item']) {
            $stats['oldest_item_age'] = time() - $stats['oldest_item'];
        }
        
        return $stats;
    }
    
    /**
     * Manual trigger for queue processing
     *
     * @return bool Success
     */
    public function trigger_processing() {
        if ($this->is_processing()) {
            return false;
        }
        
        wp_schedule_single_event(time(), 'novarax_process_provisioning_queue');
        
        NovaRax_Logger::info('Manual provisioning queue processing triggered');
        
        return true;
    }
    
    /**
     * Get items by status
     *
     * @param string $status Status to filter (pending, retrying, failed)
     * @return array Items
     */
    public function get_items_by_status($status) {
        $queue = $this->get_queue();
        
        return array_filter($queue, function($item) use ($status) {
            return $item['status'] === $status;
        });
    }
    
    /**
     * Clean up old queue items (older than 7 days)
     *
     * @param int $days Days threshold
     * @return int Number of items cleaned
     */
    public function cleanup_old_items($days = 7) {
        $queue = $this->get_queue();
        $threshold = time() - ($days * DAY_IN_SECONDS);
        
        $new_queue = array_filter($queue, function($item) use ($threshold) {
            return $item['added_at'] > $threshold;
        });
        
        $cleaned = count($queue) - count($new_queue);
        
        if ($cleaned > 0) {
            update_option($this->queue_key, array_values($new_queue), false);
            NovaRax_Logger::info("Cleaned up {$cleaned} old queue items");
        }
        
        return $cleaned;
    }
}