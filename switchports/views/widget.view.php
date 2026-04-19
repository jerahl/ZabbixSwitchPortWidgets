<?php

/**
 * Switch Port Status Widget – presentation view (stack + PoE aware).
 *
 * @var CView  $this
 * @var array  $data
 */

function renderSwitchPort(array $port, bool $show_labels, bool $show_desc): string {
	$num    = (int)  $port['num'];
	$state  = htmlspecialchars($port['css_state']);
	$is_sfp = (bool) $port['is_sfp'];

	// Build tooltip: alias | speed | PoE status | fault reasons
	$tooltip = '';
	if ($show_desc) {
		$alias = $port['alias'] !== '' ? htmlspecialchars($port['alias']) : '(no description)';
		$speed = htmlspecialchars($port['speed_label']);
		$parts = ['Port ' . $num, $alias, $speed];
		if ($port['poe_label'] !== null) {
			$parts[] = 'PoE: ' . htmlspecialchars($port['poe_label']);
		}
		if (!empty($port['poe_fault'])) {
			$parts[] = '⚠ PoE fault';
		}
		if (!empty($port['has_problem'])) {
			$parts[] = '⚠ Active problem';
		}
		$tooltip = ' title="' . implode(' | ', $parts) . '"';
	}

	$top    = $show_labels ? '<span class="sw-port-label sw-port-label--top">'    . $num . '</span>' : '';
	$bottom = $show_labels ? '<span class="sw-port-label sw-port-label--bottom">' . $num . '</span>' : '';
	$inner  = $is_sfp ? '<span class="sw-sfp-inner"></span>' : '<span class="sw-port-led"></span>';
	$cls    = 'sw-port-icon' . ($is_sfp ? ' sw-port-icon--sfp' : '') . ' ' . $state;

	// PoE dot — only shown when PoE data exists for this port
	$poe_dot = '';
	if ($port['poe_css'] !== null) {
		$poe_dot = '<span class="sw-poe-dot ' . htmlspecialchars($port['poe_css']) . '"></span>';
	}

	// Encode itemids as JSON in a data attribute for JS click broadcasting
	$data_itemids = htmlspecialchars(json_encode($port['itemids'] ?? []), ENT_QUOTES);

	$snmp_attr = ' data-snmp-index="' . (int)$port['snmp_index'] . '"';
	return '<li class="sw-port" data-itemids="' . $data_itemids . '"' . $snmp_attr . '>'
		. $top
		. '<div class="' . $cls . '"' . $tooltip . '>'
		.   $inner
		.   $poe_dot
		. '</div>'
		. $bottom . '</li>';
}

// ── Health strip helpers ──────────────────────────────────────────────────────

/**
 * Classify a percentage into thresholds.
 */
function hsPctState(?float $pct, float $warn = 80.0, float $crit = 90.0): string {
	if ($pct === null) return 'unknown';
	if ($pct >= $crit) return 'crit';
	if ($pct >= $warn) return 'warn';
	return 'ok';
}

/**
 * Classify a temperature in Celsius.
 *   <85  green / ok
 *   85-89 yellow / warn
 *   >=90  red / crit
 */
function hsTempState(?float $c, ?int $alarm): string {
	// Firmware-level over-temp alarm: 1=normal, 2=warning, 3=critical (EXTREME-SYSTEM-MIB)
	if ($alarm === 3) return 'crit';
	if ($alarm === 2) return 'warn';
	if ($c === null)  return 'unknown';
	if ($c >= 90)     return 'crit';
	if ($c >= 85)     return 'warn';
	return 'ok';
}

/**
 * Roll up the worst status from an array of psu/fan entries.
 *   'ok' if all OK
 *   'warn' if any non-OK-but-not-critical
 *   'crit' if any critical/off
 *   'unknown' if empty
 */
function hsRollupPSU(array $psus): string {
	if (!$psus) return 'unknown';
	$has_crit = false; $has_warn = false; $has_ok = false;
	foreach ($psus as $p) {
		if ($p['status'] === 3)      $has_crit = true;
		elseif ($p['status'] === 2)  $has_ok   = true;
		elseif ($p['status'] === 1)  $has_warn = true;  // notPresent in a slot is unusual
	}
	if ($has_crit) return 'crit';
	if (!$has_ok && $has_warn) return 'warn';
	return 'ok';
}

function hsRollupFan(array $fans): string {
	if (!$fans) return 'unknown';
	$has_off = false;
	foreach ($fans as $f) {
		if ($f['status'] !== 1) $has_off = true;
	}
	return $has_off ? 'crit' : 'ok';
}

/**
 * Map Zabbix severity 0-5 to a CSS state class for the problem pill.
 */
function hsSeverityState(int $sev): string {
	return match (true) {
		$sev >= 4 => 'crit',
		$sev >= 2 => 'warn',
		$sev >= 1 => 'info',
		default   => 'ok',
	};
}

/**
 * Render the full health strip.
 */
function renderHealthStrip(array $data): string {
	$h            = $data['health']    ?? [];
	$ip           = $data['ip_addr']   ?? null;
	$problems     = (int)($data['problem_count']        ?? 0);
	$problems_sev = (int)($data['problem_max_severity'] ?? 0);

	$cpu   = $h['cpu_pct']    ?? null;
	$mem   = $h['mem_pct']    ?? null;
	$temp  = $h['temp_c']     ?? null;
	$alarm = $h['temp_alarm'] ?? null;
	$psus  = $h['psus']       ?? [];
	$fans  = $h['fans']       ?? [];

	$cpu_state  = hsPctState($cpu);
	$mem_state  = hsPctState($mem);
	$temp_state = hsTempState($temp, $alarm);
	$psu_state  = hsRollupPSU($psus);
	$fan_state  = hsRollupFan($fans);

	$cpu_txt  = $cpu  !== null ? round($cpu)  . '%' : '–';
	$mem_txt  = $mem  !== null ? round($mem)  . '%' : '–';
	$temp_txt = $temp !== null ? round($temp) . '°C' : '–';

	// PSU tooltip: list each unit
	$psu_tip = $psus
		? implode(' | ', array_map(fn($p) => 'PSU ' . $p['idx'] . ': ' . $p['label'], $psus))
		: 'No PSU data';
	$fan_tip = $fans
		? implode(' | ', array_map(fn($f) => 'Fan ' . $f['idx'] . ': ' . $f['label'], $fans))
		: 'No fan data';

	$prob_state = $problems > 0 ? hsSeverityState($problems_sev) : 'ok';
	$prob_tip   = $problems . ' active problem' . ($problems === 1 ? '' : 's');

	$out  = '<div class="sw-health">';

	// CPU
	$out .= '<div class="sw-health__item sw-health__item--' . $cpu_state . '" title="CPU: ' . $cpu_txt . '">';
	$out .= '<svg class="sw-health__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
	$out .=   '<rect x="5" y="5" width="14" height="14" rx="1"/><rect x="8" y="8" width="8" height="8" fill="currentColor" opacity="0.25"/>';
	$out .=   '<path d="M10 3v2M14 3v2M10 19v2M14 19v2M3 10h2M3 14h2M19 10h2M19 14h2"/>';
	$out .= '</svg>';
	$out .= '<span class="sw-health__label">CPU</span><span class="sw-health__value">' . htmlspecialchars($cpu_txt) . '</span>';
	$out .= '</div>';

	// Memory
	$out .= '<div class="sw-health__item sw-health__item--' . $mem_state . '" title="Memory: ' . $mem_txt . '">';
	$out .= '<svg class="sw-health__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
	$out .=   '<rect x="3" y="8" width="18" height="8" rx="1"/>';
	$out .=   '<path d="M7 8v8M11 8v8M15 8v8"/>';
	$out .= '</svg>';
	$out .= '<span class="sw-health__label">Mem</span><span class="sw-health__value">' . htmlspecialchars($mem_txt) . '</span>';
	$out .= '</div>';

	// Temp
	$out .= '<div class="sw-health__item sw-health__item--' . $temp_state . '" title="Temperature: ' . $temp_txt . '">';
	$out .= '<svg class="sw-health__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
	$out .=   '<path d="M12 2a2 2 0 00-2 2v10.5a4 4 0 104 0V4a2 2 0 00-2-2z"/>';
	$out .=   '<circle cx="12" cy="17" r="2" fill="currentColor"/>';
	$out .= '</svg>';
	$out .= '<span class="sw-health__value">' . htmlspecialchars($temp_txt) . '</span>';
	$out .= '</div>';

	// PSU — icon-only with tooltip
	$out .= '<div class="sw-health__item sw-health__item--icon sw-health__item--' . $psu_state . '" title="' . htmlspecialchars($psu_tip) . '">';
	$out .= '<svg class="sw-health__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
	$out .=   '<path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z" fill="currentColor" opacity="0.35"/>';
	$out .= '</svg>';
	$out .= '<span class="sw-health__label">PSU</span>';
	$out .= '</div>';

	// Fans — icon-only with tooltip
	$out .= '<div class="sw-health__item sw-health__item--icon sw-health__item--' . $fan_state . '" title="' . htmlspecialchars($fan_tip) . '">';
	$out .= '<svg class="sw-health__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
	$out .=   '<circle cx="12" cy="12" r="2.5" fill="currentColor"/>';
	$out .=   '<path d="M12 4c-1 3-1 5 0 7.5M12 20c1-3 1-5 0-7.5M4 12c3-1 5-1 7.5 0M20 12c-3 1-5 1-7.5 0"/>';
	$out .= '</svg>';
	$out .= '<span class="sw-health__label">Fan</span>';
	$out .= '</div>';

	// Problem count pill (always shown if there are problems)
	if ($problems > 0) {
		$out .= '<div class="sw-health__item sw-health__problems sw-health__problems--' . $prob_state . '" title="' . htmlspecialchars($prob_tip) . '">';
		$out .= '<svg class="sw-health__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
		$out .=   '<path d="M12 2L2 20h20L12 2z"/><path d="M12 9v5M12 17h.01" stroke-width="2.5"/>';
		$out .= '</svg>';
		$out .= '<span class="sw-health__value">' . $problems . '</span>';
		$out .= '</div>';
	}

	// IP Address — right aligned
	if ($ip) {
		$out .= '<div class="sw-health__ip">';
		$out .= '<span class="sw-health__ip-label">IP Address:</span>';
		$out .= '<span class="sw-health__ip-value">' . htmlspecialchars($ip) . '</span>';
		$out .= '</div>';
	}

	$out .= '</div>';
	return $out;
}

// ── Main widget body ───────────────────────────────────────────────────────────
if (!empty($data['error'])) {
	$body = '<div class="sw-error-msg">' . htmlspecialchars($data['error']) . '</div>';
} else {
	$members     = $data['members'];
	$show_labels = (bool) $data['show_labels'];
	$show_desc   = (bool) $data['show_desc'];

	$all_ports = array_merge(...array_map('array_values', array_column($members, 'ports')));
	$up_total   = count(array_filter($all_ports, fn($p) => $p['css_state'] === 'port-up'));
	$down_total = count(array_filter($all_ports, fn($p) => $p['css_state'] === 'port-down'));
	$total      = count($all_ports);

	$hostname = htmlspecialchars($data['host']['name'] ?? $data['host']['host'] ?? 'Switch');

	$hostid_attr = isset($data['host']['hostid']) ? ' data-hostid="' . (int)$data['host']['hostid'] . '"' : '';
	$body  = '<div class="sw-widget-wrapper"' . $hostid_attr . '>';

	// ── Top health strip ─────────────────────────────────────────────────
	$body .= renderHealthStrip($data);

	$body .= '<div class="sw-header">'
		. '<span class="sw-header__hostname">' . $hostname . '</span>'
		. '<span class="sw-header__summary">'
		.   $up_total . ' up / ' . $down_total . ' down / ' . $total . ' total'
		. '</span>'
		. '</div>';

	foreach ($members as $m => $member) {
		$ports   = $member['ports'];
		$regular = array_filter($ports, fn($p) => !$p['is_sfp']);
		$sfp     = array_filter($ports, fn($p) =>  $p['is_sfp']);
		$m_up    = count(array_filter($ports, fn($p) => $p['css_state'] === 'port-up'));
		$m_down  = count(array_filter($ports, fn($p) => $p['css_state'] === 'port-down'));
		$m_poe_on   = count(array_filter($ports, fn($p) => $p['poe_css'] === 'poe-on'));
		$m_poe_any  = count(array_filter($ports, fn($p) => $p['poe_css'] !== null));

		$body .= '<div class="sw-stack-member">';
		$body .= '<div class="sw-member-label">'
			. '<span class="sw-member-label__name">' . htmlspecialchars($member['label']) . '</span>'
			. '<span class="sw-member-label__stats">' . $m_up . ' up / ' . $m_down . ' down'
			. ($m_poe_any > 0 ? ' / <span class="sw-member-label__poe">&#x26A1; ' . $m_poe_on . ' PoE on</span>' : '')
			. '</span>'
			. '</div>';

		$body .= '<div class="sw-faceplate">';
		$body .= '<div class="sw-panel sw-panel--rj45"><ul class="sw-portlist sw-portlist--dual-row">';
		foreach ($regular as $port) {
			$body .= renderSwitchPort($port, $show_labels, $show_desc);
		}
		$body .= '</ul></div>';

		if ($sfp) {
			$body .= '<div class="sw-panel sw-panel--sfp">'
				. '<span class="sw-sfp-label">SFP</span>'
				. '<ul class="sw-portlist sw-portlist--sfp">';
			foreach ($sfp as $port) {
				$body .= renderSwitchPort($port, $show_labels, $show_desc);
			}
			$body .= '</ul></div>';
		}
		$body .= '</div>'; // .sw-faceplate
		$body .= '</div>'; // .sw-stack-member
	}

	// Legend — port states
	$legend = [
		'port-up'       => ['label' => 'Up',          'color' => '#00c853'],
		'port-down'     => ['label' => 'Down',        'color' => '#64748b'],
		'port-error'    => ['label' => 'Error',       'color' => '#ef5350'],
		'port-disabled' => ['label' => 'Disabled',    'color' => '#9e9e9e'],
		'port-testing'  => ['label' => 'Testing',     'color' => '#fdd835'],
		'port-absent'   => ['label' => 'Not Present', 'color' => '#37474f'],
		'port-unknown'  => ['label' => 'Unknown',     'color' => '#78909c'],
	];

	$body .= '<div class="sw-legend">';
	foreach ($legend as $css_class => $info) {
		$count = count(array_filter($all_ports, fn($p) => $p['css_state'] === $css_class));
		if ($count === 0 && in_array($css_class, ['port-error', 'port-testing', 'port-absent', 'port-unknown'])) {
			continue;
		}
		$body .= '<span class="sw-legend__item">'
			. '<span class="sw-legend__swatch" style="background:' . $info['color'] . '"></span>'
			. '<span class="sw-legend__text">' . htmlspecialchars($info['label']) . ' (' . $count . ')</span>'
			. '</span>';
	}

	// PoE legend — only shown if any port has PoE data
	$poe_legend = [
		'poe-on'       => ['label' => 'PoE On',       'color' => '#f59e0b'],
		'poe-searching'=> ['label' => 'Searching',    'color' => '#38bdf8'],
		'poe-disabled' => ['label' => 'PoE Disabled', 'color' => '#6b7280'],
		'poe-fault'    => ['label' => 'PoE Fault',    'color' => '#dc2626'],
		'poe-test'     => ['label' => 'PoE Test',     'color' => '#a78bfa'],
	];

	$any_poe = count(array_filter($all_ports, fn($p) => $p['poe_css'] !== null)) > 0;
	if ($any_poe) {
		$body .= '<span class="sw-legend__sep">|</span>';
		foreach ($poe_legend as $css_class => $info) {
			$count = count(array_filter($all_ports, fn($p) => $p['poe_css'] === $css_class));
			if ($count === 0) continue;
			$body .= '<span class="sw-legend__item">'
				. '<span class="sw-poe-dot ' . $css_class . ' sw-legend__poe-swatch"></span>'
				. '<span class="sw-legend__text">' . htmlspecialchars($info['label']) . ' (' . $count . ')</span>'
				. '</span>';
		}
	}

	$body .= '</div>'; // .sw-legend
	// Debug panel injected here so it sits inside .sw-widget-wrapper flex column
	$body .= '<!-- debug-placeholder -->';
	$body .= '</div>'; // .sw-widget-wrapper
}

// ── Debug panel ────────────────────────────────────────────────────────────────
function swDebugRow(string $k, string $v): string {
	return '<tr><td class="sw-dbg-k">' . htmlspecialchars($k) . '</td>'
		. '<td class="sw-dbg-v">' . htmlspecialchars($v) . '</td></tr>';
}

$dbg  = '<details class="sw-dbg">';
$dbg .= '<summary class="sw-dbg-title">&#x1F41E; Debug: PHP data</summary>';
$dbg .= '<div class="sw-dbg-body">';

$dbg .= '<p class="sw-dbg-h">Config</p><table class="sw-dbg-tbl">';
foreach (['name', 'is_stack', 'item_prefix', 'show_labels', 'show_desc'] as $key) {
	if (array_key_exists($key, $data)) {
		$dbg .= swDebugRow($key, var_export($data[$key], true));
	}
}
$dbg .= '</table>';

if (!empty($data['stack_by_key'])) {
	$dbg .= '<p class="sw-dbg-h">Stacking items found</p><table class="sw-dbg-tbl">';
	foreach ($data['stack_by_key'] as $k => $v) {
		$dbg .= swDebugRow((string) $k, (string) $v);
	}
	$dbg .= '</table>';
}

if (!empty($data['all_indices'])) {
	$dbg .= '<p class="sw-dbg-h">Discovered SNMP indices (' . count($data['all_indices']) . ')</p>';
	$dbg .= '<div class="sw-dbg-v" style="padding:4px 6px;word-break:break-all;">'
		. htmlspecialchars(implode(', ', $data['all_indices']))
		. '</div>';
}

if (!empty($data['host'])) {
	$dbg .= '<p class="sw-dbg-h">Host</p><table class="sw-dbg-tbl">';
	foreach ($data['host'] as $k => $v) {
		$dbg .= swDebugRow((string) $k, (string) $v);
	}
	$dbg .= '</table>';
}

// Health data debug
$dbg .= '<p class="sw-dbg-h">Health strip data</p><table class="sw-dbg-tbl">';
$h_dbg = $data['health'] ?? [];
$dbg .= swDebugRow('cpu_pct',    (string)($h_dbg['cpu_pct']    ?? 'null'));
$dbg .= swDebugRow('mem_pct',    (string)($h_dbg['mem_pct']    ?? 'null'));
$dbg .= swDebugRow('temp_c',     (string)($h_dbg['temp_c']     ?? 'null'));
$dbg .= swDebugRow('temp_alarm', (string)($h_dbg['temp_alarm'] ?? 'null'));
$dbg .= swDebugRow('psus',       count($h_dbg['psus'] ?? []) . ' units: ' . json_encode($h_dbg['psus'] ?? []));
$dbg .= swDebugRow('fans',       count($h_dbg['fans'] ?? []) . ' units: ' . json_encode($h_dbg['fans'] ?? []));
$dbg .= swDebugRow('ip_addr',    (string)($data['ip_addr'] ?? 'null'));
$dbg .= swDebugRow('problems',   (int)($data['problem_count'] ?? 0) . ' (max sev: ' . (int)($data['problem_max_severity'] ?? 0) . ')');
$dbg .= '</table>';

if (!empty($data['members'])) {
	foreach ($data['members'] as $m => $member) {
		$dbg .= '<p class="sw-dbg-h">Member ' . (int)$m . ': '
			. htmlspecialchars($member['label'])
			. ' — idx_base=' . (int)$member['idx_base']
			. ', ports=' . (int)$member['port_count']
			. ', sfp=' . (int)$member['sfp_count']
			. '</p>';
		$dbg .= '<table class="sw-dbg-tbl">';
		$dbg .= '<tr><th>port#</th><th>snmp_idx</th><th>sfp</th><th>oper</th>'
			. '<th>admin</th><th>css_state</th><th>speed</th><th>poe_raw</th><th>poe_label</th>'
			. '<th>poe_flt</th><th>prob</th><th>alias</th></tr>';
		foreach ($member['ports'] as $p) {
			$row_style = ((int)$p['admin'] === 2) ? ' style="background:#3a1a1a"' : '';
			$dbg .= '<tr' . $row_style . '>'
				. '<td>' . (int)$p['num']                    . '</td>'
				. '<td>' . (int)$p['snmp_index']             . '</td>'
				. '<td>' . ($p['is_sfp'] ? '&#x2713;' : '')  . '</td>'
				. '<td>' . (int)$p['oper']                   . '</td>'
				. '<td>' . (int)$p['admin']                  . '</td>'
				. '<td>' . htmlspecialchars($p['css_state'])  . '</td>'
				. '<td>' . htmlspecialchars($p['speed_label']). '</td>'
				. '<td>' . htmlspecialchars((string)$p['poe_raw'])   . '</td>'
				. '<td>' . htmlspecialchars((string)$p['poe_label']) . '</td>'
				. '<td>' . (!empty($p['poe_fault'])   ? '&#x2713;' : '') . '</td>'
				. '<td>' . (!empty($p['has_problem']) ? 'sev ' . (int)$p['problem_severity'] : '') . '</td>'
				. '<td>' . htmlspecialchars($p['alias'])      . '</td>'
				. '</tr>';
		}
		$dbg .= '</table>';
	}
}

$dbg .= '</div></details>';

// Insert debug panel inside the wrapper if enabled
if ((bool)($data['show_debug'] ?? false)) {
	$body = str_replace('<!-- debug-placeholder -->', $dbg, $body);
} else {
	$body = str_replace('<!-- debug-placeholder -->', '', $body);
}

(new CWidgetView($data))
	->addItem($body)
	->show();
