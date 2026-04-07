/**
 * Onboarding — self-service registration with payment polling.
 *
 * Globals (via wp_localize_script):
 *   contaiOnboarding.restUrl  — REST base URL
 *   contaiOnboarding.nonce    — WP REST nonce
 *   contaiOnboarding.i18n     — Translated strings
 */
(function () {
    'use strict';

    var POLL_INTERVAL = 5000;   // 5 seconds
    var POLL_TIMEOUT  = 300000; // 5 minutes
    var pollTimer     = null;
    var pollStart     = 0;
    var selectedAmount = 10;
    var API_KEY_PATTERN = /^sk-[a-zA-Z0-9]{20,}$/;

    // ── DOM refs ──

    var form       = document.getElementById('contai-create-account-form');
    var statusBox  = document.getElementById('contai-onboarding-status');
    var statusText = document.getElementById('contai-onboarding-status-text');
    var successBox = document.getElementById('contai-onboarding-success');
    var errorBox   = document.getElementById('contai-onboarding-error');
    var submitBtn  = document.getElementById('contai-onboarding-submit');
    var emailInput = document.getElementById('contai-onboarding-email');
    var customAmt  = document.getElementById('contai-onboarding-custom-amount');
    var recovery   = document.getElementById('contai-onboarding-recovery');
    var toggleLink = document.getElementById('contai-toggle-existing-key');

    // ── Amount selector ──

    var amountBtns = document.querySelectorAll('.contai-amount-btn');
    for (var i = 0; i < amountBtns.length; i++) {
        amountBtns[i].addEventListener('click', function () {
            for (var j = 0; j < amountBtns.length; j++) {
                amountBtns[j].classList.remove('active');
            }
            this.classList.add('active');
            selectedAmount = parseFloat(this.getAttribute('data-amount'));
            if (customAmt) customAmt.value = '';
        });
    }

    if (customAmt) {
        customAmt.addEventListener('input', function () {
            if (this.value) {
                selectedAmount = parseFloat(this.value);
                for (var j = 0; j < amountBtns.length; j++) {
                    amountBtns[j].classList.remove('active');
                }
            }
        });
    }

    // ── Toggle between create account and existing key ──

    if (toggleLink) {
        toggleLink.addEventListener('click', function (e) {
            e.preventDefault();
            var createSection = document.getElementById('contai-create-account');
            var activateSection = document.querySelector('.contai-activate-license');
            if (createSection) createSection.style.display = createSection.style.display === 'none' ? '' : 'none';
            if (activateSection) activateSection.style.display = activateSection.style.display === 'none' ? '' : 'none';
            this.textContent = createSection && createSection.style.display === 'none'
                ? contaiOnboarding.i18n.createNew
                : contaiOnboarding.i18n.existingKey;
        });
    }

    // ── API helper ──

    function apiFetch(endpoint, options) {
        options = options || {};
        var url = contaiOnboarding.restUrl + endpoint;
        var headers = {
            'X-WP-Nonce': contaiOnboarding.nonce,
            'Content-Type': 'application/json'
        };
        var cfg = {
            method: options.method || 'GET',
            headers: headers,
            credentials: 'same-origin'
        };
        if (options.body) {
            cfg.body = JSON.stringify(options.body);
        }
        return fetch(url, cfg).then(function (r) {
            if (!r.ok) {
                return r.json().then(function (body) {
                    var err = new Error(body.message || 'Error ' + r.status);
                    err.status = r.status;
                    throw err;
                });
            }
            return r.json();
        });
    }

    // ── Submit registration ──

    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            var email = emailInput ? emailInput.value.trim() : '';
            if (!email) {
                showError(contaiOnboarding.i18n.emailRequired || 'Email is required.');
                return;
            }
            if (selectedAmount < 5) {
                showError(contaiOnboarding.i18n.minAmount || 'Minimum amount is $5.00 USD.');
                return;
            }

            submitBtn.disabled = true;
            hideError();

            apiFetch('register', {
                method: 'POST',
                body: { email: email, amount: selectedAmount, currency: 'USD' }
            })
            .then(function (result) {
                if (result.success && result.data) {
                    var data = result.data;
                    var sessionId = data.session_id;
                    var paymentUrl = data.payment_url;

                    // Open payment in new tab (only allow https URLs)
                    if (paymentUrl && /^https:\/\//.test(paymentUrl)) {
                        window.open(paymentUrl, '_blank');
                    }

                    // Switch to polling UI
                    showPolling();
                    startPolling(sessionId);
                } else {
                    showError(result.message || contaiOnboarding.i18n.failed);
                    submitBtn.disabled = false;
                }
            })
            .catch(function (err) {
                showError(err.message || contaiOnboarding.i18n.failed);
                submitBtn.disabled = false;
            });
        });
    }

    // ── Polling ──

    function startPolling(sessionId) {
        pollStart = Date.now();
        pollTimer = setInterval(function () {
            if (Date.now() - pollStart > POLL_TIMEOUT) {
                clearInterval(pollTimer);
                if (statusText) {
                    statusText.textContent = contaiOnboarding.i18n.timeout ||
                        'Payment is being processed. You can close this tab and return later.';
                }
                return;
            }

            apiFetch('status/' + encodeURIComponent(sessionId))
                .then(function (result) {
                    if (!result.success) return;
                    var data = result.data;
                    if (!data) return;

                    if (data.status === 'completed' && data.api_key) {
                        clearInterval(pollTimer);
                        showSuccess();
                        activateKey(data.api_key);
                    } else if (data.status === 'failed') {
                        clearInterval(pollTimer);
                        showForm();
                        showError(contaiOnboarding.i18n.failed ||
                            'Payment was not completed. Please try again.');
                    }
                })
                .catch(function (err) {
                    if (err.status === 410) {
                        clearInterval(pollTimer);
                        showForm();
                        showError(contaiOnboarding.i18n.alreadyClaimed ||
                            'API key was already retrieved. Please enter it below.');
                    }
                });
        }, POLL_INTERVAL);
    }

    // ── Activate API key ──

    function activateKey(apiKey) {
        if (!apiKey || !API_KEY_PATTERN.test(apiKey)) {
            showForm();
            showError(contaiOnboarding.i18n.invalidKey || 'Invalid API key received. Please enter it manually.');
            return;
        }

        var licenseInput = document.querySelector('input[name="contai_api_key"]');
        var licenseForm = licenseInput ? licenseInput.closest('form') : null;
        if (licenseInput && licenseForm) {
            licenseInput.value = apiKey;
            licenseForm.submit();
        } else {
            window.location.reload();
        }
    }

    // ── UI helpers ──

    function showPolling() {
        if (form) form.style.display = 'none';
        if (statusBox) statusBox.style.display = '';
        if (successBox) successBox.style.display = 'none';
    }

    function showSuccess() {
        if (statusBox) statusBox.style.display = 'none';
        if (successBox) successBox.style.display = '';
    }

    function showForm() {
        if (form) form.style.display = '';
        if (statusBox) statusBox.style.display = 'none';
        if (successBox) successBox.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
    }

    function showError(msg) {
        if (errorBox) {
            errorBox.textContent = msg;
            errorBox.style.display = '';
        }
    }

    function hideError() {
        if (errorBox) {
            errorBox.textContent = '';
            errorBox.style.display = 'none';
        }
    }

    // ── Cleanup on page unload ──

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden' && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    // ── Session recovery (from transient) ──

    if (recovery) {
        var recoverSessionId = recovery.getAttribute('data-session-id');
        if (recoverSessionId) {
            startPolling(recoverSessionId);
            // After a short delay, if still pending, show recovery + form
            setTimeout(function () {
                if (pollTimer) {
                    showPolling();
                }
            }, 1000);
        }
    }
})();
