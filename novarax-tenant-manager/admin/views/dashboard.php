<?php
/**
 * Dashboard View
 * 
 * Main dashboard with statistics, quick actions, and overview widgets.
 *
 * @package NovaRax\TenantManager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get statistics
$tenant_ops = new NovaRax_Tenant_Operations();
$total_tenants = $tenant_ops->get_tenant_count();
$active_tenants = $tenant_ops->get_tenant_count(array('status' => 'active'));
$pending_tenants = $tenant_ops->get_tenant_count(array('status' => 'pending'));
$suspended_tenants = $tenant_ops->get_tenant_count(array('status' => 'suspended'));

// Get recent tenants
$recent_tenants = $tenant_ops->get_tenants(array(
    'limit' => 5,
    'orderby' => 'created_at',
    'order' => 'DESC',
));

// Get queue stats
$queue = new NovaRax_Provisioning_Queue();
$queue_stats = $queue->get_statistics();

// Get log stats
$log_stats = NovaRax_Logger::get_statistics();
?>

<div class="wrap novarax-dashboard">
    <h1 class="wp-heading-inline">
        <?php _e('NovaRax Dashboard', 'novarax-tenant-manager'); ?>
    </h1>
    
    <a href="<?php echo admin_url('admin.php?page=novarax-tenants-add'); ?>" class="page-title-action">
        <?php _e('Add New Tenant', 'novarax-tenant-manager'); ?>
    </a>
    
    <hr class="wp-header-end">
    
    <!-- Statistics Cards -->
    <div class="novarax-stats-grid">
        <!-- Total Tenants -->
        <div class="novarax-stat-card novarax-stat-primary">
            <div class="novarax-stat-icon">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="novarax-stat-content">
                <h3><?php echo number_format($total_tenants); ?></h3>
                <p><?php _e('Total Tenants', 'novarax-tenant-manager'); ?></p>
            </div>
            <a href="<?php echo admin_url('admin.php?page=novarax-tenants-list'); ?>" class="novarax-stat-link">
                <?php _e('View All', 'novarax-tenant-manager'); ?> →
            </a>
        </div>
        
        <!-- Active Tenants -->
        <div class="novarax-stat-card novarax-stat-success">
            <div class="novarax-stat-icon">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="novarax-stat-content">
                <h3><?php echo number_format($active_tenants); ?></h3>
                <p><?php _e('Active Tenants', 'novarax-tenant-manager'); ?></p>
            </div>
            <a href="<?php echo admin_url('admin.php?page=novarax-tenants-list&status=active'); ?>" class="novarax-stat-link">
                <?php _e('View Active', 'novarax-tenant-manager'); ?> →
            </a>
        </div>
        
        <!-- Pending Tenants -->
        <div class="novarax-stat-card novarax-stat-warning">
            <div class="novarax-stat-icon">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <div class="novarax-stat-content">
                <h3><?php echo number_format($pending_tenants); ?></h3>
                <p><?php _e('Pending', 'novarax-tenant-manager'); ?></p>
            </div>
            <a href="<?php echo admin_url('admin.php?page=novarax-tenants-list&status=pending'); ?>" class="novarax-stat-link">
                <?php _e('View Pending', 'novarax-tenant-manager'); ?> →
            </a>
        </div>
        
        <!-- Suspended Tenants -->
        <div class="novarax-stat-card novarax-stat-danger">
            <div class="novarax-stat-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="novarax-stat-content">
                <h3><?php echo number_format($suspended_tenants); ?></h3>
                <p><?php _e('Suspended', 'novarax-tenant-manager'); ?></p>
            </div>
            <a href="<?php echo admin_url('admin.php?page=novarax-tenants-list&status=suspended'); ?>" class="novarax-stat-link">
                <?php _e('View Suspended', 'novarax-tenant-manager'); ?> →
            </a>
        </div>
    </div>
    
    <!-- Two Column Layout -->
    <div class="novarax-dashboard-grid">
        <!-- Left Column -->
        <div class="novarax-dashboard-column">
            <!-- Recent Tenants -->
            <div class="novarax-widget">
                <div class="novarax-widget-header">
                    <h2><?php _e('Recent Tenants', 'novarax-tenant-manager'); ?></h2>
                    <a href="<?php echo admin_url('admin.php?page=novarax-tenants-list'); ?>" class="button button-small">
                        <?php _e('View All', 'novarax-tenant-manager'); ?>
                    </a>
                </div>
                <div class="novarax-widget-content">
                    <?php if (!empty($recent_tenants)) : ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Account', 'novarax-tenant-manager'); ?></th>
                                    <th><?php _e('Status', 'novarax-tenant-manager'); ?></th>
                                    <th><?php _e('Created', 'novarax-tenant-manager'); ?></th>
                                    <th><?php _e('Actions', 'novarax-tenant-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_tenants as $tenant) : ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($tenant->account_name); ?></strong><br>
                                            <small class="description"><?php echo esc_html($tenant->tenant_username); ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = 'novarax-status-' . $tenant->status;
                                            echo '<span class="novarax-status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($tenant->status)) . '</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo human_time_diff(strtotime($tenant->created_at), current_time('timestamp')) . ' ' . __('ago', 'novarax-tenant-manager'); ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo admin_url('admin.php?page=novarax-tenants-view&tenant_id=' . $tenant->id); ?>" class="button button-small">
                                                <?php _e('View', 'novarax-tenant-manager'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p class="description"><?php _e('No tenants yet.', 'novarax-tenant-manager'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Provisioning Queue -->
            <div class="novarax-widget">
                <div class="novarax-widget-header">
                    <h2><?php _e('Provisioning Queue', 'novarax-tenant-manager'); ?></h2>
                    <button type="button" class="button button-small" id="novarax-refresh-queue">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Refresh', 'novarax-tenant-manager'); ?>
                    </button>
                </div>
                <div class="novarax-widget-content">
                    <div class="novarax-queue-stats">
                        <div class="novarax-queue-stat">
                            <span class="label"><?php _e('Total in Queue:', 'novarax-tenant-manager'); ?></span>
                            <span class="value"><?php echo $queue_stats['total']; ?></span>
                        </div>
                        <div class="novarax-queue-stat">
                            <span class="label"><?php _e('Pending:', 'novarax-tenant-manager'); ?></span>
                            <span class="value"><?php echo $queue_stats['pending']; ?></span>
                        </div>
                        <div class="novarax-queue-stat">
                            <span class="label"><?php _e('Retrying:', 'novarax-tenant-manager'); ?></span>
                            <span class="value"><?php echo $queue_stats['retrying']; ?></span>
                        </div>
                        <div class="novarax-queue-stat">
                            <span class="label"><?php _e('Failed:', 'novarax-tenant-manager'); ?></span>
                            <span class="value"><?php echo $queue_stats['failed']; ?></span>
                        </div>
                    </div>
                    
                    <?php if ($queue_stats['is_processing']) : ?>
                        <div class="notice notice-info inline">
                            <p><?php _e('Queue is currently being processed...', 'novarax-tenant-manager'); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($queue_stats['total'] > 0 && !$queue_stats['is_processing']) : ?>
                        <button type="button" class="button button-primary" id="novarax-process-queue">
                            <?php _e('Process Queue Now', 'novarax-tenant-manager'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right Column -->
        <div class="novarax-dashboard-column">
            <!-- Quick Actions -->
            <div class="novarax-widget">
                <div class="novarax-widget-header">
                    <h2><?php _e('Quick Actions', 'novarax-tenant-manager'); ?></h2>
                </div>
                <div class="novarax-widget-content">
                    <div class="novarax-quick-actions">
                        <a href="<?php echo admin_url('admin.php?page=novarax-tenants-add'); ?>" class="novarax-quick-action">
                            <span class="dashicons dashicons-plus-alt"></span>
                            <span><?php _e('Add New Tenant', 'novarax-tenant-manager'); ?></span>
                        </a>
                        
                        <a href="<?php echo admin_url('admin.php?page=novarax-tenants-list'); ?>" class="novarax-quick-action">
                            <span class="dashicons dashicons-list-view"></span>
                            <span><?php _e('View All Tenants', 'novarax-tenant-manager'); ?></span>
                        </a>
                        
                        <a href="<?php echo admin_url('admin.php?page=novarax-tenants-modules'); ?>" class="novarax-quick-action">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <span><?php _e('Manage Modules', 'novarax-tenant-manager'); ?></span>
                        </a>
                        
                        <a href="<?php echo admin_url('admin.php?page=novarax-tenants-analytics'); ?>" class="novarax-quick-action">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <span><?php _e('View Analytics', 'novarax-tenant-manager'); ?></span>
                        </a>
                        
                        <a href="<?php echo admin_url('admin.php?page=novarax-tenants-settings'); ?>" class="novarax-quick-action">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <span><?php _e('Settings', 'novarax-tenant-manager'); ?></span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- System Status -->
            <div class="novarax-widget">
                <div class="novarax-widget-header">
                    <h2><?php _e('System Status', 'novarax-tenant-manager'); ?></h2>
                </div>
                <div class="novarax-widget-content">
                    <table class="novarax-system-status">
                        <tr>
                            <td><?php _e('WordPress Version:', 'novarax-tenant-manager'); ?></td>
                            <td><strong><?php echo get_bloginfo('version'); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('PHP Version:', 'novarax-tenant-manager'); ?></td>
                            <td><strong><?php echo PHP_VERSION; ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('MySQL Version:', 'novarax-tenant-manager'); ?></td>
                            <td><strong><?php echo $GLOBALS['wpdb']->db_version(); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('Plugin Version:', 'novarax-tenant-manager'); ?></td>
                            <td><strong><?php echo NOVARAX_TM_VERSION; ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('Total Logs:', 'novarax-tenant-manager'); ?></td>
                            <td>
                                <strong><?php echo number_format($log_stats['total']); ?></strong>
                                <a href="#" id="novarax-clean-logs" class="button button-small" style="margin-left:10px;">
                                    <?php _e('Clean Old Logs', 'novarax-tenant-manager'); ?>
                                </a>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Activity Chart -->
            <div class="novarax-widget">
                <div class="novarax-widget-header">
                    <h2><?php _e('Tenant Growth (Last 30 Days)', 'novarax-tenant-manager'); ?></h2>
                </div>
                <div class="novarax-widget-content">
                    <canvas id="novarax-growth-chart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Refresh queue button
    $('#novarax-refresh-queue').on('click', function() {
        location.reload();
    });
    
    // Process queue button
    $('#novarax-process-queue').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php _e('Processing...', 'novarax-tenant-manager'); ?>');
        
        $.post(ajaxurl, {
            action: 'novarax_process_queue',
            nonce: novaraxTM.nonce
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).text('<?php _e('Process Queue Now', 'novarax-tenant-manager'); ?>');
            }
        });
    });
    
    // Clean logs button
    $('#novarax-clean-logs').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('<?php _e('Clean logs older than 30 days?', 'novarax-tenant-manager'); ?>')) {
            return;
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'novarax_clean_logs',
            nonce: novaraxTM.nonce,
            days: 30
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false);
            }
        });
    });
    
    // Growth chart (example data - replace with real data)
    var ctx = document.getElementById('novarax-growth-chart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Day 1', 'Day 7', 'Day 14', 'Day 21', 'Day 30'],
                datasets: [{
                    label: '<?php _e('New Tenants', 'novarax-tenant-manager'); ?>',
                    data: [5, 12, 18, 25, <?php echo $total_tenants; ?>],
                    borderColor: '#0073aa',
                    backgroundColor: 'rgba(0, 115, 170, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
});
</script>