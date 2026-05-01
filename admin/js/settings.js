jQuery(document).ready(function($) {
    
    function togglePlatformFields() {
        var selected = $('#pbe_active_platform').val();
        $('.pbe-platform-fields').hide(); // Hide all
        $('#pbe_fields_' + selected).fadeIn(); // Show specific
        
        if (selected === 'ownerrez') {
            $('.pbe-default-widget-setting').hide();
            $('.pbe-ownerrez-global-setting').fadeIn();
        } else {
            $('.pbe-default-widget-setting').fadeIn();
            $('.pbe-ownerrez-global-setting').hide();
        }
    }
    
    // Bind change event
    $('#pbe_active_platform').on('change', function() {
        togglePlatformFields();
    });
    
    function toggleSyncSourceFields() {
        var selected = $('select[name="pbe_sync_source"]').val();
        if (selected === 'selected_ids') {
            $('#pbe_sync_property_ids_wrapper').fadeIn();
        } else {
            $('#pbe_sync_property_ids_wrapper').hide();
        }
    }

    $('select[name="pbe_sync_source"]').on('change', function() {
        toggleSyncSourceFields();
    });

    function toggleAvailSyncSourceFields() {
        var selected = $('select[name="pbe_avail_sync_source"]').val();
        if (selected === 'selected_ids') {
            $('#pbe_avail_sync_property_ids_wrapper').fadeIn();
        } else {
            $('#pbe_avail_sync_property_ids_wrapper').hide();
        }
    }

    $('select[name="pbe_avail_sync_source"]').on('change', function() {
        toggleAvailSyncSourceFields();
    });

    function toggleStripeKeys() {
        var mode = $('select[name="pbe_stripe_mode"]').val();
        $('.pbe-stripe-key-row').hide();
        $('.pbe-stripe-mode-' + mode).fadeIn();
    }

    $('select[name="pbe_stripe_mode"]').on('change', function() {
        toggleStripeKeys();
    });
    
    // Run on load
    togglePlatformFields();
    toggleSyncSourceFields();
    toggleAvailSyncSourceFields();
    toggleStripeKeys();

    // Trigger Manual Sync via AJAX (Auto-Loop Batching)
    $('#pbe_sync_now_btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $status = $('#pbe_sync_status');
        
        $btn.attr('disabled', 'disabled').text('Syncing...');
        $status.css('color', '#000').text('Importing properties in batches... Please wait.');
        
        function processBatch(offset) {
            $.ajax({
                url: pbe_ajax_obj.ajax_url,
                type: 'POST',
                data: {
                    action: 'pbe_manual_sync_properties',
                    security: pbe_ajax_obj.nonce,
                    offset: offset
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        $status.css('color', '#007ccc').text(data.message);
                        
                        if (data.done) {
                            $btn.removeAttr('disabled').text('Sync Properties Now');
                            $status.css('color', 'green').text('Sync Complete! ' + data.message);
                        } else {
                            // Automatically fetch the next batch
                            processBatch(data.new_offset);
                        }
                    } else {
                        $btn.removeAttr('disabled').text('Sync Properties Now');
                        $status.css('color', 'red').text('Error: ' + response.data);
                    }
                },
                error: function() {
                    $btn.removeAttr('disabled').text('Sync Properties Now');
                    $status.css('color', 'red').text('Server error occurred during sync.');
                }
            });
        }

        // Start from offset 0
        processBatch(0);
    });

    // Trigger Manual Calendar Sync via AJAX
    $('#pbe_avail_sync_now_btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $status = $('#pbe_avail_sync_status');
        var source = $('select[name="pbe_avail_sync_source"]').val();
        var specific_ids = $('input[name="pbe_avail_sync_property_ids"]').val();
        
        $btn.attr('disabled', 'disabled').text('Syncing Calendars...');
        $status.css('color', '#000').text('Fetching calendar APIs... Please wait.');
        
        function processCalendarBatch(offset) {
            $.ajax({
                url: pbe_ajax_obj.ajax_url,
                type: 'POST',
                data: {
                    action: 'pbe_manual_availability_sync',
                    security: pbe_ajax_obj.nonce,
                    sync_source: source,
                    specific_ids: specific_ids,
                    offset: offset
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        if (data.done) {
                            $btn.removeAttr('disabled').text('Sync Calendars Now');
                            $status.css('color', 'green').text('Success: ' + data.message);
                        } else {
                            $status.css('color', '#007ccc').text(data.message);
                            processCalendarBatch(data.new_offset);
                        }
                    } else {
                        $btn.removeAttr('disabled').text('Sync Calendars Now');
                        $status.css('color', 'red').text('Error: ' + response.data);
                    }
                },
                error: function() {
                    $btn.removeAttr('disabled').text('Sync Calendars Now');
                    $status.css('color', 'red').text('Server error occurred during sync.');
                }
            });
        }
        
        processCalendarBatch(0);
    });

    // Trigger Manual Review Sync via AJAX (Batch Loop)
    $('#pbe_sync_reviews_now_btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $status = $('#pbe_sync_reviews_status');
        
        $btn.attr('disabled', 'disabled').text('Syncing Reviews...');
        $status.css('color', '#000').text('Fetching reviews from platform... Please wait.');
        
        function processReviewBatch(offset) {
            $.ajax({
                url: pbe_ajax_obj.ajax_url,
                type: 'POST',
                data: {
                    action: 'pbe_manual_sync_reviews',
                    security: pbe_ajax_obj.nonce,
                    offset: offset
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        if (data.done) {
                            $btn.removeAttr('disabled').text('Sync All Reviews Now');
                            $status.css('color', 'green').text('Success: Review sync complete!');
                            // Refresh page after a delay to show new last-sync date
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            $status.css('color', '#007ccc').text(data.message);
                            processReviewBatch(data.new_offset);
                        }
                    } else {
                        $btn.removeAttr('disabled').text('Sync All Reviews Now');
                        $status.css('color', 'red').text('Error: ' + response.data);
                    }
                },
                error: function() {
                    $btn.removeAttr('disabled').text('Sync All Reviews Now');
                    $status.css('color', 'red').text('Server error occurred during sync.');
                }
            });
        }
        
        processReviewBatch(0);
    });

    // Trigger Auto setup
    $('#pbe_run_setup_btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#pbe_setup_status');
        
        $btn.attr('disabled', 'disabled').text('Running Setup...');
        
        $.ajax({
            url: pbe_ajax_obj.ajax_url,
            type: 'POST',
            data: {
                action: 'pbe_manual_run_setup',
                security: pbe_ajax_obj.nonce
            },
            success: function(response) {
                $btn.removeAttr('disabled').text('Run Auto-Setup Now');
                if(response.success) {
                    $status.css('color', 'green').text('Success: ' + response.data);
                } else {
                    $status.css('color', 'red').text('Error: ' + response.data);
                }
            }
        });
    });

    window.pbeTabSwitch = function(tabId) {
        console.log('Switching to tab:', tabId);
        $('.nav-tab').removeClass('nav-tab-active');
        $(`.nav-tab[data-tab="${tabId}"]`).addClass('nav-tab-active');
        $('.pbe-tab-content').hide();
        $('#pbe-tab-' + tabId).show();
        localStorage.setItem('pbe_active_tab', tabId);
    };

    $(document).on('click', '.nav-tab', function(e) {
        e.preventDefault();
        var tabId = $(this).data('tab');
        window.pbeTabSwitch(tabId);
    });

    // Restore last active tab
    var lastTab = localStorage.getItem('pbe_active_tab') || 'general';
    window.pbeTabSwitch(lastTab);

});
