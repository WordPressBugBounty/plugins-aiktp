jQuery(document).ready(function ($) {
    'use strict';

    // Check if bulk generation was triggered
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('aiktpz_bulk_generate') === '1') {
        const productCount = parseInt(urlParams.get('aiktpz_product_count') || '0');

        if (productCount > 0) {
            // Get product IDs from server
            $.ajax({
                url: aiktpzBulkData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aiktpz_get_bulk_products',
                    nonce: aiktpzBulkData.nonce
                },
                success: function (response) {
                    if (response.success && response.data.product_ids) {
                        processBulkGeneration(response.data.product_ids);
                    }
                }
            });
        }
    }

    /**
     * Process bulk generation for products
     */
    function processBulkGeneration(productIds) {
        let currentIndex = 0;
        let successCount = 0;
        let errorCount = 0;
        let stopped = false;
        const total = productIds.length;

        const $notice = $('#wcai-bulk-notice');
        const $status = $('#wcai-bulk-status');
        const $progressBar = $('#wcai-progress-bar');
        const $progressText = $('#wcai-progress-text');

        // Update initial status
        updateStatus(aiktpzBulkData.i18n.generating);

        // Process products one by one
        function processNext() {
            // Stop if flagged
            if (stopped) {
                return;
            }

            if (currentIndex >= total) {
                // All done
                completeGeneration(successCount, errorCount, total);
                return;
            }

            const productId = productIds[currentIndex];
            currentIndex++;

            // Update progress
            $progressBar.val(currentIndex);
            $progressText.text(`${currentIndex} ${aiktpzBulkData.i18n.of} ${total}`);

            // Generate description for this product
            $.ajax({
                url: aiktpzBulkData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aiktpz_bulk_generate',
                    nonce: aiktpzBulkData.nonce,
                    post_id: productId
                },
                success: function (response) {
                    if (response.success) {
                        successCount++;
                        updateStatus(`${aiktpzBulkData.i18n.generating} (${successCount} ${aiktpzBulkData.i18n.success}, ${errorCount} errors)`);
                    } else {
                        errorCount++;
                        console.error('Error generating description:', response.data.message);

                        // Check if error is about insufficient credits
                        if (response.data && response.data.not_enough_credits) {
                            // Stop processing and show credit error
                            stopped = true;
                            stopBulkGeneration(response.data.message);
                            return;
                        }

                        updateStatus(`${aiktpzBulkData.i18n.generating} (${successCount} ${aiktpzBulkData.i18n.success}, ${errorCount} errors)`);
                    }
                },
                error: function (xhr, status, error) {
                    errorCount++;
                    console.error('AJAX error:', error);
                    updateStatus(`${aiktpzBulkData.i18n.generating} (${successCount} ${aiktpzBulkData.i18n.success}, ${errorCount} errors)`);
                },
                complete: function () {
                    // Process next product after a short delay (only if not stopped)
                    if (!stopped) {
                        setTimeout(processNext, 500);
                    }
                }
            });
        }

        // Start processing
        processNext();
    }

    /**
     * Update status message
     */
    function updateStatus(message) {
        $('#wcai-bulk-status').html('<span class="dashicons dashicons-update-alt spin"></span> ' + message);
    }

    /**
     * Stop bulk generation due to insufficient credits
     */
    function stopBulkGeneration(errorMessage) {
        const $notice = $('#wcai-bulk-notice');
        $notice.removeClass('notice-info').addClass('notice-error');
        $('#wcai-bulk-status').html(
            '<span class="dashicons dashicons-warning"></span> ' +
            '<strong>' + errorMessage + '</strong><br>' +
            '<a href="https://aiktp.com/pricing" target="_blank" style="color: #0055FF; text-decoration: underline; font-weight: 500;">' +
            'Purchase more credits at aiktp.com/pricing</a>'
        );

        // Remove URL parameters after 5 seconds
        setTimeout(function () {
            const newUrl = window.location.pathname + '?post_type=product';
            window.location.href = newUrl;
        }, 5000);
    }

    /**
     * Complete generation and show final results
     */
    function completeGeneration(successCount, errorCount, total) {
        const $notice = $('#wcai-bulk-notice');

        if (errorCount === 0) {
            $notice.removeClass('notice-info').addClass('notice-success');
            $('#wcai-bulk-status').html(
                '<span class="dashicons dashicons-yes-alt"></span> ' +
                `${aiktpzBulkData.i18n.completed}! Successfully generated ${successCount} descriptions.`
            );
        } else {
            $notice.removeClass('notice-info').addClass('notice-warning');
            $('#wcai-bulk-status').html(
                '<span class="dashicons dashicons-warning"></span> ' +
                `${aiktpzBulkData.i18n.completed}! Successfully generated ${successCount} descriptions. ${errorCount} errors occurred.`
            );
        }

        // Remove URL parameters and reload page after 3 seconds
        setTimeout(function () {
            const newUrl = window.location.pathname + '?post_type=product';
            window.location.href = newUrl;
        }, 3000);
    }


});

