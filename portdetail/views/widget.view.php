<?php
/**
 * Switch Port Detail Widget – presentation view.
 *
 * @var CView  $this
 * @var array  $data
 */

// ── Debug panel renderer (always available) ──────────────────────────────────
function pdRenderDebug(?array $debug, bool $show): string {
	if (!$show || !$debug) return '';

	$html = '<div class="pd-debug">';
	$html .= '<div class="pd-debug__title">🔍 DEBUG INFO</div>';

	foreach ($debug as $section => $content) {
		$html .= '<div class="pd-debug__section">';
		$html .= '<div class="pd-debug__section-title">' . htmlspecialchars($section) . '</div>';
		$html .= '<pre class="pd-debug__pre">';
		if (is_array($content)) {
			foreach ($content as $k => $v) {
				$val = is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : (string)$v;
				$html .= htmlspecialchars($k) . ': ' . htmlspecialchars($val) . "\n";
			}
		} else {
			$html .= htmlspecialchars((string)$content);
		}
		$html .= '</pre>';
		$html .= '</div>';
	}
	$html .= '</div>';
	return $html;
}

$debug_html = pdRenderDebug($data['debug_info'] ?? null, (bool)($data['show_debug'] ?? false));

// ── WAITING state ────────────────────────────────────────────────────────────
if (!empty($data['waiting'])) {
	$body = '<div class="pd-waiting">
		<span class="pd-waiting__icon">&#x1F4E1;</span>
		<span class="pd-waiting__text">Awaiting port selection&hellip;</span>
		<span class="pd-waiting__sub">Click a port in the Switch Port Status widget</span>
	</div>';
	$body .= $debug_html;
	(new CWidgetView($data))->addItem($body)->show();
	return;
}

// ── ERROR state ──────────────────────────────────────────────────────────────
if (!empty($data['error'])) {
	$body = '<div class="pd-error">' . htmlspecialchars($data['error']) . '</div>';
	$body .= $debug_html;
	(new CWidgetView($data))->addItem($body)->show();
	return;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function pdSparkline(array $points, string $color, string $fill_color, int $w = 120, int $h = 40): string {
	if (count($points) < 2) {
		return '<svg class="pd-spark" viewBox="0 0 '.$w.' '.$h.'" xmlns="http://www.w3.org/2000/svg">'
			. '<line x1="0" y1="'.($h/2).'" x2="'.$w.'" y2="'.($h/2).'" stroke="'.$color.'" stroke-width="1" stroke-dasharray="3,3"/>'
			. '</svg>';
	}

	$values  = array_column($points, 'value');
	$max_val = max($values) ?: 1;
	$min_val = 0;
	$range   = $max_val - $min_val ?: 1;
	$n       = count($points);
	$pad     = 2;

	$coords = [];
	foreach ($points as $i => $p) {
		$x = $pad + ($i / ($n - 1)) * ($w - $pad * 2);
		$y = $h - $pad - (($p['value'] - $min_val) / $range) * ($h - $pad * 2);
		$coords[] = round($x, 1) . ',' . round($y, 1);
	}

	$poly_pts  = implode(' ', $coords);
	$first     = $coords[0];
	$last      = $coords[$n - 1];
	[$lx, $ly] = explode(',', $last);
	[$fx, $fy] = explode(',', $first);

	$area_pts = $poly_pts . ' ' . $lx . ',' . ($h - $pad) . ' ' . $fx . ',' . ($h - $pad);

	return '<svg class="pd-spark" viewBox="0 0 '.$w.' '.$h.'" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">'
		. '<polygon points="'.$area_pts.'" fill="'.$fill_color.'" opacity="0.25"/>'
		. '<polyline points="'.$poly_pts.'" fill="none" stroke="'.$color.'" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>'
		. '</svg>';
}

function pdOnlineBar(array $history, int $now, int $from): string {
	$segments = 48;
	$seg_dur  = ($now - $from) / $segments;
	$bars     = array_fill(0, $segments, 'unknown');
	foreach ($history as $i => $point) {
		$seg = (int) min($segments - 1, floor(($point['ts'] - $from) / $seg_dur));
		$bars[$seg] = $point['value'] === 1 ? 'up' : 'down';
		$next_ts = $history[$i + 1]['ts'] ?? $now;
		$next_seg = (int) min($segments - 1, floor(($next_ts - $from) / $seg_dur));
		for ($s = $seg + 1; $s <= $next_seg && $s < $segments; $s++) {
			$bars[$s] = $point['value'] === 1 ? 'up' : 'down';
		}
	}
	$out = '<div class="pd-bar">';
	foreach ($bars as $state) {
		$cls = $state === 'up' ? 'pd-bar__seg--up'
			: ($state === 'down' ? 'pd-bar__seg--down' : 'pd-bar__seg--unknown');
		$out .= '<span class="pd-bar__seg '.$cls.'"></span>';
	}
	$out .= '</div>';
	return $out;
}

function pdEventBar(array $history, int $now, int $from): string {
	$segments = 48;
	$seg_dur  = ($now - $from) / $segments;
	$bars     = array_fill(0, $segments, 0.0);
	foreach ($history as $point) {
		$seg = (int) min($segments - 1, floor(($point['ts'] - $from) / $seg_dur));
		$bars[$seg] += $point['value'];
	}
	$max_val = max($bars) ?: 1;
	$out = '<div class="pd-bar">';
	foreach ($bars as $val) {
		$intensity = $val > 0 ? max(0.2, min(1.0, $val / $max_val)) : 0;
		if ($val > 0) {
			$out .= '<span class="pd-bar__seg pd-bar__seg--event" style="opacity:'.round($intensity, 2).'"></span>';
		} else {
			$out .= '<span class="pd-bar__seg pd-bar__seg--quiet"></span>';
		}
	}
	$out .= '</div>';
	return $out;
}

/**
 * Render a small graph-link button. Supports 1+ itemids (joined for multi-series).
 * Clicking broadcasts itemids to any dashboard Graph (classic) widget listening on _itemid.
 */
function pdGraphBtn(array $itemids, string $label): string {
	if (empty($itemids)) return '';
	$payload = htmlspecialchars(json_encode(array_values($itemids)), ENT_QUOTES);
	return '<button type="button" class="pd-graph-btn" '
		. 'data-itemids="' . $payload . '" '
		. 'title="View ' . htmlspecialchars($label) . ' graph" '
		. 'aria-label="View ' . htmlspecialchars($label) . ' graph">'
		. '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
		. '<path d="M2 13h12M3 11V7M6.5 11V4M10 11V8M13.5 11V5"/>'
		. '</svg>'
		. '</button>';
}

// ── Main data render ─────────────────────────────────────────────────────────
// Use the same time range the PHP controller used for history queries
$now  = (int)($data['range_to']   ?? time());
$from = (int)($data['range_from'] ?? ($now - 86400));

// Human label for the range: "24h", "1h", "15m", "7d"
$span_seconds = max(1, $now - $from);
if     ($span_seconds >= 604800) $range_label = round($span_seconds / 86400)  . 'd';
elseif ($span_seconds >= 86400)  $range_label = round($span_seconds / 3600)   . 'h';
elseif ($span_seconds >= 3600)   $range_label = round($span_seconds / 3600)   . 'h';
elseif ($span_seconds >= 60)     $range_label = round($span_seconds / 60)     . 'm';
else                             $range_label = $span_seconds                 . 's';
$host      = $data['host'];
$alias     = htmlspecialchars($data['alias'] ?: '—');
$port_lbl  = htmlspecialchars($data['port_label'] ?: 'Port');
$state     = $data['port_state'];
$in_lbl    = $data['in_label']    ?? '—';
$out_lbl   = $data['out_label']   ?? '—';
$util      = $data['utilization'] !== null ? $data['utilization'] . '%' : '—';
$speed_lbl = $data['speed_label'] ?? '—';
$poe       = $data['poe_info'];

$maint_on = ($host && (int)($host['maintenance_status'] ?? 0) === 1);

$state_label = match($state) {
	'up'       => 'UP',
	'down'     => 'DOWN',
	'error'    => 'ERROR',
	'disabled' => 'DISABLED',
	'testing'  => 'TESTING',
	default    => 'UNKNOWN',
};

$ei = $data['errors_in_total'];
$eo = $data['errors_out_total'];
$di = $data['discards_in_total'];
$do = $data['discards_out_total'];

$err_total = (int)($ei ?? 0) + (int)($eo ?? 0);
$dis_total = (int)($di ?? 0) + (int)($do ?? 0);
$err_label = ($ei !== null || $eo !== null)
	? $err_total . ' (in ' . ($ei ?? '?') . ' / out ' . ($eo ?? '?') . ')'
	: '—';
$dis_label = ($di !== null || $do !== null)
	? $dis_total . ' (in ' . ($di ?? '?') . ' / out ' . ($do ?? '?') . ')'
	: '—';

$err_trend = $err_total > 0 ? ', rising' : ', stable';
$dis_trend = $dis_total > 0 ? ', rising' : ', stable';

$in_spark  = pdSparkline($data['sparklines']['traffic_in']  ?? [], '#60a5fa', '#60a5fa');
$out_spark = pdSparkline($data['sparklines']['traffic_out'] ?? [], '#f59e0b', '#f59e0b');

$online_bar  = pdOnlineBar($data['online_history'],  $now, $from);
$error_bar   = pdEventBar($data['error_history'],   $now, $from);
$discard_bar = pdEventBar($data['discard_history'], $now, $from);

$itemids = $data['itemids'] ?? [];

$body = '<div class="pd-widget">';

$body .= '<div class="pd-header">';
$body .= '<span class="pd-header__port">' . $port_lbl . '</span>';
$body .= '<span class="pd-header__alias">' . $alias . '</span>';
$body .= '<span class="pd-header__state pd-state--' . $state . '">' . $state_label . '</span>';
if ($maint_on) {
	$body .= '<span class="pd-maint">MAINTENANCE: ON</span>';
}
$body .= '</div>';

$body .= '<div class="pd-main">';

$body .= '<div class="pd-traffic">';

$body .= '<div class="pd-traffic__row">';
$body .= '<span class="pd-traffic__label">IN';
if (isset($itemids['traffic_in'])) {
	$body .= pdGraphBtn([$itemids['traffic_in']], 'Traffic In');
}
$body .= '</span>';
$body .= '<div class="pd-traffic__spark">' . $in_spark . '</div>';
$body .= '<span class="pd-traffic__value">' . htmlspecialchars($in_lbl) . '</span>';
$body .= '</div>';

$body .= '<div class="pd-traffic__row">';
$body .= '<span class="pd-traffic__label">OUT';
if (isset($itemids['traffic_out'])) {
	$body .= pdGraphBtn([$itemids['traffic_out']], 'Traffic Out');
}
$body .= '</span>';
$body .= '<div class="pd-traffic__spark">' . $out_spark . '</div>';
$body .= '<span class="pd-traffic__value">' . htmlspecialchars($out_lbl) . '</span>';
$body .= '</div>';

$body .= '<div class="pd-traffic__row pd-traffic__row--util">';
$body .= '<span class="pd-traffic__label">Utilization';
$combined_traffic = array_filter([$itemids['traffic_in'] ?? null, $itemids['traffic_out'] ?? null]);
if ($combined_traffic) {
	$body .= pdGraphBtn($combined_traffic, 'Traffic (both)');
}
$body .= '</span>';
$body .= '<span class="pd-traffic__value">' . htmlspecialchars($util) . '</span>';
$body .= '</div>';

if ($poe) {
	$body .= '<div class="pd-traffic__row">';
	$body .= '<span class="pd-traffic__label">PoE</span>';
	$body .= '<span class="pd-poe-cell">';
	$body .= '<span class="pd-poe-badge ' . htmlspecialchars($poe['css']) . '">' . htmlspecialchars($poe['label']) . '</span>';

	// "Cycle PoE" button — only shown when:
	//   • a PoE item exists for this port (we're already inside the if($poe))
	//   • the widget is configured to expose it (enable_poe_cycle)
	//   • we have a hostid + snmp_index + iface_name to send to the action
	$can_cycle = !empty($data['enable_poe_cycle'])
		&& !empty($data['hostid'])
		&& !empty($data['snmp_index'])
		&& !empty($data['iface_name']);

	if ($can_cycle) {
		$body .= '<button type="button" class="pd-poe-cycle-btn"'
			. ' data-hostid="'     . (int) $data['hostid']     . '"'
			. ' data-snmp-index="' . (int) $data['snmp_index'] . '"'
			. ' data-iface="'      . htmlspecialchars($data['iface_name'], ENT_QUOTES) . '"'
			. ' title="' . htmlspecialchars(_s('Cycle PoE on %1$s via rConfig', $data['iface_name'])) . '"'
			. ' aria-label="' . htmlspecialchars(_s('Cycle PoE on %1$s', $data['iface_name'])) . '">'
			. '<svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9"/>'
			. '<path d="M13.5 2.5v3h-3"/>'
			. '</svg>'
			. '<span class="pd-poe-cycle-btn__label">' . _('Cycle') . '</span>'
			. '</button>';
		$body .= '<span class="pd-poe-cycle-status" role="status" aria-live="polite"></span>';
	} elseif (!empty($data['enable_poe_cycle']) && empty($data['iface_name'])) {
		// Helpful hint when the cycle button is enabled but we couldn't
		// resolve the interface name — usually a template-naming issue.
		$body .= '<span class="pd-poe-cycle-hint" title="'
			. htmlspecialchars(_('Could not resolve interface name from item names. Cycle disabled.')) . '">⚠</span>';
	}

	$body .= '</span>';
	$body .= '</div>';
}
$body .= '</div>';

$body .= '<div class="pd-stats">';

$body .= '<div class="pd-stat">';
$body .= '<div class="pd-stat__header">';
$body .= '<span class="pd-stat__label">' . $range_label . ' online state';
if (isset($itemids['status'])) {
	$body .= pdGraphBtn([$itemids['status']], 'Oper status');
}
$body .= '</span>';
$body .= '<span class="pd-stat__now">now</span>';
$body .= '</div>';
$body .= $online_bar;
$body .= '</div>';

$body .= '<div class="pd-stat">';
$body .= '<div class="pd-stat__header">';
$body .= '<span class="pd-stat__label">Errors ' . $range_label;
$combined_errors = array_filter([$itemids['errors_in'] ?? null, $itemids['errors_out'] ?? null]);
if ($combined_errors) {
	$body .= pdGraphBtn($combined_errors, 'Errors');
}
$body .= '</span>';
$body .= '<span class="pd-stat__value' . ($err_total > 0 ? ' pd-stat__value--warn' : '') . '">' . htmlspecialchars($err_label . $err_trend) . '</span>';
$body .= '</div>';
$body .= $error_bar;
$body .= '</div>';

$body .= '<div class="pd-stat">';
$body .= '<div class="pd-stat__header">';
$body .= '<span class="pd-stat__label">Discards ' . $range_label;
$combined_discards = array_filter([$itemids['discards_in'] ?? null, $itemids['discards_out'] ?? null]);
if ($combined_discards) {
	$body .= pdGraphBtn($combined_discards, 'Discards');
}
$body .= '</span>';
$body .= '<span class="pd-stat__value">' . htmlspecialchars($dis_label . $dis_trend) . '</span>';
$body .= '</div>';
$body .= $discard_bar;
$body .= '</div>';

$body .= '<div class="pd-speed">';
$body .= '<span class="pd-speed__label">Link Speed</span>';
$body .= '<span class="pd-speed__value">' . htmlspecialchars($speed_lbl) . '</span>';
$body .= '</div>';

$body .= '</div>'; // .pd-stats
$body .= '</div>'; // .pd-main
$body .= '</div>'; // .pd-widget

// Append debug panel at the bottom
$body .= $debug_html;

(new CWidgetView($data))->addItem($body)->show();
