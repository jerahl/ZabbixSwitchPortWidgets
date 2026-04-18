<?php

namespace Modules\PortDetail\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	private const POE_LABELS = [
		'1' => ['label' => 'Disabled',         'css' => 'pd-poe--disabled'],
		'2' => ['label' => 'Searching',        'css' => 'pd-poe--searching'],
		'3' => ['label' => 'Delivering Power', 'css' => 'pd-poe--on'],
		'4' => ['label' => 'Fault',            'css' => 'pd-poe--fault'],
		'6' => ['label' => 'Other Fault',      'css' => 'pd-poe--fault'],
		'7' => ['label' => 'Test',             'css' => 'pd-poe--test'],
		'8' => ['label' => 'Deny',             'css' => 'pd-poe--fault'],
		'0' => ['label' => 'ERROR',            'css' => 'pd-poe--fault'],
	];

	protected function init(): void {
		parent::init();
		// Accept sw_hostid and sw_snmpIndex as valid request inputs
		$this->addValidationRules([
			'sw_hostid'    => 'int32',
			'sw_snmpIndex' => 'int32',
		]);
	}

	protected function doAction(): void {
		$show_debug = (bool) ($this->fields_values['show_debug'] ?? true);
		$debug = [];

		// ── STEP 1: Discover hostid ──────────────────────────────────────────
		$debug['step_1_hostid'] = [];

		// Source A: override_hostid from fields_values (framework broadcast mechanism)
		$override_val = $this->fields_values['override_hostid'] ?? [];
		$debug['step_1_hostid']['fields_values_override_hostid'] = json_encode($override_val);

		$sw_hostid = 0;
		if (is_array($override_val) && !empty($override_val)) {
			// Handle both ['123'] format and [['id' => '123']] format
			$first = reset($override_val);
			$sw_hostid = is_array($first) ? (int)($first['id'] ?? 0) : (int)$first;
			$debug['step_1_hostid']['from_override_hostid'] = $sw_hostid;
		}

		// Source B: sw_hostid sent directly by our JS via getUpdateRequestData()
		$direct_hostid = $this->hasInput('sw_hostid') ? (int) $this->getInput('sw_hostid') : 0;
		$debug['step_1_hostid']['direct_sw_hostid_input'] = $direct_hostid;
		if (!$sw_hostid && $direct_hostid > 0) {
			$sw_hostid = $direct_hostid;
		}

		$debug['step_1_hostid']['final_hostid'] = $sw_hostid;

		// ── STEP 2: Discover snmpIndex ───────────────────────────────────────
		$debug['step_2_snmpindex'] = [];
		$debug['step_2_snmpindex']['has_input'] = $this->hasInput('sw_snmpIndex');
		$sw_snmpIndex = $this->hasInput('sw_snmpIndex') ? (int) $this->getInput('sw_snmpIndex') : 0;
		$debug['step_2_snmpindex']['raw_value'] = $sw_snmpIndex;

		// ── STEP 3: Capture all request inputs for debugging ─────────────────
		$debug['step_3_all_inputs'] = [];
		$all_input_keys = ['fields', 'name', 'templateid', 'dashboardid', 'widgetid',
			'view_mode', 'edit_mode', 'contents_width', 'contents_height',
			'sw_hostid', 'sw_snmpIndex'];
		foreach ($all_input_keys as $key) {
			if ($this->hasInput($key)) {
				$val = $this->getInput($key);
				$debug['step_3_all_inputs'][$key] = is_array($val) ? json_encode($val) : (string)$val;
			}
		}

		// ── STEP 4: All fields_values ────────────────────────────────────────
		$debug['step_4_fields_values'] = [];
		foreach ($this->fields_values as $k => $v) {
			$debug['step_4_fields_values'][$k] = is_array($v) ? json_encode($v) : (string)$v;
		}

		// ── If we don't have hostid OR snmpIndex, show waiting with debug ───
		if (!$sw_hostid || !$sw_snmpIndex) {
			$reason = [];
			if (!$sw_hostid)    $reason[] = 'no hostid';
			if (!$sw_snmpIndex) $reason[] = 'no snmpIndex';
			$debug['waiting_reason'] = implode(', ', $reason);

			$this->setResponse(new CControllerResponseData([
				'name'       => $this->getInput('name', $this->widget->getDefaultName()),
				'waiting'    => true,
				'debug_info' => $debug,
				'show_debug' => $show_debug,
				'user'       => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}

		// ── STEP 5: Build candidate keys and fetch items ─────────────────────
		$idx = $sw_snmpIndex;
		$candidate_keys = [
			'net.if.status[ifOperStatus.'        . $idx . ']',
			'net.if.type[ifType.'                . $idx . ']',
			'net.if.speed[ifHighSpeed.'          . $idx . ']',
			'net.if.adminstatus[ifIndex.'        . $idx . ']',
			'snmp.interfaces.poe.dstatus['       . (int)($idx / 1000) . '.' . ($idx % 100) . ']',
			'net.if.in[ifHCInOctets.'            . $idx . ']',
			'net.if.out[ifHCOutOctets.'          . $idx . ']',
			'net.if.in.errors[ifInErrors.'       . $idx . ']',
			'net.if.out.errors[ifOutErrors.'     . $idx . ']',
			'net.if.in.discards[ifInDiscards.'   . $idx . ']',
			'net.if.out.discards[ifOutDiscards.' . $idx . ']',
			// Alias candidates — Zabbix may key this several different ways
			'net.if.alias[ifAlias.'              . $idx . ']',
			'net.if.alias[ifIndex.'              . $idx . ']',
			'net.if.alias['                      . $idx . ']',
		];
		$debug['step_5_query']['hostid'] = $sw_hostid;
		$debug['step_5_query']['snmp_index'] = $idx;
		$debug['step_5_query']['candidate_keys'] = $candidate_keys;

		$items = API::Item()->get([
			'output'       => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock', 'units', 'hostid'],
			'hostids'      => [$sw_hostid],
			'filter'       => ['key_' => $candidate_keys],
			'webitems'     => false,
			'preservekeys' => true,
		]);

		$debug['step_5_query']['items_found'] = count($items);
		$debug['step_5_query']['found_keys'] = array_map(fn($i) => $i['key_'], $items);

		if (!$items) {
			$this->setResponse(new CControllerResponseData([
				'name'       => $this->getInput('name', $this->widget->getDefaultName()),
				'error'      => 'No items found for host ' . $sw_hostid . ' port index ' . $idx,
				'waiting'    => false,
				'debug_info' => $debug,
				'show_debug' => $show_debug,
				'user'       => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}

		// ── STEP 6: Index items by key type ──────────────────────────────────
		$by_type = [];
		foreach ($items as $item) {
			$key = $item['key_'];
			if (str_starts_with($key, 'net.if.status['))          $by_type['status']       = $item;
			elseif (str_starts_with($key, 'net.if.alias['))       $by_type['alias']        = $item;
			elseif (str_starts_with($key, 'net.if.type['))        $by_type['iftype']       = $item;
			elseif (str_starts_with($key, 'net.if.speed['))       $by_type['speed']        = $item;
			elseif (str_starts_with($key, 'net.if.adminstatus[')) $by_type['adminstatus']  = $item;
			elseif (str_starts_with($key, 'snmp.interfaces.poe')) $by_type['poe']          = $item;
			elseif (str_starts_with($key, 'net.if.in.errors['))   $by_type['errors_in']    = $item;
			elseif (str_starts_with($key, 'net.if.out.errors['))  $by_type['errors_out']   = $item;
			elseif (str_starts_with($key, 'net.if.in.discards[')) $by_type['discards_in']  = $item;
			elseif (str_starts_with($key, 'net.if.out.discards[')) $by_type['discards_out'] = $item;
			elseif (str_starts_with($key, 'net.if.in['))          $by_type['traffic_in']   = $item;
			elseif (str_starts_with($key, 'net.if.out['))         $by_type['traffic_out']  = $item;
		}
		$debug['step_6_by_type'] = array_map(fn($i) => $i['key_'] . ' = ' . $i['lastvalue'], $by_type);

		// ── STEP 7: Fetch host info ──────────────────────────────────────────
		$hostid = reset($items)['hostid'];
		$hosts = API::Host()->get([
			'output'  => ['name', 'maintenance_status', 'maintenance_type'],
			'hostids' => [$hostid],
		]);
		$host = $hosts ? reset($hosts) : null;

		// ── STEP 8: History range ───────────────────────────────────────────
		// Use the dashboard/widget-configured time period when available.
		// fields_values['time_period'] is resolved by the framework into
		// {'from_ts' => int, 'to_ts' => int} when a reference is used.
		$tp = $this->fields_values['time_period'] ?? null;
		if (is_array($tp) && isset($tp['from_ts'], $tp['to_ts'])) {
			$from = (int) $tp['from_ts'];
			$now  = (int) $tp['to_ts'];
		} else {
			$now  = time();
			$from = $now - 86400;  // fallback: last 24h
		}
		// Log resolved range for the debug panel
		$debug['step_8_time_range'] = [
			'from_ts' => $from,
			'to_ts'   => $now,
			'from'    => date('Y-m-d H:i:s', $from),
			'to'      => date('Y-m-d H:i:s', $now),
			'span_s'  => $now - $from,
		];

		$sparklines = [];
		foreach (['traffic_in', 'traffic_out'] as $type) {
			if (!isset($by_type[$type])) continue;
			$history = API::History()->get([
				'output'    => ['clock', 'value'],
				'itemids'   => [$by_type[$type]['itemid']],
				'history'   => 3,
				'time_from' => $from,
				'time_till' => $now,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit'     => 200,
			]);
			$sparklines[$type] = array_map(fn($h) => [
				'ts' => (int)$h['clock'], 'value' => (float)$h['value']
			], $history);
		}

		// Online state history
		$online_history = [];
		if (isset($by_type['status'])) {
			$raw = API::History()->get([
				'output'    => ['clock', 'value'],
				'itemids'   => [$by_type['status']['itemid']],
				'history'   => 3,
				'time_from' => $from,
				'time_till' => $now,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit'     => 500,
			]);
			$online_history = array_map(fn($h) => [
				'ts' => (int)$h['clock'], 'value' => (int)$h['value']
			], $raw);
		}

		// Errors merged history
		$error_merged = [];
		foreach (['errors_in', 'errors_out'] as $type) {
			if (!isset($by_type[$type])) continue;
			$raw = API::History()->get([
				'output'    => ['clock', 'value'],
				'itemids'   => [$by_type[$type]['itemid']],
				'history'   => 3,
				'time_from' => $from,
				'time_till' => $now,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit'     => 200,
			]);
			foreach ($raw as $h) {
				$ts = (int)$h['clock'];
				$error_merged[$ts] = ($error_merged[$ts] ?? 0) + (float)$h['value'];
			}
		}
		ksort($error_merged);
		$error_history = array_map(
			fn($ts, $v) => ['ts' => $ts, 'value' => $v],
			array_keys($error_merged), array_values($error_merged)
		);

		// Discards merged
		$discard_merged = [];
		foreach (['discards_in', 'discards_out'] as $type) {
			if (!isset($by_type[$type])) continue;
			$raw = API::History()->get([
				'output'    => ['clock', 'value'],
				'itemids'   => [$by_type[$type]['itemid']],
				'history'   => 3,
				'time_from' => $from,
				'time_till' => $now,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit'     => 200,
			]);
			foreach ($raw as $h) {
				$ts = (int)$h['clock'];
				$discard_merged[$ts] = ($discard_merged[$ts] ?? 0) + (float)$h['value'];
			}
		}
		ksort($discard_merged);
		$discard_history = array_map(
			fn($ts, $v) => ['ts' => $ts, 'value' => $v],
			array_keys($discard_merged), array_values($discard_merged)
		);

		// ── STEP 9: Derived values ───────────────────────────────────────────
		// ifHighSpeed is reported in Mbps → multiply by 1,000,000 for bps
		$speed_bps   = isset($by_type['speed'])       ? (int)$by_type['speed']['lastvalue'] : 0;
		$in_bps      = isset($by_type['traffic_in'])  ? (float)$by_type['traffic_in']['lastvalue']  : null;
		$out_bps     = isset($by_type['traffic_out']) ? (float)$by_type['traffic_out']['lastvalue'] : null;
		$utilization = ($speed_bps > 0 && ($in_bps !== null || $out_bps !== null))
			? round((max($in_bps ?? 0, $out_bps ?? 0) / $speed_bps) * 100, 1)
			: null;

		$poe_info = null;
		if (isset($by_type['poe'])) {
			$raw = (string)$by_type['poe']['lastvalue'];
			$poe_info = self::POE_LABELS[$raw] ?? ['label' => 'Unknown', 'css' => 'pd-poe--unknown'];
		}

		$oper_val  = isset($by_type['status'])      ? (int)$by_type['status']['lastvalue']      : null;
		$admin_val = isset($by_type['adminstatus']) ? (int)$by_type['adminstatus']['lastvalue'] : null;

		$port_label = 'Port ' . (int)($idx / 1000) . ':' . ($idx % 100) ;
		if (isset($by_type['status']) && preg_match('/\.(\d+)\]$/', $by_type['status']['key_'], $m)) {
			$port_label = 'Port ' . $m[1];
		}

		// Map of metric_name => itemid for broadcasting to Graph (classic) widgets
		$itemids = [];
		foreach (['status', 'adminstatus', 'speed', 'poe', 'traffic_in', 'traffic_out',
				  'errors_in', 'errors_out', 'discards_in', 'discards_out'] as $k) {
			if (isset($by_type[$k])) {
				$itemids[$k] = (int) $by_type[$k]['itemid'];
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name'               => $this->getInput('name', $this->widget->getDefaultName()),
			'waiting'            => false,
			'host'               => $host,
			'port_label'         => $port_label,
			'alias'              => isset($by_type['alias']) ? (string)$by_type['alias']['lastvalue'] : '',
			'itemids'            => $itemids,
			'speed_bps'          => $speed_bps,
			'speed_label'        => self::fmtSpeed($speed_bps),
			'in_bps'             => $in_bps,
			'in_label'           => $in_bps !== null ? self::fmtBps($in_bps) : null,
			'out_bps'            => $out_bps,
			'out_label'          => $out_bps !== null ? self::fmtBps($out_bps) : null,
			'utilization'        => $utilization,
			'poe_info'           => $poe_info,
			'port_state'         => self::portStateCss($oper_val, $admin_val),
			'oper_val'           => $oper_val,
			'admin_val'          => $admin_val,
			'errors_in_total'    => isset($by_type['errors_in'])    ? (int)$by_type['errors_in']['lastvalue']    : null,
			'errors_out_total'   => isset($by_type['errors_out'])   ? (int)$by_type['errors_out']['lastvalue']   : null,
			'discards_in_total'  => isset($by_type['discards_in'])  ? (int)$by_type['discards_in']['lastvalue']  : null,
			'discards_out_total' => isset($by_type['discards_out']) ? (int)$by_type['discards_out']['lastvalue'] : null,
			'range_from'         => $from,
			'range_to'           => $now,
			'sparklines'         => $sparklines,
			'online_history'     => $online_history,
			'error_history'      => $error_history,
			'discard_history'    => $discard_history,
			'debug_info'         => $debug,
			'show_debug'         => $show_debug,
			'user'               => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	private static function portStateCss(?int $oper, ?int $admin): string {
		if ($admin === 2) return 'disabled';
		return match ($oper) {
			1       => 'up',
			2       => 'down',
			3       => 'testing',
			7       => 'error',  // lowerLayerDown — real fault
			default => 'unknown',
		};
	}

	private static function fmtBps(float $bps): string {
		if ($bps >= 1_000_000_000) return round($bps / 1_000_000_000, 2) . ' Gbps';
		if ($bps >= 1_000_000)     return round($bps / 1_000_000, 1)     . ' Mbps';
		if ($bps >= 1_000)         return round($bps / 1_000, 1)         . ' Kbps';
		return round($bps) . ' bps';
	}

	private static function fmtSpeed(int $bps): string {
		return $bps > 0 ? self::fmtBps($bps) : '—';
	}
}
