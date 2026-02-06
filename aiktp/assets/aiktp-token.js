jQuery(document).ready(function ($) {
    // Copy token button
    $('#aiktp-copy-token').on('click', function () {
        var token = $('#aiktp-token-value').text().trim();

        // Modern way to copy to clipboard
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(token).then(function () {
                showMessage(aiktpTokenData.i18n.copied, 'success');
            }).catch(function () {
                fallbackCopy(token);
            });
        } else {
            fallbackCopy(token);
        }
    });

    // Fallback copy method for older browsers
    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            showMessage(aiktpTokenData.i18n.copied, 'success');
        } catch (err) {
            showMessage(aiktpTokenData.i18n.copyFailed, 'error');
        }

        document.body.removeChild(textarea);
    }

    // Regenerate token button
    $('#aiktp-regenerate-token').on('click', function () {
        if (!confirm(aiktpTokenData.i18n.confirmRegenerate)) {
            return;
        }

        var button = $(this);
        button.prop('disabled', true).text(aiktpTokenData.i18n.regenerating);

        $.ajax({
            url: aiktpTokenData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aiktp_regenerate_token',
                nonce: aiktpTokenData.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#aiktp-token-value').text(response.data.token);
                    showMessage(aiktpTokenData.i18n.regenerated, 'success');
                } else {
                    showMessage(aiktpTokenData.i18n.regenerateFailed, 'error');
                }
            },
            error: function () {
                showMessage(aiktpTokenData.i18n.regenerateFailed, 'error');
            },
            complete: function () {
                button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> ' + aiktpTokenData.i18n.regenerateButton);
            }
        });
    });

    // Show message function
    function showMessage(message, type) {
        var messageDiv = $('#aiktp-token-message');
        var className = type === 'success' ? 'notice notice-success' : 'notice notice-error';

        messageDiv
            .removeClass('notice-success notice-error')
            .addClass(className)
            .html('<p>' + message + '</p>')
            .slideDown()
            .delay(3000)
            .slideUp();
    }
});
