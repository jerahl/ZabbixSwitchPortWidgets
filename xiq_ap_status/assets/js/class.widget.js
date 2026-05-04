/**
 * Extreme XIQ AP Status — frontend class.
 *
 * Reads the JSON payload embedded by widget.view.php, renders the table,
 * and wires per-row kebab menus. Action buttons POST to the widget's action
 * controller; LROs are polled until SUCCEEDED/FAILED or timeout.
 *
 * Sort state, page, and filter are held in instance state so they survive
 * auto-refresh but reset on dashboard reload.
 *
 * PacketFence integration
 * ───────────────────────
 * Clicking an AP row (anywhere outside links/buttons) selects it, highlights
 * it, and fires two signals:
 *
 *   1. document CustomEvent "xiq:ap_selected"  — works across any widget
 *      on the same page. The PacketFence widget should listen:
 *        document.addEventListener('xiq:ap_selected', e => { ... e.detail ... });
 *      detail = { serial, mac, name, xiq_id } | null (null = deselect)
 *
 *   2. this.broadcast({ xiq_ap_selected: <detail> }) — Zabbix 7.x linked-
 *      widget broadcast API. Wire up the link in the dashboard editor.
 *
 * Paging (inspired by gryan337/zabbix-widgets-table)
 * ────────────────────────────────────────────────────
 * All filtering and paging happen client-side against the payload already
 * in memory, so no extra server round-trips occur. Controls rendered into
 * .xiq-toolbar and .xiq-pagination placeholders in widget.view.php:
 *   • Search input  — live filter on name / serial / MAC / IP / version
 *   • Page-size     — 10 / 25 / 50 / 100 rows per page
 *   • Prev / Next   — simple page navigation with "X–Y of Z" counter
 */

class WidgetXiqApStatus extends CWidget {

	static VERSION_MARKER = 'xiq-ap-status-v1';

	// Maximum time (ms) to poll an LRO before giving up.
	static LRO_TIMEOUT_MS = 120000;
	static LRO_POLL_MS    = 3000;

	onInitialize() {
		super.onInitialize();
		this._sort_key   = 'name';
		this._sort_dir   = 'asc';
		this._payload    = null;
		this._open_kebab = null;
		// Paging / search state
		this._page          = 1;
		this._page_size     = 25;
		this._filter_text   = '';
		// Summary-tile filter: null = show all; otherwise one of
		//   'connected' | 'disconnected' | 'cfg_mismatch' | 'clients_total'
		this._tile_filter   = null;
		// PacketFence: track currently-selected AP serial
		this._selected_serial = null;
	}

	onActivate() {
		super.onActivate();
		this._outside_handler = (e) => {
			if (this._open_kebab && !e.target.closest('.xiq-kebab')) {
				this._closeKebab();
			}
		};
		document.addEventListener('click', this._outside_handler);
	}

	onDeactivate() {
		super.onDeactivate();
		if (this._outside_handler) {
			document.removeEventListener('click', this._outside_handler);
			this._outside_handler = null;
		}
	}

	setContents(response) {
		super.setContents(response);
		const root = this._body;
		if (!root) return;

		const blob = root.querySelector('script.xiq-data');
		if (!blob) return;

		try {
			this._payload = JSON.parse(blob.textContent || '{}');
		} catch (e) {
			console.error('xiq_ap_status: failed to parse payload', e);
			return;
		}

		this._renderError(root);
		this._renderTruncation(root);
		this._renderSummary(root);
		this._wireSearch(root);
		this._wirePageSizeSelect(root);
		this._renderTable(root);
		this._wireSorting(root);
	}

	// ── Render ────────────────────────────────────────────────────────────

	_renderError(root) {
		const banner = root.querySelector('.xiq-error-banner');
		if (!banner) return;
		if (this._payload.error) {
			banner.textContent = this._payload.error;
			banner.style.display = '';
		} else {
			banner.textContent = '';
			banner.style.display = 'none';
		}
	}

	_renderTruncation(root) {
		const banner = root.querySelector('.xiq-truncated-banner');
		if (!banner) return;
		if (this._payload.truncated) {
			banner.textContent = `Showing first ${this._payload.truncated_at} APs. Increase "Max table rows" to see more.`;
			banner.style.display = '';
		} else {
			banner.style.display = 'none';
		}
	}

	_renderSummary(root) {
		const s = this._payload.summary || {};
		root.querySelectorAll('.xiq-tile').forEach((tile) => {
			const field = tile.getAttribute('data-field');
			const counter = tile.querySelector('.xiq-tile__count');
			if (counter) counter.textContent = (s[field] ?? 0).toLocaleString();

			// Active state: 'total' is active when no filter is set;
			// any other tile is active when its field matches _tile_filter.
			const is_active = (this._tile_filter === null && field === 'total')
				|| (this._tile_filter === field);
			tile.classList.toggle('xiq-tile--active', is_active);
			tile.setAttribute('aria-pressed', is_active ? 'true' : 'false');

			// Wire click once per tile element. setContents() may re-run on
			// auto-refresh, but the tile DOM is reused, so the flag persists.
			if (!tile._xiq_wired) {
				tile._xiq_wired = true;
				tile.setAttribute('role', 'button');
				tile.setAttribute('tabindex', '0');
				const handler = () => this._onTileClick(field, root);
				tile.addEventListener('click', handler);
				tile.addEventListener('keydown', (e) => {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						handler();
					}
				});
			}
		});
	}

	/**
	 * Tile click → toggle a category filter.
	 * - Clicking 'total' always clears the filter.
	 * - Clicking the currently-active tile also clears it (toggle off).
	 * - Clicking any other tile sets it as the sole filter.
	 */
	_onTileClick(field, root) {
		if (field === 'total' || this._tile_filter === field) {
			this._tile_filter = null;
		} else {
			this._tile_filter = field;
		}
		this._page = 1; // reset to first page when category changes

		// Update the active class on every tile in one pass, no re-render needed
		root.querySelectorAll('.xiq-tile').forEach((t) => {
			const f = t.getAttribute('data-field');
			const is_active = (this._tile_filter === null && f === 'total')
				|| (this._tile_filter === f);
			t.classList.toggle('xiq-tile--active', is_active);
			t.setAttribute('aria-pressed', is_active ? 'true' : 'false');
		});

		this._renderTable(root);
	}

	_renderTable(root) {
		const tbody = root.querySelector('.xiq-tbody');
		if (!tbody) return;

		const allRows = (this._payload.rows || []).slice();
		this._sortRows(allRows);

		const filtered = this._filterRows(allRows);
		const paged    = this._pageRows(filtered);

		tbody.innerHTML = paged.map((r) => this._rowHtml(r)).join('');

		// Wire kebabs
		tbody.querySelectorAll('.xiq-kebab__toggle').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.stopPropagation();
				this._toggleKebab(btn.closest('.xiq-kebab'));
			});
		});

		// Wire action buttons
		tbody.querySelectorAll('[data-act]').forEach((el) => {
			el.addEventListener('click', (e) => {
				e.stopPropagation();
				const tr = el.closest('tr.xiq-row');
				if (!tr) return;
				const serial = tr.dataset.serial;
				const xiqId  = tr.dataset.xiqid;
				const name   = tr.dataset.name;
				this._closeKebab();
				this._handleAction(el.dataset.act, {serial, xiqId, name, tr});
			});
		});

		// ── PacketFence: row click sends AP data to the PF widget ─────────
		tbody.querySelectorAll('tr.xiq-row').forEach((tr) => {
			tr.style.cursor = 'pointer';
			tr.addEventListener('click', (e) => {
				// Ignore clicks inside links, buttons, or the kebab menu.
				if (e.target.closest('a, button, .xiq-kebab')) return;
				this._onRowClick(tr, root);
			});
		});

		// Re-apply selected highlight after re-render (e.g. after sort/page)
		if (this._selected_serial) {
			const selTr = tbody.querySelector(
				`tr.xiq-row[data-serial="${CSS.escape(this._selected_serial)}"]`
			);
			if (selTr) selTr.classList.add('xiq-row--selected');
		}

		this._renderPagination(root, filtered.length, allRows.length);
	}

	// ── PacketFence integration ───────────────────────────────────────────
	//
	// The PacketFence widget (pf_device) listens for these document events,
	// in priority order:
	//
	//   pf:deviceSelected    — unified event (preferred)
	//   mcs:cameraSelected   — legacy camera-only path
	//   sw:portSelected      — legacy switchport path
	//
	// detail contract for pf:deviceSelected = {
	//   mac:    string  // required — what PF queries by
	//   ip:     string  // optional fallback if MAC isn't resolvable
	//   name:   string  // shown in the PF widget header
	//   host:   string  // shown in the PF widget header
	//   source: string  // tag identifying the originator (we use 'xiq_ap')
	// }
	//
	// The PF widget also restores the last selection from
	// sessionStorage['pf_device_selection'] on activate, so we MUST write
	// there too — otherwise selections don't survive a dashboard reload.

	static PF_EVENT          = 'pf:deviceSelected';
	static PF_SESSION_KEY    = 'pf_device_selection';
	static PF_SOURCE         = 'xiq_ap';
	// Kept for any non-PF listener that wants raw XIQ context.
	static XIQ_LEGACY_EVENT  = 'xiq:ap_selected';

	/**
	 * Called when an AP row is clicked. Toggles selection and broadcasts.
	 */
	_onRowClick(tr, root) {
		const serial = tr.dataset.serial;
		console.debug('[xiq_ap_status] row click', {
			serial, mac: tr.dataset.mac, name: tr.dataset.name, ip: tr.dataset.ip
		});

		if (this._selected_serial === serial) {
			// Second click on the same row → deselect
			tr.classList.remove('xiq-row--selected');
			this._selected_serial = null;
			this._sendToPacketFence(null);
			return;
		}

		// Deselect any previously-selected row
		const tbody = tr.closest('tbody');
		if (tbody) {
			tbody.querySelectorAll('tr.xiq-row--selected')
				.forEach(r => r.classList.remove('xiq-row--selected'));
		}
		tr.classList.add('xiq-row--selected');
		this._selected_serial = serial;

		// Build the detail using the PF widget's expected field names.
		// We populate `host` from the payload's host_label (the Zabbix host
		// carrying this XIQ template), which is what shows in the PF header.
		const host_label = (this._payload && this._payload.host_label) || '';
		this._sendToPacketFence({
			mac:    tr.dataset.mac   || '',
			ip:     tr.dataset.ip    || '',
			name:   tr.dataset.name  || '',
			host:   host_label,
			source: WidgetXiqApStatus.PF_SOURCE,
			// Extras the PF widget ignores but that may be useful for
			// other listeners or future audit/debug needs.
			serial: tr.dataset.serial || '',
			xiq_id: tr.dataset.xiqid  || '',
		});
	}

	/**
	 * Dispatch the PF selection event in the exact shape pf_device expects,
	 * and persist it so the PF widget can restore on dashboard reload.
	 *
	 * Pass null to clear the selection.
	 */
	_sendToPacketFence(apData) {
		const evt = WidgetXiqApStatus.PF_EVENT;
		console.debug('[xiq_ap_status] →', evt, apData === null ? '(deselect)' : apData);

		// 1. Persist to sessionStorage under the key pf_device restores from.
		//    pf_device.onActivate() reads this on dashboard reload.
		try {
			if (apData === null) {
				sessionStorage.removeItem(WidgetXiqApStatus.PF_SESSION_KEY);
			} else {
				sessionStorage.setItem(
					WidgetXiqApStatus.PF_SESSION_KEY,
					JSON.stringify(apData)
				);
			}
		} catch (_) { /* sessionStorage may be disabled — ignore */ }

		// 2. Fire pf:deviceSelected on document — the primary channel.
		//    pf_device.onActivate() registers a document-level listener.
		try {
			document.dispatchEvent(new CustomEvent(evt, {
				detail:  apData,
				bubbles: true,
			}));
		} catch (e) {
			console.warn('[xiq_ap_status] dispatchEvent failed:', e.message);
		}

		// 3. Also fire the legacy xiq-namespaced event for any other widget
		//    that might be listening for raw XIQ context (debug, logging,
		//    custom integrations). The PF widget ignores this one.
		try {
			document.dispatchEvent(new CustomEvent(WidgetXiqApStatus.XIQ_LEGACY_EVENT, {
				detail: apData, bubbles: true,
			}));
		} catch (_) {}

		// 4. Stash on a global so anything can read the latest selection
		//    without subscribing — useful for the debug helper below.
		try { window.xiqSelectedAp = apData; } catch (_) {}

		// Install a one-time DevTools helper so the user can verify the
		// broadcast manually without clicking a row. Run from console:
		//   window.xiqDebug.test()           — fire a fake selection
		//   window.xiqDebug.lastSelected()   — see the last payload
		//   window.xiqDebug.listenerProbe()  — confirm dispatchEvent works
		if (!window.xiqDebug) {
			window.xiqDebug = {
				lastSelected: () => window.xiqSelectedAp,
				test: (data) => {
					const t = data || {
						mac: 'AA:BB:CC:DD:EE:FF', ip: '10.0.0.42',
						name: 'TestAP', host: 'xiq-host', source: 'xiq_ap',
					};
					console.log('[xiq_ap_status] DEBUG firing test pf:deviceSelected', t);
					sessionStorage.setItem('pf_device_selection', JSON.stringify(t));
					document.dispatchEvent(new CustomEvent('pf:deviceSelected', {
						detail: t, bubbles: true,
					}));
					window.xiqSelectedAp = t;
					return t;
				},
				listenerProbe: () => {
					let heard = false;
					const probe = () => { heard = true; };
					document.addEventListener('pf:deviceSelected', probe, {once: true});
					document.dispatchEvent(new CustomEvent('pf:deviceSelected', {
						detail: {__probe: true}, bubbles: true,
					}));
					document.removeEventListener('pf:deviceSelected', probe);
					console.log(heard
						? '[xiq_ap_status] dispatchEvent works (the local probe heard it)'
						: '[xiq_ap_status] dispatchEvent FAILED (probe did not fire)');
					return heard;
				},
			};
		}
	}

	// ── Filter / page ─────────────────────────────────────────────────────

	_filterRows(rows) {
		let result = rows;

		// 1. Apply summary-tile category filter, if any.
		switch (this._tile_filter) {
			case 'connected':
				result = result.filter(r => !!r.connected);
				break;
			case 'disconnected':
				result = result.filter(r => !r.connected);
				break;
			case 'cfg_mismatch':
				result = result.filter(r => !!r.cfg_mismatch);
				break;
			case 'clients_total':
				// "Active clients" tile filters to APs that currently have ≥1 client.
				// (The tile counter sums clients across APs; the filter shows the APs
				// contributing to that count.)
				result = result.filter(r => Number(r.clients) > 0);
				break;
			// null / 'total' → no category filter
		}

		// 2. Apply free-text search on top.
		const q = this._filter_text.trim().toLowerCase();
		if (q) {
			result = result.filter((r) =>
				(r.name    || '').toLowerCase().includes(q) ||
				(r.serial  || '').toLowerCase().includes(q) ||
				(r.mac     || '').toLowerCase().includes(q) ||
				(r.ip      || '').toLowerCase().includes(q) ||
				(r.version || '').toLowerCase().includes(q)
			);
		}
		return result;
	}

	_pageRows(rows) {
		const start = (this._page - 1) * this._page_size;
		return rows.slice(start, start + this._page_size);
	}

	// ── Pagination controls ───────────────────────────────────────────────

	_renderPagination(root, filtered_count, total_count) {
		const container = root.querySelector('.xiq-pagination');
		if (!container) return;

		const total_pages = Math.max(1, Math.ceil(filtered_count / this._page_size));
		if (this._page > total_pages) this._page = total_pages;

		const start = filtered_count === 0 ? 0 : (this._page - 1) * this._page_size + 1;
		const end   = Math.min(this._page * this._page_size, filtered_count);

		// Build page-number buttons: always show first, last, and up to 2
		// neighbours of current page — matching gryan337 table widget style.
		const pageNums = this._pageNumbers(this._page, total_pages);
		const pageHtml = pageNums.map((p) => {
			if (p === '…') return `<span class="xiq-page-ellipsis">…</span>`;
			return `<button class="xiq-page-num${p === this._page ? ' xiq-page-num--active' : ''}"
				data-page="${p}" ${p === this._page ? 'aria-current="page"' : ''}>${p}</button>`;
		}).join('');

		const has_filter = !!(this._filter_text || this._tile_filter);
		const filterNote = (has_filter && filtered_count < total_count)
			? ` <span class="xiq-filter-note">(filtered from ${total_count})</span>`
			: '';

		container.innerHTML = `
			<div class="xiq-page-info">
				${filtered_count === 0 ? 'No APs match' : `${start}–${end} of ${filtered_count}`}${filterNote}
			</div>
			<div class="xiq-page-nav">
				<button class="xiq-page-btn xiq-page-prev" data-dir="-1"
					${this._page <= 1 ? 'disabled' : ''} aria-label="Previous page">‹</button>
				${pageHtml}
				<button class="xiq-page-btn xiq-page-next" data-dir="1"
					${this._page >= total_pages ? 'disabled' : ''} aria-label="Next page">›</button>
			</div>
		`;

		// Prev / Next
		container.querySelectorAll('.xiq-page-btn').forEach((btn) => {
			btn.addEventListener('click', () => {
				const dir = parseInt(btn.dataset.dir, 10);
				this._page = Math.max(1, Math.min(total_pages, this._page + dir));
				this._renderTable(root);
			});
		});

		// Numbered page buttons
		container.querySelectorAll('.xiq-page-num').forEach((btn) => {
			btn.addEventListener('click', () => {
				this._page = parseInt(btn.dataset.page, 10);
				this._renderTable(root);
			});
		});
	}

	/** Return page number sequence with ellipsis, e.g. [1, '…', 4, 5, 6, '…', 12] */
	_pageNumbers(current, total) {
		if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
		const pages = new Set([1, total, current]);
		for (let d = -2; d <= 2; d++) {
			const p = current + d;
			if (p >= 1 && p <= total) pages.add(p);
		}
		const sorted = [...pages].sort((a, b) => a - b);
		const result = [];
		let prev = 0;
		for (const p of sorted) {
			if (p - prev > 1) result.push('…');
			result.push(p);
			prev = p;
		}
		return result;
	}

	// ── Search & page-size wiring ─────────────────────────────────────────

	_wireSearch(root) {
		const input = root.querySelector('.xiq-search');
		if (!input || input._xiq_wired) return;
		input._xiq_wired = true;
		// Restore current filter text (survives setContents re-calls)
		input.value = this._filter_text;
		let debounce;
		input.addEventListener('input', () => {
			clearTimeout(debounce);
			debounce = setTimeout(() => {
				this._filter_text = input.value;
				this._page = 1;
				this._renderTable(root);
			}, 220);
		});
		// Clear button (× inside the search input)
		const clear = root.querySelector('.xiq-search-clear');
		if (clear) {
			clear.addEventListener('click', () => {
				input.value = '';
				this._filter_text = '';
				this._page = 1;
				input.focus();
				this._renderTable(root);
			});
		}
	}

	_wirePageSizeSelect(root) {
		const sel = root.querySelector('.xiq-page-size');
		if (!sel || sel._xiq_wired) return;
		sel._xiq_wired = true;
		sel.value = String(this._page_size);
		sel.addEventListener('change', () => {
			this._page_size = parseInt(sel.value, 10) || 25;
			this._page = 1;
			this._renderTable(root);
		});
	}

	// ── Row HTML ──────────────────────────────────────────────────────────

	_rowHtml(r) {
		const flags = this._payload.flags || {};
		const urls  = this._payload.urls  || {};
		const cfg_warn = r.cfg_mismatch ? '<span class="xiq-pill xiq-pill--warn" title="Config mismatch">drift</span>' : '';
		const status_pill = r.connected
			? '<span class="xiq-pill xiq-pill--up">Connected</span>'
			: '<span class="xiq-pill xiq-pill--down">Disconnected</span>';

		const last   = this._timeAgo(r.last_connect);
		const uptime = this._fmtUptime(r.uptime);

		// Build kebab menu items per enabled action.
		const items = [];
		const xiq_href = (urls.xiq_admin_url && r.xiq_id)
			? `${urls.xiq_admin_url}/devices/${encodeURIComponent(r.xiq_id)}`
			: '';
		if (xiq_href) {
			items.push(`<a class="xiq-menu__item" href="${xiq_href}" target="_blank" rel="noopener">Open in XIQ</a>`);
		}
		if (flags.enable_refresh) {
			items.push(`<button class="xiq-menu__item" data-act="refresh" type="button">Refresh now</button>`);
		}
		if (flags.enable_reboot) {
			items.push(`<button class="xiq-menu__item" data-act="reboot" type="button">Reboot AP…</button>`);
		}
		if (flags.enable_manage) {
			items.push(`<button class="xiq-menu__item" data-act="manage" type="button">Set Managed…</button>`);
			items.push(`<button class="xiq-menu__item" data-act="unmanage" type="button">Set Unmanaged…</button>`);
		}
		if (flags.enable_cli) {
			items.push(`<button class="xiq-menu__item" data-act="cli" type="button">Run show command…</button>`);
		}

		const kebab = items.length
			? `<div class="xiq-kebab">
				<button class="xiq-kebab__toggle" type="button" aria-label="Actions">⋮</button>
				<div class="xiq-kebab__menu" role="menu">${items.join('')}</div>
			</div>`
			: '';

		const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
			'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
		}[c]));

		const name_html = xiq_href
			? `<a class="xiq-name xiq-name--link" href="${xiq_href}" target="_blank" rel="noopener" title="Open ${esc(r.name)} in XIQ">${esc(r.name)}</a>`
			: `<span class="xiq-name">${esc(r.name)}</span>`;

		const last_title = (r.last_connect && Number.isFinite(Number(r.last_connect)) && Number(r.last_connect) > 1262304000)
			? new Date(Number(r.last_connect) * 1000).toLocaleString()
			: '';

		return `
			<tr class="xiq-row" data-serial="${esc(r.serial)}" data-xiqid="${esc(r.xiq_id)}" data-name="${esc(r.name)}" data-mac="${esc(r.mac)}" data-ip="${esc(r.ip)}">
				<td class="xiq-cell--name">
					${name_html}
					<div class="xiq-sub">${esc(r.serial)}${r.mac ? ' · ' + esc(r.mac) : ''}</div>
				</td>
				<td class="xiq-cell--status">${status_pill} ${cfg_warn}</td>
				<td>${esc(r.ip)}</td>
				<td>${(r.clients ?? 0).toLocaleString()}</td>
				<td>${esc(r.version)}</td>
				<td title="${last_title}">${last}</td>
				<td>${uptime}</td>
				<td class="xiq-cell--actions">${kebab}</td>
			</tr>
			<tr class="xiq-cli-panel-row" data-cli-for="${esc(r.serial)}" style="display:none">
				<td colspan="8"><div class="xiq-cli-panel"></div></td>
			</tr>
		`;
	}

	_sortRows(rows) {
		const k   = this._sort_key;
		const dir = this._sort_dir === 'desc' ? -1 : 1;
		rows.sort((a, b) => {
			let va = a[k], vb = b[k];
			if (typeof va === 'string') va = va.toLowerCase();
			if (typeof vb === 'string') vb = vb.toLowerCase();
			if (va === undefined || va === null) va = '';
			if (vb === undefined || vb === null) vb = '';
			if (va < vb) return -1 * dir;
			if (va > vb) return  1 * dir;
			return 0;
		});
	}

	_wireSorting(root) {
		root.querySelectorAll('.xiq-table thead [data-sort]').forEach((th) => {
			if (th._wired) return;
			th._wired = true;
			th.addEventListener('click', () => {
				const k = th.getAttribute('data-sort');
				if (this._sort_key === k) {
					this._sort_dir = this._sort_dir === 'asc' ? 'desc' : 'asc';
				} else {
					this._sort_key = k;
					this._sort_dir = 'asc';
				}
				this._page = 1; // reset to page 1 on sort change
				this._renderTable(root);
			});
		});
	}

	// ── Kebab ─────────────────────────────────────────────────────────────

	_toggleKebab(kebab) {
		if (!kebab) return;
		if (this._open_kebab === kebab) {
			this._closeKebab();
			return;
		}
		this._closeKebab();
		kebab.classList.add('xiq-kebab--open');
		this._open_kebab = kebab;
	}

	_closeKebab() {
		if (this._open_kebab) {
			this._open_kebab.classList.remove('xiq-kebab--open');
			this._open_kebab = null;
		}
	}

	// ── Actions ───────────────────────────────────────────────────────────

	async _handleAction(op, ctx) {
		const tr = ctx.tr;

		if (op === 'reboot') {
			if (!confirm(`Reboot ${ctx.name}? The AP will drop clients while it restarts.`)) return;
			this._setRowStatus(tr, 'pending', 'Rebooting…');
			const r = await this._postAction({op, device_ids: [ctx.xiqId]});
			this._handleActionResult(tr, r, 'Reboot queued');
			return;
		}
		if (op === 'manage' || op === 'unmanage') {
			const verb = op === 'manage' ? 'Managed' : 'Unmanaged';
			if (!confirm(`Set ${ctx.name} to ${verb}?`)) return;
			this._setRowStatus(tr, 'pending', `Setting ${verb}…`);
			const r = await this._postAction({op, device_ids: [ctx.xiqId]});
			this._handleActionResult(tr, r, `${verb} queued`);
			return;
		}
		if (op === 'refresh') {
			this._setRowStatus(tr, 'pending', 'Queuing refresh…');
			const r = await this._postAction({op});
			this._handleActionResult(tr, r, 'Refresh queued', /*no_lro*/ true);
			return;
		}
		if (op === 'cli') {
			this._showCliPanel(tr, ctx);
			return;
		}
	}

	async _postAction(payload) {
		const cfg  = this._payload.config || {};
		const urls = this._payload.urls   || {};
		const body = new URLSearchParams();
		body.append('op', payload.op);
		body.append('xiq_url', urls.xiq_url || '');
		body.append('verify_ssl', cfg.verify_ssl ? '1' : '0');
		body.append('action_token_path', cfg.action_token_path || '');
		body.append('hostid', String(cfg.hostid || ''));
		(cfg.cli_allowlist || []).forEach((c) => body.append('cli_allowlist[]', c));
		(payload.device_ids || []).forEach((id) => body.append('device_ids[]', String(id)));
		if (payload.command) body.append('command', payload.command);
		if (payload.lro_id)  body.append('lro_id',  payload.lro_id);

		try {
			const resp = await fetch('zabbix.php?action=widget.xiq_ap_status.action', {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: body.toString(),
				credentials: 'same-origin',
			});
			const text = await resp.text();
			try {
				return JSON.parse(text);
			} catch (e) {
				return {ok: false, message: 'Non-JSON response: ' + text.slice(0, 200)};
			}
		} catch (e) {
			return {ok: false, message: 'Network error: ' + e.message};
		}
	}

	_handleActionResult(tr, r, success_msg, no_lro = false) {
		if (!r.ok) {
			this._setRowStatus(tr, 'error', r.message || 'Failed');
			return;
		}
		if (!no_lro && r.lro_id) {
			this._setRowStatus(tr, 'pending', `${success_msg} (LRO ${r.lro_id.slice(0,8)}…)`);
			this._pollLro(tr, r.lro_id);
		} else {
			this._setRowStatus(tr, 'success', success_msg);
		}
	}

	async _pollLro(tr, lro_id) {
		const start = Date.now();
		const tick = async () => {
			if (Date.now() - start > WidgetXiqApStatus.LRO_TIMEOUT_MS) {
				this._setRowStatus(tr, 'error', 'LRO timed out');
				return;
			}
			const r = await this._postAction({op: 'lro_status', lro_id});
			if (!r.ok) {
				this._setRowStatus(tr, 'error', r.message || 'LRO poll failed');
				return;
			}
			const status = (r.data && (r.data.status || r.data.state || '')).toString().toUpperCase();
			if (status === 'SUCCEEDED' || status === 'SUCCESS' || status === 'COMPLETED') {
				this._setRowStatus(tr, 'success', 'Done');
				return;
			}
			if (status === 'FAILED' || status === 'ERROR') {
				const msg = (r.data && (r.data.error_message || r.data.message)) || 'LRO failed';
				this._setRowStatus(tr, 'error', msg);
				return;
			}
			setTimeout(tick, WidgetXiqApStatus.LRO_POLL_MS);
		};
		setTimeout(tick, WidgetXiqApStatus.LRO_POLL_MS);
	}

	_setRowStatus(tr, kind, msg) {
		if (!tr) return;
		const cell = tr.querySelector('.xiq-cell--actions');
		if (!cell) return;
		const existing = cell.querySelector('.xiq-row-status');
		const html = `<span class="xiq-row-status xiq-row-status--${kind}">${msg.replace(/[<>&]/g, '')}</span>`;
		if (existing) existing.outerHTML = html;
		else cell.insertAdjacentHTML('beforeend', html);
	}

	// ── CLI panel ────────────────────────────────────────────────────────

	_showCliPanel(tr, ctx) {
		const panelRow = tr.nextElementSibling;
		if (!panelRow || !panelRow.classList.contains('xiq-cli-panel-row')) return;
		const panel = panelRow.querySelector('.xiq-cli-panel');
		if (!panel) return;

		const allow = (this._payload.config || {}).cli_allowlist || [];
		if (allow.length === 0) {
			panel.innerHTML = '<em>No commands in allow-list.</em>';
			panelRow.style.display = '';
			return;
		}

		const optHtml = allow.map((c) => `<option value="${c.replace(/"/g,'&quot;')}">${c}</option>`).join('');
		panel.innerHTML = `
			<div class="xiq-cli-bar">
				<label>Run on <strong>${this._esc(ctx.name)}</strong>:</label>
				<select class="xiq-cli-select">${optHtml}</select>
				<button type="button" class="xiq-cli-run">Run</button>
				<button type="button" class="xiq-cli-close">Close</button>
			</div>
			<pre class="xiq-cli-output"></pre>
		`;
		panelRow.style.display = '';

		const sel      = panel.querySelector('.xiq-cli-select');
		const out      = panel.querySelector('.xiq-cli-output');
		const runBtn   = panel.querySelector('.xiq-cli-run');
		const closeBtn = panel.querySelector('.xiq-cli-close');

		closeBtn.addEventListener('click', () => { panelRow.style.display = 'none'; });
		runBtn.addEventListener('click', async () => {
			const cmd = sel.value;
			if (!cmd) return;
			out.textContent = `Running: ${cmd}…`;
			runBtn.disabled = true;
			const r = await this._postAction({op: 'cli', device_ids: [ctx.xiqId], command: cmd});
			runBtn.disabled = false;
			if (!r.ok) {
				out.textContent = 'Error: ' + (r.message || 'unknown');
				return;
			}
			let text = '';
			if (r.data && Array.isArray(r.data.responses)) {
				for (const resp of r.data.responses) {
					if (resp.command) text += '$ ' + resp.command + '\n';
					if (resp.output)  text += resp.output + '\n';
				}
			} else if (r.data && r.data.output) {
				text = r.data.output;
			} else {
				text = JSON.stringify(r.data, null, 2);
			}
			out.textContent = text || '(no output)';
		});
	}

	// ── Utilities ────────────────────────────────────────────────────────

	_timeAgo(epoch) {
		const e = Number(epoch);
		if (!e || !Number.isFinite(e)) return '—';
		if (e < 1262304000) return '—';
		const now = Math.floor(Date.now() / 1000);
		const d = now - e;
		if (d < 0)     return 'just now';
		if (d < 60)    return d + 's ago';
		if (d < 3600)  return Math.floor(d / 60) + 'm ago';
		if (d < 86400) return Math.floor(d / 3600) + 'h ago';
		return Math.floor(d / 86400) + 'd ago';
	}

	_fmtUptime(ms) {
		let raw = Number(ms);
		if (!raw || raw < 0 || !Number.isFinite(raw)) return '—';
		const sec = Math.floor(raw / 1000);
		const d   = Math.floor(sec / 86400);
		const h   = Math.floor((sec % 86400) / 3600);
		const m   = Math.floor((sec % 3600) / 60);
		if (d > 0) return `${d}d ${h}h`;
		if (h > 0) return `${h}h ${m}m`;
		return `${m}m`;
	}

	_esc(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({
			'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
		}[c]));
	}
}
