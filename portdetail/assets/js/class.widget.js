/*
 * Switch Port Detail Widget
 *
 * Inbound (from Switch Port Status widget):
 *   - Zabbix broadcast _hostid/_hostids → handled via override_hostid in fields_values
 *   - Custom DOM event 'sw:portSelected' → carries snmpIndex for PHP request
 *
 * Outbound (to Graph (classic) widgets):
 *   - On click of a graph-link button, broadcast _itemid/_itemids containing that
 *     metric's itemids. Graph (classic) picks this up via its 'in' manifest and
 *     re-renders to show the chosen item's graph.
 */

class WidgetPortDetail extends CWidget {

	#sw_snmpIndex = null;
	#sw_hostid = null;
	#portListener = null;
	#graphClickListener = null;

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

		// Listen for port selection events from the Switch Port Status widget
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

	setContents(response) {
		super.setContents(response);
		this.#wireGraphButtons();
	}

	/**
	 * Attach click handlers to any .pd-graph-btn buttons rendered by the view.
	 * Each button has data-itemids="[123,456]" — a JSON array of itemids that should
	 * be broadcast to listening Graph (classic) widgets.
	 */
	#wireGraphButtons() {
		const buttons = this._body.querySelectorAll('.pd-graph-btn');
		buttons.forEach((btn) => {
			// Remove any existing listener (setContents fires on every refresh)
			btn.replaceWith(btn.cloneNode(true));
		});

		// Re-query after clone
		this._body.querySelectorAll('.pd-graph-btn').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				let ids = [];
				try {
					ids = JSON.parse(btn.dataset.itemids || '[]');
				} catch (err) { return; }
				if (!ids.length) return;

				// Broadcast selected item(s) to any listening Graph (classic) widget.
				// Graph classic's 'in' manifest declares itemid:{type:_itemid, required:true}
				// — so a single broadcast covers both simple-graph (itemid) scenarios.
				this.broadcast({
					[CWidgetsData.DATA_TYPE_ITEM_ID]:  [String(ids[0])],
					[CWidgetsData.DATA_TYPE_ITEM_IDS]: ids.map(String)
				});

				// Visual feedback: briefly highlight the clicked button
				btn.classList.add('pd-graph-btn--active');
				setTimeout(() => btn.classList.remove('pd-graph-btn--active'), 350);
			});
		});
	}

	getUpdateRequestData() {
		const data = super.getUpdateRequestData();
		if (this.#sw_snmpIndex !== null) data.sw_snmpIndex = this.#sw_snmpIndex;
		if (this.#sw_hostid    !== null) data.sw_hostid    = this.#sw_hostid;
		return data;
	}
}
