/**
 * Gemini-Kimi SEO Optimizer - Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Polling intervals storage
    var pollingIntervals = {};
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        initMetaBox();
        initPolling();
    });
    
    /**
     * Initialize meta box interactions
     */
    function initMetaBox() {
        // Start test button
        $(document).on('click', '.gkso-start-test', function(e) {
            e.preventDefault();
            var postId = $(this).data('post-id');
            handleStartTest(postId);
        });
        
        // Stop test button
        $(document).on('click', '.gkso-stop-test', function(e) {
            e.preventDefault();
            var postId = $(this).data('post-id');
            handleEarlyTerminate(postId);
        });
    }
    
    /**
     * Initialize polling for testing posts
     */
    function initPolling() {
        // Check if we're on a post edit screen with Testing status
        var $metaBox = $('#gkso_seo_status');
        if ($metaBox.length) {
            var $statusBadge = $metaBox.find('.gkso-status-badge');
            if ($statusBadge.length && $statusBadge.text().trim() === 'Testing') {
                var postId = $metaBox.find('.gkso-start-test').data('post-id');
                if (postId) {
                    startPolling(postId);
                }
            }
        }
    }
    
    /**
     * Start polling for test status
     * 
     * @param {number} postId The post ID
     */
    function startPolling(postId) {
        // Clear existing interval if any
        if (pollingIntervals[postId]) {
            clearInterval(pollingIntervals[postId]);
        }
        
        // Poll every 30 seconds
        pollingIntervals[postId] = setInterval(function() {
            pollTestStatus(postId);
        }, 30000);
        
        // Initial poll
        pollTestStatus(postId);
    }
    
    /**
     * Stop polling for test status
     * 
     * @param {number} postId The post ID
     */
    function stopPolling(postId) {
        if (pollingIntervals[postId]) {
            clearInterval(pollingIntervals[postId]);
            delete pollingIntervals[postId];
        }
    }
    
    /**
     * Poll test status via AJAX
     * 
     * @param {number} postId The post ID
     */
    function pollTestStatus(postId) {
        $.ajax({
            url: gksoAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'gkso_get_test_status',
                post_id: postId,
                nonce: gksoAdmin.ajaxNonce
            },
            success: function(response) {
                if (response.success) {
                    updateMetaBoxDisplay(postId, response.data);
                    
                    // Stop polling if test is complete
                    if (response.data.status !== 'Testing') {
                        stopPolling(postId);
                    }
                }
            },
            error: function() {
                console.error('GKSO: Failed to poll test status');
            }
        });
    }
    
    /**
     * Update meta box display with new status
     * 
     * @param {number} postId The post ID
     * @param {object} data   The status data
     */
    function updateMetaBoxDisplay(postId, data) {
        var $metaBox = $('#gkso_seo_status');
        if (!$metaBox.length) return;
        
        // Update status badge
        var $statusBadge = $metaBox.find('.gkso-status-badge');
        if ($statusBadge.length) {
            $statusBadge.text(data.status);
            
            // Update color
            var colors = {
                'Baseline': '#6c757d',
                'Testing': '#ffc107',
                'Optimized': '#28a745',
                'Failed': '#dc3545'
            };
            $statusBadge.css('background-color', colors[data.status] || '#6c757d');
        }
        
        // Update progress bar if testing
        if (data.status === 'Testing' && data.progress_percent !== undefined) {
            var $progressFill = $metaBox.find('.gkso-progress-fill');
            if ($progressFill.length) {
                $progressFill.css('width', data.progress_percent + '%');
            }
            
            var $progressText = $metaBox.find('.gkso-progress-text');
            if ($progressText.length) {
                $progressText.text(data.progress_percent + '% - Est. completion: ' + 
                    new Date(data.estimated_completion).toLocaleDateString());
            }
        }
        
        // Reload page if status changed from Testing to something else
        if (data.status !== 'Testing' && $metaBox.find('.gkso-stop-test').length) {
            location.reload();
        }
    }
    
    /**
     * Handle start test button click
     * 
     * @param {number} postId The post ID
     */
    function handleStartTest(postId) {
        if (!confirm(gksoAdmin.strings.confirmStart)) {
            return;
        }
        
        showSpinner(postId, gksoAdmin.strings.starting);
        
        $.ajax({
            url: gksoAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'gkso_start_test',
                post_id: postId,
                nonce: gksoAdmin.ajaxNonce
            },
            success: function(response) {
                hideSpinner(postId);
                
                if (response.success) {
                    showMessage(postId, response.data.message, 'success');
                    
                    // Start polling for updates
                    startPolling(postId);
                    
                    // Reload page after short delay
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showMessage(postId, response.data.message || gksoAdmin.strings.error, 'error');
                }
            },
            error: function(xhr, status, error) {
                hideSpinner(postId);
                showMessage(postId, gksoAdmin.strings.error + ': ' + error, 'error');
            }
        });
    }
    
    /**
     * Handle early terminate button click
     * 
     * @param {number} postId The post ID
     */
    function handleEarlyTerminate(postId) {
        if (!confirm(gksoAdmin.strings.confirmTerminate)) {
            return;
        }
        
        showSpinner(postId, gksoAdmin.strings.stopping);
        
        $.ajax({
            url: gksoAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'gkso_early_terminate',
                post_id: postId,
                reason: 'Manually terminated by user',
                nonce: gksoAdmin.ajaxNonce
            },
            success: function(response) {
                hideSpinner(postId);
                
                if (response.success) {
                    showMessage(postId, response.data.message, 'success');
                    
                    // Stop polling
                    stopPolling(postId);
                    
                    // Reload page after short delay
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showMessage(postId, response.data.message || gksoAdmin.strings.error, 'error');
                }
            },
            error: function(xhr, status, error) {
                hideSpinner(postId);
                showMessage(postId, gksoAdmin.strings.error + ': ' + error, 'error');
            }
        });
    }
    
    /**
     * Show spinner
     * 
     * @param {number} postId The post ID
     * @param {string} text   The spinner text
     */
    function showSpinner(postId, text) {
        var $metaBox = $('#gkso_seo_status');
        var $spinner = $metaBox.find('.gkso-spinner');
        var $spinnerText = $metaBox.find('.gkso-spinner-text');
        
        $spinnerText.text(text);
        $spinner.show();
        
        // Disable buttons
        $metaBox.find('.button').prop('disabled', true);
    }
    
    /**
     * Hide spinner
     * 
     * @param {number} postId The post ID
     */
    function hideSpinner(postId) {
        var $metaBox = $('#gkso_seo_status');
        var $spinner = $metaBox.find('.gkso-spinner');
        
        $spinner.hide();
        
        // Enable buttons
        $metaBox.find('.button').prop('disabled', false);
    }
    
    /**
     * Show message
     * 
     * @param {number} postId The post ID
     * @param {string} message The message
     * @param {string} type    The message type (success/error)
     */
    function showMessage(postId, message, type) {
        var $metaBox = $('#gkso_seo_status');
        var $message = $metaBox.find('.gkso-message');
        
        $message
            .removeClass('gkso-success gkso-error')
            .addClass('gkso-' + type)
            .text(message)
            .show();
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $message.fadeOut();
        }, 5000);
    }
    
    // Expose functions for external use
    window.GKSOAdmin = {
        pollTestStatus: pollTestStatus,
        handleStartTest: handleStartTest,
        handleEarlyTerminate: handleEarlyTerminate,
        startPolling: startPolling,
        stopPolling: stopPolling
    };
    
})(jQuery);
