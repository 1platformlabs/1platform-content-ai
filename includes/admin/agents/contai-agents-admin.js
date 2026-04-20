/**
 * Agents Admin UI — 1Platform Content AI
 *
 * Vanilla JS that hydrates the server-rendered HTML skeletons with data
 * fetched from the WP REST proxy (contai/v1/).  Each view is initialised
 * by detecting the corresponding container element on the page.
 */
(function () {
    'use strict';

    /* ================================================================
       GLOBALS
       ================================================================ */
    var API      = contaiAgents.restUrl;   // e.g. "https://example.com/wp-json/contai/v1/"
    var NONCE    = contaiAgents.nonce;
    var SETTINGS = contaiAgents.settings;  // hydrated from PHP
    var ADMIN    = contaiAgents.adminUrl;  // admin.php?page=contai-agents

    /* ================================================================
       HELPERS
       ================================================================ */

    /**
     * Fetch wrapper that talks to the WP REST proxy.
     * Returns parsed JSON or throws a WP_Error-shaped object.
     */
    function apiFetch(endpoint, options) {
        options = options || {};
        var url = API + endpoint;
        var headers = {
            'X-WP-Nonce': NONCE,
            'Content-Type': 'application/json'
        };
        var cfg = {
            headers: headers,
            credentials: 'same-origin'
        };
        // merge options
        Object.keys(options).forEach(function (k) {
            if (k === 'headers') {
                Object.keys(options.headers).forEach(function (h) {
                    cfg.headers[h] = options.headers[h];
                });
            } else {
                cfg[k] = options[k];
            }
        });
        return fetch(url, cfg)
            .then(function (r) {
                if (!r.ok) {
                    return r.json().then(function (body) {
                        var err = new Error(body.message || 'Error ' + r.status);
                        err.code = body.code || 'api_error';
                        err.status = r.status;
                        throw err;
                    });
                }
                return r.json();
            });
    }

    /** Build a query-string (without leading ?) from a plain object. */
    function qs(params) {
        var parts = [];
        Object.keys(params).forEach(function (k) {
            if (params[k] !== '' && params[k] !== null && params[k] !== undefined) {
                parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
            }
        });
        return parts.length ? '?' + parts.join('&') : '';
    }

    /** Simple HTML escaping (text content only). */
    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    /** HTML attribute escaping (safe for href, data-*, etc.). */
    function escAttr(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /** Format an ISO date string to a human-readable form. */
    function fmtDate(iso) {
        if (!iso) return '-';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
             + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    /** Format seconds to a duration string. */
    function fmtDuration(seconds) {
        if (seconds === null || seconds === undefined) return '-';
        seconds = Math.round(seconds);
        if (seconds < 60) return seconds + 's';
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + 'm ' + s + 's';
    }

    /** Build a status badge HTML string. */
    function statusBadge(status) {
        var map = {
            running:   { cls: 'blue',   icon: 'dashicons-update',   label: 'Running' },
            completed: { cls: 'green',  icon: 'dashicons-yes-alt',  label: 'Completed' },
            failed:    { cls: 'red',    icon: 'dashicons-dismiss',  label: 'Failed' },
            pending:   { cls: 'gray',   icon: 'dashicons-clock',    label: 'Pending' },
            consumed:  { cls: 'green',  icon: 'dashicons-yes-alt',  label: 'Consumed' },
            active:    { cls: 'green',  icon: 'dashicons-yes-alt',  label: 'Active' },
            stopped:   { cls: 'yellow', icon: 'dashicons-controls-pause', label: 'Stopped' },
            paused:    { cls: 'yellow', icon: 'dashicons-controls-pause', label: 'Paused' },
            error:     { cls: 'red',    icon: 'dashicons-dismiss',  label: 'Error' }
        };
        var s = map[(status || '').toLowerCase()] || map.pending;
        return '<span class="contai-badge contai-badge-' + s.cls + '">' +
               '<span class="dashicons ' + s.icon + '"></span> ' + esc(s.label) +
               '</span>';
    }

    /** Show a toast notification. */
    function showToast(message, type) {
        type = type || 'success';
        var iconMap = { success: 'dashicons-yes-alt', error: 'dashicons-dismiss', info: 'dashicons-info' };
        var el = document.createElement('div');
        el.className = 'contai-toast is-' + type;
        el.setAttribute('role', 'alert');
        el.innerHTML = '<span class="dashicons ' + (iconMap[type] || iconMap.info) + '"></span> ' + esc(message);
        document.body.appendChild(el);
        // trigger reflow then animate in
        void el.offsetWidth;
        el.classList.add('is-visible');
        setTimeout(function () {
            el.classList.remove('is-visible');
            setTimeout(function () { el.remove(); }, 350);
        }, 4000);
    }

    /** Template icon mapping — maps common slugs to dashicons. */
    function templateIcon(slug) {
        var map = {
            'blog-writer':     'dashicons-edit',
            'seo-optimizer':   'dashicons-search',
            'social-media':    'dashicons-share',
            'email-writer':    'dashicons-email',
            'product-writer':  'dashicons-cart',
            'content-planner': 'dashicons-calendar-alt',
            'link-builder':    'dashicons-admin-links',
            'keyword-agent':   'dashicons-tag',
            'image-agent':     'dashicons-format-image'
        };
        return map[slug] || 'dashicons-superhero-alt';
    }

    /** Set a button to loading state. */
    function btnLoading(btn, loading) {
        if (loading) {
            btn.classList.add('is-loading');
            btn.disabled = true;
            btn._prevHTML = btn.innerHTML;
            btn.innerHTML = '<span class="dashicons dashicons-update"></span> Processing...';
        } else {
            btn.classList.remove('is-loading');
            btn.disabled = false;
            if (btn._prevHTML) {
                btn.innerHTML = btn._prevHTML;
                delete btn._prevHTML;
            }
        }
    }

    /* ================================================================
       INIT ROUTER
       ================================================================ */
    function init() {
        if (document.getElementById('contai-agents-catalog'))       initCatalog();
        if (document.getElementById('contai-agents-list'))          initAgentList();
        if (document.getElementById('contai-agents-wizard'))        initWizard();
        if (document.getElementById('contai-agent-detail'))         initAgentDetail();
        if (document.getElementById('contai-agents-runs'))          initRuns();
        if (document.getElementById('contai-run-detail'))           initRunDetail();
        if (document.getElementById('contai-agents-actions'))       initActions();
        if (document.getElementById('contai-agents-settings-form')) initSettings();
    }

    /* ================================================================
       CATALOG
       ================================================================ */
    function initCatalog() {
        var container = document.getElementById('contai-agents-catalog');
        var grid      = container.querySelector('.contai-agents-grid');
        var empty     = container.querySelector('.contai-empty');

        apiFetch('agents/catalog')
            .then(function (data) {
                var templates = Array.isArray(data) ? data : (data.templates || data.items || []);
                if (!templates.length) {
                    grid.style.display = 'none';
                    empty.style.display = '';
                    return;
                }
                grid.classList.remove('contai-skeleton-grid');
                grid.innerHTML = '';
                templates.forEach(function (t) {
                    var slug = t.slug || t.id || '';
                    var href = ADMIN + '&view=wizard&template=' + encodeURIComponent(slug);
                    var icon = t.icon || templateIcon(slug);
                    var card = document.createElement('a');
                    card.href = href;
                    card.className = 'contai-agent-card';
                    card.innerHTML =
                        '<div class="contai-agent-card-icon"><span class="dashicons ' + escAttr(icon) + '"></span></div>' +
                        '<h3 class="contai-agent-card-title">' + esc(t.name || t.title || slug) + '</h3>' +
                        '<p class="contai-agent-card-desc">' + esc(t.description || '') + '</p>' +
                        '<span class="contai-agent-card-cta">Create agent <span class="dashicons dashicons-arrow-right-alt2"></span></span>';
                    grid.appendChild(card);
                });
            })
            .catch(function (err) {
                grid.style.display = 'none';
                empty.querySelector('.contai-empty-title').textContent = 'Error loading templates';
                empty.querySelector('.contai-empty-text').textContent = err.message || 'Please try again.';
                empty.style.display = '';
                showToast(err.message || 'Could not load catalog.', 'error');
            });
    }

    /* ================================================================
       AGENT LIST
       ================================================================ */
    function initAgentList() {
        var container = document.getElementById('contai-agents-list');
        var tbody     = document.getElementById('contai-agents-tbody');
        var tableCard = container.querySelector('.contai-table-card');
        var empty     = container.querySelector('.contai-empty');

        apiFetch('agents')
            .then(function (data) {
                var agents = Array.isArray(data) ? data : (data.agents || data.items || []);
                if (!agents.length) {
                    tableCard.style.display = 'none';
                    empty.style.display = '';
                    return;
                }
                tbody.innerHTML = '';
                agents.forEach(function (a) {
                    var id   = a.id || a._id || '';
                    var name = a.name || a.title || 'Unnamed';
                    var tmpl = a.template_slug || a.template || '-';
                    var st   = a.status || 'active';
                    var last = a.last_run_at || a.last_run || null;
                    var tr   = document.createElement('tr');
                    tr.innerHTML =
                        '<td><strong>' + esc(name) + '</strong></td>' +
                        '<td>' + esc(tmpl) + '</td>' +
                        '<td>' + statusBadge(st) + '</td>' +
                        '<td>' + fmtDate(last) + '</td>' +
                        '<td><div class="contai-row-actions">' +
                            '<a href="' + escAttr(ADMIN + '&view=agent-detail&agent_id=' + id) + '" class="contai-row-action-primary">View</a>' +
                            '<a href="' + escAttr(ADMIN + '&view=runs&agent_id=' + id) + '" class="contai-row-action-primary">Runs</a>' +
                        '</div></td>';
                    tbody.appendChild(tr);
                });
            })
            .catch(function (err) {
                tbody.innerHTML = '<tr><td colspan="5" style="color:#dc2626;text-align:center;">Error: ' + esc(err.message) + '</td></tr>';
                showToast('Error loading agents: ' + err.message, 'error');
            });
    }

    /* ================================================================
       WIZARD
       ================================================================ */
    function initWizard() {
        var container    = document.getElementById('contai-agents-wizard');
        var templateSlug = container.getAttribute('data-template') || '';
        var messagesEl   = document.getElementById('contai-wizard-messages');
        var textarea     = document.getElementById('contai-wizard-message');
        var sendBtn      = document.getElementById('contai-wizard-send');
        var confirmBtn   = document.getElementById('contai-wizard-confirm');
        var steps        = container.querySelectorAll('.contai-wizard-step');

        var sessionId    = null;
        var isReady      = false;  // wizard flagged as ready_to_confirm
        var sending      = false;

        // Enable send when textarea has content
        textarea.addEventListener('input', function () {
            sendBtn.disabled = sending || textarea.value.trim().length === 0;
        });

        // Send on Enter (without Shift)
        textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !sendBtn.disabled) {
                e.preventDefault();
                sendBtn.click();
            }
        });

        /** Add a message bubble to the chat. */
        function addMessage(text, role) {
            var div = document.createElement('div');
            div.className = 'contai-wizard-msg ' + (role || 'assistant');
            div.textContent = text;
            messagesEl.appendChild(div);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        /** Show typing indicator. */
        function showTyping() {
            var div = document.createElement('div');
            div.className = 'contai-wizard-msg assistant contai-wizard-msg-typing';
            div.id = 'contai-typing';
            div.innerHTML = '<span></span><span></span><span></span>';
            messagesEl.appendChild(div);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function hideTyping() {
            var t = document.getElementById('contai-typing');
            if (t) t.remove();
        }

        /** Update the stepper to reflect the current step. */
        function setStep(num) {
            steps.forEach(function (s) {
                var sn = parseInt(s.getAttribute('data-step'), 10);
                s.classList.remove('is-active', 'is-done');
                if (sn < num) s.classList.add('is-done');
                if (sn === num) s.classList.add('is-active');
            });
        }

        /** Re-enable the input area after resuming a session. */
        function enableInput() {
            sendBtn.disabled = textarea.value.trim().length === 0;
            textarea.style.display = '';
            sendBtn.style.display = '';
            confirmBtn.style.display = 'none';
            setStep(2);
        }

        /** Handle wizard API response (start, respond). */
        function handleWizardResponse(data) {
            hideTyping();

            // Save session ID and persist to localStorage
            if (data.id) {
                sessionId = data.id;
                localStorage.setItem('contai_wizard_session_id', sessionId);
            }

            // Show the last assistant message from the messages array
            if (data.messages && data.messages.length > 0) {
                var lastMsg = data.messages[data.messages.length - 1];
                if (lastMsg.role === 'assistant' && lastMsg.content) {
                    addMessage(lastMsg.content, 'assistant');
                }
            }

            // Check if wizard is ready to confirm
            if (data.generated_config || data.status === 'completed') {
                isReady = true;
                setStep(3);
                textarea.style.display = 'none';
                sendBtn.style.display = 'none';
                confirmBtn.style.display = '';
                // Show config summary if available
                if (data.summary || data.config_summary) {
                    addMessage(data.summary || data.config_summary, 'system');
                }
            } else {
                // Advance stepper
                var stepNum = data.current_step || data.step_number || 2;
                if (typeof stepNum === 'number' && stepNum > 1) {
                    setStep(Math.min(stepNum, 2));
                } else {
                    setStep(2);
                }
            }
        }

        // ── Start a fresh wizard session ──
        function startNewSession() {
            var startPayload = {};
            if (templateSlug) {
                startPayload.template_slug = templateSlug;
            }

            showTyping();
            apiFetch('agents/wizard/start', {
                method: 'POST',
                body: JSON.stringify(startPayload)
            })
            .then(function (data) {
                setStep(1);
                handleWizardResponse(data);
            })
            .catch(function (err) {
                hideTyping();
                addMessage('Error starting the wizard: ' + (err.message || 'unknown'), 'system');
                showToast('Could not start the wizard.', 'error');
            });
        }

        // ── Resume or start session ──
        // Check localStorage for a saved session (persists across page reloads).
        var savedSessionId = localStorage.getItem('contai_wizard_session_id');
        if (savedSessionId && !templateSlug) {
            sessionId = savedSessionId;
            // Try to resume the saved session.
            apiFetch('agents/wizard/' + sessionId)
                .then(function (data) {
                    if (data && data.status === 'in_progress') {
                        // Resume session — render existing messages.
                        if (data.messages) {
                            data.messages.forEach(function (msg) {
                                addMessage(msg.content, msg.role);
                            });
                        }
                        enableInput();
                    } else {
                        localStorage.removeItem('contai_wizard_session_id');
                        startNewSession();
                    }
                })
                .catch(function () {
                    localStorage.removeItem('contai_wizard_session_id');
                    startNewSession();
                });
        } else {
            startNewSession();
        }

        // ── Send response ──
        sendBtn.addEventListener('click', function () {
            var answer = textarea.value.trim();
            if (!answer || !sessionId || sending) return;

            sending = true;
            sendBtn.disabled = true;
            addMessage(answer, 'user');
            textarea.value = '';
            showTyping();

            apiFetch('agents/wizard/' + sessionId + '/respond', {
                method: 'POST',
                body: JSON.stringify({ message: answer })
            })
            .then(function (data) {
                sending = false;
                handleWizardResponse(data);
            })
            .catch(function (err) {
                sending = false;
                hideTyping();
                addMessage('Error: ' + (err.message || 'Could not send the response.'), 'system');
                sendBtn.disabled = false;
                showToast(err.message || 'Error sending response.', 'error');
            });
        });

        // ── Confirm and create agent ──
        confirmBtn.addEventListener('click', function () {
            if (!sessionId) return;
            btnLoading(confirmBtn, true);

            apiFetch('agents/wizard/' + sessionId + '/confirm', {
                method: 'POST',
                body: JSON.stringify({})
            })
            .then(function (data) {
                btnLoading(confirmBtn, false);
                var agentId = data.id || data._id || data.agent_id || '';
                localStorage.removeItem('contai_wizard_session_id');
                showToast('Agent created successfully.', 'success');
                addMessage('Agent created successfully. Redirecting...', 'system');

                setTimeout(function () {
                    if (agentId) {
                        window.location.href = ADMIN + '&view=agent-detail&agent_id=' + agentId;
                    } else {
                        window.location.href = ADMIN + '&view=agents';
                    }
                }, 1500);
            })
            .catch(function (err) {
                btnLoading(confirmBtn, false);
                addMessage('Error creating agent: ' + (err.message || 'unknown'), 'system');
                showToast(err.message || 'Error confirming.', 'error');
            });
        });
    }

    /* ================================================================
       AGENT DETAIL
       ================================================================ */
    function initAgentDetail() {
        var container = document.getElementById('contai-agent-detail');
        var agentId   = container.getAttribute('data-agent-id');
        var infoEl    = container.querySelector('.contai-agent-info');
        var runBtn    = document.getElementById('contai-run-agent');
        var runsLink  = document.getElementById('contai-view-runs');
        var deleteBtn = document.getElementById('contai-delete-agent');

        if (!agentId) {
            infoEl.innerHTML = '<p style="color:#dc2626;">No agent ID specified.</p>';
            infoEl.classList.remove('contai-skeleton');
            return;
        }

        // Update runs link
        runsLink.href = ADMIN + '&view=runs&agent_id=' + agentId;

        apiFetch('agents/' + agentId)
            .then(function (agent) {
                infoEl.classList.remove('contai-skeleton');
                var name   = agent.name || agent.title || 'Agent';
                var tmpl   = agent.template_slug || agent.template || '-';
                var st     = agent.status || 'active';
                var desc   = agent.description || agent.config_summary || '';
                var created = agent.created_at || agent.created || '';

                infoEl.innerHTML =
                    '<h2 style="margin-top:0;">' + esc(name) + ' ' + statusBadge(st) + '</h2>' +
                    (desc ? '<p style="color:#64748b;margin:8px 0;">' + esc(desc) + '</p>' : '') +
                    '<div class="contai-detail-grid">' +
                        '<div><div class="contai-detail-field-label">Template</div><div class="contai-detail-field-value">' + esc(tmpl) + '</div></div>' +
                        '<div><div class="contai-detail-field-label">Created</div><div class="contai-detail-field-value">' + fmtDate(created) + '</div></div>' +
                        '<div><div class="contai-detail-field-label">Last Run</div><div class="contai-detail-field-value">' + fmtDate(agent.last_run_at || agent.last_run) + '</div></div>' +
                    '</div>';

                // Show config if present
                if (agent.config && typeof agent.config === 'object') {
                    var configHTML = '<div style="margin-top:16px;"><div class="contai-detail-field-label">Configuration</div>';
                    configHTML += '<div class="contai-iteration-output">' + esc(JSON.stringify(agent.config, null, 2)) + '</div>';
                    configHTML += '</div>';
                    infoEl.innerHTML += configHTML;
                }

                runBtn.disabled = false;
                deleteBtn.disabled = false;
            })
            .catch(function (err) {
                infoEl.classList.remove('contai-skeleton');
                infoEl.innerHTML = '<p style="color:#dc2626;">Error: ' + esc(err.message) + '</p>';
                showToast('Could not load the agent.', 'error');
            });

        // ── Run Agent ──
        runBtn.addEventListener('click', function () {
            btnLoading(runBtn, true);
            apiFetch('agents/' + agentId + '/run', {
                method: 'POST',
                body: JSON.stringify({})
            })
            .then(function (data) {
                btnLoading(runBtn, false);
                showToast('Execution started.', 'success');
                // Redirect to runs after a short delay
                setTimeout(function () {
                    window.location.href = ADMIN + '&view=runs&agent_id=' + agentId;
                }, 1000);
            })
            .catch(function (err) {
                btnLoading(runBtn, false);
                showToast('Error running agent: ' + (err.message || 'unknown'), 'error');
            });
        });

        // ── Delete Agent ──
        deleteBtn.addEventListener('click', function () {
            if (!window.confirm('Are you sure you want to delete this agent? This action cannot be undone.')) {
                return;
            }
            btnLoading(deleteBtn, true);
            apiFetch('agents/' + agentId, { method: 'DELETE' })
                .then(function () {
                    showToast('Agent deleted.', 'success');
                    setTimeout(function () {
                        window.location.href = ADMIN + '&view=agents';
                    }, 1000);
                })
                .catch(function (err) {
                    btnLoading(deleteBtn, false);
                    showToast('Error deleting agent: ' + (err.message || 'unknown'), 'error');
                });
        });
    }

    /* ================================================================
       RUNS LIST
       ================================================================ */
    function initRuns() {
        var container = document.getElementById('contai-agents-runs');
        var agentId   = container.getAttribute('data-agent-id');
        var tbody     = document.getElementById('contai-runs-tbody');
        var tableCard = container.querySelector('.contai-table-card');
        var empty     = container.querySelector('.contai-empty');
        var refreshTimer = null;

        function loadRuns() {
            apiFetch('agents/' + agentId + '/runs')
                .then(function (data) {
                    var runs = Array.isArray(data) ? data : (data.runs || data.items || []);
                    if (!runs.length) {
                        tableCard.style.display = 'none';
                        empty.style.display = '';
                        stopAutoRefresh();
                        return;
                    }

                    tableCard.style.display = '';
                    empty.style.display = 'none';
                    tbody.innerHTML = '';

                    var hasRunning = false;
                    runs.forEach(function (r) {
                        var rid     = r.id || r._id || '';
                        var st      = r.status || 'pending';
                        var trigger = r.trigger || r.triggered_by || 'manual';
                        var dur     = r.duration_seconds || r.duration || null;
                        var tokens  = r.total_tokens || r.tokens_used || null;
                        var date    = r.created_at || r.started_at || '';

                        if (st === 'running') hasRunning = true;

                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td>' + fmtDate(date) + '</td>' +
                            '<td>' + esc(trigger) + '</td>' +
                            '<td>' + statusBadge(st) + '</td>' +
                            '<td>' + fmtDuration(dur) + '</td>' +
                            '<td>' + (tokens !== null ? esc(String(tokens)) : '-') + '</td>' +
                            '<td><div class="contai-row-actions">' +
                                '<a href="' + escAttr(ADMIN + '&view=run-detail&agent_id=' + agentId + '&run_id=' + rid) + '" class="contai-row-action-primary">Details</a>' +
                            '</div></td>';
                        tbody.appendChild(tr);
                    });

                    // Auto-refresh if any run is RUNNING
                    if (hasRunning) {
                        startAutoRefresh();
                    } else {
                        stopAutoRefresh();
                    }
                })
                .catch(function (err) {
                    tbody.innerHTML = '<tr><td colspan="6" style="color:#dc2626;text-align:center;">Error: ' + esc(err.message) + '</td></tr>';
                    stopAutoRefresh();
                });
        }

        function startAutoRefresh() {
            if (refreshTimer) return;
            refreshTimer = setInterval(loadRuns, 5000);
        }

        function stopAutoRefresh() {
            if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
        }

        if (agentId) {
            loadRuns();
        } else {
            tbody.innerHTML = '<tr><td colspan="6" style="color:#dc2626;text-align:center;">No agent specified.</td></tr>';
        }
    }

    /* ================================================================
       RUN DETAIL
       ================================================================ */
    function initRunDetail() {
        var container     = document.getElementById('contai-run-detail');
        var agentId       = container.getAttribute('data-agent-id');
        var runId         = container.getAttribute('data-run-id');
        var infoEl        = container.querySelector('.contai-run-info');
        var iterationsEl  = document.getElementById('contai-run-iterations');

        if (!agentId || !runId) {
            infoEl.classList.remove('contai-skeleton');
            infoEl.innerHTML = '<p style="color:#dc2626;">Missing parameters (agent_id or run_id).</p>';
            return;
        }

        apiFetch('agents/' + agentId + '/runs/' + runId)
            .then(function (run) {
                infoEl.classList.remove('contai-skeleton');

                var st      = run.status || 'pending';
                var trigger = run.trigger || run.triggered_by || 'manual';
                var dur     = run.duration_seconds || run.duration || null;
                var tokens  = run.total_tokens || run.tokens_used || null;
                var date    = run.created_at || run.started_at || '';
                var err     = run.error || run.error_message || '';

                var canStop = (st === 'running' || st === 'pending');
                var stopBtnHTML = canStop
                    ? ' <button id="contai-stop-run-btn" class="contai-stop-btn">' +
                      '<span class="dashicons dashicons-controls-pause"></span> Stop Run</button>'
                    : '';

                infoEl.innerHTML =
                    '<h2 style="margin-top:0;">Run ' + statusBadge(st) + stopBtnHTML + '</h2>' +
                    '<div class="contai-detail-grid">' +
                        '<div><div class="contai-detail-field-label">Date</div><div class="contai-detail-field-value">' + fmtDate(date) + '</div></div>' +
                        '<div><div class="contai-detail-field-label">Trigger</div><div class="contai-detail-field-value">' + esc(trigger) + '</div></div>' +
                        '<div><div class="contai-detail-field-label">Duration</div><div class="contai-detail-field-value">' + fmtDuration(dur) + '</div></div>' +
                        '<div><div class="contai-detail-field-label">Tokens</div><div class="contai-detail-field-value">' + (tokens !== null ? esc(String(tokens)) : '-') + '</div></div>' +
                    '</div>' +
                    (err ? '<div style="margin-top:16px;"><div class="contai-detail-field-label">Error</div><div class="contai-iteration-output" style="color:#fca5a5;">' + esc(err) + '</div></div>' : '');

                // Bind stop button
                if (canStop) {
                    var stopBtn = document.getElementById('contai-stop-run-btn');
                    stopBtn.addEventListener('click', function () {
                        if (!window.confirm('Are you sure you want to stop this run?')) return;
                        btnLoading(stopBtn, true);
                        apiFetch('agents/' + agentId + '/runs/' + runId + '/stop', { method: 'POST', body: JSON.stringify({}) })
                            .then(function () {
                                showToast('Run stopped successfully.', 'success');
                                window.location.reload();
                            })
                            .catch(function (err) {
                                btnLoading(stopBtn, false);
                                showToast('Error stopping run: ' + (err.message || 'unknown'), 'error');
                            });
                    });
                }

                // Render iterations
                var iterations = run.iterations || run.steps || [];
                if (iterations.length) {
                    iterationsEl.innerHTML = '<h3>Iterations (' + iterations.length + ')</h3>';
                    iterations.forEach(function (it, idx) {
                        var card = document.createElement('div');
                        card.className = 'contai-iteration-card';

                        var itStatus = it.status || 'completed';
                        var itLabel  = it.name || it.label || ('Iteration ' + (idx + 1));

                        var header = document.createElement('div');
                        header.className = 'contai-iteration-header';
                        header.innerHTML = '<span>' + esc(itLabel) + ' ' + statusBadge(itStatus) + '</span><span class="dashicons dashicons-arrow-down-alt2"></span>';

                        var body = document.createElement('div');
                        body.className = 'contai-iteration-body';

                        // Build body content
                        var bodyHTML = '';
                        if (it.input)  bodyHTML += '<div class="contai-detail-field-label">Input</div><div class="contai-iteration-output">' + esc(typeof it.input === 'string' ? it.input : JSON.stringify(it.input, null, 2)) + '</div>';
                        if (it.output) bodyHTML += '<div class="contai-detail-field-label" style="margin-top:12px;">Output</div><div class="contai-iteration-output">' + esc(typeof it.output === 'string' ? it.output : JSON.stringify(it.output, null, 2)) + '</div>';
                        if (it.error)  bodyHTML += '<div class="contai-detail-field-label" style="margin-top:12px;">Error</div><div class="contai-iteration-output" style="color:#fca5a5;">' + esc(it.error) + '</div>';
                        if (!bodyHTML)  bodyHTML = '<p style="color:#94a3b8;">No iteration data.</p>';
                        body.innerHTML = bodyHTML;

                        // Toggle
                        header.addEventListener('click', function () {
                            header.classList.toggle('is-open');
                            body.classList.toggle('is-open');
                        });

                        card.appendChild(header);
                        card.appendChild(body);
                        iterationsEl.appendChild(card);
                    });

                    // Auto-open first iteration
                    var firstHeader = iterationsEl.querySelector('.contai-iteration-header');
                    var firstBody   = iterationsEl.querySelector('.contai-iteration-body');
                    if (firstHeader && firstBody) {
                        firstHeader.classList.add('is-open');
                        firstBody.classList.add('is-open');
                    }
                }

                // Auto-refresh if still running
                if (st === 'running') {
                    setTimeout(function () {
                        window.location.reload();
                    }, 5000);
                }
            })
            .catch(function (err) {
                infoEl.classList.remove('contai-skeleton');
                infoEl.innerHTML = '<p style="color:#dc2626;">Error: ' + esc(err.message) + '</p>';
                showToast('Could not load the run.', 'error');
            });
    }

    /* ================================================================
       ACTIONS
       ================================================================ */
    function initActions() {
        var container = document.getElementById('contai-agents-actions');
        var tbody     = document.getElementById('contai-actions-tbody');
        var tableCard = container.querySelector('.contai-table-card');
        var empty     = container.querySelector('.contai-empty');
        var filter    = document.getElementById('contai-actions-status-filter');

        function loadActions() {
            var status = filter.value;
            var endpoint = 'agent-actions' + qs({ status: status });

            apiFetch(endpoint)
                .then(function (data) {
                    var actions = Array.isArray(data) ? data : (data.actions || data.items || []);
                    if (!actions.length) {
                        tableCard.style.display = 'none';
                        empty.style.display = '';
                        return;
                    }

                    tableCard.style.display = '';
                    empty.style.display = 'none';
                    tbody.innerHTML = '';

                    actions.forEach(function (a) {
                        var aid    = a.id || a._id || '';
                        var type   = a.type || a.action_type || '-';
                        var agent  = a.agent_name || a.agent_id || '-';
                        var date   = a.created_at || '';
                        var st     = a.status || 'pending';

                        var tr = document.createElement('tr');
                        var actionsHTML = '';
                        if (st === 'pending') {
                            actionsHTML = '<button class="contai-row-action-success contai-consume-btn" data-action-id="' + escAttr(aid) + '">Consume</button>' +
                                ' <button class="contai-row-action-danger contai-dismiss-btn" data-action-id="' + escAttr(aid) + '">Dismiss</button>';
                        } else {
                            actionsHTML = '<span style="color:#94a3b8;">-</span>';
                        }

                        tr.innerHTML =
                            '<td><strong>' + esc(type) + '</strong></td>' +
                            '<td>' + esc(agent) + '</td>' +
                            '<td>' + fmtDate(date) + '</td>' +
                            '<td>' + statusBadge(st) + '</td>' +
                            '<td><div class="contai-row-actions">' + actionsHTML + '</div></td>';
                        tbody.appendChild(tr);
                    });

                    // Bind consume buttons
                    var consumeBtns = tbody.querySelectorAll('.contai-consume-btn');
                    consumeBtns.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            handleConsume(btn);
                        });
                    });

                    // Bind dismiss buttons
                    var dismissBtns = tbody.querySelectorAll('.contai-dismiss-btn');
                    dismissBtns.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            handleDismiss(btn);
                        });
                    });
                })
                .catch(function (err) {
                    tableCard.style.display = '';
                    empty.style.display = 'none';
                    tbody.innerHTML = '<tr><td colspan="5" style="color:#dc2626;text-align:center;">Error: ' + esc(err.message) + '</td></tr>';
                    showToast('Error loading actions: ' + err.message, 'error');
                });
        }

        function handleConsume(btn) {
            var actionId = btn.getAttribute('data-action-id');
            if (!window.confirm('Consume this action? Content will be created in WordPress.')) {
                return;
            }
            btn.disabled = true;
            btn.textContent = 'Processing...';

            apiFetch('agent-actions/' + actionId + '/consume', {
                method: 'PATCH',
                body: JSON.stringify({})
            })
            .then(function () {
                showToast('Action consumed successfully.', 'success');
                loadActions(); // refresh the table
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.textContent = 'Consume';
                showToast('Error consuming action: ' + (err.message || 'unknown'), 'error');
            });
        }

        function handleDismiss(btn) {
            var actionId = btn.getAttribute('data-action-id');
            if (!window.confirm('Dismiss this action? It will be marked as consumed without creating content.')) {
                return;
            }
            btn.disabled = true;
            btn.textContent = 'Dismissing...';

            apiFetch('agent-actions/' + actionId + '/dismiss', {
                method: 'PATCH',
                body: JSON.stringify({})
            })
            .then(function () {
                showToast('Action dismissed.', 'success');
                loadActions();
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.textContent = 'Dismiss';
                showToast('Error dismissing action: ' + (err.message || 'unknown'), 'error');
            });
        }

        // Dismiss All button
        var dismissAllBtn = document.getElementById('contai-dismiss-all-actions');
        if (dismissAllBtn) {
            dismissAllBtn.addEventListener('click', function () {
                if (!window.confirm('Dismiss ALL pending actions? They will be marked as consumed without creating content.')) {
                    return;
                }
                btnLoading(dismissAllBtn, true);

                apiFetch('agent-actions/dismiss-all', {
                    method: 'POST',
                    body: JSON.stringify({})
                })
                .then(function (data) {
                    var count = (data && data.dismissed) || 0;
                    var errors = (data && data.errors) || 0;
                    var msg = count + ' action(s) dismissed.';
                    if (errors > 0) msg += ' ' + errors + ' error(s).';
                    showToast(msg, errors > 0 ? 'error' : 'success');
                    loadActions();
                })
                .catch(function (err) {
                    showToast('Error dismissing actions: ' + (err.message || 'unknown'), 'error');
                })
                .finally(function () {
                    btnLoading(dismissAllBtn, false);
                });
            });
        }

        // Filter change
        filter.addEventListener('change', loadActions);

        // Initial load
        loadActions();
    }

    /* ================================================================
       SETTINGS
       ================================================================ */
    function initSettings() {
        var form     = document.getElementById('contai-agents-settings-form');
        var pubSel   = document.getElementById('contai-publish-status');
        var autoChk  = document.getElementById('contai-auto-consume');
        var pollIn   = document.getElementById('contai-polling-interval');

        // Hydrate from server-side settings
        if (SETTINGS) {
            if (SETTINGS.publish_status) pubSel.value = SETTINGS.publish_status;
            if (SETTINGS.auto_consume)   autoChk.checked = true;
            if (SETTINGS.polling_interval) pollIn.value = SETTINGS.polling_interval;
        }

        // Also fetch fresh from API in case they changed
        apiFetch('agents/settings')
            .then(function (s) {
                if (s.publish_status)   pubSel.value = s.publish_status;
                autoChk.checked = !!s.auto_consume;
                if (s.polling_interval) pollIn.value = s.polling_interval;
            })
            .catch(function () {
                // Silently use server-side defaults
            });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = form.querySelector('button[type="submit"]');
            btnLoading(submitBtn, true);

            var pollingVal = parseInt(pollIn.value, 10);
            if (isNaN(pollingVal) || pollingVal < 30) pollingVal = 30;
            if (pollingVal > 3600) pollingVal = 3600;

            var payload = {
                publish_status:   pubSel.value,
                auto_consume:     autoChk.checked,
                polling_interval: pollingVal
            };

            apiFetch('agents/settings', {
                method: 'POST',
                body: JSON.stringify(payload)
            })
            .then(function (data) {
                btnLoading(submitBtn, false);
                // Update local reference
                SETTINGS = data;
                showToast('Settings saved.', 'success');
            })
            .catch(function (err) {
                btnLoading(submitBtn, false);
                showToast('Error: ' + (err.message || 'Could not save settings.'), 'error');
            });
        });
    }

    /* ================================================================
       BOOT
       ================================================================ */
    document.addEventListener('DOMContentLoaded', init);
})();
