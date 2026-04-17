<?php

namespace Modules\SwitchPorts\Actions;

use API;
use CController;
use CControllerResponseData;

/**
 * Switch Port Status Widget – Auto-configuration action.
 *
 * Returns detected stack layout as JSON.
 * Uses layout.json — Zabbix renders CControllerResponseData through
 * the json layout which wraps $data as the JSON response body.
 *
 * POST parameters:
 *   hostid  (int)  — the host to query
 */
class AutoConfig extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|db hosts.hostid',
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode(['error' => _('Invalid input.')])
			]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$hostid = (int) $this->getInput('hostid');

		// ── Fetch all items whose key contains 'stacking' ─────────────────────
		$items = API::Item()->get([
			'output'                 => ['key_', 'lastvalue'],
			'hostids'                => [$hostid],
			'search'                 => ['key_' => 'stacking'],
			'searchWildcardsEnabled' => false,
			'webitems'               => false,
			'preservekeys'           => false,
		]);

		// Fetch system.hw.stacking by exact key
		$hw_items = API::Item()->get([
			'output'       => ['key_', 'lastvalue'],
			'hostids'      => [$hostid],
			'filter'       => ['key_' => ['system.hw.stacking']],
			'webitems'     => false,
			'preservekeys' => false,
		]);

		$by_key = [];
		foreach (array_merge($items, $hw_items) as $item) {
			$by_key[$item['key_']] = $item['lastvalue'];
		}

		// ── Stacking flag ──────────────────────────────────────────────────────
		$hw_stacking = isset($by_key['system.hw.stacking'])
			? (int) $by_key['system.hw.stacking']
			: null;

		$is_stack = ($hw_stacking === 1);

		// ── Active members ─────────────────────────────────────────────────────
		$members     = [];
		$stack_count = 1;

		if ($is_stack) {
			for ($i = 1; $i <= 8; $i++) {
				$key = 'stacking.member[' . $i . ']';
				if (array_key_exists($key, $by_key)) {
					$status = (int) $by_key[$key];
					$members[] = [
						'num'    => $i,
						'status' => $status === 1 ? 'active' : 'absent',
						'value'  => $status,
					];
				}
			}

			$active      = array_filter($members, fn($m) => $m['status'] === 'active');
			$stack_count = count($active) ?: count($members) ?: 1;
		}

		$result = [
			'is_stack'    => $is_stack,
			'hw_stacking' => $hw_stacking,
			'stack_count' => $stack_count,
			'members'     => $members,
			'found_keys'  => array_keys($by_key),
			'error'       => null,
		];

		// layout.json expects 'main_block' to contain the JSON string
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($result)
		]));
	}
}
