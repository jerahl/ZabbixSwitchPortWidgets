<?php

namespace Modules\PortDetail\Actions;

use DB;
use API;
use CController;
use CControllerResponseData;

/**
 * Switch Port Detail Widget – Cycle PoE action.
 *
 * Receives a port-cycle request from the browser and dispatches it to the
 * rConfig API by deploying a pre-configured snippet against the host's
 * rConfig device. The snippet is expected to take a single dynamic_var
 * called {interface_name}, which we substitute with the resolved port
 * spec (e.g. "1:7" for stack member 1, port 7).
 *
 * Security model
 * ──────────────
 * Credentials NEVER leave the Zabbix server. They are read from Zabbix
 * host macros, which support secret-macro masking:
 *
 *   {$RCONFIG.URL}              base URL, e.g. https://rconfig.example.com
 *   {$RCONFIG.TOKEN}            API token (define as Secret Macro)
 *   {$RCONFIG.POE_SNIPPET_ID}   integer ID of the PoE-cycle snippet
 *   {$RCONFIG.DEVICE_ID}        OPTIONAL — pin to a specific rConfig device
 *                               id, bypassing auto-resolution. Useful when
 *                               IP/name matching is ambiguous.
 *
 * Device-id auto-resolution
 * ─────────────────────────
 * If {$RCONFIG.DEVICE_ID} is not defined, the action queries
 * GET /api/v2/devices and matches against the Zabbix host:
 *   1. SNMP-interface IP  → rConfig device_ip   (preferred)
 *   2. Any-interface IP   → rConfig device_ip
 *   3. Zabbix host        → rConfig device_name (case-insensitive)
 *   4. Zabbix visible name→ rConfig device_name (case-insensitive)
 * The first match wins. Ambiguous results (multiple matches) are
 * rejected — the user is asked to pin the id with the macro.
 *
 * The browser only submits hostid, snmp_index, iface_name. The token
 * never appears in any HTTP response or DOM.
 *
 * Permissions
 * ───────────
 * Cycling power on a port is a destructive change. We require the
 * caller to be at least USER_TYPE_ZABBIX_ADMIN. Read-only users will
 * get a permission error and the button will not function.
 *
 * Transport
 * ─────────
 * cURL with TLS verification ON, 15-second connect/30-second total
 * timeout, and explicit User-Agent. We never log or echo the token.
 *
 * Request (POST):
 *   hostid       int  – the Zabbix hostid we are acting on
 *   snmp_index   int  – snmpIndex of the port (for audit / sanity check)
 *   iface_name   str  – resolved port spec passed as {interface_name}
 *
 * Response (json layout, wrapped in main_block):
 *   {
 *     "ok":          bool,
 *     "message":     string,
 *     "http_status": int|null
 *   }
 */
class CyclePoe extends CController {

	/** Connect timeout, seconds */
	private const CONNECT_TIMEOUT = 15;
	/** Total request timeout, seconds */
	private const TOTAL_TIMEOUT = 30;

	/** Macro names used by this action */
	private const MACRO_URL        = '{$RCONFIG.URL}';
	private const MACRO_TOKEN      = '{$RCONFIG.TOKEN}';
	private const MACRO_SNIPPET_ID = '{$RCONFIG.POE_SNIPPET_ID}';
	private const MACRO_DEVICE_ID  = '{$RCONFIG.DEVICE_ID}';

	protected function init(): void {
		// Match the existing AutoConfig pattern in switchports — Zabbix's
		// session auth and the admin permission check below are our
		// authorization boundary. CSRF is disabled here because we use
		// the layout: json action plumbing the same way Zabbix's own
		// dashboard widget AJAX calls do.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid'     => 'required|db hosts.hostid',
			'snmp_index' => 'required|int32',
			'iface_name' => 'required|string',
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode([
					'ok'      => false,
					'message' => _('Invalid request input.'),
				])
			]));
		}

		return $ret;
	}

	/**
	 * Only Zabbix Admins and Super-admins can cycle a port. Read-only
	 * users (USER_TYPE_ZABBIX_USER) get a 403-equivalent and the JS
	 * surfaces a permission error.
	 */
	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	}

	protected function doAction(): void {
		$hostid    = (int)    $this->getInput('hostid');
		$snmp_idx  = (int)    $this->getInput('snmp_index');
		$ifaceName = trim((string) $this->getInput('iface_name'));

		// Defensive: iface_name must be non-empty, reasonable length, and
		// match the character set used by switch interface names. This
		// also stops anyone from injecting newlines or shell metacharacters
		// into the JSON we'll send to rConfig — though json_encode would
		// already escape them, an early hard reject keeps the audit log
		// clean and makes intent explicit.
		if ($ifaceName === '' || strlen($ifaceName) > 64
				|| !preg_match('/^[A-Za-z0-9 ._\\/:-]+$/', $ifaceName)) {
			$this->respond(false, _('Invalid interface name.'));
			return;
		}

		// ── Verify the user actually has access to this host, and pull
		// the interfaces we'll match against rConfig device records ─────
		// API::Host()->get respects user permissions, so a host the
		// caller cannot read returns an empty array.
		$hosts = API::Host()->get([
			'output'           => ['hostid', 'host', 'name'],
			'hostids'          => [$hostid],
			'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'main', 'type', 'useip'],
		]);
		if (!$hosts) {
			$this->respond(false, _('Host not accessible.'));
			return;
		}
		$host = reset($hosts);

		// ── Resolve macros at the host level ─────────────────────────────
		// DEVICE_ID is OPTIONAL — if defined it pins us to a specific
		// rConfig device, otherwise we look it up via the API.
		$macros = $this->resolveMacros($hostid, [
			self::MACRO_URL, self::MACRO_TOKEN,
			self::MACRO_SNIPPET_ID, self::MACRO_DEVICE_ID,
		]);

		$rconfig_url       = trim((string)($macros[self::MACRO_URL]        ?? ''));
		$token             = (string)      ($macros[self::MACRO_TOKEN]      ?? '');
		$snippet_id        = (int)         ($macros[self::MACRO_SNIPPET_ID] ?? 0);
		$device_id_override = (int)        ($macros[self::MACRO_DEVICE_ID]  ?? 0);

		$missing = [];
		if ($rconfig_url === '') $missing[] = self::MACRO_URL;
		if ($token === '')       $missing[] = self::MACRO_TOKEN;
		if ($snippet_id <= 0)    $missing[] = self::MACRO_SNIPPET_ID;
		if ($missing) {
			$this->respond(false, _s('Missing required host macros: %1$s', implode(', ', $missing)));
			return;
		}

		// Refuse plaintext HTTP — token must travel over TLS.
		$scheme = strtolower((string) parse_url($rconfig_url, PHP_URL_SCHEME));
		if ($scheme !== 'https') {
			$this->respond(false, _('rConfig URL must use HTTPS.'));
			return;
		}

		// ── Resolve rConfig device id ────────────────────────────────────
		if ($device_id_override > 0) {
			$device_id     = $device_id_override;
			$resolution    = 'macro_override';
		} else {
			[$device_id, $resolution, $resolve_err] = $this->resolveDeviceId(
				$rconfig_url, $token, $host
			);
			if ($device_id <= 0) {
				$this->respond(false, $resolve_err ?: _('Could not resolve rConfig device for this host.'));
				return;
			}
		}

		// ── Compose deploy endpoint and payload ──────────────────────────
		$endpoint = rtrim($rconfig_url, '/') . '/api/v1/snippets/' . $snippet_id . '/deploy';
		$payload  = json_encode([
			'devices'      => [$device_id],
			'dynamic_vars' => [
				// The snippet's placeholder name. Documented in README.
				'interface_name' => $ifaceName,
			],
		], JSON_UNESCAPED_SLASHES);

		// ── Dispatch ─────────────────────────────────────────────────────
		[$ok, $http_status, $msg] = $this->httpPostJson($endpoint, $payload, $token);

		// Audit trail — we deliberately log the request WITHOUT the token.
		error_log(sprintf(
			'[portdetail.cyclepoe] hostid=%d snmp_index=%d iface=%s snippet=%d device=%d resolution=%s http=%s ok=%s',
			$hostid, $snmp_idx, $ifaceName, $snippet_id, $device_id, $resolution,
			$http_status === null ? '-' : (string)$http_status,
			$ok ? 'yes' : 'no'
		));

		$this->respond($ok, $msg, $http_status);
	}

	/**
	 * Resolve a list of macro names against a single host. Returns an
	 * associative array of macro_name => resolved_value. Macros that do
	 * not exist (or that the user cannot read) are absent from the array.
	 *
	 * Uses API::UserMacro()->get() which honours secret-macro masking
	 * settings — but secret macros DO return their value to the API for
	 * the resolver, which is exactly what we want server-side.
	 */
    private function resolveMacros(int $hostid, array $names): array {
        $out = [];

        // Build host + parent-template chain in BFS order so closer entities
        // win on conflict (host overrides direct template, direct template
        // overrides grandparent template, etc.).
        $chain = $this->getHostMacroChain($hostid);

        // Direct DB read on hostmacro. The CUserMacro API masks Secret macro
        // values for every caller; reading the table directly returns them in
        // plaintext, which is the same pattern CMacrosResolverGeneral uses
        // internally. (Vault-backed macros — type 2 — would store a vault
        // path here instead of the literal value; we don't dereference those.
        // Token storage in this widget is expected to use Secret type.)
        if ($chain && $names) {
            $rows = DBfetchArray(DBselect(
                'SELECT hm.hostid,hm.macro,hm.value,hm.type'.
                ' FROM hostmacro hm'.
                ' WHERE '.dbConditionInt('hm.hostid', $chain).
                ' AND '.dbConditionString('hm.macro', $names)
            ));

            // Pick the closest entity for each macro using the chain order.
            $priority = array_flip($chain); // entity_id => index (lower = higher priority)
            $best = [];
            foreach ($rows as $row) {
                $prio = $priority[$row['hostid']] ?? PHP_INT_MAX;
                if (!isset($best[$row['macro']]) || $prio < $best[$row['macro']][0]) {
                    $best[$row['macro']] = [$prio, (string)($row['value'] ?? '')];
                }
            }
            foreach ($best as $macro => [, $value]) {
                $out[$macro] = $value;
            }
        }

        // Fall through to global macros for anything still unresolved.
        $missing = array_values(array_diff($names, array_keys($out)));
        if ($missing) {
            $global_rows = DBfetchArray(DBselect(
                'SELECT gm.macro,gm.value'.
                ' FROM globalmacro gm'.
                ' WHERE '.dbConditionString('gm.macro', $missing)
            ));
            foreach ($global_rows as $row) {
                $out[$row['macro']] = (string)($row['value'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Returns [hostid, template_id_1, template_id_2, ...] in BFS order so the
     * caller can use array index as merge priority. Caps recursion depth as a
     * sanity guard against pathological template loops.
     */
    private function getHostMacroChain(int $hostid): array {
        $chain = [$hostid];

        $hosts = API::Host()->get([
            'output'                => ['hostid'],
            'hostids'               => [$hostid],
            'selectParentTemplates' => ['templateid'],
        ]);
        if (!$hosts) {
            return $chain;
        }

        $frontier = array_map('intval',
            array_column($hosts[0]['parentTemplates'] ?? [], 'templateid')
        );

        $max_depth = 10;
        while ($frontier && $max_depth-- > 0) {
            foreach ($frontier as $tid) {
                if (!in_array($tid, $chain, true)) {
                    $chain[] = $tid;
                }
            }

            $parents = API::Template()->get([
                'output'                => ['templateid'],
                'templateids'           => $frontier,
                'selectParentTemplates' => ['templateid'],
            ]);
            $next = [];
            foreach ($parents as $tpl) {
                foreach ($tpl['parentTemplates'] ?? [] as $pt) {
                    $tid = (int)$pt['templateid'];
                    if (!in_array($tid, $chain, true) && !in_array($tid, $next, true)) {
                        $next[] = $tid;
                    }
                }
            }
            $frontier = $next;
        }

        return $chain;
    }



	/**
	 * Resolve which rConfig device id corresponds to a given Zabbix host.
	 *
	 * Strategy: page through GET /api/v2/devices and match — in order —
	 * SNMP-interface IPs, then any-interface IPs, then the technical
	 * hostname, then the visible name. The first key with a matching
	 * device wins. Multiple matches against the same key are treated as
	 * ambiguous and the user is told to pin the id with the macro.
	 *
	 * Returns [device_id(int), resolution_label(string), error_msg(?string)].
	 * device_id is 0 on failure.
	 *
	 * Performance note: this fires on every cycle click. For sites with
	 * thousands of rConfig devices, consider adding {$RCONFIG.DEVICE_ID}
	 * to skip the lookup. We deliberately don't cache here — a stale
	 * cache that fires PoE on the wrong device would be far worse than
	 * the latency cost of a fresh lookup.
	 */
	private function resolveDeviceId(string $rconfig_url, string $token, array $host): array {
		// ── Build the candidate list of values to match against ─────────
		$snmp_ips = [];
		$any_ips  = [];
		foreach (($host['interfaces'] ?? []) as $iface) {
			$ip = trim((string)($iface['ip'] ?? ''));
			if ($ip === '' || $ip === '0.0.0.0') continue;
			$any_ips[] = $ip;
			// type 2 == INTERFACE_TYPE_SNMP
			if ((int)($iface['type'] ?? 0) === 2) {
				$snmp_ips[] = $ip;
			}
		}
		$snmp_ips = array_values(array_unique($snmp_ips));
		$any_ips  = array_values(array_unique($any_ips));

		$tech_name    = strtolower(trim((string)($host['host'] ?? '')));
		$visible_name = strtolower(trim((string)($host['name'] ?? '')));

		if (!$snmp_ips && !$any_ips && $tech_name === '' && $visible_name === '') {
			return [0, 'no_candidates', _('Host has no interface IP, hostname, or visible name to match against.')];
		}

		// ── Paginate through the rConfig device list ────────────────────
		// We collect all devices first (paginating up to a sane cap), then
		// pick the best match. This keeps the matching logic simple and
		// avoids surprising behaviour where match priority depends on
		// pagination order.
		$base = rtrim($rconfig_url, '/') . '/api/v2/devices';
		$max_pages = 20;  // 20 * 100 = 2000 devices ceiling
		$page = 1;
		$all  = [];

		while ($page <= $max_pages) {
			$url = $base . '?per_page=100&page=' . $page;
			[$ok, $http_status, $body, $err] = $this->httpGet($url, $token);
			if (!$ok) {
				return [0, 'list_error', _s('Could not list rConfig devices (HTTP %1$s): %2$s',
					$http_status === null ? '-' : (string)$http_status, $err ?: 'unknown')];
			}
			$decoded = json_decode((string)$body, true);
			if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
				return [0, 'list_parse_error', _('Unexpected response format from /api/v2/devices.')];
			}
			foreach ($decoded['data'] as $row) {
				if (is_array($row)) $all[] = $row;
			}
			$last_page = (int)($decoded['last_page'] ?? 1);
			if ($page >= $last_page) break;
			$page++;
		}

		if (!$all) {
			return [0, 'no_devices', _('rConfig returned an empty device list.')];
		}

		// ── Match in priority order ─────────────────────────────────────
		// Each helper returns matched ids — 1 = unambiguous, 0 = no match,
		// 2+ = ambiguous (we bail with a clear error message).
		$tries = [
			['snmp_ip',      fn($d) => in_array(trim((string)($d['device_ip'] ?? '')), $snmp_ips, true) ],
			['interface_ip', fn($d) => in_array(trim((string)($d['device_ip'] ?? '')), $any_ips,  true) ],
			['hostname',     fn($d) => $tech_name !== ''
				&& strtolower(trim((string)($d['device_name'] ?? ''))) === $tech_name],
			['visible_name', fn($d) => $visible_name !== ''
				&& strtolower(trim((string)($d['device_name'] ?? ''))) === $visible_name],
		];

		foreach ($tries as [$label, $matcher]) {
			$hits = [];
			foreach ($all as $d) {
				if ($matcher($d)) {
					$hits[(int)($d['id'] ?? 0)] = true;
				}
			}
			unset($hits[0]);
			if (count($hits) === 1) {
				return [(int)array_key_first($hits), 'auto:' . $label, null];
			}
			if (count($hits) > 1) {
				return [0, 'ambiguous:' . $label,
					_s('Multiple rConfig devices match by %1$s. Set {$RCONFIG.DEVICE_ID} on the host to pin the device.', $label)];
			}
		}

		return [0, 'no_match',
			_('No rConfig device matches this host by IP or name. Set {$RCONFIG.DEVICE_ID} on the host to pin the device.')];
	}

	/**
	 * GET helper for the device-resolution lookup. Returns
	 * [ok(bool), http_status(int|null), body(string|null), error(string|null)].
	 */
	private function httpGet(string $url, string $token): array {
		if (!function_exists('curl_init')) {
			return [false, null, null, _('cURL PHP extension is not installed.')];
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT      => 'Zabbix-PortDetail-Widget/2.8',
			CURLOPT_HTTPHEADER     => [
				'Accept: application/json',
				'apitoken: ' . $token,
			],
		]);

		$body   = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($body === false) {
			return [false, null, null, $err ?: 'unknown error'];
		}
		$ok = ($status >= 200 && $status < 300);
		return [$ok, $status, (string)$body, $ok ? null : ($err ?: ('HTTP ' . $status))];
	}

	/**
	 * Tight-loop POST helper. Returns [ok(bool), http_status(int|null),
	 * message(string)]. The raw response body is parsed as JSON and the
	 * 'data' field is surfaced when present so the user sees rConfig's
	 * own status message ("Snippet deployment jobs queued successfully…").
	 */
	private function httpPostJson(string $url, string $body, string $token): array {
		if (!function_exists('curl_init')) {
			return [false, null, _('cURL PHP extension is not installed.')];
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
			// TLS verification ON. If the rConfig server uses an internal
			// CA, the system trust store on the Zabbix server should
			// include it — we deliberately do NOT expose a knob here to
			// turn it off, because that would defeat the whole point of
			// using HTTPS to protect the token.
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT      => 'Zabbix-PortDetail-Widget/2.8',
			CURLOPT_HTTPHEADER     => [
				'Accept: application/json',
				'Content-Type: application/json',
				// rConfig accepts the token via the apitoken header.
				'apitoken: ' . $token,
			],
		]);

		$resp_body = curl_exec($ch);
		$status    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err       = curl_error($ch);
		curl_close($ch);

		if ($resp_body === false) {
			return [false, null, _s('Connection error: %1$s', $err ?: _('unknown'))];
		}

		$decoded = json_decode((string)$resp_body, true);
		$ok = ($status >= 200 && $status < 300)
			&& is_array($decoded)
			&& !empty($decoded['success']);

		$msg = '';
		if (is_array($decoded)) {
			if (isset($decoded['data']) && is_string($decoded['data'])) {
				$msg = $decoded['data'];
			} elseif (isset($decoded['message']) && is_string($decoded['message'])) {
				$msg = $decoded['message'];
			} elseif (isset($decoded['error']) && is_string($decoded['error'])) {
				$msg = $decoded['error'];
			}
		}
		if ($msg === '') {
			$msg = $ok
				? _('PoE cycle queued.')
				: _s('rConfig returned HTTP %1$d.', $status);
		}

		return [$ok, $status, $msg];
	}

	private function respond(bool $ok, string $message, ?int $http_status = null): void {
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode([
				'ok'          => $ok,
				'message'     => $message,
				'http_status' => $http_status,
			]),
		]));
	}
}
