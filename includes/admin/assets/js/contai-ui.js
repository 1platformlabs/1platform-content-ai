/**
 * 1Platform Content AI — UI v3 runtime helpers (Toast, Modal, Table).
 *
 * Vanilla JS, no jQuery. Designed as progressive enhancement over the
 * PHP-rendered DOM described in design_handoff_ui_v3/preview/*.html.
 */
(function (window, document) {
    'use strict';

    const Contai = window.Contai = window.Contai || {};

    const ICONS = {
        success: 'dashicons-yes-alt',
        error:   'dashicons-dismiss',
        warning: 'dashicons-warning',
        info:    'dashicons-info'
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function uid(prefix) {
        return prefix + '-' + Math.random().toString(36).slice(2, 9) + '-' + Date.now().toString(36);
    }


    /* ───────── Toast ───────── */

    const TOAST_MAX = 3;

    function ensureToastRoot() {
        let root = document.getElementById('contai-toasts');
        if (!root) {
            root = document.createElement('div');
            root.id = 'contai-toasts';
            root.setAttribute('role', 'region');
            root.setAttribute('aria-label', 'Notifications');
            root.setAttribute('aria-live', 'polite');
            document.body.appendChild(root);
        }
        return root;
    }

    function trimToastStack(root) {
        const toasts = root.querySelectorAll('.contai-toast:not(.is-leaving)');
        for (let i = 0; i < toasts.length - TOAST_MAX; i++) {
            dismissToast(toasts[i].id);
        }
    }

    function dismissToast(id) {
        const el = document.getElementById(id);
        if (!el || el.classList.contains('is-leaving')) {
            return;
        }
        el.classList.add('is-leaving');
        const remove = () => { if (el.parentNode) { el.parentNode.removeChild(el); } };
        el.addEventListener('animationend', remove, { once: true });
        setTimeout(remove, 400);
    }

    Contai.Toast = {
        show: function (opts) {
            opts = opts || {};
            const tone     = opts.tone || 'info';
            const title    = opts.title || '';
            const body     = opts.body || '';
            const duration = typeof opts.duration === 'number' ? opts.duration : 5000;
            const actions  = Array.isArray(opts.actions) ? opts.actions : [];

            const root = ensureToastRoot();
            const id = uid('contai-toast');
            const iconClass = ICONS[tone] || ICONS.info;
            const sticky = tone === 'error' || actions.length > 0;

            const toast = document.createElement('div');
            toast.id = id;
            toast.className = 'contai-toast is-' + tone;
            toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');

            let actionsHtml = '';
            if (actions.length) {
                actionsHtml = '<div class="contai-toast-actions">' +
                    actions.map(function (action, idx) {
                        return '<button type="button" data-contai-toast-action="' + idx + '">' +
                            escapeHtml(action.label || 'OK') + '</button>';
                    }).join('') +
                    '</div>';
            }

            toast.innerHTML =
                '<span class="dashicons ' + iconClass + '" aria-hidden="true"></span>' +
                '<div>' +
                    (title ? '<p class="contai-toast-title">' + escapeHtml(title) + '</p>' : '') +
                    (body  ? '<p class="contai-toast-body">'  + escapeHtml(body)  + '</p>' : '') +
                    actionsHtml +
                '</div>' +
                '<button type="button" class="contai-toast-close" aria-label="Dismiss">×</button>' +
                (sticky ? '' : '<span class="contai-toast-timer"></span>');

            root.appendChild(toast);
            trimToastStack(root);

            toast.querySelector('.contai-toast-close').addEventListener('click', function () {
                dismissToast(id);
            });

            if (actions.length) {
                toast.querySelectorAll('[data-contai-toast-action]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const idx = parseInt(btn.getAttribute('data-contai-toast-action'), 10);
                        const action = actions[idx];
                        if (action && typeof action.onClick === 'function') {
                            action.onClick({ dismiss: function () { dismissToast(id); } });
                        }
                        if (!action || action.keepOpen !== true) {
                            dismissToast(id);
                        }
                    });
                });
            }

            if (!sticky && duration > 0) {
                const timer = toast.querySelector('.contai-toast-timer');
                if (timer) {
                    timer.style.transition = 'transform ' + duration + 'ms linear';
                    requestAnimationFrame(function () {
                        timer.style.transform = 'scaleX(0)';
                    });
                }
                setTimeout(function () { dismissToast(id); }, duration);
            }

            return id;
        },

        dismiss: dismissToast
    };


    /* ───────── Modal ───────── */

    let activeModal = null;

    function trapFocus(container, event) {
        const focusable = container.querySelectorAll(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (!focusable.length) {
            return;
        }
        const first = focusable[0];
        const last  = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function closeActiveModal(reason) {
        if (!activeModal) { return; }
        const { backdrop, onCancel, previousFocus, keyHandler } = activeModal;
        document.removeEventListener('keydown', keyHandler);
        if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
        activeModal = null;
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
        if (reason === 'cancel' && typeof onCancel === 'function') {
            onCancel();
        }
    }

    Contai.Modal = {
        open: function (opts) {
            opts = opts || {};
            if (activeModal) { closeActiveModal(); }

            const tone         = opts.tone === 'danger' ? 'danger' : 'primary';
            const icon         = opts.icon || (tone === 'danger' ? 'dashicons-warning' : 'dashicons-info');
            const title        = opts.title || '';
            const body         = opts.body || '';
            const confirmLabel = opts.confirmLabel || 'OK';
            const confirmTone  = opts.confirmTone === 'danger' ? 'danger' : 'primary';
            const cancelLabel  = opts.cancelLabel || 'Cancel';
            const showCancel   = opts.showCancel !== false;
            const onConfirm    = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
            const onCancel     = typeof opts.onCancel  === 'function' ? opts.onCancel  : null;

            const backdrop = document.createElement('div');
            backdrop.className = 'contai-app contai-modal-backdrop';
            backdrop.setAttribute('role', 'presentation');

            const modal = document.createElement('div');
            modal.className = 'contai-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            if (title) {
                modal.setAttribute('aria-label', title);
            }

            let bodyHtml;
            if (typeof body === 'string') {
                bodyHtml = body
                    ? '<p class="contai-modal-desc">' + escapeHtml(body) + '</p>'
                    : '';
            } else {
                bodyHtml = '';
            }

            modal.innerHTML =
                '<button type="button" class="contai-modal-close" aria-label="Close">×</button>' +
                '<div class="contai-modal-head">' +
                    '<div class="contai-modal-icon is-' + tone + '">' +
                        '<span class="dashicons ' + escapeHtml(icon) + '" aria-hidden="true"></span>' +
                    '</div>' +
                    '<div>' +
                        (title ? '<h3 class="contai-modal-title">' + escapeHtml(title) + '</h3>' : '') +
                        bodyHtml +
                    '</div>' +
                '</div>' +
                '<div class="contai-modal-body" data-contai-modal-body></div>' +
                '<div class="contai-modal-foot">' +
                    (showCancel ? '<button type="button" class="contai-btn contai-btn-secondary" data-contai-modal-cancel>' + escapeHtml(cancelLabel) + '</button>' : '') +
                    '<button type="button" class="contai-btn contai-btn-' + confirmTone + '" data-contai-modal-confirm>' + escapeHtml(confirmLabel) + '</button>' +
                '</div>';

            const bodyHost = modal.querySelector('[data-contai-modal-body]');
            if (body instanceof HTMLElement) {
                bodyHost.appendChild(body);
            } else if (typeof body !== 'string') {
                bodyHost.parentNode.removeChild(bodyHost);
            } else if (!body) {
                bodyHost.parentNode.removeChild(bodyHost);
            }

            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);

            const previousFocus = document.activeElement;

            const keyHandler = function (event) {
                if (!activeModal) { return; }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeActiveModal('cancel');
                } else if (event.key === 'Tab') {
                    trapFocus(modal, event);
                }
            };
            document.addEventListener('keydown', keyHandler);

            backdrop.addEventListener('mousedown', function (event) {
                if (event.target === backdrop) {
                    closeActiveModal('cancel');
                }
            });

            modal.querySelector('.contai-modal-close').addEventListener('click', function () {
                closeActiveModal('cancel');
            });

            const cancelBtn = modal.querySelector('[data-contai-modal-cancel]');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    closeActiveModal('cancel');
                });
            }

            modal.querySelector('[data-contai-modal-confirm]').addEventListener('click', function () {
                const result = onConfirm ? onConfirm() : undefined;
                if (result && typeof result.then === 'function') {
                    result.then(function (keepOpen) {
                        if (!keepOpen) { closeActiveModal('confirm'); }
                    });
                } else if (result !== true) {
                    closeActiveModal('confirm');
                }
            });

            activeModal = {
                backdrop: backdrop,
                modal: modal,
                previousFocus: previousFocus,
                keyHandler: keyHandler,
                onCancel: onCancel
            };

            const focusable = modal.querySelector('[data-contai-modal-confirm]');
            if (focusable) {
                setTimeout(function () { focusable.focus(); }, 0);
            }

            return modal;
        },

        close: function () { closeActiveModal(); }
    };


    /* ───────── Table ───────── */

    function cellComparable(cell) {
        if (!cell) { return ''; }
        const raw = cell.getAttribute('data-sort');
        if (raw !== null) {
            const num = parseFloat(raw);
            return isNaN(num) ? raw.toLowerCase() : num;
        }
        const text = (cell.textContent || '').trim();
        const num = parseFloat(text.replace(/[, ]/g, ''));
        return isNaN(num) ? text.toLowerCase() : num;
    }

    function sortTableByColumn(table, columnIndex, direction) {
        const tbody = table.tBodies[0];
        if (!tbody) { return; }
        const rows = Array.prototype.slice.call(tbody.rows);
        rows.sort(function (a, b) {
            const va = cellComparable(a.cells[columnIndex]);
            const vb = cellComparable(b.cells[columnIndex]);
            if (va < vb) { return direction === 'asc' ? -1 : 1; }
            if (va > vb) { return direction === 'asc' ?  1 : -1; }
            return 0;
        });
        rows.forEach(function (row) { tbody.appendChild(row); });
    }

    function syncBulkUi(table, opts) {
        const selected = table.querySelectorAll('tbody input[type="checkbox"][data-contai-row]:checked');
        const count = selected.length;

        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(function (row) {
            const cb = row.querySelector('input[type="checkbox"][data-contai-row]');
            row.classList.toggle('is-selected', !!(cb && cb.checked));
        });

        if (opts.toolbar) {
            opts.toolbar.classList.toggle('is-bulk', count > 0);
            const countEl = opts.toolbar.querySelector('[data-contai-bulk-count]');
            if (countEl) {
                countEl.textContent = count + (count === 1 ? ' selected' : ' selected');
                countEl.style.display = count > 0 ? '' : 'none';
            }
            opts.toolbar.querySelectorAll('[data-contai-bulk-action]').forEach(function (btn) {
                btn.hidden = count === 0;
            });
        }

        if (typeof opts.onSelectionChange === 'function') {
            opts.onSelectionChange(Array.prototype.map.call(selected, function (cb) { return cb.value; }));
        }
    }

    Contai.Table = {
        init: function (tableEl, opts) {
            if (!tableEl) { return; }
            opts = opts || {};

            // Sorting
            const sortHeaders = tableEl.querySelectorAll('thead th.sort');
            sortHeaders.forEach(function (th, index) {
                const columnIndex = th.cellIndex;
                th.addEventListener('click', function () {
                    const wasAsc = th.classList.contains('sorted') && th.getAttribute('data-sort-dir') === 'asc';
                    const direction = wasAsc ? 'desc' : 'asc';

                    sortHeaders.forEach(function (other) {
                        other.classList.remove('sorted');
                        other.removeAttribute('data-sort-dir');
                    });
                    th.classList.add('sorted');
                    th.setAttribute('data-sort-dir', direction);
                    sortTableByColumn(tableEl, columnIndex, direction);
                });
            });

            // Bulk selection
            const headCheckbox = tableEl.querySelector('thead input[type="checkbox"][data-contai-select-all]');
            const rowCheckboxes = tableEl.querySelectorAll('tbody input[type="checkbox"][data-contai-row]');

            if (headCheckbox) {
                headCheckbox.addEventListener('change', function () {
                    rowCheckboxes.forEach(function (cb) { cb.checked = headCheckbox.checked; });
                    syncBulkUi(tableEl, opts);
                });
            }
            rowCheckboxes.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    if (headCheckbox) {
                        const all = Array.prototype.every.call(rowCheckboxes, function (x) { return x.checked; });
                        const some = Array.prototype.some.call(rowCheckboxes, function (x) { return x.checked; });
                        headCheckbox.checked = all;
                        headCheckbox.indeterminate = !all && some;
                    }
                    syncBulkUi(tableEl, opts);
                });
            });
            syncBulkUi(tableEl, opts);
        }
    };
})(window, document);
