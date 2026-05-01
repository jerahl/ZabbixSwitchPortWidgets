<?php
/**
 * Camera Device (PacketFence) widget – view.
 *
 * @var CView  $this
 * @var array  $data
 */

// ── Debug panel ──────────────────────────────────────────────────────────────
function camPfDebugRow(string $k, string $v): string {
	return '<tr><th>' . htmlspecialchars($k) . '</th><td>' . htmlspecialchars($v) . '</td></tr>';
}

function camPfRenderDebug(array $info): string {
	$out  = '<details class="pf-dbg"><summary>Debug Info</summary><div class="pf-dbg-body">';
	$out .= '<table class="pf-dbg-tbl">';
	foreach ($info as $k => $v) {
		if (is_array($v) || is_object($v)) {
			$v = json_encode($v, JSON_UNESCAPED_SLASHES);
		}
		$out .= camPfDebugRow((string) $k, (string) $v);
	}
	$out .= '</table></div></details>';
	return $out;
}

function camPfDetailRow(string $label, string $value, string $extra_cls = ''): string {
	return '<div class="pf-detail">'
		. '<span class="pf-detail__label">' . htmlspecialchars($label) . '</span>'
		. '<span class="pf-detail__value ' . $extra_cls . '">' . $value . '</span>'
		. '</div>';
}

// ── Device card ──────────────────────────────────────────────────────────────
function camPfRenderDevice(array $dev, string $pf_admin_url): string {
	$mac       = htmlspecialchars((string) ($dev['mac']      ?? '—'));
	$ip        = htmlspecialchars((string) ($dev['ip']       ?? ''));
	$ip_src    = (string) ($dev['ip_source'] ?? '');
	$pf_host   = htmlspecialchars((string) ($dev['hostname'] ?? ''));
	$vendor    = htmlspecialchars((string) ($dev['vendor']   ?? ''));
	$os        = htmlspecialchars((string) ($dev['os']       ?? ''));
	$pid       = htmlspecialchars((string) ($dev['pid']      ?? ''));
	$status    = htmlspecialchars((string) ($dev['status']   ?? ''));
	$bvlan     = htmlspecialchars((string) ($dev['bypass_vlan'] ?? ''));
	$ua        = htmlspecialchars((string) ($dev['user_agent']  ?? ''));
	$dhcp_fp   = htmlspecialchars((string) ($dev['dhcp_fingerprint'] ?? ''));
	$last_seen = htmlspecialchars((string) ($dev['last_seen'] ?? ''));
	$last_arp  = htmlspecialchars((string) ($dev['last_arp']  ?? ''));
	$last_dhcp = htmlspecialchars((string) ($dev['last_dhcp'] ?? ''));
	$not_in_pf = !empty($dev['not_in_pf']);
	$events    = $dev['security_events'] ?? [];
	$loc       = $dev['location'] ?? null;

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
		$ip_html = $ip;
		if ($ip_src === 'camera') {
			$ip_html .= ' <span class="pf-ip-src pf-ip-src--dhcp" title="IP from camera item">CAM</span>';
		} elseif ($ip_src === 'pf') {
			$ip_html .= ' <span class="pf-ip-src pf-ip-src--pf" title="IP from PacketFence ip4log">PF</span>';
		}
		$out .= camPfDetailRow('IP', $ip_html, 'pf-mono');
	}
	if ($pf_host)   $out .= camPfDetailRow('Hostname',     $pf_host);
	if ($vendor)    $out .= camPfDetailRow('Vendor',       $vendor);
	if ($os)        $out .= camPfDetailRow('OS',           $os);
	if ($pid && $pid !== 'default') $out .= camPfDetailRow('Owner', $pid);
	if ($bvlan)     $out .= camPfDetailRow('Bypass VLAN',  $bvlan);
	if ($dhcp_fp)   $out .= camPfDetailRow('DHCP FP',      $dhcp_fp, 'pf-mono');
	if ($last_seen) $out .= camPfDetailRow('Last seen',    $last_seen);
	if ($last_arp)  $out .= camPfDetailRow('Last ARP',     $last_arp);
	if ($last_dhcp) $out .= camPfDetailRow('Last DHCP',    $last_dhcp);
	if ($ua && strlen($ua) < 80) $out .= camPfDetailRow('User-Agent', $ua);
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
				$out .= camPfDetailRow('Switch', $sw_html);
			}
			if ($port !== '') {
				$port_html = $port;
				if ($ifdesc !== '' && $ifdesc !== $port) $port_html .= ' <span class="pf-mono">' . $ifdesc . '</span>';
				$out .= camPfDetailRow('Port', $port_html);
			}
			if ($vlan !== '')   $out .= camPfDetailRow('VLAN',       $vlan);
			if ($role !== '')   $out .= camPfDetailRow('Role',       $role);
			if ($ssid !== '')   $out .= camPfDetailRow('SSID',       $ssid);
			if ($ctype !== '')  $out .= camPfDetailRow('Connection', $ctype);
			if ($dot1x !== '')  $out .= camPfDetailRow('802.1X User', $dot1x);
			if ($start !== '')  $out .= camPfDetailRow('Since',      $start);
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
	$out .= '<div class="pf-card__actions">';
	if ($pf_admin_url !== '' && !$not_in_pf) {
		$node_url = $pf_admin_url . '/admin/#/node/' . rawurlencode((string) ($dev['mac'] ?? ''));
		$out .= '<a class="pf-action" href="' . htmlspecialchars($node_url) . '" target="_blank" rel="noopener">'
			. 'View in PacketFence</a>';
	}
	$out .= '</div>';

	$out .= '</div>';
	return $out;
}

// ── Main render ──────────────────────────────────────────────────────────────
$body = '<div class="pf-widget">';

if (!empty($data['waiting'])) {
	$body .= '<div class="pf-empty">'
		. '<div class="pf-empty__icon">📷</div>'
		. '<div class="pf-empty__msg">Awaiting camera selection</div>'
		. '<div class="pf-empty__hint">Click a camera in the Milestone Camera Status widget</div>'
		. '</div>';
} elseif (!empty($data['error'])) {
	$body .= '<div class="pf-header">';
	$cam_label = (string) ($data['cam_name'] ?? '');
	if ($cam_label === '' && !empty($data['cam_mac'])) {
		$cam_label = (string) $data['cam_mac'];
	}
	$body .= '<span class="pf-header__title">' . htmlspecialchars($cam_label) . '</span>';
	if (!empty($data['cam_ip'])) {
		$body .= '<span class="pf-header__port">' . htmlspecialchars((string) $data['cam_ip']) . '</span>';
	}
	$body .= '</div>';
	$body .= '<div class="pf-error">' . htmlspecialchars((string) $data['error']) . '</div>';
} else {
	// Header
	$body .= '<div class="pf-header">';
	$cam_label = (string) ($data['cam_name'] ?? '');
	if ($cam_label === '' && !empty($data['cam_mac'])) {
		$cam_label = (string) $data['cam_mac'];
	}
	$body .= '<span class="pf-header__title">' . htmlspecialchars($cam_label) . '</span>';
	if (!empty($data['cam_host'])) {
		$body .= '<span class="pf-header__port">on ' . htmlspecialchars((string) $data['cam_host']) . '</span>';
	}
	$body .= '</div>';

	// Single device card
	if (!empty($data['device'])) {
		$body .= '<div class="pf-cards">';
		$body .= camPfRenderDevice($data['device'], (string) ($data['pf_admin_url'] ?? ''));
		$body .= '</div>';
	} else {
		$body .= '<div class="pf-empty">';
		$body .= '<div class="pf-empty__icon">∅</div>';
		$body .= '<div class="pf-empty__msg">No PacketFence record</div>';
		$body .= '<div class="pf-empty__hint">PF returned no node for this MAC/IP</div>';
		$body .= '</div>';
	}
}

// Debug panel
if (!empty($data['show_debug']) && !empty($data['debug_info'])) {
	$body .= camPfRenderDebug($data['debug_info']);
}

$body .= '</div>';  // .pf-widget

(new CWidgetView($data))->addItem($body)->show();
