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
        const mac    = btn.dataset.pfMac    || '';
        const action = btn.dataset.pfAction || '';
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

            const body = new URLSearchParams();
            body.append('widgetid',  this.getWidgetId());
            body.append('mac',       mac);
            body.append('pf_action', action);

            const resp = await fetch(url.getUrl(), {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body
            });
            const data = await resp.json();

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

    getUpdateRequestData() {
        const data = super.getUpdateRequestData();
        if (this.#cam_mac  !== null) data.cam_mac  = this.#cam_mac;
        if (this.#cam_ip   !== null) data.cam_ip   = this.#cam_ip;
        if (this.#cam_name !== null) data.cam_name = this.#cam_name;
        if (this.#cam_host !== null) data.cam_host = this.#cam_host;
        return data;
    }
}
