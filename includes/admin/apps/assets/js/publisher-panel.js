(function() {
    'use strict';

    function activateTab(tabButtons, tabContents, tabName) {
        var targetButton = document.querySelector('.contai-ads-tab[data-tab="' + tabName + '"]');
        var targetContent = document.getElementById('tab-' + tabName);

        if (!targetButton || !targetContent) {
            return false;
        }

        tabButtons.forEach(function(btn) {
            btn.classList.remove('active');
            btn.setAttribute('aria-selected', 'false');
        });
        tabContents.forEach(function(content) {
            content.classList.remove('active');
        });

        targetButton.classList.add('active');
        targetButton.setAttribute('aria-selected', 'true');
        targetContent.classList.add('active');

        return true;
    }

    function initTabs() {
        var tabButtons = document.querySelectorAll('.contai-ads-tab');
        var tabContents = document.querySelectorAll('.contai-ads-tab-content');

        if (tabButtons.length === 0) {
            return;
        }

        tabButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                var tabName = this.getAttribute('data-tab');

                if (activateTab(tabButtons, tabContents, tabName)) {
                    window.history.replaceState(null, '', '#' + tabName);
                }
            });
        });

        var hash = window.location.hash.substring(1);
        if (hash) {
            activateTab(tabButtons, tabContents, hash);
        }
    }

    function initConfirmButtons() {
        var buttons = document.querySelectorAll('[data-confirm]');

        buttons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                var message = this.getAttribute('data-confirm');

                if (message && !window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    }

    function init() {
        initTabs();
        initConfirmButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
