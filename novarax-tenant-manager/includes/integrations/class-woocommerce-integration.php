<?php
/**
 * WooCommerce Integration Class
 * 
 * Handles integration with WooCommerce including order processing,
 * subscription management, and automatic tenant provisioning.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_WooCommerce_Integration {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Order processing hooks
        // IMPORTANT: We support both scenarios:
        // 1. Testing mode: Order completion without payment
        // 2. Production mode: Order completion after payment
        
        // Hook 1: When order status changes to "processing" or "completed"
        // This handles both paid and manual/test orders
        add_action('woocommerce_order_status_processing', array($this, 'handle_order_processing'), 10, 2);
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'), 10, 2);
        
        // Hook 2: When payment is completed (for paid orders)
        add_action('woocommerce_payment_complete', array($this, 'handle_payment_complete'), 10, 1);
        
        // Subscription hooks (if WooCommerce Subscriptions is active)
        if (class_exists('WC_Subscriptions')) {
            // Subscription activated
            add_action('woocommerce_subscription_status_active', array($this, 'handle_subscription_activated'), 10, 1);
            
            // Subscription on-hold (payment failed)
            add_action('woocommerce_subscription_status_on-hold', array($this, 'handle_subscription_on_hold'), 10, 1);
            
            // Subscription expired
            add_action('woocommerce_subscription_status_expired', array($this, 'handle_subscription_expired'), 10, 1);
            
            // Subscription cancelled
            add_action('woocommerce_subscription_status_cancelled', array($this, 'handle_subscription_cancelled'), 10, 1);
            
            // Subscription renewal processed
            add_action('woocommerce_subscription_renewal_payment_complete', array($this, 'handle_renewal_payment'), 10, 2);
        }
        
        // Product meta boxes (for module mapping)
        add_action('add_meta_boxes', array($this, 'add_product_meta_boxes'));
        add_action('save_post', array($this, 'save_product_meta'));
        
        // Add custom order meta
        add_action('woocommerce_checkout_create_order', array($this, 'add_order_meta'), 10, 2);
        
        // Thank you page customization
        add_action('woocommerce_thankyou', array($this, 'custom_thankyou_page'), 10, 1);
        
        // My Account page customization
        add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_items'));
        add_action('woocommerce_account_my-modules_endpoint', array($this, 'my_modules_endpoint'));
    }
    
    /**
     * Handle order processing (when order status changes to "processing")
     * This is triggered for BOTH paid and unpaid (manual/test) orders
     *
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    public function handle_order_processing($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        // Prevent duplicate processing
        if ($order->get_meta('_novarax_processed')) {
            NovaRax_Logger::debug("Order {$order_id} already processed, skipping");
            return;
        }
        
        NovaRax_Logger::info("Processing order {$order_id} (status: processing)");
        
        // Process the order
        $this->process_order($order);
    }
    
    /**
     * Handle order completion (when order status changes to "completed")
     *
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    public function handle_order_completed($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        // Prevent duplicate processing
        if ($order->get_meta('_novarax_processed')) {
            NovaRax_Logger::debug("Order {$order_id} already processed, skipping");
            return;
        }
        
        NovaRax_Logger::info("Processing order {$order_id} (status: completed)");
        
        // Process the order
        $this->process_order($order);
    }
    
    /**
     * Handle payment completion
     * This is specifically for when payment is received
     *
     * @param int $order_id Order ID
     */
    public function handle_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        
        // Prevent duplicate processing
        if ($order->get_meta('_novarax_processed')) {
            NovaRax_Logger::debug("Order {$order_id} already processed via payment hook, skipping");
            return;
        }
        
        NovaRax_Logger::info("Payment completed for order {$order_id}");
        
        // Process the order
        $this->process_order($order);
    }
    
    /**
     * Main order processing logic
     * This handles both test orders and paid orders
     *
     * @param WC_Order $order Order object
     * @return bool Success status
     */
    private function process_order($order) {
        try {
            $order_id = $order->get_id();
            $user_id = $order->get_user_id();
            
            // Get customer
            if (!$user_id) {
                throw new Exception('Order must be associated with a user');
            }
            
            $user = get_userdata($user_id);
            if (!$user) {
                throw new Exception('User not found');
            }
            
            NovaRax_Logger::info("Processing order {$order_id} for user {$user_id} ({$user->user_email})");
            
            // Check if user already has a tenant account
            $tenant_ops = new NovaRax_Tenant_Operations();
            $existing_tenant = $tenant_ops->get_tenant_by_user_id($user_id);
            
            $tenant_id = null;
            
            // Create tenant if doesn't exist
            if (!$existing_tenant) {
                NovaRax_Logger::info("Creating new tenant for user {$user_id}");
                
                // Generate username from email or user_login
                $username = $user->user_login;
                
                // Create tenant
                $tenant_result = $tenant_ops->create_tenant(array(
                    'full_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email' => $user->user_email,
                    'username' => $username,
                    'password' => wp_generate_password(16, true, true), // Random password
                    'company_name' => $order->get_billing_company(),
                    'phone_number' => $order->get_billing_phone(),
                    'address' => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
                ));
                
                if (!$tenant_result['success']) {
                    throw new Exception('Failed to create tenant: ' . $tenant_result['error']);
                }
                
                $tenant_id = $tenant_result['tenant_id'];
                NovaRax_Logger::info("Tenant created: ID {$tenant_id}");
                
            } else {
                $tenant_id = $existing_tenant->id;
                NovaRax_Logger::info("Using existing tenant: ID {$tenant_id}");
            }
            
            // Process order items (modules)
            $modules_activated = array();
            
            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                $product = $item->get_product();
                
                if (!$product) {
                    continue;
                }
                
                // Get mapped module slug
                $module_slug = get_post_meta($product_id, '_novarax_module_slug', true);
                
                if (!$module_slug) {
                    NovaRax_Logger::warning("Product {$product_id} has no module mapping, skipping");
                    continue;
                }
                
                NovaRax_Logger::info("Processing module: {$module_slug} for product {$product_id}");
                
                // Get or create module record
                $module_manager = new NovaRax_Module_Manager();
                $module = $module_manager->get_module_by_slug($module_slug);
                
                if (!$module) {
                    NovaRax_Logger::warning("Module {$module_slug} not found in database");
                    continue;
                }
                
                // Activate module for tenant
                $subscription_id = null;
                
                // If this is a subscription product, get subscription ID
                if (class_exists('WC_Subscriptions') && wcs_order_contains_subscription($order, 'any')) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    foreach ($subscriptions as $subscription) {
                        $subscription_id = $subscription->get_id();
                        break; // Use first subscription
                    }
                }
                
                // Calculate expiration date
                $expires_at = null;
                if ($subscription_id) {
                    $subscription = wcs_get_subscription($subscription_id);
                    $next_payment = $subscription->get_time('next_payment');
                    if ($next_payment) {
                        $expires_at = date('Y-m-d H:i:s', $next_payment);
                    }
                }
                
                // Activate module
                $activated = $module_manager->activate_module_for_tenant(
                    $tenant_id,
                    $module->id,
                    $subscription_id,
                    $expires_at
                );
                
                if ($activated) {
                    $modules_activated[] = $module_slug;
                    NovaRax_Logger::info("Module {$module_slug} activated for tenant {$tenant_id}");
                }
            }
            
            // Mark order as processed
            $order->update_meta_data('_novarax_processed', true);
            $order->update_meta_data('_novarax_tenant_id', $tenant_id);
            $order->update_meta_data('_novarax_modules_activated', implode(',', $modules_activated));
            $order->save();
            
            // Add order note
            $order->add_order_note(
                sprintf(
                    __('NovaRax: Tenant provisioned (ID: %d). Modules activated: %s', 'novarax-tenant-manager'),
                    $tenant_id,
                    implode(', ', $modules_activated)
                )
            );
            
            // Send activation email
            $tenant = $tenant_ops->get_tenant($tenant_id);
            if ($tenant && $tenant->status === 'active') {
                NovaRax_Email_Notifications::send_activation_email($user_id, $tenant_id);
            }
            
            NovaRax_Logger::info("Order {$order_id} processed successfully");
            
            return true;
            
        } catch (Exception $e) {
            NovaRax_Logger::error("Order processing failed: " . $e->getMessage(), array(
                'order_id' => $order->get_id(),
            ));
            
            // Add error note to order
            $order->add_order_note(
                sprintf(
                    __('NovaRax: Processing failed - %s', 'novarax-tenant-manager'),
                    $e->getMessage()
                )
            );
            
            return false;
        }
    }
    
    /**
     * Handle subscription activated
     *
     * @param WC_Subscription $subscription Subscription object
     */
    public function handle_subscription_activated($subscription) {
        $order_id = $subscription->get_parent_id();
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        NovaRax_Logger::info("Subscription activated: " . $subscription->get_id());
        
        // Process if not already processed
        if (!$order->get_meta('_novarax_processed')) {
            $this->process_order($order);
        }
    }
    
    /**
     * Handle subscription on-hold
     *
     * @param WC_Subscription $subscription Subscription object
     */
    public function handle_subscription_on_hold($subscription) {
        $subscription_id = $subscription->get_id();
        
        NovaRax_Logger::warning("Subscription on-hold: {$subscription_id}");
        
        // Get associated tenant modules
        $module_manager = new NovaRax_Module_Manager();
        $modules = $module_manager->get_tenant_modules_by_subscription($subscription_id);
        
        foreach ($modules as $module) {
            // Don't deactivate immediately, wait for grace period
            NovaRax_Logger::info("Module {$module->module_id} on-hold for tenant {$module->tenant_id}");
        }
        
        // Send warning email
        $user_id = $subscription->get_user_id();
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = $tenant_ops->get_tenant_by_user_id($user_id);
        
        if ($tenant) {
            NovaRax_Email_Notifications::send_expiration_warning($user_id, $tenant->id, $modules);
        }
    }
    
    /**
     * Handle subscription expired
     *
     * @param WC_Subscription $subscription Subscription object
     */
    public function handle_subscription_expired($subscription) {
        $subscription_id = $subscription->get_id();
        
        NovaRax_Logger::warning("Subscription expired: {$subscription_id}");
        
        // Deactivate associated modules
        $module_manager = new NovaRax_Module_Manager();
        $modules = $module_manager->get_tenant_modules_by_subscription($subscription_id);
        
        foreach ($modules as $module) {
            // Set status to expired (grace period)
            $module_manager->update_tenant_module_status($module->id, 'expired');
            
            NovaRax_Logger::info("Module {$module->module_id} expired for tenant {$module->tenant_id}");
        }
    }
    
    /**
     * Handle subscription cancelled
     *
     * @param WC_Subscription $subscription Subscription object
     */
    public function handle_subscription_cancelled($subscription) {
        $subscription_id = $subscription->get_id();
        
        NovaRax_Logger::warning("Subscription cancelled: {$subscription_id}");
        
        // Deactivate associated modules immediately
        $module_manager = new NovaRax_Module_Manager();
        $modules = $module_manager->get_tenant_modules_by_subscription($subscription_id);
        
        foreach ($modules as $module) {
            $module_manager->deactivate_module_for_tenant($module->tenant_id, $module->module_id);
            
            NovaRax_Logger::info("Module {$module->module_id} deactivated for tenant {$module->tenant_id}");
        }
        
        // Send cancellation email
        $user_id = $subscription->get_user_id();
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = $tenant_ops->get_tenant_by_user_id($user_id);
        
        if ($tenant) {
            NovaRax_Email_Notifications::send_cancellation_email($user_id, $tenant->id);
        }
    }
    
    /**
     * Handle subscription renewal payment
     *
     * @param WC_Subscription $subscription Subscription object
     * @param WC_Order $renewal_order Renewal order
     */
    public function handle_renewal_payment($subscription, $renewal_order) {
        $subscription_id = $subscription->get_id();
        
        NovaRax_Logger::info("Subscription renewed: {$subscription_id}");
        
        // Extend expiration dates for associated modules
        $module_manager = new NovaRax_Module_Manager();
        $modules = $module_manager->get_tenant_modules_by_subscription($subscription_id);
        
        // Calculate new expiration
        $next_payment = $subscription->get_time('next_payment');
        $expires_at = $next_payment ? date('Y-m-d H:i:s', $next_payment) : null;
        
        foreach ($modules as $module) {
            // Update expiration date
            $module_manager->update_tenant_module($module->id, array(
                'expires_at' => $expires_at,
                'status' => 'active',
            ));
            
            NovaRax_Logger::info("Module {$module->module_id} renewed for tenant {$module->tenant_id}");
        }
    }
    
    /**
     * Add product meta boxes
     */
    public function add_product_meta_boxes() {
        add_meta_box(
            'novarax_module_mapping',
            __('NovaRax Module Mapping', 'novarax-tenant-manager'),
            array($this, 'render_module_mapping_meta_box'),
            'product',
            'side',
            'default'
        );
    }
    
    /**
     * Render module mapping meta box
     *
     * @param WP_Post $post Post object
     */
    public function render_module_mapping_meta_box($post) {
        $module_slug = get_post_meta($post->ID, '_novarax_module_slug', true);
        
        // Get available modules
        $module_manager = new NovaRax_Module_Manager();
        $modules = $module_manager->get_all_modules();
        
        wp_nonce_field('novarax_module_mapping', 'novarax_module_mapping_nonce');
        ?>
        <p>
            <label for="novarax_module_slug"><?php _e('Map to Module:', 'novarax-tenant-manager'); ?></label>
            <select name="novarax_module_slug" id="novarax_module_slug" class="widefat">
                <option value=""><?php _e('-- No Module --', 'novarax-tenant-manager'); ?></option>
                <?php foreach ($modules as $module) : ?>
                    <option value="<?php echo esc_attr($module->module_slug); ?>" <?php selected($module_slug, $module->module_slug); ?>>
                        <?php echo esc_html($module->module_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="description">
            <?php _e('When this product is purchased, the selected module will be activated for the customer\'s tenant account.', 'novarax-tenant-manager'); ?>
        </p>
        <?php
    }
    
    /**
     * Save product meta
     *
     * @param int $post_id Post ID
     */
    public function save_product_meta($post_id) {
        // Check nonce
        if (!isset($_POST['novarax_module_mapping_nonce']) || 
            !wp_verify_nonce($_POST['novarax_module_mapping_nonce'], 'novarax_module_mapping')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save module mapping
        if (isset($_POST['novarax_module_slug'])) {
            update_post_meta($post_id, '_novarax_module_slug', sanitize_text_field($_POST['novarax_module_slug']));
        }
    }
    
    /**
     * Add custom order meta during checkout
     *
     * @param WC_Order $order Order object
     * @param array $data Checkout data
     */
    public function add_order_meta($order, $data) {
        // Add any custom meta needed
        $order->update_meta_data('_novarax_order_timestamp', current_time('mysql'));
    }
    
    /**
     * Custom thank you page
     *
     * @param int $order_id Order ID
     */
    public function custom_thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $tenant_id = $order->get_meta('_novarax_tenant_id');
        
        if (!$tenant_id) {
            return;
        }
        
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = $tenant_ops->get_tenant($tenant_id);
        
        if (!$tenant) {
            return;
        }
        
        ?>
        <div class="novarax-thankyou-message" style="background:#f8f9fa; padding:20px; margin:20px 0; border-radius:8px;">
            <h2><?php _e('Your Dashboard is Being Set Up!', 'novarax-tenant-manager'); ?></h2>
            <p><?php _e('We are currently provisioning your dashboard. This usually takes 2-5 minutes.', 'novarax-tenant-manager'); ?></p>
            
            <?php if ($tenant->status === 'active') : ?>
                <p><strong><?php _e('Your dashboard is ready!', 'novarax-tenant-manager'); ?></strong></p>
                <p>
                    <a href="https://<?php echo esc_attr($tenant->subdomain); ?>" class="button button-primary" target="_blank">
                        <?php _e('Access Your Dashboard', 'novarax-tenant-manager'); ?> →
                    </a>
                </p>
            <?php else : ?>
                <p><?php _e('You will receive an email when your dashboard is ready.', 'novarax-tenant-manager'); ?></p>
                <p><strong><?php _e('Your Dashboard URL:', 'novarax-tenant-manager'); ?></strong> <code>https://<?php echo esc_html($tenant->subdomain); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Add custom menu items to My Account
     *
     * @param array $items Menu items
     * @return array Modified menu items
     */
    public function add_account_menu_items($items) {
        // Add "My Modules" after "Orders"
        $new_items = array();
        
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            
            if ($key === 'orders') {
                $new_items['my-modules'] = __('My Modules', 'novarax-tenant-manager');
            }
        }
        
        return $new_items;
    }
    
    /**
     * My Modules endpoint content
     */
    public function my_modules_endpoint() {
        $user_id = get_current_user_id();
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = $tenant_ops->get_tenant_by_user_id($user_id);
        
        if (!$tenant) {
            echo '<p>' . __('You don\'t have a tenant account yet.', 'novarax-tenant-manager') . '</p>';
            return;
        }
        
        $module_manager = new NovaRax_Module_Manager();
        $active_modules = $module_manager->get_tenant_active_modules($tenant->id);
        
        ?>
        <h2><?php _e('My Modules', 'novarax-tenant-manager'); ?></h2>
        
        <?php if ($tenant->status === 'active') : ?>
            <p>
                <a href="https://<?php echo esc_attr($tenant->subdomain); ?>" class="button button-primary" target="_blank">
                    <?php _e('Access Dashboard', 'novarax-tenant-manager'); ?> →
                </a>
            </p>
        <?php endif; ?>
        
        <?php if (empty($active_modules)) : ?>
            <p><?php _e('You don\'t have any active modules yet.', 'novarax-tenant-manager'); ?></p>
            <p>
                <a href="<?php echo get_permalink(wc_get_page_id('shop')); ?>" class="button">
                    <?php _e('Browse Modules', 'novarax-tenant-manager'); ?>
                </a>
            </p>
        <?php else : ?>
            <table class="shop_table">
                <thead>
                    <tr>
                        <th><?php _e('Module', 'novarax-tenant-manager'); ?></th>
                        <th><?php _e('Status', 'novarax-tenant-manager'); ?></th>
                        <th><?php _e('Expires', 'novarax-tenant-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_modules as $module) : ?>
                        <tr>
                            <td><?php echo esc_html($module->module_name); ?></td>
                            <td>
                                <span class="novarax-status-<?php echo esc_attr($module->status); ?>">
                                    <?php echo esc_html(ucfirst($module->status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if ($module->expires_at) {
                                    echo date_i18n(get_option('date_format'), strtotime($module->expires_at));
                                } else {
                                    _e('Never', 'novarax-tenant-manager');
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
}