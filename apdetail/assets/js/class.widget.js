'use strict';

/**
 * AP Detail — widget JS class.
 *
 * Extends Zabbix's CWidget base class exactly as portdetail/ and switchports/
 * do — no build step, no bundler, plain ES2020 loaded as a module asset.
 *
 * Responsibilities:
 *  - Restore active tab from sessionStorage on mount / after refresh
 *  - Handle tab switching and persist selection to sessionStorage
 *  - Animate health ring SVGs after data is rendered into the DOM
 *  - Drive sparkline rendering (delegated to ApTelemetry helper — M2)
 *  - Receive _timeperiod broadcast updates and re-trigger sparklines
 *
 * SessionStorage keys (namespaced to avoid collisions with switchports widget):
 *   ap.activeTab   — last selected tab name ('overview' | 'wireless' | …)
 *
 * All keys prefixed 'ap.' — matches the 'sw.' convention in switchports/.
 */
class CWidgetAPDetail extends CWidget {

    // ── Lifecycle ─────────────────────────────────────────────────────────

    onInitialize() {
        // Called once when the widget instance is created on the dashboard.
        // Bind DOM-independent initialisation here.
        this._activeTab = sessionStorage.getItem('ap.activeTab') ?? 'overview';
    }

    onActivate() {
        // Called every time the widget becomes visible / after a refresh cycle.
        this._activateTab(this._activeTab);
        this._animateRings();
        // TODO (M2): this._telemetry = new ApTelemetry(this._container, …)
    }

    onDeactivate() {
        // Called when the dashboard navigates away or widget is hidden.
        // Clean up any running timers or animation frames.
    }

    // ── Tab management ────────────────────────────────────────────────────

    /**
     * Wire tab click events.  Called once after the widget HTML is injected
     * into the DOM by Zabbix.
     *
     * Using event delegation on the nav element so we don't need to re-bind
     * after AJAX refreshes that replace inner content.
     */
    onReady() {
        const nav = this._container.querySelector('.ap-tabs');
        if (!nav) return;

        nav.addEventListener('click', (e) => {
            const btn = e.target.closest('.ap-tab');
            if (!btn) return;
            this._activateTab(btn.dataset.tab);
        });
    }

    /**
     * Switch the visible tab and persist the selection.
     *
     * @param {string} tabName — one of overview|wireless|wired|clients|events|alerts
     */
    _activateTab(tabName) {
        const root = this._container.querySelector('.ap-detail');
        if (!root) return;

        // Buttons
        for (const btn of root.querySelectorAll('.ap-tab')) {
            btn.classList.toggle('ap-tab--active', btn.dataset.tab === tabName);
            btn.setAttribute('aria-selected', btn.dataset.tab === tabName ? 'true' : 'false');
        }

        // Panels
        for (const panel of root.querySelectorAll('.ap-panel')) {
            const active = panel.dataset.panel === tabName;
            panel.classList.toggle('ap-panel--active', active);
            panel.hidden = !active;
        }

        this._activeTab = tabName;
        sessionStorage.setItem('ap.activeTab', tabName);
    }

    // ── Health rings ──────────────────────────────────────────────────────

    /**
     * Animate the SVG donut rings on the Overview tab.
     *
     * Each ring's <circle class="ap-ring__fill"> carries a data-value
     * attribute (0–100) written by widget.view.php.  We calculate the
     * stroke-dasharray to create the arc, then CSS transitions handle the
     * animation.
     *
     * Circumference for r=26: 2π×26 ≈ 163.4
     */
    _animateRings() {
        const CIRC = 2 * Math.PI * 26;  // must match SVG r attribute in widget.view.php

        for (const fill of this._container.querySelectorAll('.ap-ring__fill')) {
            const pct = Math.min(100, Math.max(0, parseFloat(fill.dataset.value ?? '0')));
            const arc = (pct / 100) * CIRC;
            // Small rAF delay so CSS transition fires after initial paint.
            requestAnimationFrame(() => {
                fill.style.strokeDasharray = `${arc} ${CIRC - arc}`;
            });
        }
    }

    // ── Broadcast receivers ───────────────────────────────────────────────

    /**
     * Called by Zabbix when a _timeperiod broadcast arrives from the
     * dashboard time selector.
     *
     * The period object shape: { from: string, to: string }
     * Same handling as portdetail/assets/js/class.widget.js.
     *
     * TODO (M2): pass updated period to ApTelemetry instance to re-fetch
     *            Zabbix history.get with the new time window.
     */
    onTimePeriodChange(period) {
        const root = this._container.querySelector('.ap-detail');
        if (!root) return;
        root.dataset.from = period.from;
        root.dataset.to   = period.to;
        // TODO (M2): this._telemetry?.update(period);
    }

    // ── Utilities ─────────────────────────────────────────────────────────

    /**
     * Convenience accessor — the outermost element injected by Zabbix for
     * this widget instance.  Scopes all querySelector calls so widgets don't
     * bleed across each other on multi-widget dashboards.
     */
    get _container() {
        return this.getContents();
    }
}
