<?php

declare(strict_types=1);

namespace Modules\APDetail\Includes;

/**
 * PacketFence HTTP client — session auth + nodes search.
 *
 * Formalized from the static-helper bag in modules/packetfence/includes/PfClient.php
 * (Modules\PfDevice\Includes\PfClient). Behaviour is identical:
 *
 *   - POST /api/v1/login → token
 *   - All subsequent requests carry the raw token in the Authorization
 *     header (PF convention — no "Bearer " prefix)
 *   - HTTP 401 transparently triggers one re-auth + retry
 *
 * Differences from the original:
 *   - Static helpers → instance methods. Token state lives on the instance
 *     so callers don't thread $token through every request.
 *   - Constructor takes the widget-config fields declared in WidgetForm
 *     (pf_url / pf_user / pf_pass / verify_ssl).
 *   - 401 → re-auth → retry is now internal to request(); the legacy
 *     client left that to callers.
 *   - Scope is intentionally narrow: login() + searchNodes() + the static
 *     nodeSearchBody() builder. locationlogSearch() and radiusAuditLog()
 *     are added in their own M1 tasks (see AP Dashboard Project Plan v2).
 *
 * Security: credentials must be those of a dedicated read-only PF
 * webservices user. Never the admin account — the token grants every
 * right the configured user has, and widget config is stored as
 * plaintext in the Zabbix DB.
 *
 * Namespace note: file is at modules/apdetail/includes/PFClient.php so
 * the namespace is Modules\APDetail\Includes, matching WidgetForm. The
 * plan v2 task description "Modules\APDetail\PFClient" is shorthand —
 * "Includes" is implicit from the path convention.
 *
 * @example
 *     // Inside WidgetView::doAction():
 *     $pf = new PFClient(
 *         base_url: $this->getInput('pf_url'),
 *         username: $this->getInput('pf_user'),
 *         password: $this->getInput('pf_pass'),
 *     );
 *     $body = PFClient::nodeSearchBody([
 *         ['op' => 'equals', 'field' => 'switch_ip', 'value' => $ap_mgt0_ip],
 *     ]);
 *     $r = $pf->searchNodes($body);
 *     $pf_nodes = $r['ok'] ? ($r['data']['items'] ?? []) : [];
 */
final class PFClient {

    /** Base URL with any trailing slash stripped. */
    private string $base_url;

    /** Cached token from the last successful login(). Null until first call. */
    private ?string $token = null;

    public function __construct(
        string $base_url,
        private string $username,
        private string $password,
        private bool $verify_ssl = true,
        private int $connect_timeout = 5,
        private int $request_timeout = 15,
    ) {
        $this->base_url = rtrim($base_url, '/');
    }

    /**
     * POST /api/v1/login. Caches the returned token on the instance so
     * subsequent searchNodes() calls reuse it without re-authenticating.
     *
     * Callers usually don't invoke this directly — request() calls it
     * lazily on the first authed call and again on 401. Exposed publicly
     * for the rare case a caller wants to validate credentials up-front
     * (e.g. a "Test connection" button in widget config — not built yet).
     *
     * @return array{ok:bool, token:?string, http_code:int, error:?string}
     */
    public function login(): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->base_url . '/api/v1/login',
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode([
                'username' => $this->username,
                'password' => $this->password,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            CURLOPT_TIMEOUT        => $this->request_timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
        ]);
        $body      = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err_curl  = curl_error($ch);
        curl_close($ch);

        if ($err_curl !== '') {
            return ['ok' => false, 'token' => null, 'http_code' => 0, 'error' => $err_curl];
        }
        if ($http_code !== 200) {
            return [
                'ok'        => false,
                'token'     => null,
                'http_code' => $http_code,
                'error'     => sprintf('HTTP %d', $http_code),
            ];
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data) || empty($data['token'])) {
            return [
                'ok'        => false,
                'token'     => null,
                'http_code' => $http_code,
                'error'     => 'No token in response',
            ];
        }

        $this->token = (string) $data['token'];
        return ['ok' => true, 'token' => $this->token, 'http_code' => $http_code, 'error' => null];
    }

    /**
     * POST /api/v1/nodes/search. $query is the full request body in the
     * shape PF expects: {cursor, limit, sort, fields, query: {...}}.
     * Use nodeSearchBody() to build a standard-shaped body, or pass your
     * own for non-standard queries.
     *
     * For the AP Clients tab, the filter is a single equals clause:
     *     { op: 'equals', field: 'switch_ip', value: <AP Mgt0 IP> }
     *
     * Wireless clients are registered in PF with the AP's management IP
     * as switch_ip — same call as the existing packetfence widget, just
     * with the AP's IP instead of a switch port IP. No SNMP association
     * walk needed.
     *
     * @param  array<string,mixed> $query  Full POST body for /nodes/search.
     * @return array{ok:bool, data:?array, http_code:int, error:?string}
     */
    public function searchNodes(array $query): array {
        return $this->request(
            path:   '/api/v1/nodes/search',
            method: 'POST',
            body:   $query,
        );
    }

    /**
     * Build a standard /nodes/search request body that ORs an arbitrary
     * list of (op, field, value) clauses. Mirrors the helper from the
     * legacy PfClient verbatim — same default field list — so a node row
     * returned through this builder has the same shape the existing
     * packetfence widget already consumes.
     *
     * For the AP Clients tab, $clauses is typically a single entry:
     *     [['op' => 'equals', 'field' => 'switch_ip', 'value' => $ap_ip]]
     *
     * Default limit raised from 25 to 100 — a busy AP can have 60+
     * associated clients during the school day, where the legacy widget
     * was sized for switch-port-level lookups (one MAC per port).
     *
     * @param  array<int, array{op:string, field:string, value:mixed}> $clauses
     * @return array<string,mixed>
     */
    public static function nodeSearchBody(array $clauses, int $limit = 100): array {
        return [
            'cursor' => 0,
            'limit'  => $limit,
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
                'ip4log.ip', 'ip6log.ip',
            ],
            'query' => [
                'op'     => 'and',
                'values' => [
                    ['op' => 'or', 'values' => $clauses],
                ],
            ],
        ];
    }

    /**
     * Authenticated request with transparent re-auth on 401. All authed
     * callers (searchNodes() now; locationlogSearch() / radiusAuditLog()
     * later in M1) funnel through here so the token-refresh logic lives
     * in exactly one place.
     *
     * Behaviour:
     *   1. If no cached token, login() first.
     *   2. Issue the request.
     *   3. If response is 401, invalidate the cached token, login()
     *      again, and retry the request once.
     *   4. If the second attempt also fails (or the re-auth itself
     *      fails), surface the failure to the caller.
     *
     * @param  array<string,mixed>|null $body
     * @return array{ok:bool, data:?array, http_code:int, error:?string}
     */
    private function request(string $path, string $method, ?array $body = null): array {
        if ($this->token === null) {
            $login = $this->login();
            if (!$login['ok']) {
                return [
                    'ok'        => false,
                    'data'      => null,
                    'http_code' => $login['http_code'],
                    'error'     => 'login failed: ' . ($login['error'] ?? 'unknown'),
                ];
            }
        }

        $result = $this->doRequest($path, $method, $body);

        if ($result['http_code'] === 401) {
            $this->token = null;
            $login = $this->login();
            if (!$login['ok']) {
                // Re-auth itself failed — return the original 401 so the
                // caller sees a consistent shape, with an annotated error.
                return [
                    'ok'        => false,
                    'data'      => null,
                    'http_code' => 401,
                    'error'     => 're-auth failed after 401: ' . ($login['error'] ?? 'unknown'),
                ];
            }
            $result = $this->doRequest($path, $method, $body);
        }

        return $result;
    }

    /**
     * Single curl exchange. No auth-failure handling — that lives in
     * request() which calls this twice on a 401.
     *
     * @param  array<string,mixed>|null $body
     * @return array{ok:bool, data:?array, http_code:int, error:?string}
     */
    private function doRequest(string $path, string $method, ?array $body): array {
        $headers = [
            'Authorization: ' . (string) $this->token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_URL            => $this->base_url . $path,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            CURLOPT_TIMEOUT        => $this->request_timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $resp      = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err_curl  = curl_error($ch);
        curl_close($ch);

        if ($err_curl !== '') {
            return ['ok' => false, 'data' => null, 'http_code' => 0, 'error' => $err_curl];
        }
        if ($http_code < 200 || $http_code >= 300) {
            return [
                'ok'        => false,
                'data'      => null,
                'http_code' => $http_code,
                'error'     => sprintf('HTTP %d: %s', $http_code, substr((string) $resp, 0, 200)),
            ];
        }
        $data = json_decode((string) $resp, true);
        return [
            'ok'        => true,
            'data'      => is_array($data) ? $data : [],
            'http_code' => $http_code,
            'error'     => null,
        ];
    }
}
