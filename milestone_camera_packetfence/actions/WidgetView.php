<?php declare(strict_types = 0);

namespace Modules\MilestoneCameraPacketFence\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;

/**
 * Camera Device (PacketFence) widget controller.
 *
 * Data flow per refresh:
 *   1. Receive cam_mac (and optionally cam_ip + cam_name + cam_host) from JS,
 *      forwarded from a click in the Milestone Camera Status widget.
 *   2. Authenticate to PacketFence (POST /api/v1/login)
 *   3. POST /api/v1/nodes/search with an `equals` query on the MAC.
 *      If the MAC is missing but an IP was provided, fall back to a search
 *      by ip4log.ip so the operator still gets the card.
 *   4. For the matching node, fetch open security events.
 *   5. Return a single device card.
 */
class WidgetView extends CControllerDashboardWidgetView {

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'cam_mac'  => 'string',
			'cam_ip'   => 'string',
			'cam_name' => 'string',
			'cam_host' => 'string',
			'name'     => 'string',
		]);
	}

	protected function doAction(): void {
		$show_debug = (bool) ($this->fields_values['show_debug'] ?? false);
		$name       = $this->getInput('name', $this->widget->getDefaultName());

		$cam_mac  = $this->hasInput('cam_mac')  ? trim((string) $this->getInput('cam_mac'))  : '';
		$cam_ip   = $this->hasInput('cam_ip')   ? trim((string) $this->getInput('cam_ip'))   : '';
		$cam_name = $this->hasInput('cam_name') ? trim((string) $this->getInput('cam_name')) : '';
		$cam_host = $this->hasInput('cam_host') ? trim((string) $this->getInput('cam_host')) : '';

		// Normalize MAC to lowercase, colon-separated
		$mac = '';
		if ($cam_mac !== '') {
			$candidate = strtolower(str_replace('-', ':', $cam_mac));
			if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $candidate)) {
				$mac = $candidate;
			}
		}

		$debug = [
			'step_0_init' => [
				'has_cam_mac'  => $cam_mac !== '',
				'has_cam_ip'   => $cam_ip  !== '',
				'has_cam_name' => $cam_name !== '',
				'cam_host'     => $cam_host,
				'mac_valid'    => $mac !== '',
			],
		];

		// ── STEP 1: Wait for a camera to be selected ────────────────────────
		if ($mac === '' && $cam_ip === '') {
			$this->respondWaiting($name, $debug, $show_debug);
			return;
		}

		// ── STEP 2: PacketFence connection settings ─────────────────────────
		$pf_url     = rtrim((string) ($this->fields_values['pf_url']      ?? ''), '/');
		$pf_user    = (string) ($this->fields_values['pf_username'] ?? '');
		$pf_pass    = (string) ($this->fields_values['pf_password'] ?? '');
		$verify_ssl = (bool)   ($this->fields_values['verify_ssl']  ?? false);

		if (!$pf_url || !$pf_user || !$pf_pass) {
			$this->respondError(
				$name, $cam_name, $mac, $cam_ip,
				_('PacketFence URL, username, and password must be configured'),
				$debug, $show_debug
			);
			return;
		}

		// ── STEP 3: Authenticate to PacketFence ─────────────────────────────
		$token_result = self::pfLogin($pf_url, $pf_user, $pf_pass, $verify_ssl);
		$debug['step_3_auth'] = [
			'url'     => $pf_url . '/api/v1/login',
			'success' => $token_result['ok'],
			'http'    => $token_result['http_code'] ?? null,
			'error'   => $token_result['error']     ?? null,
		];

		if (!$token_result['ok']) {
			$this->respondError(
				$name, $cam_name, $mac, $cam_ip,
				sprintf(_('PacketFence login failed: %s'), $token_result['error'] ?? 'unknown'),
				$debug, $show_debug
			);
			return;
		}
		$token = $token_result['token'];

		// ── STEP 4: Search PacketFence for the node ─────────────────────────
		$search_body = self::buildNodeSearchBody($mac, $cam_ip);
		$debug['step_4_search_body'] = $search_body;

		$nodes_result = self::pfRequest(
			$pf_url . '/api/v1/nodes/search',
			'POST',
			$token,
			$search_body,
			$verify_ssl
		);
		$debug['step_4_nodes'] = [
			'http'  => $nodes_result['http_code'] ?? null,
			'error' => $nodes_result['error']     ?? null,
			'count' => is_array($nodes_result['data']['items'] ?? null)
				? count($nodes_result['data']['items']) : 0,
		];

		$node = null;
		if ($nodes_result['ok'] && !empty($nodes_result['data']['items'])) {
			// Prefer a MAC-exact match if we have a MAC. Otherwise take the
			// first IP-match — there's normally only one anyway.
			if ($mac !== '') {
				foreach ($nodes_result['data']['items'] as $n) {
					if (strtolower((string) ($n['mac'] ?? '')) === $mac) {
						$node = $n;
						break;
					}
				}
			}
			if ($node === null) {
				$node = $nodes_result['data']['items'][0];
			}
		}

		// ── STEP 5: Fetch open security events for the node ─────────────────
		$security_events = [];
		$lookup_mac = $node ? strtolower((string) ($node['mac'] ?? '')) : $mac;
		if ($lookup_mac !== '') {
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
							['op' => 'equals', 'field' => 'mac',    'value' => $lookup_mac],
							['op' => 'equals', 'field' => 'status', 'value' => 'open'],
						],
					],
				],
				$verify_ssl
			);
			if ($se_result['ok'] && isset($se_result['data']['items'])
					&& is_array($se_result['data']['items'])) {
				foreach ($se_result['data']['items'] as $ev) {
					$ev_mac = strtolower((string) ($ev['mac'] ?? ''));
					if ($ev_mac === $lookup_mac) {
						$security_events[] = $ev;
					}
				}
			}
			$debug['step_5_events'] = [
				'http'  => $se_result['http_code'] ?? null,
				'count' => count($security_events),
			];
		}

		// ── STEP 6: Build the device card ───────────────────────────────────
		$device = self::buildDeviceCard(
			$mac !== '' ? $mac : ($node['mac'] ?? '—'),
			$cam_ip,
			$node,
			$security_events,
			$show_debug
		);

		$this->setResponse(new CControllerResponseData([
			'name'         => $name,
			'waiting'      => false,
			'cam_name'     => $cam_name,
			'cam_host'     => $cam_host,
			'cam_mac'      => $mac,
			'cam_ip'       => $cam_ip,
			'device'       => $device,
			'pf_url'       => $pf_url,
			'pf_admin_url' => rtrim((string) ($this->fields_values['pf_admin_url'] ?? ''), '/'),
			'debug_info'   => $debug,
			'show_debug'   => $show_debug,
			'user'         => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private function respondWaiting(string $name, array $debug, bool $show_debug): void {
		$this->setResponse(new CControllerResponseData([
			'name'       => $name,
			'waiting'    => true,
			'device'     => null,
			'debug_info' => $debug,
			'show_debug' => $show_debug,
			'user'       => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	private function respondError(
		string $name, string $cam_name, string $mac, string $ip,
		string $error, array $debug, bool $show_debug
	): void {
		$this->setResponse(new CControllerResponseData([
			'name'       => $name,
			'waiting'    => false,
			'error'      => $error,
			'cam_name'   => $cam_name,
			'cam_mac'    => $mac,
			'cam_ip'     => $ip,
			'device'     => null,
			'debug_info' => $debug,
			'show_debug' => $show_debug,
			'user'       => ['debug_mode' => $this->getDebugMode()],
		]));
	}

	/**
	 * Build the nodes/search payload. Search by MAC if we have one;
	 * otherwise fall back to ip4log.ip. If both are present, MAC takes
	 * precedence inside an OR (so PF can match either way if its records
	 * are stale on one side).
	 */
	private static function buildNodeSearchBody(string $mac, string $ip): array {
		$or_values = [];
		if ($mac !== '') {
			$or_values[] = ['op' => 'equals', 'field' => 'mac', 'value' => $mac];
		}
		if ($ip !== '') {
			$or_values[] = ['op' => 'equals', 'field' => 'ip4log.ip', 'value' => $ip];
		}

		return [
			'cursor' => 0,
			'limit'  => 5,
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

	private static function buildDeviceCard(string $mac, string $fallback_ip, ?array $node, array $events, bool $show_debug): array {
		if ($node === null) {
			return [
				'mac'             => $mac,
				'ip'              => $fallback_ip ?: null,
				'ip_source'       => $fallback_ip !== '' ? 'camera' : null,
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

		$pf_ip = $node['ip4log.ip'] ?? null;
		// If PF has no IP but we got one from the camera item, use it.
		$ip        = $pf_ip ?: ($fallback_ip ?: null);
		$ip_source = $pf_ip ? 'pf' : ($fallback_ip ? 'camera' : null);

		return [
			'mac'              => $node['mac'] ?? $mac,
			'ip'               => $ip,
			'ip_source'        => $ip_source,
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
