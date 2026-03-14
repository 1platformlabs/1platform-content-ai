/**
 * Auto-refresh for the Job Monitor admin page.
 *
 * Stores the auto-refresh preference in localStorage and reloads the page
 * every 30 seconds when enabled.
 *
 * @package ContentAI
 */
(function () {
    'use strict';

    var autoRefreshEnabled = localStorage.getItem('contai_auto_refresh') === 'true';
    var refreshInterval    = null;

    function startAutoRefresh() {
        if (refreshInterval) {
            return;
        }
        refreshInterval = setInterval(function () {
            location.reload();
        }, 30000);
    }

    function stopAutoRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }

    if (autoRefreshEnabled) {
        startAutoRefresh();
    }
})();
