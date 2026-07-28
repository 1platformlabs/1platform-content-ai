/**
 * Search Console performance panel (SCP-04).
 *
 * Fetches the two free read endpoints through the plugin's own REST routes
 * and paints the KPIs + breakdown tables. Every value goes in via
 * `textContent`, never `innerHTML`: queries and page URLs are real strings
 * typed by third parties into Google, and are not trusted input.
 */
(function () {
	'use strict';

	var config = window.contaiScPerformance || {};
	var root = document.getElementById('contai-sc-performance');
	if (!root || !config.restUrl) {
		return;
	}

	var statusEl = document.getElementById('contai-sc-perf-status');
	var kpisEl = document.getElementById('contai-sc-perf-kpis');
	var tablesEl = document.getElementById('contai-sc-perf-tables');
	var lagEl = document.getElementById('contai-sc-perf-lag');
	var rowsEl = document.getElementById('contai-sc-perf-rows');
	var emptyEl = document.getElementById('contai-sc-perf-empty');
	var keyHeaderEl = document.getElementById('contai-sc-perf-key-header');
	var periodEl = document.getElementById('contai-sc-period');

	var i18n = config.i18n || {};
	var current = null;
	var dimension = 'queries';

	function text(el, value) {
		if (el) {
			el.textContent = value;
		}
	}

	function formatCount(value) {
		return Math.round(Number(value) || 0).toLocaleString();
	}

	function formatPercent(value) {
		return ((Number(value) || 0) * 100).toFixed(1) + '%';
	}

	function formatPosition(value) {
		return (Number(value) || 0).toFixed(1);
	}

	/**
	 * Average position is the one metric where LOWER is better, so a fall is
	 * an improvement — rendering it with the same treatment as falling clicks
	 * would report a good month as a bad one.
	 */
	function applyDelta(id, changePercent, lowerIsBetter) {
		var el = document.getElementById('contai-sc-perf-' + id + '-delta');
		if (!el) {
			return;
		}
		if (changePercent === null || changePercent === undefined) {
			el.textContent = '';
			el.className = 'contai-sc-perf-kpi-delta';
			return;
		}
		var improved = lowerIsBetter ? changePercent < 0 : changePercent > 0;
		el.textContent = (changePercent > 0 ? '+' : '') + changePercent.toFixed(1) + '%';
		el.className =
			'contai-sc-perf-kpi-delta ' +
			(changePercent === 0 ? 'is-flat' : improved ? 'is-up' : 'is-down');
	}

	function renderRows() {
		if (!current || !rowsEl) {
			return;
		}
		var rows = current[dimension] || [];
		rowsEl.replaceChildren();

		if (keyHeaderEl) {
			keyHeaderEl.textContent =
				(i18n.keyHeader && i18n.keyHeader[dimension]) || keyHeaderEl.textContent;
		}

		if (rows.length === 0) {
			if (emptyEl) emptyEl.hidden = false;
			return;
		}
		if (emptyEl) emptyEl.hidden = true;

		rows.forEach(function (row) {
			var tr = document.createElement('tr');
			[
				String(row.key || ''),
				formatCount(row.clicks),
				formatCount(row.impressions),
				formatPercent(row.ctr),
				formatPosition(row.position)
			].forEach(function (value) {
				var td = document.createElement('td');
				td.textContent = value;
				tr.appendChild(td);
			});
			rowsEl.appendChild(tr);
		});
	}

	function render(data) {
		current = data;

		text(document.getElementById('contai-sc-perf-clicks'), formatCount(data.summary.clicks.current));
		text(document.getElementById('contai-sc-perf-impressions'), formatCount(data.summary.impressions.current));
		text(document.getElementById('contai-sc-perf-ctr'), formatPercent(data.summary.ctr.current));
		text(document.getElementById('contai-sc-perf-position'), formatPosition(data.summary.position.current));

		applyDelta('clicks', data.summary.clicks.change_percent, false);
		applyDelta('impressions', data.summary.impressions.change_percent, false);
		applyDelta('ctr', data.summary.ctr.change_percent, false);
		applyDelta('position', data.summary.position.change_percent, true);

		if (lagEl) {
			lagEl.hidden = Number(data.summary.impressions.current) !== 0;
		}

		if (statusEl) statusEl.hidden = true;
		if (kpisEl) kpisEl.hidden = false;
		if (tablesEl) tablesEl.hidden = false;

		renderRows();
	}

	function fail(message) {
		if (statusEl) {
			statusEl.hidden = false;
			statusEl.textContent = message;
		}
		if (kpisEl) kpisEl.hidden = true;
		if (tablesEl) tablesEl.hidden = true;
	}

	function load() {
		var period = periodEl ? periodEl.value : '28d';
		if (statusEl) {
			statusEl.hidden = false;
			statusEl.textContent = i18n.loading || 'Loading…';
		}
		if (kpisEl) kpisEl.hidden = true;
		if (tablesEl) tablesEl.hidden = true;

		fetch(config.restUrl + '?period=' + encodeURIComponent(period), {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin'
		})
			.then(function (response) {
				// `response.ok` first: a 409/429 body still parses as JSON and
				// would otherwise be rendered as if it were a report.
				if (response.status === 409) {
					throw new Error(i18n.notVerified || 'This website is not verified yet.');
				}
				if (response.status === 429) {
					throw new Error(i18n.quota || 'Too many requests right now. Try again in a few minutes.');
				}
				if (!response.ok) {
					throw new Error(i18n.error || 'Could not load performance data.');
				}
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					throw new Error(i18n.error || 'Could not load performance data.');
				}
				render(payload.data);
			})
			.catch(function (err) {
				fail(err.message || i18n.error || 'Could not load performance data.');
			});
	}

	/**
	 * SCP-05 — add the Google-reported columns to the sitemaps table that the
	 * panel already rendered server-side.
	 *
	 * Additive by design: on any failure, or when Search Console could not be
	 * consulted (`google_status_available: false`), the table is left exactly
	 * as PHP rendered it. A count Google did not report shows "—", never 0 —
	 * "no errors" is a claim the user would act on.
	 */
	function enrichSitemaps() {
		if (!config.sitemapsUrl) {
			return;
		}
		var table = document.querySelector('.contai-sitemaps-table');
		if (!table) {
			return;
		}

		fetch(config.sitemapsUrl, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin'
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('unavailable');
				}
				return response.json();
			})
			.then(function (payload) {
				var data = payload && payload.data;
				if (!payload || !payload.success || !data || !data.google_status_available) {
					// Degraded upstream — leave the table untouched.
					return;
				}

				var byUrl = {};
				(data.sitemaps || []).forEach(function (row) {
					byUrl[row.url] = row;
				});

				var headRow = table.tHead && table.tHead.rows[0];
				if (!headRow) {
					return;
				}
				[i18n.colIndexed || 'Indexed / read', i18n.colIssues || 'Issues'].forEach(function (label) {
					var th = document.createElement('th');
					th.textContent = label;
					headRow.appendChild(th);
				});

				Array.prototype.forEach.call(table.tBodies[0].rows, function (tr) {
					var row = byUrl[tr.getAttribute('data-sitemap-url')] || {};
					var indexed = document.createElement('td');
					indexed.textContent = countOrDash(row.urls_indexed) + ' / ' + countOrDash(row.urls_submitted);
					tr.appendChild(indexed);

					var issues = document.createElement('td');
					issues.textContent = issuesLabel(row);
					tr.appendChild(issues);
				});
			})
			.catch(function () {
				/* Additive only — the table stays as rendered. */
			});
	}

	function countOrDash(value) {
		return value === null || value === undefined ? '—' : Number(value).toLocaleString();
	}

	function issuesLabel(row) {
		if ((row.errors === null || row.errors === undefined) &&
			(row.warnings === null || row.warnings === undefined)) {
			return '—';
		}
		if (row.errors) {
			return String(row.errors) + ' ' + (i18n.errors || 'errors');
		}
		if (row.warnings) {
			return String(row.warnings) + ' ' + (i18n.warnings || 'warnings');
		}
		return i18n.noIssues || 'No issues';
	}

	if (periodEl) {
		periodEl.addEventListener('change', load);
	}

	Array.prototype.forEach.call(
		root.querySelectorAll('.contai-sc-perf-tab'),
		function (tab) {
			tab.addEventListener('click', function () {
				dimension = tab.getAttribute('data-dimension') || 'queries';
				Array.prototype.forEach.call(
					root.querySelectorAll('.contai-sc-perf-tab'),
					function (other) {
						var active = other === tab;
						other.classList.toggle('is-active', active);
						other.setAttribute('aria-selected', active ? 'true' : 'false');
					}
				);
				renderRows();
			});
		}
	);

	load();
	enrichSitemaps();
})();
