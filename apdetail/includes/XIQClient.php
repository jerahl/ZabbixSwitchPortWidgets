<?php

declare(strict_types=1);

namespace Modules\APDetail;

/**
 * XIQClient — ExtremeCloud IQ API HTTP client.
 *
 * ─── AUTH STRATEGY ───────────────────────────────────────────────────────────
 *
 * Two factory constructors are provided. Use whichever matches your credential
 * type:
 *
 *   XIQClient::fromToken($token)
 *       Pass a permanent API token from {$XIQ_API_TOKEN} Zabbix global macro.
 *       Tokens are issued once via XIQ Administration → API Access Tokens and
 *       do not expire on a schedule.  On 401 the client surfaces an error
 *       (token was revoked) — it does NOT attempt re-auth.
 *       *** This is the RECOMMENDED mode for the widget. ***
 *
 *   XIQClient::fromCredentials($username, $password)
 *       Uses POST /login → short-lived JWT (~2 h).  Token is cached in APCu
 *       (with filesystem fallback) and refreshed automatically on 401.
 *       Use only if you cannot issue a permanent API token.
 *
 * ─── CACHE STRATEGY ──────────────────────────────────────────────────────────
 *
 * APCu is the primary cache.  Because APCu is per-PHP-FPM-worker process, each
 * worker primes its own segment independently — up to 10× your expected XIQ
 * call count until all workers are warm.  This is expected behaviour; it does
 * not indicate a bug.  The 7,500 req/hr quota with typical 10-worker + 10-
 * session deployments is still within budget, but monitor RateLimit-Remaining
 * in production.
 *
 * Filesystem fallback (/tmp/zabbix_xiq_cache/) activates when APCu is absent.
 * Files are JSON, named by cache key hash, with embedded expiry timestamps.
 * The directory is created on first write with 0700 permissions (www-data).
 *
 * ─── RATE LIMITS ─────────────────────────────────────────────────────────────
 *
 * XIQ enforces 7,500 requests/hour per VIQ (customer account), shared across
 * ALL integrations (Zabbix, SolarWinds NPM, etc.).  Response headers:
 *   RateLimit-Limit: 7500;w=3600
 *   RateLimit-Remaining: N
 *   RateLimit-Reset: S  (seconds until window resets)
 *
 * Every response updates $this->rateLimitRemaining.  The widget view should
 * call getRateLimitRemaining() and surface a warning banner if < 500.
 *
 * HTTP 429 throws XIQRateLimitException — the widget view should display a
 * "quota exceeded" state and skip all further XIQ calls for that page load.
 *
 * ─── M0 CORRECTIONS BAKED IN ─────────────────────────────────────────────────
 *
 * G3  mac_address on /devices/{id} is the wireless BASE MAC — not eth0.
 *     Use macInsertColons() to normalise for display.  PF switch_mac filter
 *     uses the eth0 (Zabbix SNMP) MAC, not this one.
 *
 * G4  /clients/active filter param is deviceIds (camelCase plural).
 *     Sending device_id silently returns the fleet — no error from XIQ.
 *
 * G5  /clients/active default view emits only 12 fields.  Always append
 *     views=FULL to get rssi, snr, channel, connection_duration, locations[].
 *
 * G6  /devices/{id}/wifi-if-stats requires startTime + endTime even though
 *     the OpenAPI spec marks them optional.  A window < 10 min returns [].
 *     Use a 15-minute trailing window for "current" radio values.
 *
 * G7  /devices/{id}/ssid/status returns a map keyed by SSID id (int), not
 *     name.  Join against getPolicySsids() to resolve name + security.
 *
 * G8  /d360/wireless/interfaces-graph channel param is a radio selector
 *     (WIFI0 / WIFI1 / WIFI2), not an 802.11 channel number.
 *
 * ─── DEPENDENCIES ────────────────────────────────────────────────────────────
 *
 * None — PHP curl only.  No Composer, no Guzzle, no Symfony HTTP client.
 * PHP 8.1+ required (named args, match(), readonly, enum-style constants).
 *
 * @package Modules\APDetail
 */
final class XIQClient
{
    // ── Constants ────────────────────────────────────────────────────────────

    public const BASE_URL = 'https://api.extremecloudiq.com';

    /** APCu / filesystem cache TTLs (seconds). */
    private const TTL_TOKEN    = 6600;   // login JWT ~2h; refresh at 110 min
    private const TTL_DEVICE   = 60;
    private const TTL_POLICY   = 300;
    private const TTL_SSID     = 300;
    private const TTL_LOCATION = 600;
    private const TTL_XIQ_ID   = 3600;

    /** Warn the widget view when remaining quota drops below this. */
    public const RATE_LIMIT_WARN_THRESHOLD = 500;

    /** WiFi stats window in seconds — must be ≥ 10 min or XIQ returns []. */
    private const WIFI_STATS_WINDOW_SEC = 900;  // 15 minutes (G6)

    /** Filesystem cache directory (APCu fallback). */
    private const FS_CACHE_DIR = '/tmp/zabbix_xiq_cache';

    // ── State ────────────────────────────────────────────────────────────────

    private readonly string  $baseUrl;
    private readonly ?string $staticToken;   // fromToken() path — never refreshed
    private readonly ?string $username;      // fromCredentials() path
    private readonly ?string $password;      // fromCredentials() path

    /** Last observed RateLimit-Remaining value across all calls this instance. */
    private int $rateLimitRemaining = PHP_INT_MAX;

    /** Last observed RateLimit-Reset (seconds to window reset). */
    private int $rateLimitReset = 0;

    // ── Constructors ─────────────────────────────────────────────────────────

    private function __construct(
        ?string $staticToken,
        ?string $username,
        ?string $password,
        string  $baseUrl,
    ) {
        $this->staticToken = $staticToken;
        $this->username    = $username;
        $this->password    = $password;
        $this->baseUrl     = rtrim($baseUrl, '/');
    }

    /**
     * Create a client using a permanent API token.
     *
     * Generate once via XIQ Administration → API Access Tokens.
     * Store in Zabbix global macro {$XIQ_API_TOKEN} (Secret text type).
     * Retrieve in WidgetView.php:
     *   $token = CApiInputValidator::... or pass via widget config field.
     *
     * On 401 this client throws XIQAuthException — the token was revoked.
     * Re-issue via the XIQ UI.
     */
    public static function fromToken(
        string $token,
        string $baseUrl = self::BASE_URL,
    ): self {
        return new self(
            staticToken: $token,
            username:    null,
            password:    null,
            baseUrl:     $baseUrl,
        );
    }

    /**
     * Create a client using XIQ username + password.
     *
     * Issues POST /login on first call (or after token expiry/401) and caches
     * the resulting JWT in APCu (filesystem fallback).  Tokens live ~2 h;
     * this client proactively treats any token older than TTL_TOKEN as expired.
     *
     * Credentials should come from Zabbix global macros {$XIQ_USERNAME} /
     * {$XIQ_PASSWORD} (Secret text type), never hardcoded.
     */
    public static function fromCredentials(
        string $username,
        string $password,
        string $baseUrl = self::BASE_URL,
    ): self {
        return new self(
            staticToken: null,
            username:    $username,
            password:    $password,
            baseUrl:     $baseUrl,
        );
    }

    // ── Rate-limit accessors ─────────────────────────────────────────────────

    /** Return the last observed RateLimit-Remaining value. */
    public function getRateLimitRemaining(): int
    {
        return $this->rateLimitRemaining;
    }

    /** Return the last observed RateLimit-Reset value (seconds). */
    public function getRateLimitReset(): int
    {
        return $this->rateLimitReset;
    }

    /** True when remaining quota is below the warning threshold. */
    public function isRateLimitLow(): bool
    {
        return $this->rateLimitRemaining < self::RATE_LIMIT_WARN_THRESHOLD;
    }

    // ── Static helpers ───────────────────────────────────────────────────────

    /**
     * Insert colons into a colon-less MAC address string.
     *
     * XIQ /devices/{id}.mac_address returns "BCF31004C054" (no delimiters).
     * This is the wireless BASE MAC — not the eth0 management MAC (G3).
     *
     * @param  string $mac  12-char hex string, uppercase or lower.
     * @return string       "BC:F3:10:04:C0:54"
     */
    public static function macInsertColons(string $mac): string
    {
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
        if (strlen($clean) !== 12) {
            return $mac; // not a valid MAC — return as-is
        }
        return implode(':', str_split(strtoupper($clean), 2));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PUBLIC API METHODS
    // ═════════════════════════════════════════════════════════════════════════

    // ── Device identity ──────────────────────────────────────────────────────

    /**
     * Fetch full device metadata for one XIQ device.
     *
     * Cached 60 s.  Returns the raw decoded JSON object as an associative
     * array.  Key fields used by the widget:
     *   id, hostname, mac_address (wireless base MAC — see macInsertColons()),
     *   serial_number, product_type, software_version, device_admin_state,
     *   connected, config_mismatch, system_up_time (epoch ms).
     *
     * The XIQ device ID is stored as a Zabbix host macro {$XIQ_DEVICE_ID} by
     * the fleet discovery template — no lookup required per widget load.
     *
     * @param  int   $xiqDeviceId  XIQ internal device ID (from {$XIQ_DEVICE_ID}).
     * @return array<string,mixed>
     * @throws XIQException
     */
    public function getDevice(int $xiqDeviceId): array
    {
        $cacheKey = "xiq:device:{$xiqDeviceId}";

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->httpGet("/devices/{$xiqDeviceId}");

        $this->cacheSet($cacheKey, $data, self::TTL_DEVICE);

        return $data;
    }

    /**
     * Fetch the network policy currently assigned to a device.
     *
     * Endpoint: GET /devices/{id}/network-policy
     * Cached 300 s — policy assignments change only on config push.
     *
     * Key fields: id (policy ID), name.
     * Pass the returned id to getPolicySsids() to enumerate SSIDs.
     *
     * @param  int   $xiqDeviceId
     * @return array<string,mixed>
     * @throws XIQException
     */
    public function getDeviceNetworkPolicy(int $xiqDeviceId): array
    {
        $cacheKey = "xiq:devpolicy:{$xiqDeviceId}";

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->httpGet("/devices/{$xiqDeviceId}/network-policy");

        $this->cacheSet($cacheKey, $data, self::TTL_POLICY);

        return $data;
    }

    /**
     * Fetch all SSIDs for a network policy, fanned out in parallel.
     *
     * Endpoint: GET /network-policies/{policyId}/ssids → array of SSID objects
     * then GET /ssids/{id} per entry.
     *
     * The first call returns a lightweight list (id + name).  Each SSID detail
     * call returns: vlan, auth_type (WPA2-Enterprise / Open / etc.), encryption,
     * band_steering, enabled_bands.  Fan-out uses curl_multi for concurrency.
     *
     * Each SSID detail response is cached individually under xiq:ssid:{id}
     * (300 s).  The policy SSID list is cached under xiq:policylist:{policyId}
     * (300 s).
     *
     * Returns an array of enriched SSID objects (list fields merged with detail
     * fields).
     *
     * NOTE (G7): /devices/{id}/ssid/status returns a map keyed by SSID id.
     * JOIN the returned 'id' fields against that map to get per-SSID admin state.
     *
     * @param  int  $policyId  From getDeviceNetworkPolicy()['id'].
     * @return array<int,array<string,mixed>>
     * @throws XIQException
     */
    public function getPolicySsids(int $policyId): array
    {
        $listKey = "xiq:policylist:{$policyId}";

        $cached = $this->cacheGet($listKey);
        if ($cached !== null) {
            return $cached;
        }

        // Step 1 — get SSID id list for the policy
        $list = $this->httpGet("/network-policies/{$policyId}/ssids");
        $ssidList = $list['data'] ?? $list; // unwrap pagination envelope if present

        if (empty($ssidList)) {
            return [];
        }

        // Step 2 — fetch per-SSID detail, serving from cache where possible
        $toFetch = [];   // ssid id => true
        $results = [];

        foreach ($ssidList as $entry) {
            $ssidId = (int)($entry['id'] ?? 0);
            if ($ssidId === 0) {
                continue;
            }
            $ssidKey = "xiq:ssid:{$ssidId}";
            $detail  = $this->cacheGet($ssidKey);
            if ($detail !== null) {
                $results[$ssidId] = array_merge($entry, $detail);
            } else {
                $toFetch[$ssidId] = $entry;
            }
        }

        // Step 3 — parallel fetch for cache-miss SSIDs
        if (!empty($toFetch)) {
            $fetched = $this->httpGetMulti(
                paths: array_map(
                    fn(int $id): string => "/ssids/{$id}",
                    array_keys($toFetch),
                ),
                params: [],
            );

            foreach ($fetched as $index => $detail) {
                $ssidId  = array_keys($toFetch)[$index];
                $ssidKey = "xiq:ssid:{$ssidId}";
                $this->cacheSet($ssidKey, $detail, self::TTL_SSID);
                $results[$ssidId] = array_merge($toFetch[$ssidId], $detail);
            }
        }

        // Return as a flat array (not keyed by SSID id) for template iteration
        $flat = array_values($results);

        $this->cacheSet($listKey, $flat, self::TTL_POLICY);

        return $flat;
    }

    /**
     * Fetch per-SSID admin state for a device.
     *
     * Endpoint: GET /devices/{id}/ssid/status
     *
     * Returns a map keyed by SSID id (string).  Each value has at minimum:
     *   enabled (bool), admin_state (string).
     *
     * G7: JOIN this map against getPolicySsids() results using SSID id.
     *
     * Not cached — SSID enable/disable state can change without a config push.
     *
     * @param  int  $xiqDeviceId
     * @return array<string,array<string,mixed>>  Keyed by SSID id string.
     * @throws XIQException
     */
    public function getSsidStatus(int $xiqDeviceId): array
    {
        $data = $this->httpGet("/devices/{$xiqDeviceId}/ssid/status");
        // Response is a map — return directly
        return is_array($data) ? $data : [];
    }

    // ── Radio / wireless stats ───────────────────────────────────────────────

    /**
     * Fetch per-radio WiFi interface stats for one device.
     *
     * Endpoint: GET /devices/{id}/wifi-if-stats
     *
     * G6: startTime + endTime are REQUIRED even though the spec marks them
     * optional.  A window < ~10 min returns [].  This method always uses a
     * 15-minute trailing window to guarantee data.
     *
     * Returns an array of radio stat objects.  Confirmed fields (M0 v5):
     *   radio_index (int), frequency_band (string or MHz int), channel (int),
     *   channel_width (int MHz or string), tx_power → field name 'power' (int dBm),
     *   channel_utilization → 'total_utilization' (int %),
     *   noise_floor (int dBm, negative), client_count (int),
     *   ssid_count (int), mac_address (BSSID, no colons),
     *   radio_profile_name (string), tx_retry_frame (int),
     *   tx_byte_count / rx_byte_count (int), scan_avg_interference (int).
     *
     * Not cached — called per page load for live telemetry.
     *
     * @param  int  $xiqDeviceId
     * @return array<int,array<string,mixed>>
     * @throws XIQException
     */
    public function getWifiStats(int $xiqDeviceId): array
    {
        $now       = time();
        $startTime = ($now - self::WIFI_STATS_WINDOW_SEC) * 1000; // epoch ms
        $endTime   = $now * 1000;

        $data = $this->httpGet(
            path:   "/devices/{$xiqDeviceId}/wifi-if-stats",
            params: [
                'startTime' => $startTime,
                'endTime'   => $endTime,
            ],
        );

        // Response may be wrapped in a 'data' envelope or be a bare array
        return $data['data'] ?? (array_is_list($data) ? $data : []);
    }

    /**
     * Fetch per-radio time-series data for Live Telemetry sparklines.
     *
     * Endpoint: GET /d360/wireless/interfaces-graph
     *
     * G8: The 'channel' parameter is a RADIO SELECTOR (WIFI0 / WIFI1 / WIFI2),
     * not an 802.11 channel number.  One call per radio.
     *
     * Optional: source=CHANNEL_UTILIZATION|CONNECTED_CLIENTS (default = both).
     * When omitted, each sample includes both keys.
     *
     * Returns an array of {timestamp, channel_utilization, connected_clients}
     * sample objects.  Bucket interval is ~10 minutes.
     *
     * Not cached — sparkline data is time-range-bound and always live.
     *
     * @param  int    $xiqDeviceId
     * @param  string $radioSelector  'WIFI0' | 'WIFI1' | 'WIFI2'
     * @param  int    $startTime      Unix timestamp (seconds)
     * @param  int    $endTime        Unix timestamp (seconds)
     * @param  string $source         '' | 'CHANNEL_UTILIZATION' | 'CONNECTED_CLIENTS'
     * @return array<int,array<string,mixed>>
     * @throws XIQException
     */
    public function getInterfacesGraph(
        int    $xiqDeviceId,
        string $radioSelector,
        int    $startTime,
        int    $endTime,
        string $source = '',
    ): array {
        $params = [
            'deviceId'  => $xiqDeviceId,
            'startTime' => $startTime * 1000,  // XIQ expects epoch ms
            'endTime'   => $endTime   * 1000,
            'channel'   => strtoupper($radioSelector),
        ];

        if ($source !== '') {
            $params['source'] = $source;
        }

        $data = $this->httpGet('/d360/wireless/interfaces-graph', $params);

        return $data['data'] ?? (array_is_list($data) ? $data : []);
    }

    // ── Client list ──────────────────────────────────────────────────────────

    /**
     * Fetch active clients associated with a device.
     *
     * Endpoint: GET /clients/active
     *
     * G4: Filter param is 'deviceIds' (camelCase, plural array).  Sending
     *     'device_id' (snake_case) silently returns fleet-wide results.
     *
     * G5: Default view emits only 12 fields.  Always pass views=FULL to get
     *     rssi, snr, channel, mac_protocol, connection_duration, locations[].
     *
     * Returns array of client objects.  Key fields for the Connected Clients
     * table: mac_address, hostname, user_name, ssid, rssi, data_rate, band,
     * connection_duration, radio_index, os_type.
     *
     * Not cached — called per page load on the Clients tab.
     *
     * @param  int  $xiqDeviceId
     * @return array<int,array<string,mixed>>
     * @throws XIQException
     */
    public function getClients(int $xiqDeviceId): array
    {
        // G4: param name MUST be 'deviceIds' (camelCase plural)
        // G5: always request FULL view
        $data = $this->httpGet(
            path:   '/clients/active',
            params: [
                'deviceIds' => $xiqDeviceId,
                'views'     => 'FULL',
                'page'      => 1,
                'pageSize'  => 500,  // large enough for a single AP; AP305C max ~250 clients
            ],
        );

        return $data['data'] ?? (array_is_list($data) ? $data : []);
    }

    // ── Location ─────────────────────────────────────────────────────────────

    /**
     * Fetch location metadata for a device.
     *
     * Endpoint: GET /locations/{locationId}
     *
     * The location ID is found in getDevice() response — look for 'location_id'
     * or similar field (confirm field name against live AP in M0 validation).
     *
     * Returns: building name, floor, coordinates, installation metadata.
     * Cached 600 s — location assignments almost never change.
     *
     * @param  int  $locationId  From getDevice()['location_id'].
     * @return array<string,mixed>
     * @throws XIQException
     */
    public function getLocation(int $locationId): array
    {
        $cacheKey = "xiq:location:{$locationId}";

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->httpGet("/locations/{$locationId}");

        $this->cacheSet($cacheKey, $data, self::TTL_LOCATION);

        return $data;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — AUTH
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Return a valid bearer token for the next request.
     *
     * fromToken() path: returns the static token immediately — no caching
     * needed, no expiry to manage.
     *
     * fromCredentials() path: returns a cached JWT, or issues POST /login to
     * obtain a fresh one.  Cached under 'xiq:token:{hash(username)}' for
     * TTL_TOKEN seconds.
     *
     * @throws XIQAuthException  If login fails or static token is blank.
     */
    private function token(): string
    {
        if ($this->staticToken !== null) {
            if ($this->staticToken === '') {
                throw new XIQAuthException(
                    'XIQ static token is empty — check {$XIQ_API_TOKEN} macro.'
                );
            }
            return $this->staticToken;
        }

        // Credential-based path — check APCu / filesystem cache
        $cacheKey = 'xiq:token:' . substr(md5($this->username ?? ''), 0, 16);

        $cached = $this->cacheGet($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Fetch a fresh JWT
        $token = $this->login();

        $this->cacheSet($cacheKey, $token, self::TTL_TOKEN);

        return $token;
    }

    /**
     * POST /login — exchange username + password for a bearer JWT.
     *
     * @return string  Bearer token string (~890 chars).
     * @throws XIQAuthException
     */
    private function login(): string
    {
        $url  = $this->baseUrl . '/login';
        $body = json_encode([
            'username' => $this->username,
            'password' => $this->password,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new XIQAuthException("XIQ login curl error: {$error}");
        }

        if ($status !== 200) {
            throw new XIQAuthException(
                "XIQ login failed — HTTP {$status}: " . substr((string)$raw, 0, 200)
            );
        }

        $decoded = json_decode((string)$raw, associative: true, flags: JSON_THROW_ON_ERROR);
        $token   = $decoded['access_token'] ?? '';

        if ($token === '') {
            throw new XIQAuthException('XIQ login response missing access_token field.');
        }

        return $token;
    }

    /**
     * Clear the cached token for this client's credential set.
     *
     * Called on 401 response before retrying with a fresh token.
     * No-op for fromToken() clients (static token never cached here).
     */
    private function clearCachedToken(): void
    {
        if ($this->staticToken !== null) {
            return;
        }
        $cacheKey = 'xiq:token:' . substr(md5($this->username ?? ''), 0, 16);
        $this->cacheDelete($cacheKey);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — HTTP
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Execute a GET request against the XIQ API.
     *
     * Handles:
     *   - Bearer token injection
     *   - RateLimit-Remaining header parsing
     *   - 401 → token refresh → single retry  (credentials path)
     *   - 401 → throw XIQAuthException         (static token path)
     *   - 429 → throw XIQRateLimitException
     *   - Non-2xx → throw XIQException
     *
     * @param  string               $path    URL path (e.g. '/devices/12345').
     * @param  array<string,mixed>  $params  Query parameters.
     * @return array<string,mixed>           Decoded JSON response.
     * @throws XIQException
     */
    private function httpGet(string $path, array $params = []): array
    {
        return $this->request(method: 'GET', path: $path, params: $params, body: null);
    }

    /**
     * Execute multiple GET requests in parallel using curl_multi.
     *
     * All requests share the same query params.  Used by getPolicySsids() to
     * fan out per-SSID detail calls concurrently.
     *
     * Returns results in the same order as $paths.
     *
     * @param  string[]             $paths   URL paths.
     * @param  array<string,mixed>  $params  Shared query parameters.
     * @return array<int,array<string,mixed>>
     * @throws XIQException
     */
    private function httpGetMulti(array $paths, array $params): array
    {
        if (empty($paths)) {
            return [];
        }

        $token   = $this->token();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($paths as $index => $path) {
            $url = $this->buildUrl($path, $params);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_HEADERFUNCTION => $this->makeHeaderCallback(),
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$index] = $ch;
        }

        // Execute all handles
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active > 0 && $status === CURLM_OK);

        $results = [];

        foreach ($handles as $index => $ch) {
            $raw    = curl_multi_getcontent($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($raw === false || $raw === null) {
                throw new XIQException("curl_multi fetch failed for path: {$paths[$index]}");
            }

            if ($status === 429) {
                curl_multi_close($mh);
                throw new XIQRateLimitException(
                    "XIQ rate limit exceeded (429) on {$paths[$index]}.",
                    remaining: 0,
                );
            }

            if ($status < 200 || $status >= 300) {
                throw new XIQException(
                    "XIQ HTTP {$status} on {$paths[$index]}: " . substr($raw, 0, 200)
                );
            }

            $results[$index] = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        }

        curl_multi_close($mh);

        // Re-key as plain list preserving input order
        ksort($results);
        return array_values($results);
    }

    /**
     * Core single-request executor.
     *
     * @param  string               $method  'GET' | 'POST'
     * @param  string               $path
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>|null $body  POST body (null for GET).
     * @return array<string,mixed>
     * @throws XIQException
     */
    private function request(
        string  $method,
        string  $path,
        array   $params,
        ?array  $body,
        bool    $isRetry = false,
    ): array {
        $token = $this->token();
        $url   = $this->buildUrl($path, $params);

        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$responseHeaders): int {
                $responseHeaders[] = $header;
                return strlen($header);
            },
        ]);

        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        // Parse rate-limit headers from whatever we received
        $this->parseRateLimitHeaders($responseHeaders);

        if ($raw === false) {
            throw new XIQException("XIQ curl error on {$path}: {$error}");
        }

        // ── 429 Rate limit exceeded ───────────────────────────────────────
        if ($status === 429) {
            throw new XIQRateLimitException(
                message:   "XIQ rate limit exceeded (429) — retry after {$this->rateLimitReset} s.",
                remaining: 0,
                resetIn:   $this->rateLimitReset,
            );
        }

        // ── 401 Unauthorized ──────────────────────────────────────────────
        if ($status === 401) {
            if ($this->staticToken !== null) {
                // Permanent token was revoked — surface error, do not retry.
                throw new XIQAuthException(
                    'XIQ bearer token rejected (401). ' .
                    'The API token may have been revoked. ' .
                    'Re-issue via XIQ Administration → API Access Tokens ' .
                    'and update the {$XIQ_API_TOKEN} Zabbix macro.'
                );
            }

            if ($isRetry) {
                // Second consecutive 401 — do not loop.
                throw new XIQAuthException(
                    'XIQ authentication failed on retry (401). ' .
                    'Check {$XIQ_USERNAME} / {$XIQ_PASSWORD} macros.'
                );
            }

            // Credential path: clear cached token, re-auth once, retry.
            $this->clearCachedToken();
            return $this->request(
                method:  $method,
                path:    $path,
                params:  $params,
                body:    $body,
                isRetry: true,
            );
        }

        // ── Other non-2xx ─────────────────────────────────────────────────
        if ($status < 200 || $status >= 300) {
            throw new XIQException(
                "XIQ HTTP {$status} on {$path}: " . substr((string)$raw, 0, 300)
            );
        }

        if ((string)$raw === '') {
            return [];
        }

        return json_decode((string)$raw, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — HELPERS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Build a fully-qualified URL with query string.
     *
     * @param  string               $path
     * @param  array<string,mixed>  $params
     * @return string
     */
    private function buildUrl(string $path, array $params): string
    {
        $url = $this->baseUrl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }

    /**
     * Parse RateLimit-* headers and update instance state.
     *
     * XIQ response headers (one per call):
     *   RateLimit-Limit: 7500;w=3600
     *   RateLimit-Remaining: 7492
     *   RateLimit-Reset: 47
     *
     * Note: curl_getinfo() has no CURLINFO_RATELIMIT_REMAINING constant.
     * Use the CURLOPT_HEADERFUNCTION callback to collect raw header lines.
     *
     * @param  string[]  $headers  Raw header lines from CURLOPT_HEADERFUNCTION.
     */
    private function parseRateLimitHeaders(array $headers): void
    {
        foreach ($headers as $line) {
            $line = trim($line);

            if (stripos($line, 'RateLimit-Remaining:') === 0) {
                $val = trim(substr($line, strlen('RateLimit-Remaining:')));
                if (is_numeric($val)) {
                    $this->rateLimitRemaining = (int)$val;
                }
                continue;
            }

            if (stripos($line, 'RateLimit-Reset:') === 0) {
                $val = trim(substr($line, strlen('RateLimit-Reset:')));
                if (is_numeric($val)) {
                    $this->rateLimitReset = (int)$val;
                }
                continue;
            }
        }
    }

    /**
     * Return a closure suitable for CURLOPT_HEADERFUNCTION.
     *
     * Used in httpGetMulti() where the inline closure in request() is not
     * available per-handle.  Updates $this->rateLimitRemaining as headers
     * arrive on any handle in the multi stack.
     */
    private function makeHeaderCallback(): \Closure
    {
        return function ($ch, string $header): int {
            $this->parseRateLimitHeaders([$header]);
            return strlen($header);
        };
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — CACHE  (APCu primary · filesystem fallback)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Read a value from cache.
     *
     * Returns null on miss.  The value may be a string (token) or array (API
     * response).
     *
     * @return string|array<string,mixed>|null
     */
    private function cacheGet(string $key): string|array|null
    {
        // APCu path
        if (function_exists('apcu_fetch')) {
            $success = false;
            $value   = apcu_fetch($key, $success);
            return $success ? $value : null;
        }

        // Filesystem fallback
        $file = $this->fsCachePath($key);
        if (!file_exists($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $entry = json_decode($raw, associative: true);
        if (!is_array($entry) || !isset($entry['exp'], $entry['val'])) {
            return null;
        }

        if ($entry['exp'] < time()) {
            @unlink($file);
            return null;
        }

        return $entry['val'];
    }

    /**
     * Write a value to cache with a TTL.
     *
     * @param  string|array<string,mixed>  $value
     */
    private function cacheSet(string $key, string|array $value, int $ttl): void
    {
        // APCu path
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);
            return;
        }

        // Filesystem fallback
        $dir = self::FS_CACHE_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, recursive: true);
        }

        $entry = json_encode([
            'exp' => time() + $ttl,
            'val' => $value,
        ], JSON_THROW_ON_ERROR);

        @file_put_contents($this->fsCachePath($key), $entry, LOCK_EX);
    }

    /**
     * Delete a cache entry (used to invalidate cached tokens on 401).
     */
    private function cacheDelete(string $key): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete($key);
            return;
        }

        $file = $this->fsCachePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Return the filesystem path for a cache key.
     *
     * Key is hashed to avoid filesystem-unsafe characters and limit filename
     * length.  The first 8 chars of the key are prepended as a human-readable
     * prefix for debugging.
     */
    private function fsCachePath(string $key): string
    {
        $safe   = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
        $prefix = substr($safe, 0, 20);
        $hash   = sha1($key);
        return self::FS_CACHE_DIR . "/{$prefix}_{$hash}.json";
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// EXCEPTION HIERARCHY
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Base exception for all XIQ API errors.
 *
 * Catch this in WidgetView::doAction() and add a 'xiq_error' key to the
 * response data — the view template surfaces it as a widget-level error banner
 * rather than a PHP fatal.
 *
 * Example in WidgetView.php:
 *
 *   try {
 *       $xiq_device = $xiq->getDevice($xiq_device_id);
 *   } catch (XIQRateLimitException $e) {
 *       $xiq_error = 'XIQ rate limit exceeded — data unavailable. Reset in '
 *                    . $e->getResetIn() . ' s.';
 *   } catch (XIQAuthException $e) {
 *       $xiq_error = 'XIQ authentication error: ' . $e->getMessage();
 *   } catch (XIQException $e) {
 *       $xiq_error = 'XIQ API error: ' . $e->getMessage();
 *   }
 */
class XIQException extends \RuntimeException {}

/**
 * Authentication failure — either login rejected or static token revoked.
 *
 * fromToken() path: surface this as a permanent error (re-issue token).
 * fromCredentials() path: surface this after the single retry attempt fails.
 */
class XIQAuthException extends XIQException {}

/**
 * HTTP 429 — quota exhausted for the current window.
 *
 * The widget view should skip all remaining XIQ calls for this page load and
 * display a "quota exceeded" state rather than accumulating more 429s.
 */
class XIQRateLimitException extends XIQException
{
    public function __construct(
        string          $message,
        private int     $remaining,
        private int     $resetIn = 0,
        ?\Throwable     $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Remaining calls in the current window (0 when 429 is returned). */
    public function getRemaining(): int { return $this->remaining; }

    /** Seconds until the rate-limit window resets. */
    public function getResetIn(): int { return $this->resetIn; }
}
