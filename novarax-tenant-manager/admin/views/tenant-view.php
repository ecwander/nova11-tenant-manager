<?php
if (!defined('ABSPATH')) exit;

$tenant_ops = new NovaRax_Tenant_Operations();
$tenant = $tenant_ops->get_tenant($tenant_id);

if (!$tenant) {
    wp_die(__('Tenant not found', 'novarax-tenant-manager'));
}

$user = get_userdata($tenant->user_id);
$db_manager = new NovaRax_Database_Manager();
$db_size = $db_manager->get_database_size($tenant->database_name);
?>

<div class="wrap">
    <h1><?php echo esc_html($tenant->account_name); ?></h1>
    
    <div class="novarax-tenant-header">
        <div class="novarax-tenant-status">
            <span class="novarax-status-badge novarax-status-<?php echo $tenant->status; ?>">
                <?php echo esc_html(ucfirst($tenant->status)); ?>
            </span>
        </div>
        
        <div class="novarax-tenant-actions">
            <a href="<?php echo admin_url('admin.php?page=novarax-tenants-edit&tenant_id=' . $tenant->id); ?>" class="button">
                <?php _e('Edit', 'novarax-tenant-manager'); ?>
            </a>
            
            <?php if ($tenant->status === 'active') : ?>
                <button type="button" class="button novarax-suspend-tenant" data-tenant-id="<?php echo $tenant->id; ?>">
                    <?php _e('Suspend', 'novarax-tenant-manager'); ?>
                </button>
            <?php elseif ($tenant->status === 'suspended') : ?>
                <button type="button" class="button novarax-activate-tenant" data-tenant-id="<?php echo $tenant->id; ?>">
                    <?php _e('Activate', 'novarax-tenant-manager'); ?>
                </button>
            <?php endif; ?>
            
            <button type="button" class="button button-link-delete novarax-delete-tenant" data-tenant-id="<?php echo $tenant->id; ?>">
                <?php _e('Delete', 'novarax-tenant-manager'); ?>
            </button>
        </div>
    </div>
    
    <div class="novarax-tenant-grid">
        <!-- Tenant Information -->
        <div class="novarax-widget">
            <h2><?php _e('Tenant Information', 'novarax-tenant-manager'); ?></h2>
            <table class="widefat">
                <tr>
                    <th><?php _e('Account Name:', 'novarax-tenant-manager'); ?></th>
                    <td><?php echo esc_html($tenant->account_name); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Username:', 'novarax-tenant-manager'); ?></th>
                    <td><code><?php echo esc_html($tenant->tenant_username); ?></code></td>
                </tr>
                <tr>
                    <th><?php _e('Subdomain:', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <a href="https://<?php echo esc_attr($tenant->subdomain); ?>" target="_blank">
                            <?php echo esc_html($tenant->subdomain); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Company:', 'novarax-tenant-manager'); ?></th>
                    <td><?php echo esc_html($tenant->company_name ?: '—'); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Email:', 'novarax-tenant-manager'); ?></th>
                    <td><a href="mailto:<?php echo esc_attr($tenant->billing_email); ?>"><?php echo esc_html($tenant->billing_email); ?></a></td>
                </tr>
                <tr>
                    <th><?php _e('Phone:', 'novarax-tenant-manager'); ?></th>
                    <td><?php echo esc_html($tenant->phone_number ?: '—'); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Created:', 'novarax-tenant-manager'); ?></th>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($tenant->created_at)); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Last Login:', 'novarax-tenant-manager'); ?></th>
                    <td><?php echo $tenant->last_login ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($tenant->last_login)) : __('Never', 'novarax-tenant-manager'); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Resource Usage -->
        <div class="novarax-widget">
            <h2><?php _e('Resource Usage', 'novarax-tenant-manager'); ?></h2>
            <table class="widefat">
                <tr>
                    <th><?php _e('Storage Used:', 'novarax-tenant-manager'); ?></th>
                    <td>
                        <?php echo size_format($tenant->storage_used, 2); ?> / 
                        <?php echo size_format($tenant->storage_limit, 2); ?>
                        (<?php echo round(($tenant->storage_used / $tenant->storage_limit) * 100, 2); ?>%)
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Database Size:', 'novarax-tenant-manager'); ?></th>
                    <td><?php echo size_format($db_size, 2); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Database Name:', 'novarax-tenant-manager'); ?></th>
                    <td><code><?php echo esc_html($tenant->database_name); ?></code></td>
                </tr>
                <tr>
                    <th><?php _e('User Limit:', 'novarax-tenant-manager'); ?></th>
                    <td><?php echo $tenant->user_limit; ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>