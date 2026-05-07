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
 *
 * Current scope (incremental — see AP Dashboard Project Plan v2):
 *   - login()                  — session auth
 *   - searchNodes()            — POST /api/v1/nodes/search (filter
 *                                by mac, NOT switch_ip — see note
 *                                below)
 *   - getActiveClientsForAp()  — orchestrated locationlog + nodes
 *                                merge for the Clients tab; this is
 *                                the operational primitive widget
 *                                code should call
 *   - locationlogSearch()      — POST /api/v1/locationlogs/search +
 *                                bucketed client-count series for the
 *                                Live Telemetry "Clients Connected"
 *                                sparkline (resolves M0 Q6)
 *   - radiusAuditLog()         — POST /api/v1/radius_audit_logs/search
 *                                + grouped failures-by-MAC (Clients
 *                                tab Auth Failures table)
 *   - nodeSearchBody()         — static body builder for nodes/search
 *   - locationlogSearchBody() / bucketClientCounts() — static helpers
 *     for the locationlog flow, exposed for unit testing and reuse by
 *     M3 SSID Broadcast (which consumes the same raw rows).
 *
 * IMPORTANT — PF data model gotcha (project plan v2 misread this):
 * The 'switch_ip' field exists on the locationlog table but NOT on the
 * node table. Filtering nodes/search by switch_ip → HTTP 422
 * "switch_ip is an invalid field". The legacy packetfence/ widget
 * never did that — it queried nodes/search by MAC. For wireless on a
 * specific AP, the correct flow is two calls (locationlog → MAC list,
 * then nodes/search by MAC); getActiveClientsForAp() encapsulates it.
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
 *     // Inside WidgetView::doAction() for the Clients tab:
 *     $pf = new PFClient(
 *         base_url: $this->getInput('pf_url'),
 *         username: $this->getInput('pf_user'),
 *         password: $this->getInput('pf_pass'),
 *     );
 *     $r = $pf->getActiveClientsForAp($ap_mgt0_ip);
 *     $rows = $r['ok'] ? $r['clients'] : [];
 *     // Each row: ['mac' => ..., 'node' => [...], 'session' => [...]]
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
        // curl_close() removed: no-op since PHP 8.0; CurlHandle is GC'd
        // when $ch goes out of scope.

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
     * POST /api/v1/locationlogs/search → bucketed client-count time series.
     *
     * Drives the "Clients Connected" sparkline on the Live Telemetry
     * strip (Overview tab). Resolves M0 Open Question Q6: PF locationlog
     * gives genuine historical client-count shape that XIQ cannot.
     *
     * Filter: switch_ip = $switchIp AND start_time >= $from. PF datetimes
     * are written as 'Y-m-d H:i:s' in PF's local TZ — passed Unix
     * timestamps are formatted with date() so the comparison is apples
     * to apples on the PF server side. (If PF and the Zabbix host run
     * in different TZs, that's a deployment problem, not a query bug.)
     *
     * Active session sentinel: PF writes end_time = '0000-00-00 00:00:00'
     * for sessions that haven't closed yet (NOT NULL — that's a PF
     * gotcha). bucketClientCounts() handles the sentinel; raw caller
     * code that introspects $sessions must too.
     *
     * Result:
     *   - 'series'   — [{ts:int, count:int}] bucketed sparkline data,
     *                  one entry per bucket, ts at bucket midpoint
     *   - 'sessions' — raw locationlog rows (mac, ssid, start_time,
     *                  end_time, role) for callers that want to do
     *                  their own grouping (e.g. M3 SSID Broadcast)
     *
     * Cached for 60 seconds in APCu, keyed by base_url+username+filter
     * args. The cache is a no-op if APCu isn't loaded — for a 60-second
     * window, falling back to filesystem caching costs more than the
     * refetch it would save.
     *
     * Pagination: PF caps /locationlogs/search at 1000 rows per call.
     * Per the M0 capacity math, 1000 rows is far above the realistic
     * ceiling for a single AP across an 8h window (a busy school AP
     * tops out ~80 unique clients/day). If the response carries
     * nextCursor, that's surfaced via 'truncated' so a caller can warn
     * but the call is not retried — TODO if a real deployment hits it.
     *
     * @param  string $switchIp  AP Mgt0 IP, e.g. '172.16.97.59'
     * @param  int    $from      Unix ts — window start (inclusive)
     * @param  int    $to        Unix ts — window end   (exclusive)
     * @param  int    $buckets   Bucket count (default 24); caller picks
     *                           a value matching the _timeperiod span
     * @return array{
     *     ok:bool, http_code:int, error:?string,
     *     series:array<int, array{ts:int, count:int}>,
     *     sessions:array<int, array<string,mixed>>,
     *     truncated:bool
     * }
     */
    public function locationlogSearch(string $switchIp, int $from, int $to, int $buckets = 24): array {
        $empty = [
            'ok'        => false,
            'http_code' => 0,
            'error'     => null,
            'series'    => [],
            'sessions'  => [],
            'truncated' => false,
        ];

        if ($from >= $to) {
            return ['error' => 'invalid window: from >= to'] + $empty;
        }
        if ($buckets < 1) {
            return ['error' => 'invalid bucket count'] + $empty;
        }

        $cache_key = $this->cacheKey('locationlog', $switchIp, (string) $from, (string) $to, (string) $buckets);
        $cached = $this->cacheGet($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        $body = self::locationlogSearchBody($switchIp, $from);
        $r    = $this->request(path: '/api/v1/locationlogs/search', method: 'POST', body: $body);

        if (!$r['ok']) {
            // Don't cache failures — let the next render try again.
            return [
                'ok'        => false,
                'http_code' => $r['http_code'],
                'error'     => $r['error'],
                'series'    => [],
                'sessions'  => [],
                'truncated' => false,
            ];
        }

        $sessions  = is_array($r['data']['items'] ?? null) ? $r['data']['items'] : [];
        $truncated = !empty($r['data']['nextCursor']);
        $series    = self::bucketClientCounts($sessions, $from, $to, $buckets);

        $result = [
            'ok'        => true,
            'http_code' => $r['http_code'],
            'error'     => null,
            'series'    => $series,
            'sessions'  => $sessions,
            'truncated' => $truncated,
        ];

        $this->cacheSet($cache_key, $result, 60);
        return $result;
    }

    /**
     * Build a /locationlogs/search request body filtered to one AP +
     * a start-time floor. Field list is kept minimal (mac, ssid,
     * start_time, end_time, role) per the task spec — adding fields
     * here costs PF query time even if the caller doesn't read them.
     *
     * Sort 'start_time ASC' so the bucket loop walks sessions in
     * chronological order; not strictly required by the algorithm
     * (it's order-independent), but it makes the raw $sessions
     * payload easier for downstream callers to reason about.
     *
     * Operator note: PF 15 rejects 'greater_than_or_equals' (the M0
     * doc had this wrong — confirmed live by HTTP 422 "op is not
     * valid"). The valid operator is 'greater_than'. To preserve
     * inclusive boundary semantics, we subtract 1 second from $from
     * before formatting — sessions that started in the same second
     * as the window edge still appear in the result.
     *
     * @return array<string,mixed>
     */
    public static function locationlogSearchBody(string $switchIp, int $from): array {
        return [
            'cursor' => 0,
            'limit'  => 1000,
            'sort'   => ['start_time ASC'],
            'fields' => ['mac', 'ssid', 'start_time', 'end_time', 'role'],
            'query'  => [
                'op'     => 'and',
                'values' => [
                    [
                        'op'    => 'equals',
                        'field' => 'switch_ip',
                        'value' => $switchIp,
                    ],
                    [
                        'op'    => 'greater_than',
                        'field' => 'start_time',
                        // -1s compensates for losing the inclusive
                        // boundary when switching from '>=' to '>'.
                        'value' => date('Y-m-d H:i:s', $from - 1),
                    ],
                ],
            ],
        ];
    }

    /**
     * Bucket a list of locationlog session rows into a client-count
     * time series. Counts DISTINCT MACs with sessions active at each
     * bucket midpoint — a roaming client whose two sessions overlap
     * counts once, not twice.
     *
     * Session active at time T iff:
     *     start_time <= T  AND  (end_time = open-sentinel OR end_time >= T)
     *
     * Open sessions ('0000-00-00 00:00:00') are treated as active up
     * to time() — not PHP_INT_MAX. If the caller passes a $to that's
     * slightly in the future (e.g. rounded to the next minute), open
     * sessions correctly stop being counted past now() rather than
     * continuing forever into a future we don't have data for yet.
     *
     * O(N × B) — for typical AP traffic (N≈100 sessions, B=24 buckets)
     * that's 2400 comparisons, well inside one widget render budget.
     * Public + static so it can be unit-tested in isolation and reused
     * by other callers needing the same bucket math against
     * session-shaped data.
     *
     * @param  array<int, array<string,mixed>> $sessions  Raw locationlog rows.
     * @param  int $from     Unix ts — window start (inclusive).
     * @param  int $to       Unix ts — window end   (exclusive).
     * @param  int $buckets  Number of bucket points to emit.
     * @return array<int, array{ts:int, count:int}>
     */
    public static function bucketClientCounts(array $sessions, int $from, int $to, int $buckets = 24): array {
        if ($buckets < 1 || $from >= $to) {
            return [];
        }
        $step = intdiv($to - $from, $buckets);
        if ($step < 1) {
            // Window too narrow for the requested resolution. Caller
            // should pass fewer buckets; returning [] is the honest
            // answer rather than producing identical adjacent points.
            return [];
        }

        $now = time();

        // Pre-parse so strtotime() runs once per session, not once per
        // (session × bucket).
        $parsed = [];
        foreach ($sessions as $s) {
            $start_raw = (string) ($s['start_time'] ?? '');
            if ($start_raw === '') {
                continue;
            }
            $start = strtotime($start_raw);
            if ($start === false) {
                continue;
            }

            $end_raw = (string) ($s['end_time'] ?? '');
            $end = match (true) {
                $end_raw === ''                       => $now,
                $end_raw === '0000-00-00 00:00:00'    => $now,
                default                               => (strtotime($end_raw) ?: $now),
            };

            $parsed[] = [
                'mac'   => strtolower((string) ($s['mac'] ?? '')),
                'start' => $start,
                'end'   => $end,
            ];
        }

        $series = [];
        for ($i = 0; $i < $buckets; $i++) {
            $t = $from + ($i * $step) + intdiv($step, 2);

            // Distinct MACs only — multiple overlapping sessions for
            // the same MAC count once. (This is why the M0 sketch's
            // session-count loop differs from this client-count loop.)
            $macs = [];
            foreach ($parsed as $p) {
                if ($p['start'] <= $t && $p['end'] >= $t) {
                    $macs[$p['mac']] = true;
                }
            }
            $series[] = ['ts' => $t, 'count' => count($macs)];
        }
        return $series;
    }

    /**
     * Get all currently-active wireless clients on a single AP, enriched
     * with PF node-table fields. This is the Clients-tab data primitive.
     *
     * The PF data model does NOT expose switch_ip on the node table —
     * that field lives on locationlog only. So a single nodes/search
     * filtered by switch_ip would 422. The correct pattern is two
     * calls:
     *
     *     1. locationlog filtered by switch_ip + open-session sentinel
     *          → list of currently-connected MACs on this AP
     *     2. nodes/search filtered by mac IN [list]
     *          → enriched device records
     *
     * Result rows merge node fields with the originating session row,
     * so callers get both registration data (vendor, OS, role) and
     * live session data (start_time, ssid, vlan) in one shape:
     *
     *     [
     *       {
     *         mac:     '...',
     *         node:    { ...nodes/search row... },
     *         session: { mac, ssid, start_time, end_time, role }
     *       },
     *       ...
     *     ]
     *
     * Window: locationlog query covers the last 24h. That's wide
     * enough to capture even long-running sessions in school context
     * (devices reboot daily) and narrow enough to keep response size
     * bounded. Only sessions with end_time = '0000-00-00 00:00:00'
     * (the open sentinel) survive into the result.
     *
     * @return array{
     *     ok:bool, http_code:int, error:?string,
     *     clients:array<int, array{mac:string, node:array, session:array}>
     * }
     */
    public function getActiveClientsForAp(string $apIp): array {
        // Step 1: pull recent locationlog rows for this AP, then keep
        // only those still open. We deliberately DO NOT post-filter in
        // PHP for "from" — the 24h window is the inclusive bound, and
        // any session that started earlier and is still open will not
        // appear here. In school context, that's a non-event (devices
        // get rebooted/closed nightly). If a long-IoT-session edge case
        // shows up in production, widen the window here.
        $loc = $this->locationlogSearch(
            switchIp: $apIp,
            from:     time() - 24 * 3600,
            to:       time(),
            buckets:  1, // bucket series unused; pass min valid value
        );
        if (!$loc['ok']) {
            return [
                'ok'        => false,
                'http_code' => $loc['http_code'],
                'error'     => 'locationlog: ' . ($loc['error'] ?? 'unknown'),
                'clients'   => [],
            ];
        }

        // Dedupe by lowercased MAC. If the same client has two open
        // session rows (rare — usually means a missed Acct-Stop), the
        // most recent wins because locationlog is sorted ASC and we
        // overwrite as we walk.
        $sessions_by_mac = [];
        foreach ($loc['sessions'] as $s) {
            if (($s['end_time'] ?? '') !== '0000-00-00 00:00:00') {
                continue;
            }
            $mac = strtolower((string) ($s['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }
            $sessions_by_mac[$mac] = $s;
        }

        if ($sessions_by_mac === []) {
            // No active clients — legitimate (idle AP, after-hours).
            // Return success with empty list so callers can render
            // "0 clients connected" instead of an error state.
            return [
                'ok'        => true,
                'http_code' => 200,
                'error'     => null,
                'clients'   => [],
            ];
        }

        // Step 2: enrich those MACs via nodes/search. Use the existing
        // OR-of-equals body builder — known to work because the legacy
        // packetfence widget uses the same pattern. PF does support an
        // 'in' operator but its handling varies across versions, so OR
        // of equals is the conservative choice.
        $mac_list = array_keys($sessions_by_mac);
        $clauses  = array_map(
            fn(string $mac) => ['op' => 'equals', 'field' => 'mac', 'value' => $mac],
            $mac_list,
        );
        // Limit must be at least clause count — every clause potentially
        // returns one row.
        $body = self::nodeSearchBody($clauses, max(count($clauses), 100));
        $r    = $this->searchNodes($body);

        if (!$r['ok']) {
            return [
                'ok'        => false,
                'http_code' => $r['http_code'],
                'error'     => 'nodes/search: ' . ($r['error'] ?? 'unknown'),
                'clients'   => [],
            ];
        }

        // Step 3: merge. Index node rows by lowercased MAC, then
        // produce one client row per active session. A MAC in
        // locationlog with no node-table entry still yields a row
        // (registration-less device — possibly autoreg) with node = [].
        $nodes_by_mac = [];
        foreach (($r['data']['items'] ?? []) as $node) {
            $mac = strtolower((string) ($node['mac'] ?? ''));
            if ($mac !== '') {
                $nodes_by_mac[$mac] = $node;
            }
        }

        $clients = [];
        foreach ($sessions_by_mac as $mac => $session) {
            $clients[] = [
                'mac'     => $mac,
                'node'    => $nodes_by_mac[$mac] ?? [],
                'session' => $session,
            ];
        }

        return [
            'ok'        => true,
            'http_code' => 200,
            'error'     => null,
            'clients'   => $clients,
        ];
    }

    /**
     * POST /api/v1/radius_audit_logs/search → grouped auth-failure rows.
     *
     * Drives the Auth Failures table on the Clients tab. Returns
     * recent RADIUS Access-Reject events for this AP, collapsed by
     * client MAC so the table shows unique offenders with an
     * attempt counter — operationally what's useful is "iPad X
     * failed 14 times in the last hour", not 14 individual rows
     * for the same iPad.
     *
     * Filters (POST body, AND of two clauses):
     *   - nas_ip_address starts_with <AP IP>
     *     (the AP itself is the NAS in 802.1X — filtering on its IP
     *     is more reliable than parsing the BSSID-prefixed
     *     called_station_id, which varies by vendor format. Uses
     *     starts_with rather than equals because PF may append a
     *     port or interface suffix in some configurations.)
     *   - auth_status equals Reject
     *
     * History (live-verified findings against PF 15, 2026-05-06):
     *   - GET ?filter=… → HTTP 400 "invalid path 'filter'".
     *     Switched to POST /search, matching the other PF endpoints.
     *   - PF returns HTTP 404 with body "entries not found" when a
     *     valid query matches zero rows. We translate that to an
     *     empty success.
     *   - Initial design filtered by called_station_id; switched to
     *     nas_ip_address because that's vendor-independent and uses
     *     the same identifier (AP Mgt0 IP) as the rest of the
     *     dashboard.
     *
     * Each result row:
     *   - timestamp      most recent failure for this MAC
     *   - mac            client MAC, lowercased
     *   - ssid           from the dedicated `ssid` column. Falls
     *                    back to parsing called_station_id if the
     *                    column is empty (defensive — shouldn't fire
     *                    in normal PF 15 operation).
     *   - reason         from the `reason` column. Falls through to
     *                    radius_reply → auth_status only when reason
     *                    is empty.
     *   - user_name      stripped_user_name preferred (display-
     *                    friendly, no realm). Falls back to raw
     *                    user_name when stripped is empty.
     *   - attempt_count  rejects in the result set for this MAC
     *
     * No caching (per task spec) — Auth Failures table refreshes
     * live with the dashboard, and stale auth data would mislead an
     * operator triaging a current outage.
     *
     * @param  string $apIp   AP Mgt0 IP, e.g. '172.16.97.59'. Same
     *                        identifier used to filter locationlog,
     *                        which keeps the dashboard's AP-scoping
     *                        consistent across all PF data sources.
     * @param  int    $limit  Max raw audit rows pre-grouping. 100
     *                        covers a busy AP for ~1 hour of bad
     *                        802.1X retries.
     * @return array{
     *     ok:bool, http_code:int, error:?string,
     *     failures:array<int, array{
     *         timestamp:string, mac:string, ssid:string,
     *         reason:string, user_name:string, attempt_count:int
     *     }>
     * }
     */
    public function radiusAuditLog(string $apIp, int $limit = 100): array {
        $limit = max(1, min($limit, 1000));

        $body = [
            'cursor' => 0,
            'limit'  => $limit,
            'sort'   => ['created_at DESC'],
            // Field list per PF 15 schema (live-verified). called_station_id
            // kept in the projection only as fallback fuel for the SSID
            // parser; it's not displayed.
            'fields' => [
                'mac', 'called_station_id', 'ssid',
                'user_name', 'stripped_user_name',
                'auth_status', 'reason', 'radius_reply',
                'created_at',
            ],
            'query' => [
                'op' => 'and',
                'values' => [
                    [
                        'op'    => 'starts_with',
                        'field' => 'nas_ip_address',
                        'value' => $apIp,
                    ],
                    [
                        'op'    => 'equals',
                        'field' => 'auth_status',
                        'value' => 'Reject',
                    ],
                ],
            ],
        ];

        $r = $this->request(
            path:   '/api/v1/radius_audit_logs/search',
            method: 'POST',
            body:   $body,
        );

        if (!$r['ok']) {
            // PF idiosyncrasy: radius_audit_logs returns HTTP 404 with
            // body {"errors":[{"message":"entries not found"}]} when a
            // valid query matches zero rows. Other PF endpoints
            // (locationlogs, nodes) return 200 + empty items[] for the
            // same situation. We treat this 404 as an empty success so
            // the dashboard doesn't show a fake error state during the
            // common case (AP with no recent auth failures).
            $is_empty_404 = $r['http_code'] === 404
                && is_string($r['error'])
                && str_contains($r['error'], 'entries not found');
            if ($is_empty_404) {
                return [
                    'ok'        => true,
                    'http_code' => 404, // preserved so callers can log/observe
                    'error'     => null,
                    'failures'  => [],
                ];
            }
            return [
                'ok'        => false,
                'http_code' => $r['http_code'],
                'error'     => $r['error'],
                'failures'  => [],
            ];
        }

        $rows     = is_array($r['data']['items'] ?? null) ? $r['data']['items'] : [];
        $failures = self::groupAuthFailures($rows);

        return [
            'ok'        => true,
            'http_code' => $r['http_code'],
            'error'     => null,
            'failures'  => $failures,
        ];
    }

    /**
     * Collapse raw audit rows into one row per unique client MAC
     * with an attempt counter. Assumes input is sorted DESC by
     * created_at — the first occurrence of each MAC is therefore
     * the most recent failure, and we keep its timestamp / SSID /
     * reason as representative values.
     *
     * Field projections per the PF 15 schema (live-verified against
     * BHS-56-Hallway 2026-05-06):
     *
     *   - ssid:      use the dedicated `ssid` column. Only fall back
     *                to parsing called_station_id if the column is
     *                empty (shouldn't happen, but defensive).
     *   - reason:    `reason` is the canonical column. Fall through
     *                to radius_reply → auth_status only when reason
     *                is empty (older PF builds, or row types where
     *                reason isn't populated).
     *   - user:      prefer stripped_user_name (display-friendly,
     *                no realm) over user_name (raw RADIUS attribute).
     *
     * Private static: the grouping logic is intrinsic to the auth
     * failures use case and not reusable elsewhere — unlike
     * bucketClientCounts() which generalises to any session-shaped
     * data.
     *
     * @param  array<int, array<string,mixed>> $rows
     * @return array<int, array{
     *     timestamp:string, mac:string, ssid:string,
     *     reason:string, user_name:string, attempt_count:int
     * }>
     */
    private static function groupAuthFailures(array $rows): array {
        $by_mac = [];
        foreach ($rows as $row) {
            $mac = strtolower((string) ($row['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }

            if (isset($by_mac[$mac])) {
                $by_mac[$mac]['attempt_count']++;
                continue;
            }

            // SSID — dedicated column first, parser fallback.
            $ssid = (string) ($row['ssid'] ?? '');
            if ($ssid === '') {
                $ssid = self::parseSsidFromCalledStationId(
                    (string) ($row['called_station_id'] ?? '')
                );
            }

            // Reason — `reason` is the real field; the others cover
            // the rare cases where it's not populated.
            $reason = (string) ($row['reason'] ?? '');
            if ($reason === '') {
                $reason = (string) ($row['radius_reply'] ?? $row['auth_status'] ?? '');
            }

            // User — stripped_user_name is display-friendly when set.
            $user = (string) ($row['stripped_user_name'] ?? '');
            if ($user === '') {
                $user = (string) ($row['user_name'] ?? '');
            }

            $by_mac[$mac] = [
                'timestamp'     => (string) ($row['created_at'] ?? ''),
                'mac'           => $mac,
                'ssid'          => $ssid,
                'reason'        => $reason,
                'user_name'     => $user,
                'attempt_count' => 1,
            ];
        }
        return array_values($by_mac);
    }

    /**
     * Pull the SSID out of a wireless RADIUS Called-Station-Id.
     *
     * Format from APs running 802.11: <BSSID>:<SSID>, where BSSID is
     * the radio MAC as 6 colon-separated octets. So everything after
     * the 6th colon is the SSID. Using implode(':') on the slice
     * preserves SSIDs that legitimately contain colons (rare but
     * valid). Returns '' for non-wireless rows or unrecognized format.
     */
    private static function parseSsidFromCalledStationId(string $cs_id): string {
        if ($cs_id === '') {
            return '';
        }
        $parts = explode(':', $cs_id);
        return count($parts) > 6 ? implode(':', array_slice($parts, 6)) : '';
    }

    // ── Cache helpers ──────────────────────────────────────────────────
    //
    // Thin APCu wrapper. Silently no-ops when APCu isn't loaded or is
    // disabled — the project envelope says "APCu preferred, filesystem
    // fallback" but that's specifically for the long-lived XIQ OAuth
    // token. For 60-second locationlog caches, filesystem I/O costs
    // more than the refetch it would save, so just skip caching in
    // that case.

    /**
     * Build a stable cache key including base_url + username so that
     * two PFClient instances pointing at different PF servers, or
     * different webservices accounts on the same server, cannot
     * collide in the shared APCu store.
     */
    private function cacheKey(string ...$parts): string {
        $material = array_merge(
            ['pf_pfclient_v1', $this->base_url, $this->username],
            $parts,
        );
        return 'pf:' . md5(implode('|', $material));
    }

    private static function apcuAvailable(): bool {
        return function_exists('apcu_fetch')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    private function cacheGet(string $key): ?array {
        if (!self::apcuAvailable()) {
            return null;
        }
        $hit = apcu_fetch($key, $ok);
        return $ok && is_array($hit) ? $hit : null;
    }

    private function cacheSet(string $key, array $value, int $ttl): void {
        if (!self::apcuAvailable()) {
            return;
        }
        apcu_store($key, $value, $ttl);
    }

    /**
     * Authenticated request with transparent re-auth on 401. All
     * authed callers (searchNodes, locationlogSearch, radiusAuditLog)
     * funnel through here so the token-refresh logic lives in
     * exactly one place.
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
        // curl_close() removed: no-op since PHP 8.0.

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
