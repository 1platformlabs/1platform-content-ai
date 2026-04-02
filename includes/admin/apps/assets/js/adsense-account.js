/**
 * AdSense Account tab — OAuth popup, REST calls, status display.
 */
(function () {
    'use strict';

    if (typeof contaiAdsense === 'undefined') {
        return;
    }

    var restUrl = contaiAdsense.restUrl;
    var nonce = contaiAdsense.nonce;

    function apiRequest(endpoint, method, data) {
        var options = {
            method: method || 'GET',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json',
            },
        };
        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }
        return fetch(restUrl + endpoint, options).then(function (r) {
            if (!r.ok) {
                return r.text().then(function (text) {
                    try { return JSON.parse(text); } catch (e) {
                        return { success: false, msg: 'Server error (' + r.status + ')' };
                    }
                });
            }
            return r.json();
        });
    }

    // ── OAuth Popup ──

    var connectBtn = document.getElementById('contai-adsense-connect-btn');
    if (connectBtn) {
        connectBtn.addEventListener('click', function () {
            connectBtn.disabled = true;
            connectBtn.textContent = 'Connecting...';

            apiRequest('authorize', 'GET')
                .then(function (resp) {
                    if (!resp.success || !resp.data) {
                        alert('Failed to start authorization');
                        connectBtn.disabled = false;
                        connectBtn.textContent = 'Connect AdSense';
                        return;
                    }

                    var authData = resp.data;
                    var authUrl = authData.authorization_url || (authData.data && authData.data.authorization_url);
                    if (!authUrl) {
                        alert('No authorization URL received');
                        connectBtn.disabled = false;
                        connectBtn.textContent = 'Connect AdSense';
                        return;
                    }

                    var popup = window.open(authUrl, 'contai_adsense_oauth', 'width=600,height=700');

                    window.addEventListener('message', function onMessage(event) {
                        if (event.origin !== window.location.origin) return;
                        if (!event.data || event.data.type !== 'contai_adsense_oauth_complete') return;

                        window.removeEventListener('message', onMessage);

                        if (event.data.success) {
                            apiRequest('connect', 'POST')
                                .then(function () {
                                    window.location.reload();
                                })
                                .catch(function () {
                                    window.location.reload();
                                });
                        } else {
                            connectBtn.disabled = false;
                            connectBtn.textContent = 'Connect AdSense';
                            alert('Authorization failed: ' + (event.data.error || 'Unknown error'));
                        }
                    });
                })
                .catch(function () {
                    connectBtn.disabled = false;
                    connectBtn.textContent = 'Connect AdSense';
                    alert('Failed to start authorization');
                });
        });
    }

    // ── Status Loading ──

    var statusLoading = document.getElementById('contai-adsense-status-loading');
    var statusData = document.getElementById('contai-adsense-status-data');

    if (statusLoading && statusData) {
        loadStatus();
    }

    function loadStatus() {
        apiRequest('status', 'GET')
            .then(function (resp) {
                if (!resp.success || !resp.data) {
                    statusLoading.textContent = 'Failed to load status';
                    return;
                }
                var data = resp.data.data || resp.data;
                displayStatus(data);
                if (data.status === 'active') {
                    loadEarnings();
                }
            })
            .catch(function () {
                statusLoading.textContent = 'Failed to load status';
            });
    }

    function displayStatus(data) {
        statusLoading.style.display = 'none';
        statusData.style.display = '';

        setText('contai-adsense-account-name', data.account_name || '—');
        setText('contai-adsense-publisher-id', data.publisher_id || '—');
        setText('contai-adsense-site-state', formatSiteState(data.site_state));
        setText('contai-adsense-connection-status', data.status || '—');
    }

    function loadEarnings() {
        apiRequest('earnings?period=7d', 'GET')
            .then(function (resp) {
                if (!resp.success || !resp.data) return;
                var data = resp.data.data || resp.data;
                displayEarnings(data);
            })
            .catch(function () {});
    }

    function displayEarnings(data) {
        var earningsSection = document.getElementById('contai-adsense-earnings');
        if (!earningsSection) return;
        earningsSection.style.display = '';

        var earnings = extractValue(data.estimated_earnings);
        var clicks = extractValue(data.clicks);
        var impressions = extractValue(data.impressions);
        var rpm = extractValue(data.page_views_rpm);

        setText('contai-adsense-est-earnings', '$' + parseFloat(earnings).toFixed(2));
        setText('contai-adsense-clicks', Math.round(clicks).toString());
        setText('contai-adsense-impressions', Math.round(impressions).toString());
        setText('contai-adsense-rpm', '$' + parseFloat(rpm).toFixed(2));
    }

    function extractValue(metric) {
        if (metric === null || metric === undefined) return 0;
        if (typeof metric === 'object' && metric.current !== undefined) return metric.current;
        return metric;
    }

    function formatSiteState(state) {
        var map = {
            'REQUIRES_REVIEW': 'Requires Review',
            'GETTING_READY': 'Getting Ready',
            'READY': 'Ready',
            'NEEDS_ATTENTION': 'Needs Attention',
        };
        return map[state] || state || '—';
    }

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    // ── Refresh ──

    var refreshBtn = document.getElementById('contai-adsense-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshBtn.disabled = true;
            loadStatus();
            setTimeout(function () {
                refreshBtn.disabled = false;
            }, 3000);
        });
    }

    // ── Disconnect ──

    var disconnectBtn = document.getElementById('contai-adsense-disconnect-btn');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', function () {
            if (!confirm('Are you sure you want to disconnect AdSense?')) return;
            disconnectBtn.disabled = true;

            apiRequest('disconnect', 'DELETE')
                .then(function () {
                    window.location.reload();
                })
                .catch(function () {
                    disconnectBtn.disabled = false;
                    alert('Failed to disconnect');
                });
        });
    }
})();
