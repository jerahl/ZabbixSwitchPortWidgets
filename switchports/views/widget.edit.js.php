<?php
/**
 * Switch Port Status Widget – edit form JavaScript.
 *
 * Auto-configures the stack layout whenever the host selection changes.
 * Also runs on initial load if a host is already selected.
 *
 * @var CView  $this
 * @var array  $data
 */
?>

window.switchports_edit = new class {

	init({max_members}) {
		this._max_members  = max_members;
		this._status       = document.getElementById('sw-autoconfig-status');
		this._debounce_timer = null;

		// Show/hide member sections when stack_count changes
		const count_input = document.querySelector('[name="stack_count"]');
		if (count_input) {
			count_input.addEventListener('change', () => this._updateMemberVisibility());
			this._updateMemberVisibility();
		}

		// ── Hook into the Zabbix multiselect for hostids ─────────────────────
		// Zabbix multiselects fire a 'change' event on their hidden input AND
		// dispatch a custom 'multiselect.change' event on the container element.
		// We listen on the form container using event delegation to catch either.

		const form = document.querySelector('.overlay-dialogue-body');

		if (form) {
			// Custom Zabbix multiselect event
			form.addEventListener('change', (e) => {
				if (e.target.matches('[name^="override_hostid"]')) {
					this._onHostChange();
				}
			});

			// Also listen for the Zabbix multiselect select/remove actions
			// which fire on the ms-suggest container
			form.addEventListener('multiselect.change', (e) => {
				if (e.target.id && e.target.id.includes('override_hostid')) {
					this._onHostChange();
				}
			});
		}

		// Run on load if a host is already selected (editing existing widget)
		this._onHostChange();
	}

	_onHostChange() {
		// Debounce rapid changes
		clearTimeout(this._debounce_timer);
		this._debounce_timer = setTimeout(() => this._run(), 300);
	}

	_getHostId() {
		// CWidgetFieldMultiSelectOverrideHost stores the resolved hostid in a
		// hidden input. Try the patterns Zabbix uses for override_hostid.
		for (const sel of [
			'input[type="hidden"][name="override_hostid[0]"]',
			'input[type="hidden"][name^="override_hostid["]',
			'input[type="hidden"][name="override_hostid[]"]',
			'input[type="hidden"][id^="override_hostid_"]',
		]) {
			const el = document.querySelector(sel);
			if (el && el.value && el.value !== '0') return el.value;
		}
		return null;
	}

	async _run() {
		const hostid = this._getHostId();

		if (!hostid) {
			// No host selected yet — clear status quietly
			this._setStatus('', '');
			return;
		}

		this._setStatus('info', '<?= _('Auto-detecting stack configuration…') ?>');

		try {
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'widget.switchports.autoconfig');

			const resp = await fetch(url.getUrl(), {
				method:  'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body:    'hostid=' + encodeURIComponent(hostid),
			});

			if (!resp.ok) throw new Error(`HTTP ${resp.status}`);

			const outer = await resp.json();

			// layout.json wraps the payload in {main_block: "...json string..."}
			const json = (outer.main_block !== undefined)
				? JSON.parse(outer.main_block)
				: outer;

			if (json.error) {
				this._setStatus('error', json.error);
				return;
			}

			this._applyConfig(json);

		} catch (e) {
			this._setStatus('error', '<?= _('Auto-config failed: ') ?>' + e.message);
		}
	}

	_applyConfig(cfg) {
		const setVal = (name, val) => {
			const el = document.querySelector(`[name="${name}"]`);
			if (el && el.value != val) {
				el.value = val;
				el.dispatchEvent(new Event('change', {bubbles: true}));
			}
		};

		if (!cfg.is_stack) {
			setVal('stack_count', 1);
			this._setStatus('success', '<?= _('Standalone switch — 1 member.') ?>');
		} else {
			setVal('stack_count', cfg.stack_count);

			cfg.members.forEach((m) => {
				const label = m.status === 'active'
					? `Member ${m.num}`
					: `Member ${m.num} (absent)`;
				setVal(`member_label_${m.num}`, label);
			});

			this._setStatus('success',
				`<?= _('Stack detected: ') ?>${cfg.stack_count}<?= _(' active member(s)') ?>`
			);
		}

		this._updateMemberVisibility();
	}

	_updateMemberVisibility() {
		const count_input = document.querySelector('[name="stack_count"]');
		const count = parseInt(count_input?.value) || 1;
		for (let m = 1; m <= this._max_members; m++) {
			const el = document.getElementById('sw-member-' + m);
			if (el) el.style.display = m <= count ? '' : 'none';
		}
	}

	_setStatus(type, msg) {
		if (!this._status) return;
		const colours = {
			info:    '#94a3b8',
			success: '#4ade80',
			error:   '#f87171',
			'':      '',
		};
		this._status.textContent = msg;
		this._status.style.color = colours[type] ?? colours.info;
	}
};
