/*
 * Switch Port Status Widget
 *
 * Modelled exactly on the Zabbix hostnavigator/itemnavigator pattern:
 *
 *  - When a port is clicked, call this.broadcast() with _hostid and _hostids.
 *    The manifest declares out: [{type:_hostid},{type:_hostids}] so broadcast()
 *    will pass the type validation in CWidgetBase.
 *    this._fields.reference is auto-populated by CWidgetFieldReference (added
 *    automatically by PHP CWidget.getForm() when out is non-empty).
 *
 *  - Store the selected snmpIndex in a property and also write it to
 *    sessionStorage so the Port Detail widget can read it independently.
 *
 *  - Fire a custom DOM event so the Port Detail widget triggers immediately
 *    without waiting for Zabbix EventHub polling.
 */

class WidgetSwitchPorts extends CWidget {

	#selected_hostid = null;
	#selected_snmp_index = null;
	#listeners = {};

	onActivate() {
		this._contents.scrollTop = 0;
	}

	setContents(response) {
		super.setContents(response);
		this.#reflowGrid();
		this.#registerListeners();
		this.#activateListeners();

		// Re-highlight previously selected port after re-render
		if (this.#selected_snmp_index !== null) {
			const port = this._body.querySelector(
				`[data-snmp-index="${this.#selected_snmp_index}"]`
			);
			if (port) {
				port.classList.add('sw-port--selected');
			}
		}
	}

	onResize() {
		this.#reflowGrid();
	}

	hasPadding() {
		return false;
	}

	onClearContents() {
		this.#deactivateListeners();
	}

	// ─── Private ──────────────────────────────────────────────────────────────

	#reflowGrid() {
		// Column-flow layout: 2 rows tall, ports fill top-then-bottom.
		// The number of columns is determined by ceil(count/2). We set it explicitly
		// so the row track sizing stays consistent and the browser doesn't stretch
		// columns when the widget gets wider than the strict port width.
		for (const list of this._body.querySelectorAll('.sw-portlist--dual-row')) {
			const count = list.querySelectorAll('.sw-port').length;
			if (!count) continue;
			const cols = Math.ceil(count / 2);
			list.style.gridTemplateColumns = `repeat(${cols}, minmax(30px, 44px))`;
		}
	}

	#registerListeners() {
		this.#listeners = {
			portClick: (e) => {
				const port = e.target.closest('.sw-port');
				if (!port) return;
				this.#onPortClick(port);
			},
			tooltipEnter: (e) => {
				const icon = e.target.closest('[title]');
				if (!icon) return;
				this._tooltip_timer = setTimeout(() => this.#showTooltip(icon), 180);
			},
			tooltipLeave: () => {
				clearTimeout(this._tooltip_timer);
				this.#destroyTooltip();
			}
		};
	}

	#activateListeners() {
		const wrapper = this._body.querySelector('.sw-widget-wrapper');
		if (!wrapper) return;
		wrapper.addEventListener('click',      this.#listeners.portClick);
		wrapper.addEventListener('mouseover',  this.#listeners.tooltipEnter);
		wrapper.addEventListener('mouseleave', this.#listeners.tooltipLeave);
	}

	#deactivateListeners() {
		const wrapper = this._body && this._body.querySelector('.sw-widget-wrapper');
		if (!wrapper) return;
		wrapper.removeEventListener('click',      this.#listeners.portClick);
		wrapper.removeEventListener('mouseover',  this.#listeners.tooltipEnter);
		wrapper.removeEventListener('mouseleave', this.#listeners.tooltipLeave);
	}

	#onPortClick(port_el) {
		// Deselect if clicking the same port again
		if (this.#selected_snmp_index !== null &&
				port_el.dataset.snmpIndex == this.#selected_snmp_index) {
			port_el.classList.remove('sw-port--selected');
			this.#selected_snmp_index = null;
			this.#selected_hostid = null;
			this.#storeAndNotify(null, null);
			return;
		}

		// Deselect previously selected port
		const prev = this._body.querySelector('.sw-port--selected');
		if (prev) prev.classList.remove('sw-port--selected');

		port_el.classList.add('sw-port--selected');

		this.#selected_snmp_index = port_el.dataset.snmpIndex ?? null;

		// Hostid comes from the widget wrapper data attribute (set by PHP)
		const wrapper = this._body.querySelector('.sw-widget-wrapper');
		this.#selected_hostid = wrapper?.dataset?.hostid ?? null;

		this.#storeAndNotify(this.#selected_hostid, this.#selected_snmp_index);
	}

	#storeAndNotify(hostid, snmpIndex) {
		// 1. SessionStorage — for Port Detail to read on page load / activate
		try {
			if (hostid && snmpIndex) {
				sessionStorage.setItem('sw_port_selection', JSON.stringify({hostid, snmpIndex}));
			} else {
				sessionStorage.removeItem('sw_port_selection');
			}
		} catch(e) {}

		// 2. Custom DOM event — Port Detail listens on document
		document.dispatchEvent(new CustomEvent('sw:portSelected', {
			detail: {hostid, snmpIndex}
		}));

		// 3. Zabbix EventHub broadcast — triggers any widget that listens for
		//    _hostid/_hostids via Override host → Widget → Switch Port Status.
		//    this._fields.reference is auto-set by CWidgetFieldReference.
		if (hostid !== null) {
			this.broadcast({
				[CWidgetsData.DATA_TYPE_HOST_ID]:  [hostid],
				[CWidgetsData.DATA_TYPE_HOST_IDS]: [hostid]
			});
		} else {
			this.broadcast({
				[CWidgetsData.DATA_TYPE_HOST_ID]:  [],
				[CWidgetsData.DATA_TYPE_HOST_IDS]: []
			});
		}
	}

	// ─── Tooltip ──────────────────────────────────────────────────────────────

	#tooltip_el = null;
	#tooltip_anchor = null;
	_tooltip_timer = null;

	#showTooltip(anchor) {
		this.#destroyTooltip();
		const text = anchor.getAttribute('title');
		if (!text) return;

		const tip = document.createElement('div');
		tip.className = 'sw-port-tooltip';
		tip.textContent = text;
		anchor.dataset.title = text;
		anchor.removeAttribute('title');
		document.body.appendChild(tip);

		const rect = anchor.getBoundingClientRect();
		const tipRect = tip.getBoundingClientRect();
		let top  = rect.top  + window.scrollY - tipRect.height - 8;
		let left = rect.left + window.scrollX + (rect.width - tipRect.width) / 2;
		left = Math.max(4, Math.min(left, window.innerWidth - tipRect.width - 4));
		if (top < 4) top = rect.bottom + window.scrollY + 8;

		tip.style.top = top + 'px';
		tip.style.left = left + 'px';
		tip.style.opacity = '1';
		this.#tooltip_el = tip;
		this.#tooltip_anchor = anchor;
	}

	#destroyTooltip() {
		if (this.#tooltip_el) {
			this.#tooltip_el.remove();
			this.#tooltip_el = null;
		}
		if (this.#tooltip_anchor?.dataset?.title) {
			this.#tooltip_anchor.setAttribute('title', this.#tooltip_anchor.dataset.title);
			delete this.#tooltip_anchor.dataset.title;
			this.#tooltip_anchor = null;
		}
	}
}
