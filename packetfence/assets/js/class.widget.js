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

			// Mirror the standard widget refresh body so the server-side
			// CControllerDashboardWidgetView populates $this->fields_values
			// from saved widget config (PF URL/credentials live there). Then
			// add our action-specific params on top.
			const body = WidgetPacketFence.#serializeWidgetRequest(this.getUpdateRequestData());
			body.append('mac',       mac);
			body.append('pf_action', action);

			const resp = await fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body
			});
			const data = await WidgetPacketFence.#parseActionResponse(resp);

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

		// HTML-wrapped fallback: scan for the first balanced {"ok":...} object.
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
