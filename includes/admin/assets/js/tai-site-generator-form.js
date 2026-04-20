/**
 * Site Wizard form handling for Content AI.
 *
 * Handles unsaved-change warnings, double-submit prevention,
 * inline validation errors, and loading-state feedback.
 *
 * @package ContentAI
 */
(function () {
    'use strict';

    var form      = document.querySelector('.contai-site-generator-form');
    var submitBtn = document.getElementById('contai_submit_btn');

    if (!form || !submitBtn) {
        return;
    }

    var formDirty    = false;
    var isSubmitting = false;

    form.addEventListener('input', function () {
        formDirty = true;
    });

    window.addEventListener('beforeunload', function (e) {
        if (formDirty && !isSubmitting) {
            e.preventDefault();
            e.returnValue = contaiSiteGenI18n.unsavedWarning;
            return e.returnValue;
        }
    });

    form.addEventListener('submit', function (e) {
        if (isSubmitting) {
            e.preventDefault();
            return false;
        }

        isSubmitting = true;
        formDirty    = false;

        submitBtn.disabled  = true;
        var originalText    = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="contai-spinner" aria-hidden="true"></span>' + contaiSiteGenI18n.starting;
        submitBtn.classList.add('is-loading');

        setTimeout(function () {
            if (!form.checkValidity()) {
                submitBtn.disabled  = false;
                submitBtn.innerHTML = originalText;
                submitBtn.classList.remove('is-loading');
                isSubmitting = false;
            }
        }, 100);
    });

    var inputs = form.querySelectorAll('input[required], select[required]');

    inputs.forEach(function (input) {
        input.addEventListener('invalid', function (e) {
            e.preventDefault();
            var errorMsg = input.validationMessage;

            var existingError = input.parentElement.querySelector('.contai-field-error');
            if (existingError) {
                existingError.remove();
            }

            input.classList.add('contai-input-error');

            var errorDiv       = document.createElement('div');
            errorDiv.className = 'contai-field-error';
            errorDiv.textContent = errorMsg;
            input.parentElement.appendChild(errorDiv);

            if (!document.querySelector('.contai-field-error[data-focused]')) {
                input.focus();
                errorDiv.setAttribute('data-focused', 'true');
            }
        });

        input.addEventListener('input', function () {
            input.classList.remove('contai-input-error');
            var error = input.parentElement.querySelector('.contai-field-error');
            if (error) {
                error.remove();
            }
        });
    });
})();
