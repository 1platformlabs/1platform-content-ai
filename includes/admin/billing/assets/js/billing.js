(function ($) {
    'use strict';

    var BillingModal = {
        init: function () {
            this.$backdrop = $('#contai-topup-modal');
            if (!this.$backdrop.length) {
                return;
            }

            this.$modal = this.$backdrop.find('.contai-modal');
            this.$openBtn = $('#contai-open-topup-modal');
            this.$closeBtn = $('#contai-close-topup-modal');
            this.$amountInput = $('#contai_topup_amount');
            this.$form = this.$backdrop.find('form');
            this.submitting = false;

            this.$backdrop.attr({
                'aria-label': this.$modal.find('h3').text()
            });

            this.bindEvents();
        },

        bindEvents: function () {
            var self = this;

            this.$openBtn.on('click', function () {
                self.open();
            });

            this.$closeBtn.on('click', function () {
                self.close();
            });

            this.$backdrop.on('click', function (e) {
                if (e.target === self.$backdrop[0]) {
                    self.close();
                }
            });

            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && self.$backdrop.is(':visible')) {
                    self.close();
                }
            });

            this.$form.on('submit', function (e) {
                if (self.submitting) {
                    e.preventDefault();
                    return;
                }
                self.submitting = true;
                var $submitBtn = self.$form.find('button[type="submit"]');
                $submitBtn.addClass('is-loading').prop('disabled', true);
            });
        },

        open: function () {
            this.$backdrop.css('display', 'flex');
            this.$amountInput.val('').trigger('focus');
        },

        close: function () {
            this.$backdrop.hide();
            this.$amountInput.val('');
            this.submitting = false;
            var $submitBtn = this.$form.find('button[type="submit"]');
            $submitBtn.removeClass('is-loading').prop('disabled', false);
        }
    };

    $(document).ready(function () {
        BillingModal.init();
    });
})(jQuery);
