/**
 * Tenant Form JavaScript
 * Real-time validation and helpers
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        TenantForm.init();
    });
    
    var TenantForm = {
        
        /**
         * Initialize form functionality
         */
        init: function() {
            this.bindEvents();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Username availability check
            $('#username').on('blur', this.checkUsername);
            $('#username').on('input', this.updateSubdomainPreview);
            
            // Email availability check
            $('#email').on('blur', this.checkEmail);
            
            // Generate password
            $('#generate-password').on('click', this.generatePassword);
            
            // Form validation
            $('.novarax-tenant-form').on('submit', this.validateForm);
        },
        
        /**
         * Check username availability
         */
        checkUsername: function() {
            var username = $(this).val().trim();
            
            if (!username || username.length < 3) {
                return;
            }
            
            var $feedback = $('#username-feedback');
            $feedback.removeClass('available unavailable')
                     .html('<span class="spinner is-active"></span> ' + novaraxTM.strings.checking);
            
            $.post(ajaxurl, {
                action: 'novarax_check_username',
                nonce: novaraxTM.nonce,
                username: username
            }, function(response) {
                if (response.success) {
                    $feedback.addClass('available')
                             .html('✓ ' + novaraxTM.strings.available);
                } else {
                    $feedback.addClass('unavailable')
                             .html('✗ ' + response.data.message);
                }
            });
        },
        
        /**
         * Update subdomain preview
         */
        updateSubdomainPreview: function() {
            var username = $(this).val().trim().toLowerCase();
            var suffix = '<?php echo get_option("novarax_tm_subdomain_suffix", ".app.novarax.ae"); ?>';
            
            // Sanitize username
            username = username.replace(/[^a-z0-9-]/g, '');
            
            $('#subdomain-preview').text(username + suffix);
        },
        
        /**
         * Check email availability
         */
        checkEmail: function() {
            var email = $(this).val().trim();
            
            if (!email) {
                return;
            }
            
            var $feedback = $('#email-feedback');
            $feedback.removeClass('available unavailable')
                     .html('<span class="spinner is-active"></span> ' + novaraxTM.strings.checking);
            
            $.post(ajaxurl, {
                action: 'novarax_check_email',
                nonce: novaraxTM.nonce,
                email: email
            }, function(response) {
                if (response.success) {
                    $feedback.addClass('available')
                             .html('✓ ' + novaraxTM.strings.available);
                } else {
                    $feedback.addClass('unavailable')
                             .html('✗ ' + response.data.message);
                }
            });
        },
        
        /**
         * Generate secure password
         */
        generatePassword: function(e) {
            e.preventDefault();
            
            var length = 16;
            var charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            var password = '';
            
            for (var i = 0; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            
            $('#password').val(password).attr('type', 'text');
            
            // Show password for 5 seconds then hide
            setTimeout(function() {
                $('#password').attr('type', 'password');
            }, 5000);
        },
        
        /**
         * Validate form before submission
         */
        validateForm: function(e) {
            var isValid = true;
            var errors = [];
            
            // Username validation
            var username = $('#username').val().trim();
            if (username.length < 3) {
                errors.push('Username must be at least 3 characters');
                isValid = false;
            }
            
            // Email validation
            var email = $('#email').val().trim();
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                errors.push('Please enter a valid email address');
                isValid = false;
            }
            
            // Password validation
            var password = $('#password').val();
            if (password.length < 12) {
                errors.push('Password must be at least 12 characters');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
            }
            
            return isValid;
        }
    };
    
})(jQuery);