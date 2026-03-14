(function ($) {
    'use strict';

    const SearchConsole = {
        init: function () {
            this.initCopyButtons();
        },

        initCopyButtons: function () {
            $(document).on('click', '.contai-copy-btn', function () {
                const textToCopy = $(this).data('copy');
                SearchConsole.copyToClipboard(textToCopy, $(this));
            });
        },

        copyToClipboard: function (text, $button) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    SearchConsole.showCopySuccess($button);
                });
            } else {
                const $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                SearchConsole.showCopySuccess($button);
            }
        },

        showCopySuccess: function ($button) {
            const originalHtml = $button.html();
            $button.addClass('copied');
            $button.html('<span class="dashicons dashicons-yes"></span>');

            setTimeout(function () {
                $button.removeClass('copied');
                $button.html(originalHtml);
            }, 2000);
        }
    };

    $(document).ready(function () {
        SearchConsole.init();
    });

})(jQuery);
