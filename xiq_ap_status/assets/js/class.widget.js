/**
 * XIQ AP status widget — frontend class.
 *
 * Mirrors WidgetMilestoneCameraStatus: parses the JSON payload embedded in
 * the rendered body, fills summary tiles, sorts/filters the fault table,
 * and broadcasts a row click for a companion PacketFence widget.
 *
 * Selection event:
 *   Dispatches 'pf:deviceSelected' (the new unified event), and also
 *   'mcs:cameraSelected' for backwards compatibility with the existing
 *   milestone_camera_packetfence widget. When the unified PacketFence
 *   widget lands, the legacy event can be retired.
 */

class WidgetXiqApStatus extends CWidget {

    static VERSION_MARKER = 'xiq-ap-js-v1-2026-05-01';

    // Tile data-field -> status code(s) the bucket holds. Only fault
    // tiles are clickable.
    static FILTERABLE_TILES = {
        config_mismatch: 1,
        disconnected:    2,
    };

    onInitialize() {
        super.onInitialize();
        this._sort_key = 'status';
        this._sort_dir = 'desc';
        this._filter_status = null;
        this._latest = null;
    }

    setContents(response) {
        super.setContents(response);

        if (!this._wired) {
            this._wireSortHandlers();
            this._wireFilterHandlers();
            this._wireRowClickHandler();
            this._wired = true;
        }

        const payload = this._readPayload();
        if (!payload) return;
        this._latest = payload;
        this._render(payload);
    }

    _readPayload() {
        if (!this._body) return null;
        const node = this._body.querySelector('script.xas-data');
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (e) {
            console.error('xas: failed to parse data blob', e);
            return null;
        }
    }

    _wireSortHandlers() {
        const ths = this._body && this._body.querySelectorAll('th[data-sort]');
        if (!ths) return;
        ths.forEach((th) => {
            th.addEventListener('click', () => {
                const key = th.getAttribute('data-sort');
                if (key === this._sort_key) {
                    this._sort_dir = (this._sort_dir === 'asc') ? 'desc' : 'asc';
                } else {
                    this._sort_key = key;
                    this._sort_dir = (key === 'status' || key === 'lastclock') ? 'desc' : 'asc';
                }
                if (this._latest) this._render(this._latest);
            });
        });
    }

    _wireFilterHandlers() {
        if (!this._body) return;
        Object.keys(WidgetXiqApStatus.FILTERABLE_TILES).forEach((field) => {
            const tile = this._body.querySelector(`.xas-tile[data-field="${field}"]`);
            if (!tile) return;
            tile.classList.add('xas-tile--clickable');
            tile.setAttribute('role', 'button');
            tile.setAttribute('tabindex', '0');

            const onActivate = () => {
                const code = WidgetXiqApStatus.FILTERABLE_TILES[field];
                if (this._filter_status === code) return;
                this._filter_status = code;
                if (this._latest) this._render(this._latest);
            };
            tile.addEventListener('click', onActivate);
            tile.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onActivate();
                }
            });
        });
    }

    _clearFilter() {
        this._filter_status = null;
        if (this._latest) this._render(this._latest);
    }

    _render(payload) {
        const summary = payload.summary || {};
        const rows = payload.rows || [];
        const error = payload.error || null;

        const tile_fields = ['total', 'ok', 'config_mismatch', 'disconnected', 'no_data'];
        tile_fields.forEach((f) => {
            const tile = this._body.querySelector(`.xas-tile[data-field="${f}"]`);
            if (!tile) return;
            const v = tile.querySelector('.xas-tile-value');
            const n = Number(summary[f] || 0);
            v.textContent = n.toLocaleString();
            if (['config_mismatch', 'disconnected'].includes(f)) {
                tile.classList.toggle('xas-tile--active', n > 0);
            }
            const code = WidgetXiqApStatus.FILTERABLE_TILES[f];
            tile.classList.toggle(
                'xas-tile--selected',
                code !== undefined && code === this._filter_status
            );
        });

        const empty = this._body.querySelector('.xas-empty');
        const empty_msg = empty.querySelector('.xas-empty-msg');
        const wrap = this._body.querySelector('.xas-table-wrap');

        if (error) {
            empty_msg.textContent = error;
            empty.removeAttribute('hidden');
            wrap.style.display = 'none';
            this._renderFilterBar(wrap, false);
            return;
        }

        const visible_rows = (this._filter_status === null)
            ? rows
            : rows.filter((r) => r.status === this._filter_status);

        if (!visible_rows.length) {
            if (this._filter_status !== null && rows.length > 0) {
                empty_msg.textContent = this._t('No APs match this filter.');
            } else {
                empty_msg.textContent = this._t('All APs OK.');
            }
            empty.removeAttribute('hidden');
            wrap.style.display = 'none';
            this._renderFilterBar(wrap, this._filter_status !== null);
            return;
        }
        empty.setAttribute('hidden', 'hidden');
        wrap.style.display = '';

        this._renderFilterBar(wrap, this._filter_status !== null);

        const sorted = this._sortRows(visible_rows);
        this._visible_rows = sorted;
        const tbody = this._body.querySelector('.xas-rows');
        tbody.innerHTML = sorted.map((r, i) => this._rowHtml(r, i)).join('');

        const ths = this._body.querySelectorAll('th[data-sort]');
        ths.forEach((th) => {
            th.classList.remove('xas-sort-asc', 'xas-sort-desc');
            if (th.getAttribute('data-sort') === this._sort_key) {
                th.classList.add('xas-sort-' + this._sort_dir);
            }
        });

        let trunc_el = wrap.querySelector('.xas-truncated');
        if (payload.truncated) {
            if (!trunc_el) {
                trunc_el = document.createElement('div');
                trunc_el.className = 'xas-truncated';
                wrap.appendChild(trunc_el);
            }
            trunc_el.textContent = this._t('Showing first {0} rows. Increase Max table rows in widget config to see more.')
                .replace('{0}', payload.truncated_at);
        } else if (trunc_el) {
            trunc_el.remove();
        }
    }

    _renderFilterBar(wrap_el, filter_active) {
        let bar = wrap_el.querySelector('.xas-filter-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'xas-filter-bar';

            const label = document.createElement('span');
            label.className = 'xas-filter-label';
            bar.appendChild(label);

            const clear_btn = document.createElement('button');
            clear_btn.type = 'button';
            clear_btn.className = 'xas-filter-clear';
            clear_btn.textContent = this._t('Show all');
            clear_btn.addEventListener('click', () => this._clearFilter());
            bar.appendChild(clear_btn);

            wrap_el.insertBefore(bar, wrap_el.firstChild);
        }

        if (!filter_active) {
            bar.setAttribute('hidden', 'hidden');
            return;
        }
        bar.removeAttribute('hidden');

        const label_text = {
            1: this._t('Filtered: Config mismatch'),
            2: this._t('Filtered: Offline'),
        }[this._filter_status] || this._t('Filtered');
        bar.querySelector('.xas-filter-label').textContent = label_text;
    }

    _sortRows(rows) {
        const key = this._sort_key;
        const dir = this._sort_dir;
        const mult = (dir === 'asc') ? 1 : -1;
        const copy = rows.slice();
        copy.sort((a, b) => {
            let av = a[key], bv = b[key];
            if (typeof av === 'string' && typeof bv === 'string') {
                return av.localeCompare(bv) * mult;
            }
            av = (av == null) ? -Infinity : av;
            bv = (bv == null) ? -Infinity : bv;
            if (av < bv) return -1 * mult;
            if (av > bv) return  1 * mult;
            return 0;
        });
        return copy;
    }

    _wireRowClickHandler() {
        if (!this._body) return;
        const tbody = this._body.querySelector('.xas-rows');
        if (!tbody) return;
        tbody.addEventListener('click', (ev) => {
            if (ev.target.closest('a')) return;
            const tr = ev.target.closest('tr[data-row-index]');
            if (!tr) return;
            const idx = Number(tr.getAttribute('data-row-index'));
            const row = (this._visible_rows || [])[idx];
            if (!row) return;
            this._dispatchDeviceSelected(row);
        });
    }

    /**
     * Persist the selection in sessionStorage and dispatch both the new
     * unified event and the legacy camera event so existing companion
     * widgets keep working until the unified PacketFence widget lands.
     */
    _dispatchDeviceSelected(row) {
        const detail = {
            source: 'xiq_ap',
            hostid: row.hostid,
            mac:    row.mac || null,
            ip:     row.ip  || null,
            name:   row.ap_name,
            host:   row.host_name,
            serial: row.serial || null,
        };
        try {
            sessionStorage.setItem('pf_device_selection', JSON.stringify(detail));
            // Legacy key — read by milestone_camera_packetfence on activate.
            sessionStorage.setItem('mcs_camera_selection', JSON.stringify(detail));
        } catch (e) {}
        document.dispatchEvent(new CustomEvent('pf:deviceSelected', {detail}));
        document.dispatchEvent(new CustomEvent('mcs:cameraSelected', {detail}));
    }

    _rowHtml(r, idx) {
        const status_cls = {
            1: 'xas-pill--mismatch',
            2: 'xas-pill--offline',
        }[r.status] || 'xas-pill--unknown';
        const status_label = {
            1: this._t('Mismatch'),
            2: this._t('Offline'),
        }[r.status] || String(r.status);

        const last_check = r.lastclock
            ? new Date(r.lastclock * 1000).toLocaleString()
            : '—';

        const item_url = r.itemid
            ? `history.php?action=showvalues&itemids[]=${encodeURIComponent(r.itemid)}`
            : null;

        const ap_cell = item_url
            ? `<a href="${this._esc(item_url)}" target="_blank" rel="noopener">${this._esc(r.ap_name)}</a>`
            : this._esc(r.ap_name);

        const mac = r.mac ? this._esc(r.mac) : '<span class="xas-dim">—</span>';
        const ip  = r.ip  ? this._esc(r.ip)  : '<span class="xas-dim">—</span>';

        return [
            `<tr data-row-index="${idx}" class="xas-row-clickable" title="${this._esc(this._t('Click to look up in PacketFence'))}">`,
                `<td><span class="xas-pill ${status_cls}">${this._esc(status_label)}</span></td>`,
                `<td>${ap_cell}</td>`,
                `<td class="xas-mono">${mac}</td>`,
                `<td class="xas-mono">${ip}</td>`,
                `<td class="xas-mono xas-dim">${this._esc(last_check)}</td>`,
            '</tr>'
        ].join('');
    }

    _esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    _t(s) {
        return (typeof t === 'function') ? t(s) : s;
    }
}
