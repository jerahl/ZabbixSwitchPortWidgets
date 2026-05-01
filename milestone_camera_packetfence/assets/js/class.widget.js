/*
 * Camera Device (PacketFence) Widget
 *
 * Listens for camera selection from the Milestone Camera Status widget and
 * injects the selected camera's MAC and IP into the widget's update request,
 * so the PHP controller can query the PacketFence API for that device.
 */

class WidgetMilestoneCameraPacketFence extends CWidget {

    #cam_mac = null;
    #cam_ip = null;
    #cam_name = null;
    #cam_host = null;
    #camListener = null;
    #actionListener = null;

    onActivate() {
        // Restore last camera selection from sessionStorage
        try {
            const stored = sessionStorage.getItem('mcs_camera_selection');
            if (stored) {
                const {mac, ip, name, host} = JSON.parse(stored);
                this.#cam_mac  = mac  ?? null;
                this.#cam_ip   = ip   ?? null;
                this.#cam_name = name ?? null;
                this.#cam_host = host ?? null;
            }
        } catch (e) {}

        // Listen for camera clicks from the Milestone Camera Status widget
        this.#camListener = ({detail}) => {
            const {mac, ip, name, host} = detail ?? {};
            this.#cam_mac  = mac  ?? null;
            this.#cam_ip   = ip   ?? null;
            this.#cam_name = name ?? null;
            this.#cam_host = host ?? null;
            if (this.getState() === WIDGET_STATE_ACTIVE) {
                this._startUpdating();
            }
        };
        document.addEventListener('mcs:cameraSelected', this.#camListener);

        // Per-device action buttons (Reevaluate access / Restart switchport).
        // Delegated on document, scoped to this widget's DOM via _target.contains.
        this.#actionListener = (e) => {
            const btn = e.target.closest('button.pf-action[data-pf-action]');
            if (!btn) return;
            if (!this._target || !this._target.contains(btn)) return;
            e.preventDefault();
            this.#runPfAction(btn);
        };
        document.addEventListener('click', this.#actionListener);
    }

    onDeactivate() {
        if (this.#camListener) {
            document.removeEventListener('mcs:cameraSelected', this.#camListener);
            this.#camListener = null;
        }
        if (this.#actionListener) {
            document.removeEventListener('click', this.#actionListener);
            this.#actionListener = null;
        }
    }

    async #runPfAction(btn) {
        const action = btn.dataset.pfAction || '';

        // Cycle PoE is dispatched to the portdetail widget's existing
        // rConfig-backed action — different endpoint, different params.
        if (action === 'cycle_poe') {
            return this.#runCyclePoe(btn);
        }

        const mac = btn.dataset.pfMac || '';
        if (!mac || !action) return;

        // Restart switchport bounces the entire port — confirm before firing.
        const confirmMsg = action === 'restart_switchport'
            ? `Restart the switch port for ${mac}? This briefly drops every device on that port.`
            : `Reevaluate PacketFence access for ${mac}?`;
        if (!window.confirm(confirmMsg)) return;

        const status = this._target.querySelector(`.pf-action-status[data-pf-status-for="${mac}"]`);
        const siblings = this._target.querySelectorAll(
            `button.pf-action[data-pf-mac="${mac}"]`
        );
        siblings.forEach(b => { b.disabled = true; });
        if (status) {
            status.textContent = '…working';
            status.className = 'pf-action-status pf-action-status--pending';
        }

        try {
            const url = new Curl('zabbix.php');
            url.setArgument('action', 'widget.milestone_camera_packetfence.action');

            // Mirror the standard widget refresh body so the server-side
            // CControllerDashboardWidgetView populates $this->fields_values
            // from saved widget config (PF URL/credentials live there).
            const body = WidgetMilestoneCameraPacketFence.#serializeWidgetRequest(
                this.getUpdateRequestData()
            );
            body.append('mac',       mac);
            body.append('pf_action', action);

            const resp = await fetch(url.getUrl(), {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body
            });
            const data = await WidgetMilestoneCameraPacketFence.#parseActionResponse(resp);

            if (data && data.ok) {
                if (status) {
                    status.textContent = '✓ ' + (action === 'restart_switchport' ? 'port restarted' : 'access reevaluated');
                    status.className = 'pf-action-status pf-action-status--ok';
                }
            } else {
                const msg = (data && data.error) ? data.error : `HTTP ${resp.status}`;
                if (status) {
                    status.textContent = '✗ ' + msg;
                    status.className = 'pf-action-status pf-action-status--err';
                }
            }
        } catch (err) {
            if (status) {
                status.textContent = '✗ ' + (err.message || 'request failed');
                status.className = 'pf-action-status pf-action-status--err';
            }
        } finally {
            siblings.forEach(b => { b.disabled = false; });
        }
    }

    /**
     * Cycle PoE on the switch port the camera is connected to. Reuses the
     * portdetail widget's cyclepoe action — the camera widget resolves the
     * switch hostid + iface name server-side from PacketFence's locationlog
     * and stamps them onto the button as data attributes.
     *
     * Response parsing mirrors the portdetail widget's handler since Zabbix
     * sometimes wraps action JSON in HTML page chrome.
     */
    async #runCyclePoe(btn) {
        const hostid    = btn.dataset.pfHostid;
        const snmpIndex = btn.dataset.pfSnmpIndex;
        const iface     = btn.dataset.pfIface;
        // The status span is keyed by MAC — find it by walking up to the card.
        const card = btn.closest('.pf-card');
        const status = card ? card.querySelector('.pf-action-status') : null;

        if (!hostid || !iface) {
            if (status) {
                status.textContent = '✗ Missing switch context';
                status.className = 'pf-action-status pf-action-status--err';
            }
            return;
        }

        const ok = window.confirm(
            `Cycle PoE on ${iface}?\n\n` +
            'The camera (and any other device on this port) will lose power briefly. ' +
            'This is dispatched to rConfig as a snippet deployment.'
        );
        if (!ok) return;

        btn.disabled = true;
        if (status) {
            status.textContent = '…sending to rConfig';
            status.className = 'pf-action-status pf-action-status--pending';
        }

        try {
            const url = new Curl('zabbix.php');
            url.setArgument('action', 'widget.portdetail.cyclepoe');

            const params = new URLSearchParams();
            params.set('hostid',     hostid);
            params.set('snmp_index', snmpIndex || '0');
            params.set('iface_name', iface);

            const resp = await fetch(url.getUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: params.toString()
            });

            const json = await WidgetMilestoneCameraPacketFence.#parseActionResponse(resp);

            if (json && json.ok) {
                if (status) {
                    status.textContent = '✓ ' + (json.message || 'PoE cycle queued');
                    status.className = 'pf-action-status pf-action-status--ok';
                }
            } else {
                const msg = (json && json.message) ? json.message : `HTTP ${resp.status}`;
                if (status) {
                    status.textContent = '✗ ' + msg;
                    status.className = 'pf-action-status pf-action-status--err';
                }
            }
        } catch (err) {
            if (status) {
                status.textContent = '✗ ' + (err.message || 'request failed');
                status.className = 'pf-action-status pf-action-status--err';
            }
        } finally {
            btn.disabled = false;
        }
    }

    getUpdateRequestData() {
        const data = super.getUpdateRequestData();
        if (this.#cam_mac  !== null) data.cam_mac  = this.#cam_mac;
        if (this.#cam_ip   !== null) data.cam_ip   = this.#cam_ip;
        if (this.#cam_name !== null) data.cam_name = this.#cam_name;
        if (this.#cam_host !== null) data.cam_host = this.#cam_host;
        return data;
    }

    /**
     * Convert the object returned by getUpdateRequestData() into a flat
     * URLSearchParams body, expanding `fields` into `fields[name]=value`
     * entries the way Zabbix's standard widget refresh does. This is what
     * makes $this->fields_values populated server-side.
     */
    static #serializeWidgetRequest(data) {
        const body = new URLSearchParams();
        for (const [k, v] of Object.entries(data || {})) {
            if (v === undefined || v === null) continue;
            if (k === 'fields' && typeof v === 'object') {
                for (const [fk, fv] of Object.entries(v)) {
                    if (fv === undefined || fv === null) continue;
                    if (Array.isArray(fv)) {
                        fv.forEach(item => body.append(`fields[${fk}][]`, String(item)));
                    } else {
                        body.append(`fields[${fk}]`, String(fv));
                    }
                }
            } else {
                body.append(k, String(v));
            }
        }
        return body;
    }

    /**
     * Parse our action response. Zabbix sometimes wraps action output in the
     * standard HTML page chrome (with the JSON dropped in verbatim from
     * CControllerResponseData's main_block) — try strict JSON first, then
     * fall back to extracting the embedded {"ok":...} object.
     */
    static async #parseActionResponse(resp) {
        const text = await resp.text();
        try {
            const outer = JSON.parse(text);
            return (outer && outer.main_block !== undefined)
                ? JSON.parse(outer.main_block)
                : outer;
        } catch (_) {}

        const start = text.indexOf('{"ok"');
        if (start !== -1) {
            let depth = 0, inStr = false, esc = false;
            for (let i = start; i < text.length; i++) {
                const ch = text[i];
                if (inStr) {
                    if (esc)        { esc = false; }
                    else if (ch === '\\') { esc = true; }
                    else if (ch === '"')  { inStr = false; }
                } else {
                    if (ch === '"')  inStr = true;
                    else if (ch === '{') depth++;
                    else if (ch === '}') {
                        depth--;
                        if (depth === 0) {
                            try { return JSON.parse(text.slice(start, i + 1)); }
                            catch (_) { return null; }
                        }
                    }
                }
            }
        }
        return null;
    }
}
