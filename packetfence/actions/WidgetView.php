<?php declare(strict_types = 0);

namespace Modules\PacketFence\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

/**
 * Switch Port Device (PacketFence) widget controller.
 *
 * Talks to the PacketFence v1 REST API on the configured server to retrieve
 * device details for a port selected in the Switch Port Status widget.
 *
 * Data flow per refresh:
 *   1. Receive override_hostid (from broadcast) + sw_snmpIndex (from JS)
 *   2. Look up switch management IP from Zabbix host interfaces
 *   3. Convert snmpIndex → port-number via port_modulus
 *   4. Authenticate to PacketFence (POST /api/v1/login)
 *   5. Search locationlogs for switch=ip & port=port_num, end_time=null
 *   6. For each open log, fetch full node details
 *   7. Assemble device cards for the view
 */
class WidgetView extends CControllerDashboardWidgetView {

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'sw_snmpIndex' => 'int32',
			'sw_hostid'    => 'db hosts.hostid',
			'name'         => 'string',
		]);
	}

	protected function doAction(): void {
		$debug = [
			'step_0_init' => [
				'has_sw_snmpIndex' => $this->hasInput('sw_snmpIndex'),
				'has_sw_hostid'    => $this->hasInput('sw_hostid'),
				'override_hostid'  => $this->fields_values['override_hostid'] ?? null,
			],
		];

		$show_debug = (bool) ($this->fields_values['show_debug'] ?? false);

		// ── STEP 1: Resolve target hostid ────────────────────────────────────
		$hostid = 0;
		$override = $this->fields_values['override_hostid'] ?? null;
		if (is_array($override) && $override) {
			$hostid = (int) reset($override);
		} elseif ($this->hasInput('sw_hostid')) {
			$hostid = (int) $this->getInput('sw_hostid');
		}
		$debug['step_1_hostid'] = $hostid;

		// ── STEP 2: Resolve SNMP index ──────────────────────────────────────
		$snmpIndex = $this->hasInput('sw_snmpIndex') ? (int) $this->getInput('sw_snmpIndex') : 0;
		$debug['step_2_snmpindex'] = $snmpIndex;

		if ($hostid === 0 || $snmpIndex === 0) {
			$this->setResponse(new CControllerResponseData([
				'name'       => $this->getInput('name', $this->widget->getDefaultName()),
				'waiting'    => true,
				'devices'    => [],
				'debug_info' => $debug,
				'show_debug' => $show_debug,
				'user'       => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}

		// ── STEP 3: Look up switch management IP from Zabbix ────────────────
		$switch_ip = null;
		$switch_name = '';
		$interfaces = API::HostInterface()->get([
			'output'  => ['ip', 'useip', 'dns', 'main', 'type'],
			'hostids' => [$hostid],
		]);
		foreach ($interfaces as $iface) {
			if ((int) $iface['main'] === 1) {
				$switch_ip = (int) $iface['useip'] === 1 ? $iface['ip'] : $iface['dns'];
				if ((int) $iface['type'] === 2) break;  // prefer SNMP
			}
		}
		$debug['step_3_switch_ip'] = $switch_ip;

		$hosts = API::Host()->get(['output' => ['host', 'name'], 'hostids' => [$hostid]]);
		if ($hosts) {
			$switch_name = $hosts[0]['name'] ?: $hosts[0]['host'];
		}

		// ── STEP 4: Convert snmpIndex → port number ─────────────────────────
		$modulus = max(1, (int) ($this->fields_values['port_modulus'] ?? 1000));
		$port_number = $modulus > 1 ? ($snmpIndex % $modulus) : $snmpIndex;
		$stack_member = $modulus > 1 ? (int) floor($snmpIndex / $modulus) : 0;
		$debug['step_4_port'] = [
			'snmp_index'   => $snmpIndex,
			'modulus'      => $modulus,
			'stack_member' => $stack_member,
			'port_number'  => $port_number,
		];

		if (!$switch_ip) {
			$this->setResponse(new CControllerResponseData([
				'name'        => $this->getInput('name', $this->widget->getDefaultName()),
				'waiting'     => false,
				'error'       => _('Switch management IP not found on Zabbix host interfaces'),
				'switch_ip'   => null,
				'switch_name' => $switch_name,
				'port_number' => $port_number,
				'snmp_index'  => $snmpIndex,
				'devices'     => [],
				'debug_info'  => $debug,
				'show_debug'  => $show_debug,
				'user'        => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}

		// ── STEP 5: PacketFence connection settings ─────────────────────────
		$pf_url      = rtrim((string) ($this->fields_values['pf_url']      ?? ''), '/');
		$pf_user     = (string) ($this->fields_values['pf_username'] ?? '');
		$pf_pass     = (string) ($this->fields_values['pf_password'] ?? '');
		$verify_ssl  = (bool)   ($this->fields_values['verify_ssl']  ?? false);

		if (!$pf_url || !$pf_user || !$pf_pass) {
			$this->setResponse(new CControllerResponseData([
				'name'        => $this->getInput('name', $this->widget->getDefaultName()),
				'waiting'     => false,
				'error'       => _('PacketFence URL, username, and password must be configured'),
				'switch_ip'   => $switch_ip,
				'switch_name' => $switch_name,
				'port_number' => $port_number,
				'snmp_index'  => $snmpIndex,
				'devices'     => [],
				'debug_info'  => $debug,
				'show_debug'  => $show_debug,
				'user'        => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}

		// ── STEP 6: Authenticate to PacketFence ─────────────────────────────
		$token_result = self::pfLogin($pf_url, $pf_user, $pf_pass, $verify_ssl);
		$debug['step_6_auth'] = [
			'url'      => $pf_url . '/api/v1/login',
			'success'  => $token_result['ok'],
			'http'     => $token_result['http_code'] ?? null,
			'error'    => $token_result['error']     ?? null,
		];

		if (!$token_result['ok']) {
			$this->setResponse(new CControllerResponseData([
				'name'        => $this->getInput('name', $this->widget->getDefaultName()),
				'waiting'     => false,
				'error'       => sprintf(_('PacketFence login failed: %s'), $token_result['error'] ?? 'unknown'),
				'switch_ip'   => $switch_ip,
				'switch_name' => $switch_name,
				'port_number' => $port_number,
				'snmp_index'  => $snmpIndex,
				'devices'     => [],
				'debug_info'  => $debug,
				'show_debug'  => $show_debug,
				'user'        => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}
		$token = $token_result['token'];

		// ── STEP 7: Query open locationlogs for this port ──────────────────
		// Use the search API — POST /api/v1/locationlogs/search with a filter
		$search_body = [
			'query' => [
				'op'     => 'and',
				'values' => [
					['op' => 'equals', 'field' => 'switch',    'value' => $switch_ip],
					['op' => 'equals', 'field' => 'port',      'value' => (string) $port_number],
					['op' => 'equals', 'field' => 'end_time',  'value' => '0000-00-00 00:00:00'],
				],
			],
			'fields' => ['mac', 'switch', 'port', 'vlan', 'role', 'ssid', 'connection_type',
				'connection_sub_type', 'dot1x_username', 'realm', 'start_time', 'end_time'],
			'limit' => 10,
		];
		$loc_result = self::pfRequest(
			$pf_url . '/api/v1/locationlogs/search',
			'POST',
			$token,
			$search_body,
			$verify_ssl
		);
		$debug['step_7_locationlogs'] = [
			'http'   => $loc_result['http_code'] ?? null,
			'error'  => $loc_result['error']     ?? null,
			'count'  => is_array($loc_result['data']['items'] ?? null) ? count($loc_result['data']['items']) : 0,
		];

		$location_entries = [];
		if ($loc_result['ok'] && isset($loc_result['data']['items']) && is_array($loc_result['data']['items'])) {
			$location_entries = $loc_result['data']['items'];
		}

		// ── STEP 8: For each open log, fetch full node detail ──────────────
		$devices = [];
		foreach ($location_entries as $loc) {
			$mac = $loc['mac'] ?? '';
			if (!$mac) continue;

			$node_result = self::pfRequest(
				$pf_url . '/api/v1/node/' . rawurlencode($mac),
				'GET',
				$token,
				null,
				$verify_ssl
			);

			$node = $node_result['ok'] ? ($node_result['data']['item'] ?? []) : [];

			// Fetch active security events for this MAC
			$se_result = self::pfRequest(
				$pf_url . '/api/v1/security_events?limit=5&query=' . rawurlencode(json_encode([
					'op' => 'and',
					'values' => [
						['op' => 'equals', 'field' => 'mac',    'value' => $mac],
						['op' => 'equals', 'field' => 'status', 'value' => 'open'],
					],
				])),
				'GET',
				$token,
				null,
				$verify_ssl
			);
			$security_events = [];
			if ($se_result['ok'] && isset($se_result['data']['items']) && is_array($se_result['data']['items'])) {
				$security_events = $se_result['data']['items'];
			}

			$devices[] = [
				'mac'             => $mac,
				'ip'              => $node['last_ip']         ?? ($node['ip4log']['ip'] ?? null),
				'hostname'        => $node['computername']    ?? null,
				'vendor'          => $node['manufacturer']    ?? ($node['device_manufacturer'] ?? null),
				'os'              => $node['device_type']     ?? ($node['device_class'] ?? null),
				'pid'             => $node['pid']             ?? null,  // owner/user
				'category'        => $node['category']        ?? ($node['category_id'] ?? null),
				'status'          => $node['status']          ?? null,
				'online'          => $node['online']          ?? null,
				'last_seen'       => $node['last_seen']       ?? ($node['last_arp'] ?? null),
				'regdate'         => $node['regdate']         ?? null,
				'vlan'            => $loc['vlan']             ?? ($node['locationlog']['vlan'] ?? null),
				'role'            => $loc['role']             ?? ($node['locationlog']['role'] ?? null),
				'connection_type' => $loc['connection_type']  ?? null,
				'dot1x_username'  => $loc['dot1x_username']   ?? null,
				'realm'           => $loc['realm']            ?? null,
				'session_start'   => $loc['start_time']       ?? null,
				'bypass_vlan'     => $node['bypass_vlan']     ?? null,
				'bypass_role'     => $node['bypass_role']     ?? null,
				'notes'           => $node['notes']           ?? null,
				'security_events' => $security_events,
				'_raw_node'       => $show_debug ? $node : null,
				'_raw_loc'        => $show_debug ? $loc  : null,
			];
		}
		$debug['step_8_devices'] = count($devices);

		$this->setResponse(new CControllerResponseData([
			'name'         => $this->getInput('name', $this->widget->getDefaultName()),
			'waiting'      => false,
			'switch_ip'    => $switch_ip,
			'switch_name'  => $switch_name,
			'port_number'  => $port_number,
			'stack_member' => $stack_member,
			'snmp_index'   => $snmpIndex,
			'devices'      => $devices,
			'pf_url'       => $pf_url,
			'debug_info'   => $debug,
			'show_debug'   => $show_debug,
			'user'         => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	// ── PacketFence HTTP helpers ─────────────────────────────────────────────

	/**
	 * Authenticate to PacketFence and return a token.
	 * Returns: ['ok' => bool, 'token' => string|null, 'http_code' => int, 'error' => string|null]
	 */
	private static function pfLogin(string $base_url, string $user, string $pass, bool $verify_ssl): array {
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $base_url . '/api/v1/login',
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => json_encode(['username' => $user, 'password' => $pass]),
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Accept: application/json',
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_SSL_VERIFYPEER => $verify_ssl,
			CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
		]);
		$body      = curl_exec($ch);
		$http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err_curl  = curl_error($ch);
		curl_close($ch);

		if ($err_curl) {
			return ['ok' => false, 'token' => null, 'http_code' => 0, 'error' => $err_curl];
		}
		if ($http_code !== 200) {
			return ['ok' => false, 'token' => null, 'http_code' => $http_code,
				'error' => sprintf('HTTP %d', $http_code)];
		}
		$data = json_decode((string) $body, true);
		if (!is_array($data) || empty($data['token'])) {
			return ['ok' => false, 'token' => null, 'http_code' => $http_code,
				'error' => 'No token in response'];
		}
		return ['ok' => true, 'token' => (string) $data['token'], 'http_code' => $http_code, 'error' => null];
	}

	/**
	 * Make an authenticated request to PacketFence.
	 * Returns: ['ok' => bool, 'data' => array|null, 'http_code' => int, 'error' => string|null]
	 */
	private static function pfRequest(string $url, string $method, string $token, $body, bool $verify_ssl): array {
		$ch = curl_init();
		$headers = [
			'Authorization: ' . $token,
			'Accept: application/json',
		];
		$opts = [
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_SSL_VERIFYPEER => $verify_ssl,
			CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
		];
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = json_encode($body);
			$headers[] = 'Content-Type: application/json';
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);

		$resp      = curl_exec($ch);
		$http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err_curl  = curl_error($ch);
		curl_close($ch);

		if ($err_curl) {
			return ['ok' => false, 'data' => null, 'http_code' => 0, 'error' => $err_curl];
		}
		if ($http_code < 200 || $http_code >= 300) {
			return ['ok' => false, 'data' => null, 'http_code' => $http_code,
				'error' => sprintf('HTTP %d: %s', $http_code, substr((string) $resp, 0, 200))];
		}
		$data = json_decode((string) $resp, true);
		return ['ok' => true, 'data' => is_array($data) ? $data : [], 'http_code' => $http_code, 'error' => null];
	}
}
