(function($) {
    'use strict';

    var BillingModal = {
        init: function() {
            this.$overlay = $('#contai-topup-modal');
            this.$modal = this.$overlay.find('.contai-billing-modal');
            this.$openBtn = $('#contai-open-topup-modal');
            this.$closeBtn = $('#contai-close-topup-modal');
            this.$amountInput = $('#contai_topup_amount');
            this.$form = this.$overlay.find('form');

            if (!this.$overlay.length) {
                return;
            }

            this.$overlay.attr({
                'role': 'dialog',
                'aria-modal': 'true',
                'aria-label': this.$overlay.find('.contai-billing-modal-header h3').text()
            });

            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            this.$openBtn.on('click', function() {
                self.open();
            });

            this.$closeBtn.on('click', function() {
                self.close();
            });

            this.$overlay.on('click', function(e) {
                if (e.target === self.$overlay[0]) {
                    self.close();
                }
            });

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.$overlay.is(':visible')) {
                    self.close();
                }
            });

            this.$form.on('submit', function(e) {
                if (self.submitting) {
                    e.preventDefault();
                    return;
                }
                self.submitting = true;
                var $submitBtn = self.$form.find('button[type="submit"]');
                $submitBtn.addClass('disabled').text($submitBtn.data('loading') || 'Processing...');
            });
        },

        open: function() {
            this.$overlay.show();
            this.$amountInput.val('').focus();
        },

        close: function() {
            this.$overlay.hide();
            this.$amountInput.val('');
            this.submitting = false;
            var $submitBtn = this.$form.find('button[type="submit"]');
            $submitBtn.removeClass('disabled');
        }
    };

    $(document).ready(function() {
        BillingModal.init();
    });

})(jQuery);
