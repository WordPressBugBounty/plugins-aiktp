jQuery(document).ready(function ($) {
    'use strict';
    $('.wcai-generate-btn').on('click', function (e) {
        e.preventDefault();

        const $btn = $(this);
        const type = $btn.data('type');
        const $status = $('.wcai-status');
        const $message = $('.wcai-message');

        // Disable button
        $btn.prop('disabled', true);
        const originalText = $btn.html();
        $btn.html('<span class="dashicons dashicons-update-alt spin"></span> ' + aiktpzData.i18n.generating);

        // Show loading status
        $status.removeClass('error success').show();
        $message.text(aiktpzData.i18n.generating);

        // AJAX request
        $.ajax({
            url: aiktpzData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aiktpz_generate_content',
                nonce: aiktpzData.nonce,
                post_id: aiktpzData.postId,
                type: type
            },
            success: function (response) {
                if (response.success) {
                    $status.addClass('success');
                    $message.text(response.data.message);

                    // Update content in editor
                    if (type === 'description') {
                        // Kiểm tra loại editor
                        if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                            tinymce.get('content').setContent(response.data.content);
                        } else if ($('#content').length) {
                            $('#content').val(response.data.content);
                        }
                    } else if (type === 'short_description') {
                        // Update short description
                        if (typeof tinymce !== 'undefined' && tinymce.get('excerpt')) {
                            tinymce.get('excerpt').setContent(response.data.content);
                        } else if ($('#excerpt').length) {
                            $('#excerpt').val(response.data.content);
                        }
                    }

                    // Auto hide success message after 3 seconds
                    setTimeout(function () {
                        $status.fadeOut();
                    }, 1000);
                } else {
                    $status.addClass('error');
                    $message.html(response.data.message || aiktpzData.i18n.error);
                }
            },
            error: function (xhr, status, error) {
                $status.addClass('error');
                $message.text(aiktpzData.i18n.error + ' ' + error);
            },
            complete: function () {
                // Re-enable button
                $btn.prop('disabled', false);
                $btn.html(originalText);
            }
        });
    });

    // Settings page functionality
    $('.aiktp-tab').on('click', function () {
        var tabId = $(this).data('tab');

        $('.aiktp-tab').removeClass('active');
        $('.aiktp-tab-content').removeClass('active');

        $(this).addClass('active');
        $('#aiktp-tab-' + tabId).addClass('active');
    });

    $('#aiktp-connect-btn').on('click', function () {
        var apiKey = $('#aiktp_api_key').val().trim();
        var $btn = $(this);
        var $status = $('#aiktp-connect-status');

        if (!apiKey) {
            alert('Please enter your API key');
            return;
        }

        $btn.prop('disabled', true).text('Connecting...');
        $status.removeClass('connected not-connected').addClass('loading').text('Connecting...').show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aiktp_connect',
                api_key: apiKey,
                nonce: typeof aiktpSettings !== 'undefined' ? aiktpSettings.connectNonce : '',
            },
            success: function (response) {
                if (response.success) {
                    $status.removeClass('loading not-connected').addClass('connected').text('Connected to AIKTP');
                    $('#aiktp_api_key').val(response.data.api_key || apiKey);

                    // Show credit info section if it exists
                    if ($('#aiktp-credit-info').length === 0) {
                        // Reload page to show credit section
                        location.reload();
                    } else {
                        // Check credit after successful connection
                        checkCredit();
                    }
                } else {
                    $status.removeClass('loading connected').addClass('not-connected').text('Connection failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function () {
                $status.removeClass('loading connected').addClass('not-connected').text('Connection failed: Network error');
            },
            complete: function () {
                $btn.prop('disabled', false).text('Connect');
            }
        });
    });

    function checkCredit() {
        var apiKey = $('#aiktp_api_key').val().trim();
        if (!apiKey) {
            return;
        }

        $.ajax({
            url: 'https://aiktp.com/api/ai.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                task: 'checkMyCredit'
            }),
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + apiKey
            },
            success: function (response) {
                response = JSON.parse(response);
                $('#aiktp-credit-loading').hide();

                if (response && response.data && typeof response.data.credits !== 'undefined' && typeof response.data.posts !== 'undefined') {
                    $('#aiktp-credits-value').text(response.data.credits.toLocaleString());
                    $('#aiktp-posts-value').text(response.data.posts.toLocaleString());
                    $('#aiktp-credit-content').show();
                    $('#aiktp-credit-error').hide();
                } else {
                    $('#aiktp-credit-error').text('Unable to load credit information').show();
                }
            },
            error: function (xhr, status, error) {
                $('#aiktp-credit-loading').hide();
                var errorMsg = 'Failed to load credit information';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                $('#aiktp-credit-error').text(errorMsg).show();
            }
        });
    }

    // Check credit on page load if connected
    if (typeof aiktpSettings !== 'undefined' && aiktpSettings.isConnected) {
        $('#aiktp-connect-status').show();
        checkCredit();
    }
});