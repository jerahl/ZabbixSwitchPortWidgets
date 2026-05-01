<?php declare(strict_types = 0);

namespace Modules\MilestoneCameraPacketFence\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;

/**
 * AJAX endpoint for per-node PacketFence operations on the selected camera.
 *
 * Accepts a MAC address and an action name, then issues:
 *   PUT /api/v1/node/{mac}/reevaluate_access
 *   PUT /api/v1/node/{mac}/restart_switchport
 *
 * Credentials live in the widget's saved fields (server-side), so the JS
 * caller only needs to pass widgetid + mac + action.
 *
 * Response shape (JSON, returned via main_block):
 *   { ok: bool, http_code: int|null, error: string|null, data: array|null }
 */
class WidgetAction extends CControllerDashboardWidgetView {

	private const ALLOWED_ACTIONS = ['reevaluate_access', 'restart_switchport'];

	protected function init(): void {
		parent::init();
		$this->addValidationRules([
			'mac'       => 'string',
			'pf_action' => 'string',
		]);
	}

	protected function doAction(): void {
		$mac    = strtolower(trim((string) $this->getInput('mac', '')));
		$action = (string) $this->getInput('pf_action', '');

		if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
			$this->respondJson(['ok' => false, 'error' => 'Invalid MAC address']);
			return;
		}
		if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
			$this->respondJson(['ok' => false, 'error' => 'Unknown action']);
			return;
		}

		$pf_url     = rtrim((string) ($this->fields_values['pf_url']      ?? ''), '/');
		$pf_user    = (string) ($this->fields_values['pf_username'] ?? '');
		$pf_pass    = (string) ($this->fields_values['pf_password'] ?? '');
		$verify_ssl = (bool)   ($this->fields_values['verify_ssl']  ?? false);

		if (!$pf_url || !$pf_user || !$pf_pass) {
			$this->respondJson(['ok' => false, 'error' => 'PacketFence URL/credentials not configured']);
			return;
		}

		$login = self::pfLogin($pf_url, $pf_user, $pf_pass, $verify_ssl);
		if (!$login['ok']) {
			$this->respondJson([
				'ok'    => false,
				'error' => 'PacketFence login failed: ' . ($login['error'] ?? 'unknown'),
			]);
			return;
		}

		$url = $pf_url . '/api/v1/node/' . rawurlencode($mac) . '/' . $action;
		$r   = self::pfRequest($url, 'PUT', $login['token'], null, $verify_ssl);

		$this->respondJson([
			'ok'        => $r['ok'],
			'http_code' => $r['http_code'],
			'error'     => $r['error'],
			'data'      => $r['data'],
			'action'    => $action,
			'mac'       => $mac,
		]);
	}

	private function respondJson(array $payload): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload)]));
	}

	// ── PF helpers (mirror of WidgetView; intentionally duplicated to keep
	//    each action self-contained) ───────────────────────────────────────

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
			CURLOPT_TIMEOUT        => 30,
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
