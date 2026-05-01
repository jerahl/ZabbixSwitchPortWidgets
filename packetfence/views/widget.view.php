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
function pfRenderDevice(array $dev, string $pf_admin_url): string {
	$mac       = htmlspecialchars($dev['mac']      ?? '—');
	$ip        = htmlspecialchars((string) ($dev['ip']       ?? ''));
	$ip_src    = (string) ($dev['ip_source'] ?? '');
	$pf_host   = htmlspecialchars((string) ($dev['hostname'] ?? ''));
	$dhcp_host = htmlspecialchars((string) ($dev['dhcp_hostname'] ?? ''));
	$vendor    = htmlspecialchars((string) ($dev['vendor']   ?? ''));
	$os        = htmlspecialchars((string) ($dev['os']       ?? ''));
	$pid       = htmlspecialchars((string) ($dev['pid']      ?? ''));
	$status    = htmlspecialchars((string) ($dev['status']   ?? ''));
	$bvlan     = htmlspecialchars((string) ($dev['bypass_vlan'] ?? ''));
	$ua        = htmlspecialchars((string) ($dev['user_agent']  ?? ''));
	$dhcp_fp   = htmlspecialchars((string) ($dev['dhcp_fingerprint'] ?? ''));
	$last_seen = htmlspecialchars((string) ($dev['last_seen'] ?? ''));
	$scope     = htmlspecialchars((string) ($dev['dhcp_scope'] ?? ''));
	$not_in_pf = !empty($dev['not_in_pf']);
	$events    = $dev['security_events'] ?? [];
	$loc       = $dev['location'] ?? null;

	// Prefer PF hostname when present, fall back to DHCP hostname
	$hostname = $pf_host !== '' ? $pf_host : $dhcp_host;

	$status_css = match (strtolower($status)) {
		'reg', 'registered'     => 'pf-status--ok',
		'unreg', 'unregistered' => 'pf-status--warn',
		'pending'               => 'pf-status--pending',
		default                 => 'pf-status--unknown',
	};
	$status_label = $status !== '' ? ucfirst($status) : 'Unknown';

	$out  = '<div class="pf-card' . ($not_in_pf ? ' pf-card--unknown' : '') . '">';

	// Header: MAC + status pills
	$out .= '<div class="pf-card__header">';
	$out .= '<span class="pf-card__mac">' . $mac . '</span>';
	$out .= '<span class="pf-card__pills">';
	if ($not_in_pf) {
		$out .= '<span class="pf-status pf-status--unknown">Not in PacketFence</span>';
	} else {
		$out .= '<span class="pf-status ' . $status_css . '">' . $status_label . '</span>';
	}
	$out .= '</span>';
	$out .= '</div>';

	// Details grid
	$out .= '<div class="pf-card__details">';
	if ($ip) {
		// Suffix a small label indicating where the IP came from (PF vs DHCP)
		$ip_html = $ip;
		if ($ip_src === 'dhcp') {
			$ip_html .= ' <span class="pf-ip-src pf-ip-src--dhcp" title="IP from Windows DHCP lease">DHCP</span>';
		} elseif ($ip_src === 'pf') {
			$ip_html .= ' <span class="pf-ip-src pf-ip-src--pf" title="IP from PacketFence ip4log">PF</span>';
		}
		$out .= pfDetailRow('IP', $ip_html, 'pf-mono');
	}
	if ($hostname)  $out .= pfDetailRow('Hostname',     $hostname);
	if ($vendor)    $out .= pfDetailRow('Vendor',       $vendor);
	if ($os)        $out .= pfDetailRow('OS',           $os);
	if ($pid && $pid !== 'default') $out .= pfDetailRow('Owner', $pid);
	if ($bvlan)     $out .= pfDetailRow('Bypass VLAN',  $bvlan);
	if ($scope && $ip_src === 'dhcp') $out .= pfDetailRow('DHCP Scope', $scope, 'pf-mono');
	if ($dhcp_fp)   $out .= pfDetailRow('DHCP FP',      $dhcp_fp, 'pf-mono');
	if ($last_seen) $out .= pfDetailRow('Last seen',    $last_seen);
	if ($ua && strlen($ua) < 80) $out .= pfDetailRow('User-Agent', $ua);
	$out .= '</div>';

	// Location (switch / port / role) from PacketFence locationlogs
	if (is_array($loc)) {
		$sw       = htmlspecialchars((string) ($loc['switch']          ?? ''));
		$sw_ip    = htmlspecialchars((string) ($loc['switch_ip']       ?? ''));
		$port     = htmlspecialchars((string) ($loc['port']            ?? ''));
		$ifdesc   = htmlspecialchars((string) ($loc['ifDesc']          ?? ''));
		$vlan     = htmlspecialchars((string) ($loc['vlan']            ?? ''));
		$role     = htmlspecialchars((string) ($loc['role']            ?? ''));
		$ssid     = htmlspecialchars((string) ($loc['ssid']            ?? ''));
		$ctype    = htmlspecialchars((string) ($loc['connection_type'] ?? ''));
		$dot1x    = htmlspecialchars((string) ($loc['dot1x_username']  ?? ''));
		$start    = htmlspecialchars((string) ($loc['start_time']      ?? ''));

		$has_any = $sw !== '' || $port !== '' || $vlan !== '' || $role !== ''
			|| $ssid !== '' || $ctype !== '' || $dot1x !== '';
		if ($has_any) {
			$out .= '<div class="pf-card__details pf-card__details--location">';
			if ($sw !== '') {
				$sw_html = $sw;
				if ($sw_ip !== '') $sw_html .= ' <span class="pf-mono">(' . $sw_ip . ')</span>';
				$out .= pfDetailRow('Switch', $sw_html);
			}
			if ($port !== '') {
				$port_html = $port;
				if ($ifdesc !== '' && $ifdesc !== $port) $port_html .= ' <span class="pf-mono">' . $ifdesc . '</span>';
				$out .= pfDetailRow('Port', $port_html);
			}
			if ($vlan !== '')   $out .= pfDetailRow('VLAN',       $vlan);
			if ($role !== '')   $out .= pfDetailRow('Role',       $role);
			if ($ssid !== '')   $out .= pfDetailRow('SSID',       $ssid);
			if ($ctype !== '')  $out .= pfDetailRow('Connection', $ctype);
			if ($dot1x !== '')  $out .= pfDetailRow('802.1X User', $dot1x);
			if ($start !== '')  $out .= pfDetailRow('Since',      $start);
			$out .= '</div>';
		}
	}

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
	// PF admin UI uses SPA hash routing: /admin/#/node<encoded-mac>
	// The MAC is appended directly after "node" with no separator, colons URL-encoded.
	$out .= '<div class="pf-card__actions">';
	if ($pf_admin_url !== '' && !$not_in_pf) {
		$node_url = $pf_admin_url . '/admin/#/node' . rawurlencode($dev['mac']);
		$out .= '<a class="pf-action" href="' . htmlspecialchars($node_url) . '" target="_blank" rel="noopener">'
			. 'View in PacketFence</a>';
	}
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
	if (!empty($data['snmp_index'])) {
		$body .= '<span class="pf-header__port">ifIndex ' . (int) $data['snmp_index'] . '</span>';
	}
	$body .= '</div>';
	$body .= '<div class="pf-error">' . htmlspecialchars((string) $data['error']) . '</div>';
} else {
	// Header
	$body .= '<div class="pf-header">';
	$body .= '<span class="pf-header__title">' . htmlspecialchars((string) ($data['switch_name'] ?? '')) . '</span>';
	$body .= '<span class="pf-header__port">ifIndex ' . (int) ($data['snmp_index'] ?? 0) . '</span>';
	if (!empty($data['macs'])) {
		$body .= '<span class="pf-header__count">' . count($data['macs']) . ' MAC'
			. (count($data['macs']) === 1 ? '' : 's') . '</span>';
	}
	if (isset($data['mac_item_age']) && $data['mac_item_age'] !== null) {
		$age = (int) $data['mac_item_age'];
		$age_label = $age < 60 ? $age . 's' : ($age < 3600 ? round($age / 60) . 'm' : round($age / 3600) . 'h');
		$body .= '<span class="pf-header__age" title="MAC list last updated">' . $age_label . ' old</span>';
	}
	$body .= '</div>';

	// Device list
	$devices = $data['devices'] ?? [];
	if ($devices) {
		$body .= '<div class="pf-cards">';
		foreach ($devices as $dev) {
			$body .= pfRenderDevice($dev, (string) ($data['pf_admin_url'] ?? ''));
		}
		$body .= '</div>';
	} else {
		$body .= '<div class="pf-empty">';
		$body .= '<div class="pf-empty__icon">∅</div>';
		$body .= '<div class="pf-empty__msg">No devices on this port</div>';
		$body .= '<div class="pf-empty__hint">No MACs learned on this port in the switch FDB</div>';
		$body .= '</div>';
	}
}

// Debug panel
if (!empty($data['show_debug']) && !empty($data['debug_info'])) {
	$body .= pfRenderDebug($data['debug_info']);
}

$body .= '</div>';  // .pf-widget

(new CWidgetView($data))->addItem($body)->show();
