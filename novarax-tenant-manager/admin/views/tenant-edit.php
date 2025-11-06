<?php
if (!defined('ABSPATH')) exit;

$tenant_ops = new NovaRax_Tenant_Operations();
$tenant = $tenant_ops->get_tenant($tenant_id);

if (!$tenant) {
    wp_die(__('Tenant not found', 'novarax-tenant-manager'));
}

$user = get_userdata($tenant->user_id);
?>

<div class="wrap">
    <h1><?php _e('Edit Tenant', 'novarax-tenant-manager'); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('novarax_tm_action', 'novarax_tm_nonce'); ?>
        <input type="hidden" name="novarax_tm_action" value="update_tenant">
        <input type="hidden" name="tenant_id" value="<?php echo $tenant->id; ?>">
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Username', 'novarax-tenant-manager'); ?></th>
                <td>
                    <code><?php echo esc_html($tenant->tenant_username); ?></code>
                    <p class="description"><?php _e('Username cannot be changed', 'novarax-tenant-manager'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Subdomain', 'novarax-tenant-manager'); ?></th>
                <td>
                    <a href="https://<?php echo esc_attr($tenant->subdomain); ?>" target="_blank">
                        <?php echo esc_html($tenant->subdomain); ?>
                    </a>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="account_name"><?php _e('Account Name', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <input type="text" name="account_name" id="account_name" class="regular-text" 
                           value="<?php echo esc_attr($tenant->account_name); ?>" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="company_name"><?php _e('Company Name', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <input type="text" name="company_name" id="company_name" class="regular-text" 
                           value="<?php echo esc_attr($tenant->company_name); ?>">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="billing_email"><?php _e('Billing Email', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <input type="email" name="billing_email" id="billing_email" class="regular-text" 
                           value="<?php echo esc_attr($tenant->billing_email); ?>" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="phone_number"><?php _e('Phone Number', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <input type="tel" name="phone_number" id="phone_number" class="regular-text" 
                           value="<?php echo esc_attr($tenant->phone_number); ?>">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="address"><?php _e('Address', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <textarea name="address" id="address" rows="3" class="large-text"><?php echo esc_textarea($tenant->address); ?></textarea>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('Update Tenant', 'novarax-tenant-manager')); ?>
    </form>
</div>