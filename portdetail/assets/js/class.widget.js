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
 *
 * Cycle PoE button:
 *   - On click, asks for confirmation, then POSTs to widget.portdetail.cyclepoe.
 *     The PHP action calls rConfig server-side using credentials read from
 *     Zabbix host macros — no token ever reaches the browser.
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
		this.#wireCycleButtons();
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

	/**
	 * Wire the "Cycle PoE" button. Each button carries the host id, snmp
	 * index, and resolved interface name as data attributes — they are
	 * sent verbatim to the cyclepoe action, which looks up rConfig
	 * credentials from Zabbix host macros server-side.
	 */
	#wireCycleButtons() {
		const buttons = this._body.querySelectorAll('.pd-poe-cycle-btn');
		buttons.forEach((btn) => btn.replaceWith(btn.cloneNode(true)));

		this._body.querySelectorAll('.pd-poe-cycle-btn').forEach((btn) => {
			btn.addEventListener('click', (e) => this.#onCycleClick(e, btn));
		});
	}

	async #onCycleClick(e, btn) {
		e.preventDefault();
		e.stopPropagation();

		const hostid    = btn.dataset.hostid;
		const snmpIndex = btn.dataset.snmpIndex;
		const iface     = btn.dataset.iface;
		const status    = btn.parentElement.querySelector('.pd-poe-cycle-status');

		if (!hostid || !snmpIndex || !iface) {
			this.#setCycleStatus(status, 'error', 'Missing port context.');
			return;
		}

		// Destructive operation — explicit confirmation before firing.
		const ok = window.confirm(
			'Cycle PoE on ' + iface + '?\n\n' +
			'Any device powered by this port will lose power briefly. ' +
			'This is dispatched to rConfig as a snippet deployment.'
		);
		if (!ok) return;

		// Disable the button while in flight to prevent double-fire.
		btn.disabled = true;
		btn.classList.add('pd-poe-cycle-btn--busy');
		this.#setCycleStatus(status, 'info', 'Sending to rConfig…');

		try {
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'widget.portdetail.cyclepoe');

			const params = new URLSearchParams();
			params.set('hostid',     hostid);
			params.set('snmp_index', snmpIndex);
			params.set('iface_name', iface);

			const resp = await fetch(url.getUrl(), {
				method:  'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					// Hint to Zabbix that we'd prefer JSON. Whether the response
					// layer honours it depends on routing — the parser below
					// copes with HTML-wrapped JSON either way.
					'Accept': 'application/json'
				},
				body:    params.toString(),
			});

			// Read the body as text so we can parse it ourselves rather than
			// relying on Content-Type. Zabbix sometimes renders action output
			// inside the standard page chrome (HTML), with the JSON payload
			// dropped in verbatim from CControllerResponseData's main_block.
			const text = await resp.text();
			const ctype = resp.headers.get('content-type') || '';

			let json = null;

			// Pass 1 — strict JSON. This is the happy path: the response is
			// either {"ok":...,"message":...} directly or {"main_block":"<json
			// string>"} that we unwrap one level.
			try {
				const outer = JSON.parse(text);
				json = (outer.main_block !== undefined)
					? JSON.parse(outer.main_block)
					: outer;
			} catch (_) { /* fall through */ }

			// Pass 2 — HTML-wrapped. Locate the {"ok":...} object inside the
			// HTML body and extract it with a brace-balanced walker that
			// respects JSON string escapes (so a brace inside a "message"
			// string doesn't trip the depth counter).
			if (json === null) {
				json = WidgetPortDetail.#extractEmbeddedPayload(text);
			}

			if (json === null) {
				console.error('[portdetail.cyclepoe] could not parse response', {
					status:      resp.status,
					redirected:  resp.redirected,
					finalUrl:    resp.url,
					contentType: ctype,
					bodyHead:    text.slice(0, 400),
					bodyTail:    text.slice(-400)
				});
				throw new Error('HTTP ' + resp.status + ' (unparseable response; see console)');
			}

			if (json.ok) {
				this.#setCycleStatus(status, 'success', json.message || 'Queued.');
			} else {
				this.#setCycleStatus(status, 'error', json.message || 'rConfig error.');
			}
		} catch (err) {
			this.#setCycleStatus(status, 'error', 'Request failed: ' + err.message);
		} finally {
			btn.disabled = false;
			btn.classList.remove('pd-poe-cycle-btn--busy');
		}
	}

	#setCycleStatus(el, kind, text) {
		if (!el) return;
		el.classList.remove(
			'pd-poe-cycle-status--info',
			'pd-poe-cycle-status--success',
			'pd-poe-cycle-status--error'
		);
		el.classList.add('pd-poe-cycle-status--' + kind);
		el.textContent = text;

		// Auto-clear non-error transient messages after a few seconds so
		// the UI doesn't get crowded by stale state on the next refresh.
		if (kind !== 'error') {
			clearTimeout(el._pdClearTimer);
			el._pdClearTimer = setTimeout(() => {
				el.textContent = '';
				el.classList.remove('pd-poe-cycle-status--' + kind);
			}, 6000);
		}
	}

	/**
	 * Extract the cyclepoe payload from a response body that may be HTML
	 * page chrome with our JSON object inlined as text.
	 *
	 * The PHP side (CyclePoe::respond) emits an object of the shape
	 *   {"ok":<bool>,"message":<string>,"http_status":<null|int>}
	 * via CControllerResponseData's main_block. When Zabbix renders that
	 * with the HTML layout (instead of a JSON layout), the JSON text ends
	 * up in the body of a full HTML document. We anchor on the literal
	 * `{"ok":` opener — distinctive enough to avoid matching unrelated
	 * JSON the page chrome may inject for CSRF tokens, page state, etc.
	 *
	 * The walker tracks brace depth while respecting JSON string escape
	 * rules so a closing brace inside an error message string doesn't
	 * prematurely close the object.
	 *
	 * @param  {string} text  full response body
	 * @return {object|null}  parsed payload, or null if none found
	 */
	static #extractEmbeddedPayload(text) {
		const ANCHOR = '{"ok":';
		const start = text.indexOf(ANCHOR);
		if (start < 0) return null;

		let depth  = 0;
		let inStr  = false;
		let esc    = false;
		for (let j = start; j < text.length; j++) {
			const c = text[j];
			if (esc)   { esc = false; continue; }
			if (inStr) {
				if      (c === '\\') esc = true;
				else if (c === '"')  inStr = false;
				continue;
			}
			if      (c === '"') inStr = true;
			else if (c === '{') depth++;
			else if (c === '}') {
				depth--;
				if (depth === 0) {
					try { return JSON.parse(text.slice(start, j + 1)); }
					catch (_) { return null; }
				}
			}
		}
		return null;
	}

	getUpdateRequestData() {
		const data = super.getUpdateRequestData();
		if (this.#sw_snmpIndex !== null) data.sw_snmpIndex = this.#sw_snmpIndex;
		if (this.#sw_hostid    !== null) data.sw_hostid    = this.#sw_hostid;
		return data;
	}
}