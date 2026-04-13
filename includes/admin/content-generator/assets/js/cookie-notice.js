/**
 * Cookie consent banner logic for Content AI.
 *
 * @package ContentAI
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + (value || '') + expires + '; path=/; SameSite=Lax';
    }

    function updateConsent(state) {
        if (typeof gtag === 'function') {
            gtag('consent', 'update', {
                'analytics_storage': state,
                'ad_storage': state
            });
        }
    }

    function hideBanner() {
        var notice = document.getElementById('cookie-notice');
        if (notice) {
            notice.style.display = 'none';
        }
    }

    var acceptBtn = document.getElementById('cn-accept-cookie');
    var refuseBtn = document.getElementById('cn-refuse-cookie');
    var closeBtn  = document.getElementById('cn-close-notice');

    if (acceptBtn) {
        acceptBtn.addEventListener('click', function () {
            setCookie('cookie_notice_accepted', 'true', 180);
            updateConsent('granted');
            hideBanner();
        });
    }

    if (refuseBtn) {
        refuseBtn.addEventListener('click', function () {
            setCookie('cookie_notice_accepted', 'false', 180);
            updateConsent('denied');
            hideBanner();
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            setCookie('cookie_notice_accepted', 'false', 180);
            updateConsent('denied');
            hideBanner();
        });
    }
});
