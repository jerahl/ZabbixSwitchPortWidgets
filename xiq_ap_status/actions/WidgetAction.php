<?php declare(strict_types = 0);

namespace Modules\XiqApStatus\Actions;

use API;
use CController;
use CControllerResponseData;
use CSessionHelper;
use CWebUser;

/**
 * XIQ AP Status — action controller.
 *
 * Receives POSTs from the widget's kebab menu and translates them into XIQ
 * API calls. The action token is read from a file on the Zabbix frontend
 * server. It NEVER traverses the wire, the session, the database, or the
 * Zabbix API surface.
 *
 * All ops return JSON: {ok: bool, message: string, lro_id?: string, data?: object}.
 *
 * NOTE on CSRF: The Zabbix framework CSRF check is disabled (it's keyed to
 * page-level form submissions and is incompatible with AJAX RPC calls).
 * Security is enforced by (a) Zabbix's session authentication gate, which
 * runs before doAction() is reached, and (b) the checkPermissions() Admin
 * gate below. This matches the approach Zabbix's own built-in widget actions
 * use.
 *
 * NOTE on JSON output: respond() outputs raw JSON and calls exit() instead
 * of going through CControllerResponseData / the view pipeline. The view
 * pipeline wraps responses in the full HTML page template, which caused the
 * "Non-JSON response: <!DOCTYPE html>" error. Using exit() is safe here —
 * PHP's registered shutdown handlers (including session write-back) still
 * run normally.
 */
class WidgetAction extends CController {

	private const ALLOWED_TOKEN_PREFIX = '/etc/zabbix/secrets/';
	private const TOKEN_MAX_BYTES      = 8192;
	private const MAX_DEVICE_IDS       = 100;
	private const CLI_MAX_LEN          = 512;
	private const AUDIT_CLI_OUTPUT_TRUNCATE = 4096;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'op'                => 'required|in reboot,manage,unmanage,refresh,cli,lro_status',
			'device_ids'        => 'array',
			'lro_id'            => 'string',
			'command'           => 'string',
			'xiq_url'           => 'string',
			'verify_ssl'        => 'in 0,1',
			'action_token_path' => 'string',
			'hostid'            => 'db hosts.hostid',
			'cli_allowlist'     => 'array',
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return in_array(CWebUser::getType(), [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN]);
	}

	protected function doAction(): void {
		$op = $this->getInput('op');
		switch ($op) {
			case 'reboot':     $this->opReboot();           break;
			case 'manage':     $this->opManageToggle(true); break;
			case 'unmanage':   $this->opManageToggle(false);break;
			case 'refresh':    $this->opRefresh();          break;
			case 'cli':        $this->opCli();              break;
			case 'lro_status': $this->opLroStatus();        break;
			default:           $this->respond(false, _('Unknown op'));
		}
	}

	// ── Ops ─────────────────────────────────────────────────────────────────

	private function opReboot(): void {
		$ids = $this->normalizeDeviceIds();
		if (!$ids) { $this->respond(false, _('No device IDs provided')); return; }

		$resolved = false; $token_path = '';
		$token = $this->resolveActionToken($resolved, $token_path);
		if (!$resolved) { $this->logAudit('reboot', $ids, false, 'token resolution failed (path=' . ($token_path ?: '?') . ')'); $this->respondTokenError(); return; }

		$r = $this->xiqCall('POST', '/devices/:reboot', $token, self::deviceFilterBody($ids));
		$this->logAudit('reboot', $ids, true,
			'token=' . self::tokenFingerprint($token, $token_path) . ' ' .
			($r['ok'] ? ('lro=' . ($r['lro_id'] ?? '?')) : ('failed: ' . $r['message'])));
		$this->respond($r['ok'], $r['message'], $r['lro_id'] ?? null, $r['data'] ?? null);
	}

	private function opManageToggle(bool $managed): void {
		$ids = $this->normalizeDeviceIds();
		if (!$ids) { $this->respond(false, _('No device IDs provided')); return; }

		$op_name = $managed ? 'manage' : 'unmanage';
		$resolved = false; $token_path = '';
		$token = $this->resolveActionToken($resolved, $token_path);
		if (!$resolved) { $this->logAudit($op_name, $ids, false, 'token resolution failed (path=' . ($token_path ?: '?') . ')'); $this->respondTokenError(); return; }

		$endpoint = $managed ? '/devices/:manage' : '/devices/:unmanage';
		$r = $this->xiqCall('POST', $endpoint, $token, self::deviceFilterBody($ids));
		$this->logAudit($op_name, $ids, true,
			'token=' . self::tokenFingerprint($token, $token_path) . ' ' .
			($r['ok'] ? ('lro=' . ($r['lro_id'] ?? '?')) : ('failed: ' . $r['message'])));
		$this->respond($r['ok'], $r['message'], $r['lro_id'] ?? null, $r['data'] ?? null);
	}

	/**
	 * Build the XiqDeviceFilter body shape XIQ expects for bulk
	 * device-action endpoints: /devices/:reboot, /devices/:manage,
	 * /devices/:unmanage, /devices/:delete. Per the XIQ Swagger and
	 * confirmed by the DeviceManagementEndpoint signature, the body is a
	 * top-level object with an `ids` array of long-typed device IDs.
	 *
	 * NOT to be confused with the nested {"devices":{"ids":[…]}} envelope
	 * used by /devices/:cli and /devices/config/:deploy — those endpoints
	 * carry additional sibling fields (clis, deploy_policy) that need a
	 * dedicated container.
	 */
	private static function deviceFilterBody(array $ids): array {
		return ['ids' => array_values(array_map('intval', $ids))];
	}

	/**
	 * Non-secret fingerprint of the resolved token, for audit logging.
	 * Includes the path it came from, byte length, and a 4-char prefix so
	 * an operator can correlate against the file on disk without exposing
	 * the bearer value.
	 */
	private static function tokenFingerprint(string $token, string $path): string {
		return sprintf('path=%s bytes=%d prefix=%s',
			$path !== '' ? $path : '?',
			strlen($token),
			substr($token, 0, 4) . '…');
	}


	private function opRefresh(): void {
		$hostid = (int) $this->getInput('hostid', 0);
		if ($hostid <= 0) { $this->respond(false, _('Missing hostid for refresh')); return; }

		$items = API::Item()->get([
			'output'  => ['itemid'],
			'hostids' => [$hostid],
			'filter'  => ['key_' => 'xiq.devices.raw'],
		]);
		if (!$items) {
			$this->logAudit('refresh', [], true, 'master item not found');
			$this->respond(false, _('Master item xiq.devices.raw not found on host'));
			return;
		}

		// ZBX_TM_TASK_CHECK_NOW = 6
		$task = API::Task()->create([[
			'type'    => 6,
			'request' => ['itemid' => $items[0]['itemid']],
		]]);
		$ok = !empty($task['taskids']);
		$this->logAudit('refresh', [], true, $ok ? 'queued' : 'task.create failed');
		$this->respond($ok, $ok ? _('Refresh queued') : _('Failed to queue refresh'));
	}

	private function opCli(): void {
		$ids = $this->normalizeDeviceIds();
		if (count($ids) !== 1) { $this->respond(false, _('CLI requires exactly one device ID')); return; }
		$device_id = (int) $ids[0];

		$cmd = trim((string) $this->getInput('command', ''));
		if ($cmd === '') { $this->respond(false, _('Empty command')); return; }
		if (strlen($cmd) > self::CLI_MAX_LEN) { $this->respond(false, _('Command too long')); return; }

		if (!str_starts_with($cmd, 'show ')) {
			$this->logAudit('cli', [$device_id], false, 'rejected: not a show command: ' . $cmd);
			$this->respond(false, _('Only "show ..." commands are permitted')); return;
		}

		$allowlist_raw = $this->getInput('cli_allowlist', []);
		$allowlist = [];
		foreach ((array) $allowlist_raw as $entry) {
			$entry = trim((string) $entry);
			if ($entry !== '' && str_starts_with($entry, 'show ')) {
				$allowlist[] = $entry;
			}
		}
		if (!in_array($cmd, $allowlist, true)) {
			$this->logAudit('cli', [$device_id], false, 'rejected: not in allowlist: ' . $cmd);
			$this->respond(false, _('Command is not in the allow-list')); return;
		}

		$resolved = false;
		$token = $this->resolveActionToken($resolved);
		if (!$resolved) { $this->logAudit('cli', [$device_id], false, 'token resolution failed'); $this->respondTokenError(); return; }

		$r = $this->xiqCall('POST', '/devices/' . $device_id . '/:cli', $token, ['clis' => [$cmd]]);

		$audit_msg = sprintf('cmd=%s; ', $cmd);
		if ($r['ok']) {
			$out = '';
			if (isset($r['data']['responses']) && is_array($r['data']['responses'])) {
				foreach ($r['data']['responses'] as $resp) {
					$out .= ($resp['output'] ?? '');
				}
			} elseif (isset($r['data']['output'])) {
				$out = (string) $r['data']['output'];
			}
			if ($out === '') $out = json_encode($r['data']);
			$audit_msg .= 'output=' . substr((string) $out, 0, self::AUDIT_CLI_OUTPUT_TRUNCATE);
		} else {
			$audit_msg .= 'failed: ' . $r['message'];
		}
		$this->logAudit('cli', [$device_id], true, $audit_msg);

		$this->respond($r['ok'], $r['message'], $r['lro_id'] ?? null, $r['data'] ?? null);
	}

	private function opLroStatus(): void {
		$lro_id = trim((string) $this->getInput('lro_id', ''));
		if ($lro_id === '') { $this->respond(false, _('Missing lro_id')); return; }
		if (!preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $lro_id)) {
			$this->respond(false, _('Invalid lro_id'));
			return;
		}

		$resolved = false;
		$token = $this->resolveActionToken($resolved);
		if (!$resolved) { $this->respondTokenError(); return; }

		$r = $this->xiqCall('GET', '/lros/' . urlencode($lro_id), $token);
		$this->respond($r['ok'], $r['message'], null, $r['data'] ?? null);
	}

	// ── XIQ HTTP plumbing ──────────────────────────────────────────────────

	private function xiqCall(string $method, string $path, string $token, array $body = null): array {
		$xiq_url = rtrim((string) $this->getInput('xiq_url', ''), '/');
		if ($xiq_url === '') {
			$xiq_url = 'https://api.extremecloudiq.com';
		}
		if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?(/.*)?$#', $xiq_url)) {
			return ['ok' => false, 'message' => _('Invalid XIQ URL')];
		}
		$verify = ((int) $this->getInput('verify_ssl', 1)) === 1;

		$ch = curl_init($xiq_url . $path);
		$headers = [
			'Authorization: Bearer ' . $token,
			'Accept: application/json',
		];
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => $verify,
			CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
			CURLOPT_CUSTOMREQUEST  => $method,
		];
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = json_encode($body);
			$headers[] = 'Content-Type: application/json';
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);

		$resp = curl_exec($ch);
		$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($resp === false) {
			return ['ok' => false, 'message' => 'cURL error: ' . $err];
		}

		$data = json_decode((string) $resp, true);
		if ($http >= 200 && $http < 300) {
			$lro_id = null;
			if (is_array($data)) {
				$lro_id = $data['lro_id'] ?? ($data['id'] ?? ($data['operationId'] ?? null));
			}
			return [
				'ok'      => true,
				'message' => _('OK'),
				'lro_id'  => is_string($lro_id) ? $lro_id : (is_int($lro_id) ? (string) $lro_id : null),
				'data'    => is_array($data) ? $data : null,
			];
		}

		$emsg = is_array($data) && isset($data['error_message'])
			? (string) $data['error_message']
			: (string) $resp;
		if (strlen($emsg) > 500) $emsg = substr($emsg, 0, 500) . '…';
		return [
			'ok'      => false,
			'message' => sprintf('XIQ HTTP %d at %s %s: %s', $http, $method, $path, $emsg),
		];
	}

	// ── Token resolution ───────────────────────────────────────────────────

	/**
	 * Resolve the action token from the file referenced by the
	 * action_token_path widget input. Validates that the path lies under
	 * /etc/zabbix/secrets/, is a readable regular file, and is non-empty.
	 *
	 * On success: $resolved=true, $resolved_path=<realpath of the file read>,
	 * returns the trimmed token contents.
	 * On failure: $resolved=false; $resolved_path is best-effort populated
	 * with whatever value was inspected (the input path if nothing else),
	 * to aid audit logging.
	 */
	private function resolveActionToken(bool &$resolved, string &$resolved_path = ''): string {
		$resolved = false;
		$resolved_path = '';

		$path = trim((string) $this->getInput('action_token_path', ''));
		$resolved_path = $path;       // expose the requested path even if it fails
		if ($path === '' || $path[0] !== '/') return '';

		$real = realpath($path);
		if ($real === false) return '';
		$resolved_path = $real;       // upgrade to the realpath if we got that far
		if (strpos($real, self::ALLOWED_TOKEN_PREFIX) !== 0) return '';
		if (!is_file($real) || !is_readable($real)) return '';
		if (filesize($real) === false || filesize($real) > self::TOKEN_MAX_BYTES) return '';

		$contents = @file_get_contents($real);
		if ($contents === false) return '';

		$contents = rtrim($contents, "\r\n");
		if ($contents === '') return '';

		$resolved = true;
		return $contents;
	}


	private function respondTokenError(): void {
		$this->respond(false, _('Action token could not be resolved. '
			. 'Verify the file exists under /etc/zabbix/secrets/ and is readable by Apache (mode 0640, owner root:apache).'));
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function normalizeDeviceIds(): array {
		$raw = $this->hasInput('device_ids') ? $this->getInput('device_ids') : [];
		$ids = [];
		foreach ((array) $raw as $id) {
			$n = (int) $id;
			if ($n > 0) $ids[] = $n;
		}
		$ids = array_values(array_unique($ids));
		if (count($ids) > self::MAX_DEVICE_IDS) {
			$ids = array_slice($ids, 0, self::MAX_DEVICE_IDS);
		}
		return $ids;
	}

	private function logAudit(string $op, array $device_ids, bool $token_resolved, string $detail): void {
		$msg = sprintf(
			'XIQ widget %s: device_ids=[%s] token_resolved=%s %s',
			$op,
			implode(',', $device_ids),
			$token_resolved ? 'yes' : 'no',
			$detail
		);
		try {
			if (class_exists('\\CAudit')) {
				\CAudit::log(
					(int) (CWebUser::$data['userid'] ?? 0),
					(string) (CWebUser::$data['username'] ?? ''),
					(string) ($_SERVER['REMOTE_ADDR'] ?? ''),
					\CAudit::ACTION_EXECUTE ?? 7,
					\CAudit::RESOURCE_SCRIPT ?? 25,
					$msg
				);
			} else {
				error_log('xiq_ap_status audit: ' . $msg);
			}
		} catch (\Throwable $e) {
			error_log('xiq_ap_status audit (fallback): ' . $msg . ' (audit error: ' . $e->getMessage() . ')');
		}
	}

	/**
	 * Output a pure-JSON response and terminate.
	 *
	 * We bypass CControllerResponseData / view rendering entirely because
	 * this is an AJAX RPC endpoint. Going through the Zabbix view pipeline
	 * wraps the response in the full HTML page template, which caused the
	 * "Non-JSON response: <!DOCTYPE html>" error that triggered this fix.
	 */
	private function respond(bool $ok, string $message, ?string $lro_id = null, $data = null): void {
		$out = ['ok' => $ok, 'message' => $message];
		if ($lro_id !== null) $out['lro_id'] = $lro_id;
		if ($data   !== null) $out['data']   = $data;

		// Discard any buffered output so no stray bytes appear before the JSON.
		while (ob_get_level()) {
			ob_end_clean();
		}

		header('Content-Type: application/json; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit();
	}
}
