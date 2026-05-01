/*
 * PacketFence Device Lookup Widget
 *
 * Listens for port selection from the Switch Port Status widget and injects
 * the SNMP index into the widget's update request, so the PHP controller
 * can query the PacketFence API for devices on that port.
 */

class WidgetPacketFence extends CWidget {

	#sw_snmpIndex = null;
	#sw_hostid = null;
	#portListener = null;
	#actionListener = null;

	onActivate() {
		// Restore last port selection from sessionStorage
		try {
			const stored = sessionStorage.getItem('sw_port_selection');
			if (stored) {
				const {hostid, snmpIndex} = JSON.parse(stored);
				this.#sw_hostid    = hostid    ?? null;
				this.#sw_snmpIndex = snmpIndex ?? null;
			}
		} catch (e) {}

		// Listen for port clicks from the Switch Port Status widget
		this.#portListener = ({detail}) => {
			const {hostid, snmpIndex} = detail ?? {};
			this.#sw_hostid    = hostid    ?? null;
			this.#sw_snmpIndex = snmpIndex ?? null;
			if (this.getState() === WIDGET_STATE_ACTIVE) {
				this._startUpdating();
			}
		};
		document.addEventListener('sw:portSelected', this.#portListener);

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
		if (this.#portListener) {
			document.removeEventListener('sw:portSelected', this.#portListener);
			this.#portListener = null;
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
			url.setArgument('action', 'widget.packetfence.action');

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
		if (this.#sw_snmpIndex !== null) data.sw_snmpIndex = this.#sw_snmpIndex;
		if (this.#sw_hostid    !== null) data.sw_hostid    = this.#sw_hostid;
		return data;
	}
}
