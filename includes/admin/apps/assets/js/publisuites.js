/**
 * Publisuites Panel JavaScript
 *
 * Handles clipboard copy, form loading states, and confirmation dialogs.
 * No business logic — UI micro-interactions only.
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // --- Clipboard copy ---
        $('.contai-ps-copy-btn').on('click', async function(e) {
            e.preventDefault();

            var textToCopy = $(this).data('copy');
            var $button    = $(this);
            var $icon      = $button.find('.dashicons');

            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(textToCopy);
                    showCopySuccess($button, $icon);
                } else {
                    copyToClipboardFallback(textToCopy);
                    showCopySuccess($button, $icon);
                }
            } catch (err) {
                showCopyError($button, $icon);
            }
        });

        function showCopySuccess($button, $icon) {
            $icon.removeClass('dashicons-admin-page').addClass('dashicons-yes');
            $button.addClass('copied');

            setTimeout(function() {
                $icon.removeClass('dashicons-yes').addClass('dashicons-admin-page');
                $button.removeClass('copied');
            }, 2000);
        }

        function showCopyError($button, $icon) {
            $icon.removeClass('dashicons-admin-page').addClass('dashicons-no');
            $button.addClass('error');

            setTimeout(function() {
                $icon.removeClass('dashicons-no').addClass('dashicons-admin-page');
                $button.removeClass('error');
            }, 2000);
        }

        function copyToClipboardFallback(text) {
            var $temp = $('<textarea>');
            $temp.val(text).css({ position: 'absolute', left: '-9999px', top: '-9999px' });
            $('body').append($temp);
            $temp.select();
            document.execCommand('copy');
            $temp.remove();
        }

        // --- Form loading states ---
        // Delay the disable so the browser collects form data (including the
        // submit button name) BEFORE we disable the button. Without this delay
        // the button name is excluded from POST and the handler ignores the request.
        $('.contai-ps-form, .contai-ps-form--inline').on('submit', function() {
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');

            setTimeout(function() {
                $form.addClass('is-loading');
                $button.prop('disabled', true);
            }, 0);
        });

        // --- Confirmation dialogs ---
        $('form[data-confirm]').on('submit', function(e) {
            var message = $(this).data('confirm');
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });

        // --- Auto-dismiss flash messages ---
        var $flash = $('.contai-ps-flash');
        if ($flash.length) {
            setTimeout(function() {
                $flash.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 6000);
        }
    });

})(jQuery);
