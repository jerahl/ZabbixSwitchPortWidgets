<?php declare(strict_types = 0);

namespace Modules\PacketFence\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

/**
 * Switch Port Device (PacketFence) widget controller.
 *
 * Data flow per refresh:
 *   1. Receive override_hostid (from broadcast) + sw_snmpIndex (from JS)
 *   2. Query Zabbix for the port's MAC-list item (e.g. port.mac.list[<snmpIndex>])
 *   3. Parse the comma-separated MAC list
 *   4. Authenticate to PacketFence (POST /api/v1/login)
 *   5. POST /api/v1/nodes/search with an `in` query across the MACs
 *   6. For each node returned, optionally fetch open security events
 *   7. Return device cards
 *
 * The MAC-list item is produced by a template preprocessing step that walks
 * dot1dTpFdbTable and dot1dBasePortIfIndex. See README for template details.
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
		$name       = $this->getInput('name', $this->widget->getDefaultName());

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
			$this->respondWaiting($name, $debug, $show_debug);
			return;
		}

		// Look up friendly host name for the header
		$switch_name = '';
		$hosts = API::Host()->get(['output' => ['host', 'name'], 'hostids' => [$hostid]]);
		if ($hosts) {
			$switch_name = $hosts[0]['name'] ?: $hosts[0]['host'];
		}

		// ── STEP 3: Query Zabbix for MAC-list item ──────────────────────────
		$mac_item_key = (string) ($this->fields_values['mac_item_prefix'] ?? 'port.mac.list[')
			. $snmpIndex . ']';

		$candidate_keys = [
			$mac_item_key,
			'port.mac.list[ifIndex.' . $snmpIndex . ']',
			'net.if.mac[ifIndex.' . $snmpIndex . ']',
		];
		$debug['step_3_candidate_keys'] = $candidate_keys;

		$mac_items = API::Item()->get([
			'output'  => ['itemid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => [$hostid],
			'filter'  => ['key_' => $candidate_keys],
		]);

		$mac_item = null;
		foreach ($mac_items as $item) {
			if ($item['lastvalue'] !== '' && $item['lastvalue'] !== null) {
				$mac_item = $item;
				break;
			}
		}
		if (!$mac_item && $mac_items) {
			$mac_item = reset($mac_items);
		}
		$debug['step_3_mac_item'] = $mac_item
			? ['key' => $mac_item['key_'], 'lastvalue' => $mac_item['lastvalue'],
				'lastclock' => (int) $mac_item['lastclock']]
			: null;

		if (!$mac_item) {
			$this->respondError(
				$name,
				$switch_name,
				$snmpIndex,
				sprintf(_('No MAC-list item found on host. Expected key: %s'), $mac_item_key),
				$debug,
				$show_debug
			);
			return;
		}

		// ── STEP 4: Parse the comma-separated MAC list ──────────────────────
		$mac_list_raw = (string) $mac_item['lastvalue'];
		$macs = [];
		foreach (preg_split('/[,\s]+/', $mac_list_raw, -1, PREG_SPLIT_NO_EMPTY) as $m) {
			$m = strtolower(trim($m));
			if (preg_match('/^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/', $m)) {
				$macs[] = str_replace('-', ':', $m);
			}
		}
		$macs = array_values(array_unique($macs));
		$debug['step_4_macs'] = $macs;

		if (!$macs) {
			$this->setResponse(new CControllerResponseData([
				'name'          => $name,
				'waiting'       => false,
				'switch_name'   => $switch_name,
				'snmp_index'    => $snmpIndex,
				'mac_item_key'  => $mac_item['key_'],
				'mac_item_age'  => $mac_item['lastclock'] ? (time() - (int) $mac_item['lastclock']) : null,
				'devices'       => [],
				'pf_url'        => rtrim((string) ($this->fields_values['pf_url'] ?? ''), '/'),
				'pf_admin_url'  => rtrim((string) ($this->fields_values['pf_admin_url'] ?? ''), '/'),
				'debug_info'    => $debug,
				'show_debug'    => $show_debug,
				'user'          => ['debug_mode' => $this->getDebugMode()],
			]));
			return;
		}

		// ── STEP 5: PacketFence connection settings ─────────────────────────
		$pf_url     = rtrim((string) ($this->fields_values['pf_url']      ?? ''), '/');
		$pf_user    = (string) ($this->fields_values['pf_username'] ?? '');
		$pf_pass    = (string) ($this->fields_values['pf_password'] ?? '');
		$verify_ssl = (bool)   ($this->fields_values['verify_ssl']  ?? false);

		if (!$pf_url || !$pf_user || !$pf_pass) {
			$this->respondError(
				$name, $switch_name, $snmpIndex,
				_('PacketFence URL, username, and password must be configured'),
				$debug, $show_debug, $macs
			);
			return;
		}

		// ── STEP 6: Authenticate to PacketFence ─────────────────────────────
		$token_result = self::pfLogin($pf_url, $pf_user, $pf_pass, $verify_ssl);
		$debug['step_6_auth'] = [
			'url'     => $pf_url . '/api/v1/login',
			'success' => $token_result['ok'],
			'http'    => $token_result['http_code'] ?? null,
			'error'   => $token_result['error']     ?? null,
		];

		if (!$token_result['ok']) {
			$this->respondError(
				$name, $switch_name, $snmpIndex,
				sprintf(_('PacketFence login failed: %s'), $token_result['error'] ?? 'unknown'),
				$debug, $show_debug, $macs
			);
			return;
		}
		$token = $token_result['token'];

		// ── STEP 7: Search PacketFence nodes by MAC list ────────────────────
		$search_body = self::buildNodeSearchBody($macs);
		$debug['step_7_search_body'] = $search_body;

		$nodes_result = self::pfRequest(
			$pf_url . '/api/v1/nodes/search',
			'POST',
			$token,
			$search_body,
			$verify_ssl
		);
		$debug['step_7_nodes'] = [
			'http'  => $nodes_result['http_code'] ?? null,
			'error' => $nodes_result['error']     ?? null,
			'count' => is_array($nodes_result['data']['items'] ?? null)
				? count($nodes_result['data']['items']) : 0,
		];

		$node_items = [];
		if ($nodes_result['ok'] && isset($nodes_result['data']['items'])) {
			foreach ($nodes_result['data']['items'] as $n) {
				$node_items[strtolower($n['mac'] ?? '')] = $n;
			}
		}

		// ── STEP 8: Build device cards, one per MAC ─────────────────────────
		$devices = [];
		foreach ($macs as $mac) {
			$node = $node_items[$mac] ?? null;

			$security_events = [];
			if ($node) {
				$se_result = self::pfRequest(
					$pf_url . '/api/v1/security_events/search',
					'POST',
					$token,
					[
						'cursor' => 0,
						'limit'  => 5,
						'fields' => ['security_event_id', 'mac', 'description',
							'status', 'start_date', 'release_date', 'ip'],
						'query'  => [
							'op'     => 'and',
							'values' => [
								['op' => 'equals', 'field' => 'mac',    'value' => $mac],
								['op' => 'equals', 'field' => 'status', 'value' => 'open'],
							],
						],
					],
					$verify_ssl
				);
				if ($se_result['ok'] && isset($se_result['data']['items'])
						&& is_array($se_result['data']['items'])) {
					// Defensive: drop any events whose MAC doesn't match this card's MAC.
					// Guards against API shape changes that might widen the filter.
					foreach ($se_result['data']['items'] as $ev) {
						$ev_mac = strtolower((string) ($ev['mac'] ?? ''));
						if ($ev_mac === $mac) {
							$security_events[] = $ev;
						}
					}
				}
			}

			$devices[] = self::buildDeviceCard($mac, $node, $security_events, $show_debug);
		}
		$debug['step_8_devices'] = count($devices);

		// ── STEP 9: DHCP fallback — fill in missing IPs from Windows DHCP ──
		$dhcp_host     = trim((string) ($this->fields_values['dhcp_host']     ?? ''));
		$dhcp_item_key = trim((string) ($this->fields_values['dhcp_item_key'] ?? ''));
		$debug['step_9_dhcp'] = [
			'host'            => $dhcp_host,
			'item_key'        => $dhcp_item_key,
			'lookup_attempts' => 0,
			'matches'         => 0,
			'lease_count'     => 0,
			'item_age'        => null,
			'error'           => null,
		];

		// Only query DHCP if at least one device is missing an IP and both
		// config fields are set.
		$needs_dhcp = false;
		foreach ($devices as $d) {
			if (empty($d['ip'])) { $needs_dhcp = true; break; }
		}

		if ($needs_dhcp && $dhcp_host !== '' && $dhcp_item_key !== '') {
			$lease_map = self::fetchDhcpLeases($dhcp_host, $dhcp_item_key, $debug['step_9_dhcp']);

			if ($lease_map !== null) {
				foreach ($devices as &$dev) {
					if (!empty($dev['ip'])) continue;          // already have a PF IP
					$debug['step_9_dhcp']['lookup_attempts']++;
					$mac_lc = strtolower((string) $dev['mac']);
					if (isset($lease_map[$mac_lc])) {
						$lease = $lease_map[$mac_lc];
						$dev['ip']            = $lease['ip'] ?? null;
						$dev['ip_source']     = 'dhcp';
						$dev['dhcp_hostname'] = $lease['hostname'] ?? null;
						$dev['dhcp_expires']  = $lease['expires']  ?? null;
						$dev['dhcp_scope']    = $lease['scope']    ?? null;
						$debug['step_9_dhcp']['matches']++;
					}
				}
				unset($dev);
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name'          => $name,
			'waiting'       => false,
			'switch_name'   => $switch_name,
			'snmp_index'    => $snmpIndex,
			'mac_item_key'  => $mac_item['key_'],
			'mac_item_age'  => $mac_item['lastclock'] ? (time() - (int) $mac_item['lastclock']) : null,
			'macs'          => $macs,
			'devices'       => $devices,
			'pf_url'        => $pf_url,
			'pf_admin_url'  => rtrim((string) ($this->fields_values['pf_admin_url'] ?? ''), '/'),
			'debug_info'    => $debug,
			'show_debug'    => $show_debug,
			'user'          => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Fetch the DHCP lease JSON blob from a Zabbix item on the configured
	 * host, parse it, and return a map of lowercased-MAC → lease row.
	 *
	 * Returns null on any failure; $debug is updated in-place with
	 * lease_count / item_age / error for the debug panel.
	 */
	private static function fetchDhcpLeases(string $hostname, string $item_key, array &$debug): ?array {
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'filter' => ['host' => $hostname],
		]);
		if (!$hosts) {
			// Try by visible name if the technical host name didn't match
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'search' => ['name' => $hostname],
			]);
		}
		if (!$hosts) {
			$debug['error'] = 'DHCP host not found: ' . $hostname;
			return null;
		}

		$items = API::Item()->get([
			'output'  => ['itemid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => [$hosts[0]['hostid']],
			'filter'  => ['key_' => $item_key],
		]);
		if (!$items) {
			$debug['error'] = 'DHCP item not found: ' . $item_key;
			return null;
		}
		$item = $items[0];
		$debug['item_age'] = $item['lastclock'] ? (time() - (int) $item['lastclock']) : null;

		$raw = (string) $item['lastvalue'];
		if ($raw === '') {
			$debug['error'] = 'DHCP item has empty value';
			return null;
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			$debug['error'] = 'DHCP item value is not valid JSON';
			return null;
		}
		$debug['lease_count'] = count($data);

		$map = [];
		foreach ($data as $lease) {
			if (!is_array($lease) || !isset($lease['mac'])) continue;
			// Normalize MAC: lowercase, colon-separated
			$mac = strtolower(str_replace('-', ':', (string) $lease['mac']));
			if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) continue;
			$map[$mac] = $lease;
		}
		return $map;
	}

	private function respondWaiting(string $name, array $debug, bool $show_debug): void {
		$this->setResponse(new CControllerResponseData([
			'name'       => $name,
			'waiting'    => true,
			'devices'    => [],
			'debug_info' => $debug,
			'show_debug' => $show_debug,
			'user'       => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	private function respondError(
		string $name, string $switch_name, int $snmpIndex, string $error,
		array $debug, bool $show_debug, array $macs = []
	): void {
		$this->setResponse(new CControllerResponseData([
			'name'        => $name,
			'waiting'     => false,
			'error'       => $error,
			'switch_name' => $switch_name,
			'snmp_index'  => $snmpIndex,
			'macs'        => $macs,
			'devices'     => [],
			'debug_info'  => $debug,
			'show_debug'  => $show_debug,
			'user'        => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	/**
	 * Build the nodes/search payload.
	 * Uses OR of equals(mac) since the 'in' op support varies across PF versions.
	 * Returns fields from the node table relevant to a dashboard card.
	 */
	private static function buildNodeSearchBody(array $macs): array {
		$or_values = [];
		foreach ($macs as $m) {
			$or_values[] = ['op' => 'equals', 'field' => 'mac', 'value' => $m];
		}

		return [
			'cursor' => 0,
			'limit'  => 25,
			'sort'   => ['mac ASC'],
			'fields' => [
				'autoreg', 'bandwidth_balance', 'bypass_acls', 'bypass_role_id',
				'bypass_vlan', 'category_id', 'computername', 'detect_date',
				'device_class', 'device_manufacturer', 'device_score', 'device_type',
				'device_version', 'dhcp6_enterprise', 'dhcp6_fingerprint',
				'dhcp_fingerprint', 'dhcp_vendor', 'last_arp', 'last_dhcp',
				'last_seen', 'mac', 'machine_account', 'notes', 'pid', 'regdate',
				'sessionid', 'status', 'time_balance', 'unregdate', 'user_agent',
				'voip',
				// Joined fields — pull the current IPs from ip4log / ip6log
				'ip4log.ip', 'ip6log.ip',
			],
			'query' => [
				'op'     => 'and',
				'values' => [
					['op' => 'or', 'values' => $or_values],
				],
			],
		];
	}

	private static function buildDeviceCard(string $mac, ?array $node, array $events, bool $show_debug): array {
		if ($node === null) {
			return [
				'mac'             => $mac,
				'ip'              => null,
				'ip_source'       => null,
				'ip6'             => null,
				'last_arp'        => null,
				'last_dhcp'       => null,
				'hostname'        => null,
				'vendor'          => null,
				'os'              => null,
				'pid'             => null,
				'status'          => 'unknown',
				'not_in_pf'       => true,
				'security_events' => [],
			];
		}

		return [
			'mac'              => $node['mac'] ?? $mac,
			'ip'               => $node['ip4log.ip']      ?? null,
			'ip_source'        => ($node['ip4log.ip'] ?? null) ? 'pf' : null,
			'ip6'              => $node['ip6log.ip']      ?? null,
			'last_arp'         => $node['last_arp']       ?? null,
			'last_dhcp'        => $node['last_dhcp']      ?? null,
			'hostname'         => $node['computername']   ?? null,
			'vendor'           => $node['device_manufacturer'] ?? null,
			'os'               => $node['device_type']    ?? ($node['device_class'] ?? null),
			'device_version'   => $node['device_version'] ?? null,
			'pid'              => $node['pid']            ?? null,
			'status'           => $node['status']         ?? null,
			'category'         => $node['category_id']    ?? null,
			'last_seen'        => $node['last_seen']      ?? null,
			'regdate'          => $node['regdate']        ?? null,
			'unregdate'        => $node['unregdate']      ?? null,
			'bypass_vlan'      => $node['bypass_vlan']    ?? null,
			'bypass_acls'      => $node['bypass_acls']    ?? null,
			'user_agent'       => $node['user_agent']     ?? null,
			'dhcp_fingerprint' => $node['dhcp_fingerprint'] ?? null,
			'machine_account'  => $node['machine_account'] ?? null,
			'notes'            => $node['notes']          ?? null,
			'voip'             => $node['voip']           ?? null,
			'security_events'  => $events,
			'_raw'             => $show_debug ? $node : null,
		];
	}

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
