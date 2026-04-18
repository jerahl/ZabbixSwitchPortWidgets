<?php
/**
 * PacketFence Device Lookup widget – view.
 *
 * @var CView  $this
 * @var array  $data
 */

// ── Debug panel ──────────────────────────────────────────────────────────────
function pfDebugRow(string $k, string $v): string {
	return '<tr><th>' . htmlspecialchars($k) . '</th><td>' . htmlspecialchars($v) . '</td></tr>';
}

function pfRenderDebug(array $info): string {
	$out  = '<details class="pf-dbg"><summary>Debug Info</summary><div class="pf-dbg-body">';
	$out .= '<table class="pf-dbg-tbl">';
	foreach ($info as $k => $v) {
		if (is_array($v) || is_object($v)) {
			$v = json_encode($v, JSON_UNESCAPED_SLASHES);
		}
		$out .= pfDebugRow((string) $k, (string) $v);
	}
	$out .= '</table></div></details>';
	return $out;
}

// ── Individual device card ───────────────────────────────────────────────────
function pfRenderDevice(array $dev, string $pf_url): string {
	$mac      = htmlspecialchars($dev['mac']      ?? '—');
	$ip       = htmlspecialchars((string) ($dev['ip']       ?? ''));
	$hostname = htmlspecialchars((string) ($dev['hostname'] ?? ''));
	$vendor   = htmlspecialchars((string) ($dev['vendor']   ?? ''));
	$os       = htmlspecialchars((string) ($dev['os']       ?? ''));
	$pid      = htmlspecialchars((string) ($dev['pid']      ?? ''));
	$vlan     = htmlspecialchars((string) ($dev['vlan']     ?? ''));
	$role     = htmlspecialchars((string) ($dev['role']     ?? ''));
	$status   = htmlspecialchars((string) ($dev['status']   ?? ''));
	$user     = htmlspecialchars((string) ($dev['dot1x_username'] ?? ''));
	$conn     = htmlspecialchars((string) ($dev['connection_type'] ?? ''));
	$online   = $dev['online'] ?? null;
	$events   = $dev['security_events'] ?? [];

	$status_css = match (strtolower($status)) {
		'reg', 'registered' => 'pf-status--ok',
		'unreg', 'unregistered' => 'pf-status--warn',
		'pending'      => 'pf-status--pending',
		default        => 'pf-status--unknown',
	};
	$status_label = $status !== '' ? ucfirst($status) : 'Unknown';

	$online_css = match (strtolower((string) $online)) {
		'on',  'online'  => 'pf-online--on',
		'off', 'offline' => 'pf-online--off',
		default          => 'pf-online--unknown',
	};
	$online_label = $online ? ucfirst((string) $online) : '';

	$out  = '<div class="pf-card">';

	// Header: MAC + status pills
	$out .= '<div class="pf-card__header">';
	$out .= '<span class="pf-card__mac">' . $mac . '</span>';
	$out .= '<span class="pf-card__pills">';
	$out .= '<span class="pf-status ' . $status_css . '">' . $status_label . '</span>';
	if ($online_label) {
		$out .= '<span class="pf-online ' . $online_css . '">' . $online_label . '</span>';
	}
	$out .= '</span>';
	$out .= '</div>';

	// Details grid
	$out .= '<div class="pf-card__details">';
	if ($ip)       $out .= pfDetailRow('IP',       $ip,       'pf-mono');
	if ($hostname) $out .= pfDetailRow('Hostname', $hostname);
	if ($vendor)   $out .= pfDetailRow('Vendor',   $vendor);
	if ($os)       $out .= pfDetailRow('OS',       $os);
	if ($user)     $out .= pfDetailRow('User',     $user);
	elseif ($pid && $pid !== 'default') $out .= pfDetailRow('Owner', $pid);
	if ($vlan)     $out .= pfDetailRow('VLAN',     $vlan);
	if ($role)     $out .= pfDetailRow('Role',     $role);
	if ($conn)     $out .= pfDetailRow('Conn',     $conn);
	if (!empty($dev['session_start'])) {
		$out .= pfDetailRow('Since', htmlspecialchars((string) $dev['session_start']));
	}
	$out .= '</div>';

	// Security events
	if ($events) {
		$out .= '<div class="pf-card__events">';
		$out .= '<span class="pf-events-label">⚠ ' . count($events) . ' open security event'
			. (count($events) === 1 ? '' : 's') . '</span>';
		$out .= '<ul class="pf-events-list">';
		foreach ($events as $ev) {
			$desc = htmlspecialchars((string) ($ev['description'] ?? $ev['name'] ?? 'Unknown'));
			$out .= '<li>' . $desc . '</li>';
		}
		$out .= '</ul></div>';
	}

	// Action links
	$out .= '<div class="pf-card__actions">';
	$node_url = rtrim($pf_url, '/') . '/admin/nodes/' . rawurlencode($dev['mac']);
	$out .= '<a class="pf-action" href="' . htmlspecialchars($node_url) . '" target="_blank" rel="noopener">'
		. 'View in PacketFence</a>';
	$out .= '</div>';

	$out .= '</div>';
	return $out;
}

function pfDetailRow(string $label, string $value, string $extra_cls = ''): string {
	return '<div class="pf-detail">'
		. '<span class="pf-detail__label">' . htmlspecialchars($label) . '</span>'
		. '<span class="pf-detail__value ' . $extra_cls . '">' . $value . '</span>'
		. '</div>';
}

// ── Main render ──────────────────────────────────────────────────────────────
$body = '<div class="pf-widget">';

if (!empty($data['waiting'])) {
	$body .= '<div class="pf-empty">'
		. '<div class="pf-empty__icon">🔌</div>'
		. '<div class="pf-empty__msg">Awaiting port selection</div>'
		. '<div class="pf-empty__hint">Click a port in the Switch Port Status widget</div>'
		. '</div>';
} elseif (!empty($data['error'])) {
	$body .= '<div class="pf-header">';
	$body .= '<span class="pf-header__title">' . htmlspecialchars((string) ($data['switch_name'] ?? '')) . '</span>';
	if (!empty($data['port_number'])) {
		$body .= '<span class="pf-header__port">Port ' . (int) $data['port_number'] . '</span>';
	}
	$body .= '</div>';
	$body .= '<div class="pf-error">' . htmlspecialchars((string) $data['error']) . '</div>';
} else {
	// Header
	$body .= '<div class="pf-header">';
	$body .= '<span class="pf-header__title">' . htmlspecialchars((string) ($data['switch_name'] ?? '')) . '</span>';
	$body .= '<span class="pf-header__port">Port ' . (int) $data['port_number'];
	if (!empty($data['stack_member'])) {
		$body .= ' <span class="pf-header__member">(member ' . (int) $data['stack_member'] . ')</span>';
	}
	$body .= '</span>';
	$body .= '<span class="pf-header__ip">' . htmlspecialchars((string) ($data['switch_ip'] ?? '')) . '</span>';
	$body .= '</div>';

	// Device list
	$devices = $data['devices'] ?? [];
	if ($devices) {
		$body .= '<div class="pf-cards">';
		foreach ($devices as $dev) {
			$body .= pfRenderDevice($dev, (string) ($data['pf_url'] ?? ''));
		}
		$body .= '</div>';
	} else {
		$body .= '<div class="pf-empty">';
		$body .= '<div class="pf-empty__icon">∅</div>';
		$body .= '<div class="pf-empty__msg">No devices connected</div>';
		$body .= '<div class="pf-empty__hint">PacketFence has no open location logs for this port</div>';
		$body .= '</div>';
	}
}

// Debug panel
if (!empty($data['show_debug']) && !empty($data['debug_info'])) {
	$body .= pfRenderDebug($data['debug_info']);
}

$body .= '</div>';  // .pf-widget

(new CWidgetView($data))
	->addItem(new CTag('div', true, $body))
	->show();
