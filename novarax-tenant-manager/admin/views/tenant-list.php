<?php
if (!defined('ABSPATH')) exit;

// Create instance of list table
$list_table = new NovaRax_Tenant_List_Table();
$list_table->prepare_items();
$list_table->process_bulk_action();
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('All Tenants', 'novarax-tenant-manager'); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=novarax-tenants-add'); ?>" class="page-title-action">
        <?php _e('Add New', 'novarax-tenant-manager'); ?>
    </a>
    
    <hr class="wp-header-end">
    
    <form method="get">
        <input type="hidden" name="page" value="novarax-tenants-list">
        <?php
        $list_table->views();
        $list_table->search_box(__('Search Tenants', 'novarax-tenant-manager'), 'tenant');
        $list_table->display();
        ?>
    </form>
</div>