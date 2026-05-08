'use strict';

/**
 * AP Detail — widget JS class.
 *
 * Extends Zabbix's CWidget base class. Plain ES2020+, no build step.
 *
 * Responsibilities:
 *   - Tab nav: persist active tab to sessionStorage, restore on every
 *     setContents (which fires after _hostid broadcasts and refreshes).
 *   - Ring fill animation: compute stroke-dasharray from data-value and
 *     transition into place after first paint.
 *   - Keyboard nav on the tab bar (WAI-ARIA tablist).
 *
 * Sparkline paths are pre-rendered server-side as <path d="..."/>
 * elements; no JS sparkline code is needed.
 *
 * Framework lessons (M1 closeout):
 *   G27: Every lifecycle override calls super.<method>() first.
 *   G28: DOM rendering and event binding happen in setContents. Each
 *        setContents call gets a fresh this._body so we re-bind events
 *        and re-trigger animations on every refresh.
 */
class CWidgetAPDetail extends CWidget {

    // ── Lifecycle ────────────────────────────────────────────────────────

    onInitialize() {
        super.onInitialize();
        this._activeTab = sessionStorage.getItem('ap.activeTab') ?? 'overview';
    }

    setContents(response) {
        super.setContents(response);

        const root = this._body && this._body.querySelector('.ap-detail');
        if (!root) return;

        // Restore the user's last selected tab.
        this._activateTab(this._activeTab);

        // Tab click delegation. this._body is fresh on every setContents
        // so old listeners die with the old DOM tree — no double-binding.
        root.addEventListener('click', (e) => {
            const btn = e.target.closest('.ap-tab');
            if (!btn || !root.contains(btn)) return;
            this._activateTab(btn.dataset.tab);
        });

        // WAI-ARIA tablist keyboard nav.
        const nav = root.querySelector('.ap-tabs');
        if (nav) {
            nav.addEventListener('keydown', (e) => this._onTabKeydown(e, nav));
        }

        // Trigger ring-fill animation on next frame so the CSS transition
        // catches the dasharray change (otherwise the value is set during
        // initial paint and there's nothing to animate from).
        requestAnimationFrame(() => this._animateRings(root));

        // Live Telemetry strip — broadcast _itemids on cell click so a
        // peer Graph (classic) widget can render the full series. Only
        // ZBX-source cells carry data-itemid (XIQ d360 sparklines have
        // no Zabbix item to broadcast).
        this._wireTelemetryClicks(root);
    }

    onActivate() {
        super.onActivate();
    }

    onDeactivate() {
        super.onDeactivate();
    }

    // ── Tab management ───────────────────────────────────────────────────

    _activateTab(tabName) {
        if (!tabName) return;
        const root = this._body && this._body.querySelector('.ap-detail');
        if (!root) return;

        // Fall back to overview if the persisted tab was renamed away.
        const known = root.querySelector(`.ap-tab[data-tab="${CSS.escape(tabName)}"]`);
        if (!known) {
            tabName = 'overview';
        }

        for (const btn of root.querySelectorAll('.ap-tab')) {
            const isActive = btn.dataset.tab === tabName;
            btn.classList.toggle('ap-tab--active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            btn.setAttribute('tabindex', isActive ? '0' : '-1');
        }

        for (const panel of root.querySelectorAll('.ap-panel')) {
            const isActive = panel.dataset.panel === tabName;
            panel.classList.toggle('ap-panel--active', isActive);
            if (isActive) {
                panel.removeAttribute('hidden');
            }
            else {
                panel.setAttribute('hidden', 'hidden');
            }
        }

        this._activeTab = tabName;
        sessionStorage.setItem('ap.activeTab', tabName);
    }

    _onTabKeydown(e, nav) {
        const focused = document.activeElement;
        if (!focused || !focused.classList.contains('ap-tab')) return;

        const tabs = Array.from(nav.querySelectorAll('.ap-tab'));
        const idx  = tabs.indexOf(focused);
        if (idx === -1) return;

        let next = idx;
        switch (e.key) {
            case 'ArrowLeft':  next = (idx - 1 + tabs.length) % tabs.length; break;
            case 'ArrowRight': next = (idx + 1) % tabs.length; break;
            case 'Home':       next = 0; break;
            case 'End':        next = tabs.length - 1; break;
            default: return;
        }
        e.preventDefault();
        tabs[next].focus();
        this._activateTab(tabs[next].dataset.tab);
    }

    // ── Ring fill animation ──────────────────────────────────────────────

    /**
     * For each <circle class="ap-ring__fill"> in the DOM, compute the
     * stroke-dasharray that produces an arc of the right length for its
     * data-value (0–100) and apply it. CSS transition does the easing.
     *
     * Circle radius is fixed at 40 in the SVG (matches widget.view.php),
     * so the circumference 2π·40 ≈ 251.33 is constant. Two-segment
     * dasharray "<arcLen> <restLen>" controls how much of the stroke
     * is visible, starting at the top because of the -90deg transform
     * applied in CSS.
     */
    _animateRings(root) {
        const CIRC = 2 * Math.PI * 40;

        for (const fill of root.querySelectorAll('.ap-ring__fill')) {
            const raw = parseFloat(fill.dataset.value ?? '0');
            const pct = Number.isFinite(raw) ? Math.min(100, Math.max(0, raw)) : 0;
            const arc = (pct / 100) * CIRC;
            // Use float strings so SVG won't serialize "163.36000000000001".
            fill.style.strokeDasharray = `${arc.toFixed(2)} ${(CIRC - arc).toFixed(2)}`;
        }
    }

    // ── Live Telemetry strip ────────────────────────────────────────────

    /**
     * Bind click + Enter/Space activation on every clickable telemetry
     * cell. A click broadcasts the cell's itemid as both _itemid (single)
     * and _itemids (list) so any peer Graph (classic) widget listening
     * on either type picks it up — same pattern as portdetail.
     *
     * Re-binding on every setContents is safe: this._body is replaced by
     * super.setContents, so the previous listener died with the old DOM.
     */
    _wireTelemetryClicks(root) {
        const cells = root.querySelectorAll('.ap-tele-cell--clickable[data-itemid]');
        for (const cell of cells) {
            const itemid = cell.dataset.itemid;
            if (!itemid) continue;

            const fire = (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.broadcast({
                    [CWidgetsData.DATA_TYPE_ITEM_ID]:  [String(itemid)],
                    [CWidgetsData.DATA_TYPE_ITEM_IDS]: [String(itemid)],
                });
                cell.classList.add('ap-tele-cell--active');
                setTimeout(() => cell.classList.remove('ap-tele-cell--active'), 300);
            };

            cell.addEventListener('click', fire);
            cell.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    fire(e);
                }
            });
        }
    }
}
