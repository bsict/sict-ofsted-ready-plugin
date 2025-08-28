jQuery(document).ready(function($) {
    'use strict';
    
    const sictOr = {
        init: function() {
            this.bindEvents();
            this.setupFormValidation();
        },
        
        bindEvents: function() {
            // Generate content button
            $(document).on('click', '#generate_btn', this.generateContent.bind(this));
            
            // Save content
            $(document).on('click', '#save_content', this.saveContent.bind(this));
            
            // Copy content
            $(document).on('click', '#copy_content', this.copyContent.bind(this));
            
            // Export dropdown
            $(document).on('click', '.dropdown-toggle', this.toggleExportMenu.bind(this));
            
            // Export options
            $(document).on('click', '.export-option', this.handleExport.bind(this));
            
            // View saved content from history
            $(document).on('click', '.view-content', this.loadContentFromHistory.bind(this));
            
            // Close export menu when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.sict-or-export-dropdown').length) {
                    $('.sict-or-export-menu').hide();
                }
            });
            
            // Form input handlers
            $('select, textarea').on('change keyup', function() {
                if ($(this).val()) {
                    $(this).removeClass('error');
                }
            });
        },
        
        setupFormValidation: function() {
            // Basic form validation
            $('#sict-or-generator-form').on('submit', function(e) {
                e.preventDefault();
                return false;
            });
        },
        
        generateContent: function() {
            const $button = $('#generate_btn');
            const $loading = $('#loading');
            const $form = $('#sict-or-generator-form');
            
            // Validate form
            let valid = true;
            const policyType = $('#policy_type').val();
            
            if (!policyType) {
                $('#policy_type').addClass('error');
                alert(sict_or_ajax.strings.error + ' - Please select a policy type.');
                return;
            } else {
                $('#policy_type').removeClass('error');
            }
            
            // Show loading state
            $button.prop('disabled', true);
            $loading.show();
            
            // Collect form data
            const data = {
                action: 'sict_generate_policy',
                nonce: sict_or_ajax.nonce,
                policy_type: policyType,
                output_format: $('#output_format').val(),
                complexity_level: $('#complexity_level').val(),
                additional_context: $('#additional_context').val()
            };
            
            $.post(sict_or_ajax.ajax_url, data, this.handleGenerateResponse.bind(this))
                .fail(this.handleAjaxError.bind(this))
                .always(function() {
                    $button.prop('disabled', false);
                    $loading.hide();
                });
        },
        
        handleGenerateResponse: function(response) {
            if (response.success) {
                $('#generated_policy_type').text($('option:selected', '#policy_type').text());
                $('#generation_time').text('Generated on ' + new Date().toLocaleString());
                $('#generated_content').html(this.formatContent(response.data.content));
                
                // Make sure the output section is visible
                $('#output_section').show();
                
                // Scroll to output
                $('html, body').animate({
                    scrollTop: $('#output_section').offset().top - 100
                }, 500);
            } else {
                alert(sict_or_ajax.strings.error + ': ' + response.data);
            }
        },
        
        formatContent: function(content) {
            // Basic formatting for line breaks
            return content.replace(/\n/g, '<br>');
        },
        
        saveContent: function() {
            const $button = $(this);
            const policyType = $('#policy_type').val();
            const content = $('#generated_content').html();
            
            if (!content) {
                alert('No content to save.');
                return;
            }
            
            $button.prop('disabled', true).addClass('updating-message');
            
            const data = {
                action: 'sict_save_content',
                nonce: sict_or_ajax.nonce,
                policy_type: policyType,
                content: content
            };
            
            $.post(sict_or_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    alert(sict_or_ajax.strings.saved);
                } else {
                    alert('Error saving content: ' + response.data);
                }
            })
            .fail(this.handleAjaxError.bind(this))
            .always(function() {
                $button.prop('disabled', false).removeClass('updating-message');
            });
        },
        
        copyContent: function() {
            const content = $('#generated_content').text();
            const $button = $(this);
            
            navigator.clipboard.writeText(content).then(function() {
                const originalText = $button.text();
                $button.text(sict_or_ajax.strings.copied);
                setTimeout(function() {
                    $button.text(originalText);
                }, 2000);
            }).catch(function(err) {
                console.error('Could not copy text: ', err);
                alert('Failed to copy content. Please try again.');
            });
        },
        
        toggleExportMenu: function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('.sict-or-export-menu').not($(this).siblings('.sict-or-export-menu')).hide();
            $(this).siblings('.sict-or-export-menu').toggle();
        },
        
        handleExport: function(e) {
            e.preventDefault();
            const format = $(e.currentTarget).data('format');
            const action = $(e.currentTarget).data('action') || 'export';
            const content = $('#generated_content').html();
            const policyType = $('#policy_type').val();
            
            if (!content) {
                alert('No content to export.');
                return;
            }
            
            // Hide the menu
            $(e.currentTarget).closest('.sict-or-export-menu').hide();
            
            // Show loading state
            const $exportBtn = $('#export_content');
            $exportBtn.prop('disabled', true).addClass('updating-message');
            
            const data = {
                action: 'sict_' + action,
                nonce: sict_or_ajax.nonce,
                content: content,
                policy_type: policyType,
                format: format
            };
            
            $.post(sict_or_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    if (format === 'pdf' && response.data && response.data.url) {
                        // For PDF, we need to open the HTML file for printing
                        const newWindow = window.open(response.data.url, '_blank');
                        if (newWindow) {
                            newWindow.focus();
                            setTimeout(function() {
                                newWindow.print();
                            }, 1000);
                        }
                    } else {
                        alert(sict_or_ajax.strings.export_success);
                    }
                } else {
                    alert(response.data || sict_or_ajax.strings.export_error);
                }
            })
            .fail(function(xhr, status, error) {
                console.error('AJAX Error:', error);
                alert(sict_or_ajax.strings.export_error + ': ' + error);
            })
            .always(function() {
                $exportBtn.prop('disabled', false).removeClass('updating-message');
            });
        },
        
        loadContentFromHistory: function(e) {
            e.preventDefault();
            const contentId = $(e.currentTarget).data('content-id');
            const policyType = $(e.currentTarget).data('policy-type');
            
            const $button = $(e.currentTarget);
            $button.prop('disabled', true).addClass('updating-message');
            
            const data = {
                action: 'sict_load_content',
                nonce: sict_or_ajax.nonce,
                content_id: contentId
            };
            
            $.post(sict_or_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    // Populate the output section
                    $('#generated_policy_type').text(policyType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
                    $('#generation_time').text('Loaded from history on ' + new Date().toLocaleString());
                    $('#generated_content').html(response.data.content);
                    
                    // Make sure the output section is visible
                    $('#output_section').show();
                    
                    // Scroll to output
                    $('html, body').animate({
                        scrollTop: $('#output_section').offset().top - 100
                    }, 500);
                } else {
                    alert('Error loading content: ' + response.data);
                }
            })
            .fail(this.handleAjaxError.bind(this))
            .always(function() {
                $button.prop('disabled', false).removeClass('updating-message');
            });
        },
        
        createWordPressPost: function(content, policyType) {
            const data = {
                action: 'sict_create_post',
                nonce: sict_or_ajax.nonce,
                content: content,
                policy_type: policyType
            };
            
            $.post(sict_or_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    alert(sict_or_ajax.strings.post_created + '\n\nYou can edit the post here: ' + response.data.edit_url);
                } else {
                    alert('Error creating WordPress post: ' + response.data);
                }
            })
            .fail(this.handleAjaxError.bind(this));
        },
        
        handleAjaxError: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            alert(sict_or_ajax.strings.error);
        }
    };
    
    // Initialize
    sictOr.init();
});