<?php
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1><?php _e('Add New Tenant', 'novarax-tenant-manager'); ?></h1>
    
    <form method="post" action="" class="novarax-tenant-form">
        <?php wp_nonce_field('novarax_tm_action', 'novarax_tm_nonce'); ?>
        <input type="hidden" name="novarax_tm_action" value="create_tenant">
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="full_name"><?php _e('Full Name', 'novarax-tenant-manager'); ?> *</label>
                </th>
                <td>
                    <input type="text" name="full_name" id="full_name" class="regular-text" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="username"><?php _e('Username', 'novarax-tenant-manager'); ?> *</label>
                </th>
                <td>
                    <input type="text" name="username" id="username" class="regular-text" required>
                    <p class="description" id="username-feedback"></p>
                    <p class="description"><?php _e('Letters, numbers, hyphens, and underscores only', 'novarax-tenant-manager'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php _e('Subdomain Preview', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <code id="subdomain-preview">username<?php echo get_option('novarax_tm_subdomain_suffix', '.app.novarax.ae'); ?></code>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="email"><?php _e('Email Address', 'novarax-tenant-manager'); ?> *</label>
                </th>
                <td>
                    <input type="email" name="email" id="email" class="regular-text" required>
                    <p class="description" id="email-feedback"></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="password"><?php _e('Password', 'novarax-tenant-manager'); ?> *</label>
                </th>
                <td>
                    <input type="password" name="password" id="password" class="regular-text" required>
                    <button type="button" class="button" id="generate-password">
                        <?php _e('Generate', 'novarax-tenant-manager'); ?>
                    </button>
                    <p class="description"><?php _e('Minimum 12 characters with letters, numbers, and symbols', 'novarax-tenant-manager'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="company_name"><?php _e('Company Name', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <input type="text" name="company_name" id="company_name" class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="phone_number"><?php _e('Phone Number', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <input type="tel" name="phone_number" id="phone_number" class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="address"><?php _e('Address', 'novarax-tenant-manager'); ?></label>
                </th>
                <td>
                    <textarea name="address" id="address" rows="3" class="large-text"></textarea>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('Create Tenant', 'novarax-tenant-manager'), 'primary', 'submit'); ?>
    </form>
</div>