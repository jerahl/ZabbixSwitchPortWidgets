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
	}

	onDeactivate() {
		if (this.#portListener) {
			document.removeEventListener('sw:portSelected', this.#portListener);
			this.#portListener = null;
		}
	}

	getUpdateRequestData() {
		const data = super.getUpdateRequestData();
		if (this.#sw_snmpIndex !== null) data.sw_snmpIndex = this.#sw_snmpIndex;
		if (this.#sw_hostid    !== null) data.sw_hostid    = this.#sw_hostid;
		return data;
	}
}
