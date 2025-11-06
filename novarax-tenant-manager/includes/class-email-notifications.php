<?php
/**
 * Email Notifications Class
 * 
 * Handles all email notifications with beautiful HTML templates
 * for welcome emails, activations, subscriptions, and more.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NovaRax_Email_Notifications {
    
    /**
     * From email address
     *
     * @var string
     */
    private static $from_email = null;
    
    /**
     * From name
     *
     * @var string
     */
    private static $from_name = null;
    
    /**
     * Initialize email settings
     */
    public static function init() {
        self::$from_email = get_option('novarax_tm_from_email', get_option('admin_email'));
        self::$from_name = get_option('novarax_tm_from_name', get_bloginfo('name'));
        
        // Set up email hooks
        add_filter('wp_mail_from', array(__CLASS__, 'set_from_email'));
        add_filter('wp_mail_from_name', array(__CLASS__, 'set_from_name'));
        add_filter('wp_mail_content_type', array(__CLASS__, 'set_content_type'));
    }
    
    /**
     * Set from email
     *
     * @return string
     */
    public static function set_from_email() {
        return self::$from_email;
    }
    
    /**
     * Set from name
     *
     * @return string
     */
    public static function set_from_name() {
        return self::$from_name;
    }
    
    /**
     * Set content type to HTML
     *
     * @return string
     */
    public static function set_content_type() {
        return 'text/html';
    }
    
    /**
     * Send email
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email message
     * @param array $headers Additional headers
     * @return bool Success
     */
    private static function send_email($to, $subject, $message, $headers = array()) {
        self::init();
        
        // Wrap message in HTML template
        $html_message = self::get_email_template($subject, $message);
        
        // Send email
        $sent = wp_mail($to, $subject, $html_message, $headers);
        
        if ($sent) {
            NovaRax_Logger::info("Email sent: {$subject}", array(
                'to' => $to,
                'subject' => $subject,
            ));
        } else {
            NovaRax_Logger::error("Email failed: {$subject}", array(
                'to' => $to,
                'subject' => $subject,
            ));
        }
        
        return $sent;
    }
    
    /**
     * Send welcome email to new tenant
     *
     * @param int $user_id User ID
     * @param int $tenant_id Tenant ID
     * @param string $password User password (plain text, only sent once)
     * @return bool Success
     */
    public static function send_welcome_email($user_id, $tenant_id, $password) {
        $user = get_userdata($user_id);
        $tenant = self::get_tenant_data($tenant_id);
        
        if (!$user || !$tenant) {
            return false;
        }
        
        $subject = sprintf(
            __('Welcome to %s!', 'novarax-tenant-manager'),
            get_bloginfo('name')
        );
        
        $login_url = site_url('/wp-login.php');
        $modules_url = site_url('/apps');
        
        $message = self::get_template('welcome', array(
            'user_name' => $user->display_name,
            'username' => $user->user_login,
            'password' => $password,
            'email' => $user->user_email,
            'tenant_name' => $tenant['account_name'],
            'login_url' => $login_url,
            'modules_url' => $modules_url,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send activation email after tenant is provisioned
     *
     * @param int $user_id User ID
     * @param int $tenant_id Tenant ID
     * @return bool Success
     */
    public static function send_activation_email($user_id, $tenant_id) {
        $user = get_userdata($user_id);
        $tenant = self::get_tenant_data($tenant_id);
        
        if (!$user || !$tenant) {
            return false;
        }
        
        $subject = sprintf(
            __('Your %s Dashboard is Ready!', 'novarax-tenant-manager'),
            get_bloginfo('name')
        );
        
        $dashboard_url = 'https://' . $tenant['subdomain'];
        
        $message = self::get_template('activation', array(
            'user_name' => $user->display_name,
            'tenant_name' => $tenant['account_name'],
            'dashboard_url' => $dashboard_url,
            'username' => $user->user_login,
            'modules' => $tenant['active_modules'],
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send subscription renewal reminder
     *
     * @param int $user_id User ID
     * @param int $tenant_id Tenant ID
     * @param array $subscription_data Subscription data
     * @return bool Success
     */
    public static function send_renewal_reminder($user_id, $tenant_id, $subscription_data) {
        $user = get_userdata($user_id);
        $tenant = self::get_tenant_data($tenant_id);
        
        if (!$user || !$tenant) {
            return false;
        }
        
        $subject = sprintf(
            __('Your %s subscription will renew soon', 'novarax-tenant-manager'),
            get_bloginfo('name')
        );
        
        $renewal_date = date_i18n(get_option('date_format'), strtotime($subscription_data['renewal_date']));
        $manage_url = site_url('/apps/subscriptions');
        
        $message = self::get_template('renewal_reminder', array(
            'user_name' => $user->display_name,
            'renewal_date' => $renewal_date,
            'amount' => $subscription_data['amount'],
            'modules' => $subscription_data['modules'],
            'manage_url' => $manage_url,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send subscription expiration warning
     *
     * @param int $user_id User ID
     * @param int $tenant_id Tenant ID
     * @param array $modules Expiring modules
     * @return bool Success
     */
    public static function send_expiration_warning($user_id, $tenant_id, $modules) {
        $user = get_userdata($user_id);
        $tenant = self::get_tenant_data($tenant_id);
        
        if (!$user || !$tenant) {
            return false;
        }
        
        $subject = __('Action Required: Your subscription is expiring', 'novarax-tenant-manager');
        
        $renew_url = site_url('/apps/subscriptions');
        
        $message = self::get_template('expiration_warning', array(
            'user_name' => $user->display_name,
            'modules' => $modules,
            'grace_period_days' => get_option('novarax_tm_grace_period_days', 7),
            'renew_url' => $renew_url,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send subscription cancelled notification
     *
     * @param int $user_id User ID
     * @param int $tenant_id Tenant ID
     * @return bool Success
     */
    public static function send_cancellation_email($user_id, $tenant_id) {
        $user = get_userdata($user_id);
        $tenant = self::get_tenant_data($tenant_id);
        
        if (!$user || !$tenant) {
            return false;
        }
        
        $subject = __('Your subscription has been cancelled', 'novarax-tenant-manager');
        
        $reactivate_url = site_url('/apps');
        
        $message = self::get_template('cancellation', array(
            'user_name' => $user->display_name,
            'tenant_name' => $tenant['account_name'],
            'reactivate_url' => $reactivate_url,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send account suspended notification
     *
     * @param int $user_id User ID
     * @param int $tenant_id Tenant ID
     * @param string $reason Suspension reason
     * @return bool Success
     */
    public static function send_suspension_email($user_id, $tenant_id, $reason = '') {
        $user = get_userdata($user_id);
        $tenant = self::get_tenant_data($tenant_id);
        
        if (!$user || !$tenant) {
            return false;
        }
        
        $subject = __('Your account has been suspended', 'novarax-tenant-manager');
        
        $support_url = site_url('/support');
        
        $message = self::get_template('suspension', array(
            'user_name' => $user->display_name,
            'reason' => $reason,
            'support_url' => $support_url,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send invoice email
     *
     * @param int $user_id User ID
     * @param int $order_id WooCommerce order ID
     * @return bool Success
     */
    public static function send_invoice_email($user_id, $order_id) {
        $user = get_userdata($user_id);
        $order = wc_get_order($order_id);
        
        if (!$user || !$order) {
            return false;
        }
        
        $subject = sprintf(
            __('Invoice #%s from %s', 'novarax-tenant-manager'),
            $order->get_order_number(),
            get_bloginfo('name')
        );
        
        $invoice_url = $order->get_view_order_url();
        
        $message = self::get_template('invoice', array(
            'user_name' => $user->display_name,
            'order_number' => $order->get_order_number(),
            'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'order_total' => $order->get_formatted_order_total(),
            'items' => $order->get_items(),
            'invoice_url' => $invoice_url,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send password reset email
     *
     * @param int $user_id User ID
     * @param string $reset_key Reset key
     * @return bool Success
     */
    public static function send_password_reset_email($user_id, $reset_key) {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        $subject = sprintf(
            __('Password Reset Request for %s', 'novarax-tenant-manager'),
            get_bloginfo('name')
        );
        
        $reset_url = network_site_url("wp-login.php?action=rp&key={$reset_key}&login=" . rawurlencode($user->user_login), 'login');
        
        $message = self::get_template('password_reset', array(
            'user_name' => $user->display_name,
            'reset_url' => $reset_url,
            'valid_hours' => 1,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Send support ticket notification
     *
     * @param int $user_id User ID
     * @param array $ticket_data Ticket data
     * @return bool Success
     */
    public static function send_support_ticket_email($user_id, $ticket_data) {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        $subject = sprintf(
            __('Support Ticket #%s: %s', 'novarax-tenant-manager'),
            $ticket_data['ticket_id'],
            $ticket_data['subject']
        );
        
        $ticket_url = site_url('/support/tickets/' . $ticket_data['ticket_id']);
        
        $message = self::get_template('support_ticket', array(
            'user_name' => $user->display_name,
            'ticket_id' => $ticket_data['ticket_id'],
            'ticket_subject' => $ticket_data['subject'],
            'ticket_message' => $ticket_data['message'],
            'ticket_url' => $ticket_url,
            'site_name' => get_bloginfo('name'),
        ));
        
        return self::send_email($user->user_email, $subject, $message);
    }
    
    /**
     * Get email template
     *
     * @param string $template_name Template name
     * @param array $vars Template variables
     * @return string HTML content
     */
    private static function get_template($template_name, $vars = array()) {
        extract($vars);
        
        ob_start();
        
        // Try to load custom template first
        $custom_template = NOVARAX_TM_PLUGIN_DIR . 'templates/emails/' . $template_name . '.php';
        
        if (file_exists($custom_template)) {
            include $custom_template;
        } else {
            // Use default inline templates
            include NOVARAX_TM_PLUGIN_DIR . 'templates/emails/default-' . $template_name . '.php';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Get base email template wrapper
     *
     * @param string $title Email title
     * @param string $content Email content
     * @return string HTML email
     */
    private static function get_email_template($title, $content) {
        $logo_url = get_option('novarax_tm_email_logo', '');
        $site_name = get_bloginfo('name');
        $site_url = site_url();
        $primary_color = get_option('novarax_tm_email_primary_color', '#0073aa');
        $current_year = date('Y');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($title) . '</title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .email-header {
            background: ' . esc_attr($primary_color) . ';
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .email-logo {
            max-width: 150px;
            margin-bottom: 15px;
        }
        .email-body {
            padding: 40px 30px;
        }
        .email-body h2 {
            color: #333;
            font-size: 20px;
            margin-top: 0;
        }
        .email-body p {
            margin: 15px 0;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: ' . esc_attr($primary_color) . ';
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            margin: 20px 0;
        }
        .button:hover {
            opacity: 0.9;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid ' . esc_attr($primary_color) . ';
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-box strong {
            display: block;
            margin-bottom: 5px;
        }
        .email-footer {
            background: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .email-footer a {
            color: ' . esc_attr($primary_color) . ';
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                border-radius: 0;
            }
            .email-body {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">';
        
        if ($logo_url) {
            $html .= '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" class="email-logo">';
        }
        
        $html .= '<h1>' . esc_html($site_name) . '</h1>
        </div>
        
        <div class="email-body">
            ' . $content . '
        </div>
        
        <div class="email-footer">
            <p>&copy; ' . $current_year . ' ' . esc_html($site_name) . '. All rights reserved.</p>
            <p>
                <a href="' . esc_url($site_url) . '">' . __('Visit Website', 'novarax-tenant-manager') . '</a> | 
                <a href="' . esc_url($site_url . '/support') . '">' . __('Support', 'novarax-tenant-manager') . '</a> | 
                <a href="' . esc_url($site_url . '/privacy-policy') . '">' . __('Privacy Policy', 'novarax-tenant-manager') . '</a>
            </p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Get tenant data
     *
     * @param int $tenant_id Tenant ID
     * @return array|false Tenant data
     */
    private static function get_tenant_data($tenant_id) {
        $tenant_ops = new NovaRax_Tenant_Operations();
        $tenant = $tenant_ops->get_tenant($tenant_id);
        
        if (!$tenant) {
            return false;
        }
        
        return array(
            'id' => $tenant->id,
            'account_name' => $tenant->account_name,
            'subdomain' => $tenant->subdomain,
            'status' => $tenant->status,
            'active_modules' => array(), // Will be populated from tenant_modules table
        );
    }
    
    /**
     * Test email configuration
     *
     * @param string $to Test recipient email
     * @return bool Success
     */
    public static function send_test_email($to) {
        $subject = __('Test Email from NovaRax Tenant Manager', 'novarax-tenant-manager');
        
        $message = '<h2>' . __('Test Email', 'novarax-tenant-manager') . '</h2>';
        $message .= '<p>' . __('If you\'re reading this, your email configuration is working correctly!', 'novarax-tenant-manager') . '</p>';
        $message .= '<p>' . __('Sent at:', 'novarax-tenant-manager') . ' ' . current_time('mysql') . '</p>';
        
        return self::send_email($to, $subject, $message);
    }
}

// Initialize email notifications
NovaRax_Email_Notifications::init();