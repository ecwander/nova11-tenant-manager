/**
 * NovaRax Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        NovaRaxAdmin.init();
    });
    
    var NovaRaxAdmin = {
        
        /**
         * Initialize all admin functionality
         */
        init: function() {
            this.bindEvents();
            this.initColorPicker();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Activate tenant
            $(document).on('click', '.novarax-activate-tenant', this.activateTenant);
            
            // Suspend tenant
            $(document).on('click', '.novarax-suspend-tenant', this.suspendTenant);
            
            // Delete tenant
            $(document).on('click', '.novarax-delete-tenant', this.deleteTenant);
            
            // Provision tenant
            $(document).on('click', '.novarax-provision-tenant', this.provisionTenant);
            
            // Export CSV
            $(document).on('click', '#novarax-export-csv', this.exportCSV);
            
            // Refresh list
            $(document).on('click', '#novarax-refresh-list', function() {
                location.reload();
            });
        },
        
        /**
         * Initialize color picker
         */
        initColorPicker: function() {
            if ($.fn.wpColorPicker) {
                $('.novarax-color-picker').wpColorPicker();
            }
        },
        
        /**
         * Activate tenant
         */
        activateTenant: function(e) {
            e.preventDefault();
            
            if (!confirm(novaraxTM.strings.confirmActivate)) {
                return;
            }
            
            var $btn = $(this);
            var tenantId = $btn.data('tenant-id');
            
            $btn.prop('disabled', true);
            
            $.post(ajaxurl, {
                action: 'novarax_activate_tenant',
                nonce: novaraxTM.nonce,
                tenant_id: tenantId
            }, function(response) {
                if (response.success) {
                    NovaRaxAdmin.showNotice('success', response.data.message);
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    NovaRaxAdmin.showNotice('error', response.data.message);
                    $btn.prop('disabled', false);
                }
            });
        },
        
        /**
         * Suspend tenant
         */
        suspendTenant: function(e) {
            e.preventDefault();
            
            if (!confirm(novaraxTM.strings.confirmSuspend)) {
                return;
            }
            
            var $btn = $(this);
            var tenantId = $btn.data('tenant-id');
            
            $btn.prop('disabled', true);
            
            $.post(ajaxurl, {
                action: 'novarax_suspend_tenant',
                nonce: novaraxTM.nonce,
                tenant_id: tenantId
            }, function(response) {
                if (response.success) {
                    NovaRaxAdmin.showNotice('success', response.data.message);
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    NovaRaxAdmin.showNotice('error', response.data.message);
                    $btn.prop('disabled', false);
                }
            });
        },
        
        /**
         * Delete tenant
         */
        deleteTenant: function(e) {
            e.preventDefault();
            
            if (!confirm(novaraxTM.strings.confirmDelete)) {
                return;
            }
            
            var hardDelete = confirm('Permanently delete tenant and all data? (This cannot be undone!)');
            
            var $btn = $(this);
            var tenantId = $btn.data('tenant-id');
            
            $btn.prop('disabled', true);
            
            $.post(ajaxurl, {
                action: 'novarax_delete_tenant',
                nonce: novaraxTM.nonce,
                tenant_id: tenantId,
                hard_delete: hardDelete
            }, function(response) {
                if (response.success) {
                    NovaRaxAdmin.showNotice('success', response.data.message);
                    setTimeout(function() {
                        window.location.href = adminUrl + 'admin.php?page=novarax-tenants-list';
                    }, 1500);
                } else {
                    NovaRaxAdmin.showNotice('error', response.data.message);
                    $btn.prop('disabled', false);
                }
            });
        },
        
        /**
         * Provision tenant
         */
        provisionTenant: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var tenantId = $btn.data('tenant-id');
            
            $btn.prop('disabled', true).text(novaraxTM.strings.provisioning);
            
            $.post(ajaxurl, {
                action: 'novarax_provision_tenant',
                nonce: novaraxTM.nonce,
                tenant_id: tenantId
            }, function(response) {
                if (response.success) {
                    NovaRaxAdmin.showNotice('success', response.data.message);
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    NovaRaxAdmin.showNotice('error', response.data.message);
                    $btn.prop('disabled', false).text('Provision Now');
                }
            });
        },
        
        /**
         * Export tenants to CSV
         */
        exportCSV: function(e) {
            e.preventDefault();
            
            var status = $('input[name="status"]').val() || 'all';
            
            $.post(ajaxurl, {
                action: 'novarax_export_tenants',
                nonce: novaraxTM.nonce,
                status: status
            }, function(response) {
                if (response.success) {
                    // Download file
                    window.location.href = response.data.download_url;
                    NovaRaxAdmin.showNotice('success', response.data.count + ' tenants exported');
                } else {
                    NovaRaxAdmin.showNotice('error', response.data.message);
                }
            });
        },
        
        /**
         * Show admin notice
         */
        showNotice: function(type, message) {
            var noticeClass = 'notice-' + type;
            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
            
            $('.wrap > h1').after($notice);
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };
    
})(jQuery);