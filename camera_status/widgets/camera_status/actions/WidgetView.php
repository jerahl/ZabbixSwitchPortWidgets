<?php declare(strict_types = 0);

namespace Modules\CameraStatus\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	// Item keys we expect on each camera host (see template)
	private const KEY_ONLINE          = 'camera.online';          // 1/0
	private const KEY_RECORDING       = 'camera.recording';       // 1/0
	private const KEY_LAST_FRAME_TS   = 'camera.last_frame_ts';   // unix ts
	private const KEY_RETENTION_DAYS  = 'camera.retention_days';  // numeric
	private const KEY_STORAGE_PCT     = 'camera.storage_used_pct';// numeric 0-100
	private const KEY_STREAM_NAME     = 'camera.stream_name';     // text
	private const KEY_MILESTONE_GUID  = 'camera.milestone_guid';  // text

	protected function doAction(): void {
		$fields = $this->getForm()->getFieldsValues();

		$groupids = $fields['groupids'] ?: null;
		$retention_warn = (int) $fields['retention_warn_days'];
		$frame_stale    = (int) $fields['frame_stale_seconds'];
		$problems_only  = (bool) $fields['show_offline_only'];

		// Pull hosts in configured group(s)
		$hosts = API::Host()->get([
			'output'   => ['hostid', 'host', 'name', 'status'],
			'groupids' => $groupids,
			'selectInterfaces' => ['ip'],
			'monitored_hosts'  => true,
			'preservekeys' => true
		]);

		if (!$hosts) {
			$this->setResponse(new CControllerResponseData([
				'name' => $this->getInput('name', $this->widget->getDefaultName()),
				'cameras' => [],
				'config' => [
					'layout'          => (int) $fields['layout'],
					'columns'         => (int) $fields['columns'],
					'retention_warn'  => $retention_warn,
					'frame_stale'     => $frame_stale,
					'problems_only'   => $problems_only
				],
				'user' => ['debug_mode' => $this->getDebugMode()]
			]));
			return;
		}

		$hostids = array_keys($hosts);

		// Pull the items we care about
		$items = API::Item()->get([
			'output'   => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock', 'value_type', 'units'],
			'hostids'  => $hostids,
			'filter'   => [
				'key_' => [
					self::KEY_ONLINE,
					self::KEY_RECORDING,
					self::KEY_LAST_FRAME_TS,
					self::KEY_RETENTION_DAYS,
					self::KEY_STORAGE_PCT,
					self::KEY_STREAM_NAME,
					self::KEY_MILESTONE_GUID
				]
			],
			'monitored' => true,
			'webitems'  => false
		]);

		// Group items by host
		$by_host = [];
		foreach ($items as $it) {
			$by_host[$it['hostid']][$it['key_']] = $it;
		}

		$now = time();
		$cameras = [];

		foreach ($hosts as $hostid => $host) {
			$hostitems = $by_host[$hostid] ?? [];

			$online    = isset($hostitems[self::KEY_ONLINE])
				? (int) $hostitems[self::KEY_ONLINE]['lastvalue']
				: null;
			$recording = isset($hostitems[self::KEY_RECORDING])
				? (int) $hostitems[self::KEY_RECORDING]['lastvalue']
				: null;
			$last_ts   = isset($hostitems[self::KEY_LAST_FRAME_TS])
				? (int) $hostitems[self::KEY_LAST_FRAME_TS]['lastvalue']
				: 0;
			$retention = isset($hostitems[self::KEY_RETENTION_DAYS])
				? (float) $hostitems[self::KEY_RETENTION_DAYS]['lastvalue']
				: null;
			$storage   = isset($hostitems[self::KEY_STORAGE_PCT])
				? (float) $hostitems[self::KEY_STORAGE_PCT]['lastvalue']
				: null;
			$stream    = $hostitems[self::KEY_STREAM_NAME]['lastvalue'] ?? '';
			$guid      = $hostitems[self::KEY_MILESTONE_GUID]['lastvalue'] ?? '';

			$frame_age = $last_ts > 0 ? max(0, $now - $last_ts) : null;

			// Determine state
			$state = 'ok';
			$reasons = [];

			if ($online === 0) {
				$state = 'offline';
				$reasons[] = _('Camera offline');
			}
			if ($recording === 0) {
				$state = ($state === 'ok') ? 'warning' : $state;
				$reasons[] = _('Not recording');
			}
			if ($frame_age !== null && $frame_age > $frame_stale) {
				$state = ($state === 'ok') ? 'warning' : $state;
				$reasons[] = _('Stale frame');
			}
			if ($retention !== null && $retention < $retention_warn) {
				$state = ($state === 'ok') ? 'warning' : $state;
				$reasons[] = _('Low retention');
			}
			if ($storage !== null && $storage >= 90) {
				$state = ($state === 'ok') ? 'warning' : $state;
				$reasons[] = _('Storage high');
			}

			if ($problems_only && $state === 'ok') {
				continue;
			}

			$ip = '';
			if (!empty($host['interfaces'])) {
				$ip = $host['interfaces'][0]['ip'] ?? '';
			}

			$cameras[] = [
				'hostid'    => $hostid,
				'name'      => $host['name'],
				'ip'        => $ip,
				'online'    => $online,
				'recording' => $recording,
				'frame_age' => $frame_age,
				'retention' => $retention,
				'storage'   => $storage,
				'stream'    => $stream,
				'guid'      => $guid,
				'state'     => $state,
				'reasons'   => $reasons
			];
		}

		// Sort: problems first, then name
		usort($cameras, function ($a, $b) {
			$order = ['offline' => 0, 'warning' => 1, 'ok' => 2];
			$sa = $order[$a['state']] ?? 3;
			$sb = $order[$b['state']] ?? 3;
			if ($sa !== $sb) return $sa <=> $sb;
			return strnatcasecmp($a['name'], $b['name']);
		});

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'cameras' => $cameras,
			'config' => [
				'layout'          => (int) $fields['layout'],
				'columns'         => (int) $fields['columns'],
				'retention_warn'  => $retention_warn,
				'frame_stale'     => $frame_stale,
				'problems_only'   => $problems_only
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}
}
