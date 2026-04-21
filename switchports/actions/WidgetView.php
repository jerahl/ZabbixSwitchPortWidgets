<?php

namespace Modules\SwitchPorts\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	private const MAX_MEMBERS = 8;

	private const POE_STATUS = [
		'1' => ['label' => 'Disabled',        'css' => 'poe-disabled'],
		'2' => ['label' => 'Searching',        'css' => 'poe-searching'],
		'3' => ['label' => 'Delivering Power', 'css' => 'poe-on'],
		'4' => ['label' => 'Fault',            'css' => 'poe-fault'],
		'6' => ['label' => 'Other Fault',      'css' => 'poe-fault'],
		'7' => ['label' => 'Test',             'css' => 'poe-test'],
		'8' => ['label' => 'Deny',             'css' => 'poe-fault'],
		'0' => ['label' => 'ERROR',            'css' => 'poe-fault'],
	];

	protected function doAction(): void {
		$item_prefix = $this->fields_values['item_prefix'] ?? 'net.if.status[ifOperStatus.';
		$show_labels = (bool) ($this->fields_values['show_labels'] ?? true);
		$show_desc   = (bool) ($this->fields_values['show_desc']   ?? true);
		$show_debug  = (bool) ($this->fields_values['show_debug']  ?? false);

		// ── Resolve host ───────────────────────────────────────────────────────
		// fields_values['override_hostid'] is an array of hostids when the Override host
		// field is connected to a Host Navigator (or similar broadcasting widget).
		// It is populated by the Zabbix framework via getFieldsData() before calling doAction().
		$hostid = null;
		$override_val = $this->fields_values['override_hostid'] ?? [];
		if (is_array($override_val) && $override_val) {
			$hostid = (int) reset($override_val);
			if ($hostid === 0) $hostid = null;
		}

		if (!$hostid) {
			$this->setResponse(new CControllerResponseData([
				'name'       => $this->getInput('name', $this->widget->getDefaultName()),
				'error'      => _('No host selected. Link this widget to a Host Navigator.'),
				'show_debug' => false,
				'user'       => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}

		$hosts = API::Host()->get([
			'output'  => ['host', 'name'],
			'hostids' => [$hostid],
		]);

		if (!$hosts) {
			$this->setResponse(new CControllerResponseData([
				'name'       => $this->getInput('name', $this->widget->getDefaultName()),
				'error'      => _('Host not found.'),
				'show_debug' => false,
				'user'       => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}
		$host = reset($hosts);

		// ── Step 1: Fetch stacking items ───────────────────────────────────────
		$stack_filter = array_merge(
			['system.hw.stacking'],
			array_map(fn($i) => 'stacking.member[' . $i . ']', range(1, self::MAX_MEMBERS))
		);

		$stack_items = API::Item()->get([
			'output'       => ['key_', 'lastvalue'],
			'hostids'      => [$hostid],
			'filter'       => ['key_' => $stack_filter],
			'webitems'     => false,
			'preservekeys' => false,
		]);

		$stack_by_key = [];
		foreach ($stack_items as $item) {
			$stack_by_key[$item['key_']] = $item['lastvalue'];
		}

		$hw_stacking = isset($stack_by_key['system.hw.stacking'])
			? (int) $stack_by_key['system.hw.stacking']
			: null;
		$is_stack = ($hw_stacking === 1);

		// ── Step 2: Discover ALL status items for this host ────────────────────
		// Search by prefix — finds every net.if.status[ifIndex.*] item.
		// This tells us exactly which SNMP indices exist, grouped by member.
		$prefix_bare = rtrim($item_prefix, '[');  // e.g. "net.if.status[ifIndex"
		$status_items = API::Item()->get([
			'output'                 => ['key_', 'lastvalue'],
			'hostids'                => [$hostid],
			'search'                 => ['key_' => $item_prefix],
			'searchWildcardsEnabled' => false,
			'webitems'               => false,
			'preservekeys'           => false,
		]);

		// Extract index from each key: "net.if.status[ifOperStatus.1001]" → 1001
		// Exclude indices over 10000 (virtual/loopback/tunnel interfaces)
		// Exclude indices ending in 000 (management port: 1000, 2000, 3000, ...)
		$all_indices = [];
		foreach ($status_items as $item) {
			if (preg_match('/\[(?:[^.]+\.)?(\d+)\]$/', $item['key_'], $m)) {
				$idx = (int)$m[1];
				if ($idx > 10000)         continue;   // skip virtual interfaces
				if ($idx % 1000 === 0)    continue;   // skip mgmt ports (1000, 2000, ...)
				$all_indices[$idx] = $item['lastvalue'];
			}
		}
		ksort($all_indices);

		// ── Step 3: Group indices into members ─────────────────────────────────
		// Strategy: group consecutive indices into blocks.
		// For a stack of N×48p+4sfp switches, indices run 1001-1052, 2001-2052 etc.
		// We split into groups by detecting gaps > 100 between indices.
		$member_groups = [];  // [ [idx, ...], [idx, ...], ... ]
		$current_group = [];
		$prev_idx      = null;

		foreach (array_keys($all_indices) as $idx) {
			if ($prev_idx !== null && ($idx - $prev_idx) > 100) {
				if ($current_group) $member_groups[] = $current_group;
				$current_group = [];
			}
			$current_group[] = $idx;
			$prev_idx        = $idx;
		}
		if ($current_group) $member_groups[] = $current_group;

		// ── Step 4: Build member definitions ──────────────────────────────────
		$member_defs = [];

		if ($is_stack && count($member_groups) > 1) {
			// Multiple index groups = multiple stack members
			foreach ($member_groups as $g_idx => $indices) {
				$member_num = $g_idx + 1;

				// Check if this member number has a stacking.member item
				$stack_key  = 'stacking.member[' . $member_num . ']';
				$active     = !isset($stack_by_key[$stack_key])
					|| ((int) $stack_by_key[$stack_key]) === 1;

				$member_defs[] = [
					'num'     => $member_num,
					'label'   => 'Member ' . $member_num . ($active ? '' : ' (absent)'),
					'active'  => $active,
					'indices' => $indices,
				];
			}
		} elseif (!empty($member_groups)) {
			// Single group (standalone or single-member stack)
			$member_defs[] = [
				'num'     => 1,
				'label'   => $is_stack ? 'Member 1' : $host['name'],
				'active'  => true,
				'indices' => $member_groups[0],
			];
		}

		// If nothing found at all, show empty single member
		if (!$member_defs) {
			$member_defs[] = [
				'num'     => 1,
				'label'   => $host['name'],
				'active'  => true,
				'indices' => [],
			];
		}

		// ── Step 5: Fetch all port detail items ────────────────────────────────
		$all_detail_keys = [];
		foreach ($member_defs as $def) {
			foreach ($def['indices'] as $idx) {
				$all_detail_keys[] = $item_prefix                   . $idx . ']';
				$all_detail_keys[] = 'net.if.type[ifType.'          . $idx . ']';
				$all_detail_keys[] = 'net.if.speed[ifHighSpeed.'    . $idx . ']';
				$all_detail_keys[] = 'net.if.adminstatus[ifIndex.'  . $idx . ']';
				$all_detail_keys[] = 'snmp.interfaces.poe.dstatus[' . (int)($idx / 1000) . '.' . ($idx % 100) . ']';
				// Alias candidates — the Zabbix key may be formatted several ways
				$all_detail_keys[] = 'net.if.alias[ifAlias.'        . $idx . ']';
				$all_detail_keys[] = 'net.if.alias[ifIndex.'        . $idx . ']';
				$all_detail_keys[] = 'net.if.alias['                . $idx . ']';
			}
		}

		$detail_items = []; // key_ => lastvalue
		$detail_itemids = []; // key_ => itemid
		if ($all_detail_keys) {
			$raw = API::Item()->get([
				'output'       => ['itemid', 'key_', 'lastvalue'],
				'hostids'      => [$hostid],
				'filter'       => ['key_' => array_unique($all_detail_keys)],
				'webitems'     => false,
				'preservekeys' => false,
			]);
			foreach ($raw as $item) {
				$detail_items[$item['key_']]   = $item['lastvalue'];
				$detail_itemids[$item['key_']] = (int) $item['itemid'];
			}
		}

		// ── Step 6: Problems for this host (with trigger+item expansion) ────
		// Must run before Step 7 so per-port build can check for port-attached problems.
		$problems = API::Problem()->get([
			'output'     => ['eventid', 'name', 'severity', 'objectid'],
			'hostids'    => [$hostid],
			'recent'     => true,
			'suppressed' => false,
			'symptom'    => false,
		]);
		$problem_count        = count($problems);
		$problem_max_severity = 0;
		$ports_with_problems  = [];  // snmp_index => max_severity_on_this_port

		if ($problems) {
			$trigger_ids = array_column($problems, 'objectid');
			$trigger_sev = [];
			foreach ($problems as $p) {
				$tid = (int) $p['objectid'];
				$sev = (int) $p['severity'];
				$trigger_sev[$tid] = max($trigger_sev[$tid] ?? 0, $sev);
				if ($sev > $problem_max_severity) {
					$problem_max_severity = $sev;
				}
			}

			$triggers = API::Trigger()->get([
				'output'       => ['triggerid'],
				'selectItems'  => ['itemid', 'key_'],
				'triggerids'   => array_unique($trigger_ids),
				'preservekeys' => true,
			]);

			foreach ($triggers as $trigger) {
				$tid = (int) $trigger['triggerid'];
				$sev = $trigger_sev[$tid] ?? 0;
				foreach ($trigger['items'] ?? [] as $item) {
					if (preg_match('/\[(?:[^\]]*\.)?(\d+)(?:\.[^\]]+)?\]$/', $item['key_'], $m)) {
						$snmp_idx = (int) $m[1];
						if ($snmp_idx > 0 && $snmp_idx < 100000) {
							$ports_with_problems[$snmp_idx] = max(
								$ports_with_problems[$snmp_idx] ?? 0,
								$sev
							);
						}
					}
				}
			}
		}

		// ── Step 7: Build final members array ─────────────────────────────────
		$members = [];

		foreach ($member_defs as $def) {
			$ports = [];

			// Build a lookup: port_number → ifIndex from the discovered indices
			// Port number is (ifIndex mod 1000) for Extreme-style indexing, and
			// falls back to positional for switches that don't use that scheme.
			$present_indices = $def['indices'];
			$idx_to_port = [];
			$max_port    = 0;
			$use_modulo  = true;
			foreach ($present_indices as $pos => $idx) {
				// If any index doesn't fit the "base-of-1000" scheme, fall back
				// to positional (the old behavior) for this member.
				$mod = $idx % 1000;
				if ($mod === 0) {
					// Shouldn't happen since we skipped mgmt earlier, but guard anyway
					$use_modulo = false;
					break;
				}
				$idx_to_port[$idx] = $mod;
				$max_port = max($max_port, $mod);
			}

			if (!$use_modulo) {
				// Positional fallback: port_num = array position + 1
				$idx_to_port = [];
				foreach ($present_indices as $pos => $idx) {
					$idx_to_port[$idx] = $pos + 1;
				}
				$max_port = count($present_indices);
			}

			$present_index_by_port = array_flip($idx_to_port);

			// Iterate from port 1 to max_port so that gaps produce an "absent"
			// placeholder, keeping the faceplate columns aligned physically.
			for ($port_num = 1; $port_num <= $max_port; $port_num++) {
				if (!isset($present_index_by_port[$port_num])) {
					// Gap in the ifIndex sequence — render an absent slot
					$ports[$port_num] = [
						'num'              => $port_num,
						'snmp_index'       => 0,
						'is_sfp'           => false,
						'itemids'          => [],
						'oper'             => 6,      // notPresent
						'admin'            => 1,
						'speed_label'      => '',
						'speed_class'      => '',
						'alias'            => '',
						'poe_raw'          => null,
						'poe_label'        => null,
						'poe_css'          => null,
						'poe_fault'        => false,
						'has_problem'      => false,
						'problem_severity' => 0,
						'css_state'        => 'port-absent',
					];
					continue;
				}

				$idx = $present_index_by_port[$port_num];

				$status_key = $item_prefix                       . $idx . ']';
				$type_key   = 'net.if.type[ifType.'              . $idx . ']';
				$speed_key  = 'net.if.speed[ifHighSpeed.'        . $idx . ']';
				$admin_key  = 'net.if.adminstatus[ifIndex.'      . $idx . ']';
				$poe_key    = 'snmp.interfaces.poe.dstatus['     . (int)($idx / 1000) . '.' . ($idx % 100) . ']';
				$alias_candidates = [
					'net.if.alias[ifAlias.' . $idx . ']',
					'net.if.alias[ifIndex.' . $idx . ']',
					'net.if.alias['         . $idx . ']',
				];

				$oper    = array_key_exists($status_key, $detail_items) ? (int)    $detail_items[$status_key] : 6;
				$admin   = array_key_exists($admin_key,  $detail_items) ? (int)    $detail_items[$admin_key]  : 1;
				$speed   = array_key_exists($speed_key,  $detail_items) ? (int)$detail_items[$speed_key] : 0;  // ifHighSpeed is Mbps
				$poe_raw = array_key_exists($poe_key,    $detail_items) ? (string) $detail_items[$poe_key]    : null;

				// Resolve alias from whichever candidate key was found
				$alias = '';
				foreach ($alias_candidates as $ak) {
					if (array_key_exists($ak, $detail_items) && $detail_items[$ak] !== '') {
						$alias = (string) $detail_items[$ak];
						break;
					}
				}

				// A port is SFP if it has no PoE item — PoE only exists on copper RJ-45 ports
				$is_sfp = ($poe_raw === null);
				$poe_info = ($poe_raw !== null && isset(self::POE_STATUS[$poe_raw]))
					? self::POE_STATUS[$poe_raw]
					: null;

				// Fault conditions that force port-error color:
				//   (1) PoE reports a fault state (4, 6, 8, 0)
				//   (2) An active Zabbix problem targets an item on this port
				$poe_fault        = in_array($poe_raw, ['4', '6', '8', '0'], true);
				$has_problem      = array_key_exists($idx, $ports_with_problems);
				$problem_severity = $ports_with_problems[$idx] ?? 0;

				$css_state = self::statusToCss($oper, $admin);
				// Override to error whenever a fault is present on this port
				if ($css_state === 'port-up' && ($poe_fault || $has_problem)) {
					$css_state = 'port-error';
				}

				// Collect itemids for all items on this port for broadcasting
				$port_itemids = array_filter(array_map(
					fn($key) => $detail_itemids[$key] ?? null,
					[$status_key, $type_key, $speed_key, $admin_key, $poe_key]
				));

				$ports[$port_num] = [
					'num'              => $port_num,
					'snmp_index'       => $idx,
					'is_sfp'           => $is_sfp,
					'itemids'          => array_values($port_itemids),
					'oper'             => $oper,
					'admin'            => $admin,
					'speed_label'      => self::formatSpeed($speed),
					'speed_class'      => self::speedToCss($speed, $oper),
					'alias'            => $alias,
					'poe_raw'          => $poe_raw,
					'poe_label'        => $poe_info ? $poe_info['label'] : null,
					'poe_css'          => $poe_info ? $poe_info['css']   : null,
					'poe_fault'        => $poe_fault,
					'has_problem'      => $has_problem,
					'problem_severity' => $problem_severity,
					'css_state'        => $css_state,
				];
			}

			$sfp_count  = count(array_filter($ports, fn($p) => $p['is_sfp']));
			$real_ports = array_filter($ports, fn($p) => $p['css_state'] !== 'port-absent' || $p['snmp_index'] > 0);
			$port_count = count($real_ports) - $sfp_count;

			$members[$def['num']] = [
				'label'      => $def['label'],
				'active'     => $def['active'],
				'port_count' => $port_count,
				'sfp_count'  => $sfp_count,
				'idx_base'   => $def['indices'][0] ?? 0,
				'total'      => count($real_ports),
				'ports'      => $ports,
			];
		}

		// ── Step 8: Switch health data (CPU, memory, temp, PSUs, fans) ────────
		$health = self::gatherHealth($hostid);

		// ── Step 9: IP address from host interfaces ───────────────────────────
		$interfaces = API::HostInterface()->get([
			'output'  => ['ip', 'useip', 'dns', 'main', 'type'],
			'hostids' => [$hostid],
		]);
		// Prefer main SNMP interface, fall back to any main interface
		$ip_addr = null;
		foreach ($interfaces as $iface) {
			if ((int)$iface['main'] === 1) {
				$ip_addr = (int)$iface['useip'] === 1 ? $iface['ip'] : $iface['dns'];
				if ((int)$iface['type'] === 2) break;  // SNMP wins; keep searching otherwise
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name'                 => $this->getInput('name', $this->widget->getDefaultName()),
			'host'                 => $host,
			'members'              => $members,
			'is_stack'             => $is_stack,
			'item_prefix'          => $item_prefix,
			'show_labels'          => $show_labels,
			'show_desc'            => $show_desc,
			'show_debug'           => $show_debug,
			'stack_by_key'         => $stack_by_key,
			'all_indices'          => array_keys($all_indices),
			'health'               => $health,
			'ip_addr'              => $ip_addr,
			'problem_count'        => $problem_count,
			'problem_max_severity' => $problem_max_severity,
			'user'                 => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	/**
	 * Collect all health-related items for a host in one API call.
	 * Returns an array with: cpu, memory, temp_c, temp_status, psus[], fans[].
	 */
	private static function gatherHealth(int $hostid): array {
		// Fetch all health items. We run one call per key prefix because
		// the Zabbix 'search' filter does NOT support OR across multiple
		// substrings for the same field — 'searchByAny' only applies to
		// different fields, not different values.
		$prefixes = [
			'system.cpu.util',
			'vm.memory.util',
			'sensor.temp.value',
			'sensor.temp.status',
			'sensor.psu.status',
			'sensor.fan.status',
		];

		$health_items = [];
		foreach ($prefixes as $prefix) {
			$items = API::Item()->get([
				'output'  => ['key_', 'lastvalue', 'name', 'units'],
				'hostids' => [$hostid],
				'search'  => ['key_' => $prefix],
				'startSearch' => true,   // match only at beginning of key
				'webitems'    => false,
			]);
			foreach ($items as $it) {
				$health_items[] = $it;
			}
		}

		$h = [
			'cpu_pct'       => null,
			'mem_pct'       => null,
			'temp_c'        => null,
			'temp_alarm'    => null,  // raw value from extremeOverTemperatureAlarm
			'psus'          => [],    // [['idx' => 1, 'status' => 2, 'label' => 'OK']]
			'fans'          => [],    // [['idx' => 1, 'status' => 1, 'label' => 'Running']]
		];

		foreach ($health_items as $item) {
			$key = $item['key_'];
			$val = $item['lastvalue'];

			if (str_starts_with($key, 'system.cpu.util[')) {
				$h['cpu_pct'] = (float) $val;
			}
			elseif (str_starts_with($key, 'vm.memory.util[')) {
				// Use highest mem util across items if multiple
				$pct = (float) $val;
				if ($h['mem_pct'] === null || $pct > $h['mem_pct']) {
					$h['mem_pct'] = $pct;
				}
			}
			elseif (str_starts_with($key, 'sensor.temp.value[')) {
				$h['temp_c'] = (float) $val;
			}
			elseif (str_starts_with($key, 'sensor.temp.status[')) {
				$h['temp_alarm'] = (int) $val;
			}
			elseif (str_starts_with($key, 'sensor.psu.status[')) {
				if (preg_match('/\[(?:[^.]+\.)?(\d+)\]$/', $key, $m)) {
					$status = (int) $val;
					$h['psus'][] = [
						'idx'    => (int) $m[1],
						'status' => $status,
						'label'  => match($status) {
							1 => 'Not present',
							2 => 'OK',
							3 => 'Critical',
							default => 'Unknown',
						},
					];
				}
			}
			elseif (str_starts_with($key, 'sensor.fan.status[')) {
				if (preg_match('/\[(?:[^.]+\.)?(\d+)\]$/', $key, $m)) {
					$status = (int) $val;
					$h['fans'][] = [
						'idx'    => (int) $m[1],
						'status' => $status,
						'label'  => match($status) {
							1 => 'Running',
							2 => 'Off/Failed',
							default => 'Unknown',
						},
					];
				}
			}
		}

		usort($h['psus'], fn($a, $b) => $a['idx'] <=> $b['idx']);
		usort($h['fans'], fn($a, $b) => $a['idx'] <=> $b['idx']);

		return $h;
	}

	private static function statusToCss(int $oper, int $admin): string {
		if ($admin === 2) return 'port-disabled';
		return match ($oper) {
			1       => 'port-up',
			2       => 'port-down',
			3       => 'port-testing',
			6       => 'port-absent',
			7       => 'port-error',    // lowerLayerDown — real fault
			default => 'port-unknown',
		};
	}

	private static function formatSpeed(int $bps): string {
		if ($bps <= 0)             return 'N/A';
		if ($bps >= 1_000_000_000) return round($bps / 1_000_000_000, 1) . ' Gbps';
		if ($bps >= 1_000_000)     return (int) round($bps / 1_000_000)  . ' Mbps';
		return (int) round($bps / 1_000) . ' Kbps';
	}

	/**
	 * Bucket a speed (in bps) into a CSS class for LED coloring.
	 * Only applies when the port is operationally up — otherwise the state
	 * color drives the LED and speed coloring is irrelevant.
	 *
	 *   10 Mbps  → speed-10m   (amber)
	 *   100 Mbps → speed-100m  (yellow-green)
	 *   1 Gbps   → speed-1g    (bright green, the default "up" color)
	 *   10 Gbps  → speed-10g   (cyan)
	 *   25 Gbps+ → speed-25g   (blue / purple accent for high-speed uplinks)
	 */
	private static function speedToCss(int $bps, int $oper): string {
		if ($oper !== 1)                return '';  // only color LED when port is up
		if ($bps >= 25_000_000_000)     return 'speed-25g';
		if ($bps >= 10_000_000_000)     return 'speed-10g';
		if ($bps >= 1_000_000_000)      return 'speed-1g';
		if ($bps >= 100_000_000)        return 'speed-100m';
		if ($bps >= 10_000_000)         return 'speed-10m';
		return '';  // unknown / sub-10M — default up color
	}
}
