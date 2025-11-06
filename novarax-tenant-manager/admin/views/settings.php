<?php
if (!defined('ABSPATH')) exit;

$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
?>

<div class="wrap">
    <h1><?php _e('NovaRax Settings', 'novarax-tenant-manager'); ?></h1>
    
    <h2 class="nav-tab-wrapper">
        <a href="?page=novarax-tenants-settings&tab=general" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
            <?php _e('General', 'novarax-tenant-manager'); ?>
        </a>
        <a href="?page=novarax-tenants-settings&tab=email" class="nav-tab <?php echo $current_tab === 'email' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Email', 'novarax-tenant-manager'); ?>
        </a>
        <a href="?page=novarax-tenants-settings&tab=advanced" class="nav-tab <?php echo $current_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Advanced', 'novarax-tenant-manager'); ?>
        </a>
    </h2>
    
    <form method="post" action="">
        <?php wp_nonce_field('novarax_tm_action', 'novarax_tm_nonce'); ?>
        <input type="hidden" name="novarax_tm_action" value="update_settings">
        
        <?php if ($current_tab === 'general') : ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Subdomain Suffix', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="text" name="novarax_tm_subdomain_suffix" class="regular-text" 
                               value="<?php echo esc_attr(get_option('novarax_tm_subdomain_suffix', '.app.novarax.ae')); ?>">
                        <p class="description"><?php _e('e.g., .app.novarax.ae', 'novarax-tenant-manager'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Default Storage Limit', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="number" name="novarax_tm_tenant_storage_limit" class="small-text" 
                               value="<?php echo esc_attr(get_option('novarax_tm_tenant_storage_limit', 5368709120) / 1073741824); ?>"> GB
                        <p class="description"><?php _e('Default storage limit for new tenants', 'novarax-tenant-manager'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Default User Limit', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="number" name="novarax_tm_tenant_user_limit" class="small-text" 
                               value="<?php echo esc_attr(get_option('novarax_tm_tenant_user_limit', 10)); ?>">
                        <p class="description"><?php _e('Maximum users per tenant', 'novarax-tenant-manager'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Grace Period', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="number" name="novarax_tm_grace_period_days" class="small-text" 
                               value="<?php echo esc_attr(get_option('novarax_tm_grace_period_days', 7)); ?>"> days
                        <p class="description"><?php _e('Days before modules are deactivated after subscription expires', 'novarax-tenant-manager'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Auto Provision', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="novarax_tm_auto_provision" value="1" 
                                   <?php checked(get_option('novarax_tm_auto_provision', true)); ?>>
                            <?php _e('Automatically provision tenants after creation', 'novarax-tenant-manager'); ?>
                        </label>
                    </td>
                </tr>
            </table>
        <?php elseif ($current_tab === 'email') : ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('From Name', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="text" name="novarax_tm_from_name" class="regular-text" 
                               value="<?php echo esc_attr(get_option('novarax_tm_from_name', get_bloginfo('name'))); ?>">
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('From Email', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="email" name="novarax_tm_from_email" class="regular-text" 
                               value="<?php echo esc_attr(get_option('novarax_tm_from_email', get_option('admin_email'))); ?>">
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Email Logo URL', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="url" name="novarax_tm_email_logo" class="regular-text" 
                               value="<?php echo esc_attr(get_option('novarax_tm_email_logo', '')); ?>">
                        <p class="description"><?php _e('Logo displayed in email header', 'novarax-tenant-manager'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Primary Color', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="text" name="novarax_tm_email_primary_color" class="novarax-color-picker" 
                               value="<?php echo esc_attr(get_option('novarax_tm_email_primary_color', '#0073aa')); ?>">
                        <p class="description"><?php _e('Primary color used in email templates', 'novarax-tenant-manager'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Test Email', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <input type="email" id="test-email-address" class="regular-text" 
                               placeholder="<?php esc_attr_e('Enter email address', 'novarax-tenant-manager'); ?>">
                        <button type="button" class="button" id="send-test-email">
                            <?php _e('Send Test Email', 'novarax-tenant-manager'); ?>
                        </button>
                    </td>
                </tr>
            </table>
        <?php endif; ?>
        
        <?php submit_button(); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Color picker
    $('.novarax-color-picker').wpColorPicker();
    
    // Send test email
    $('#send-test-email').on('click', function() {
        var email = $('#test-email-address').val();
        if (!email) {
            alert('<?php _e('Please enter an email address', 'novarax-tenant-manager'); ?>');
            return;
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php _e('Sending...', 'novarax-tenant-manager'); ?>');
        
        $.post(ajaxurl, {
            action: 'novarax_send_test_email',
            nonce: novaraxTM.nonce,
            email: email
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
            } else {
                alert(response.data.message);
            }
            $btn.prop('disabled', false).text('<?php _e('Send Test Email', 'novarax-tenant-manager'); ?>');
        });
    });
});
</script>