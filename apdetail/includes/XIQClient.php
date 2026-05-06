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
 * G6  /devices/{id}/interfaces/wifi requires startTime + endTime even though
 *     the OpenAPI spec marks them optional.  A window < 10 min returns [].
 *     Use a 15-minute trailing window for "current" radio values.
 *     (M0 inferred path was /wifi-if-stats; live-validated path is /interfaces/wifi.)
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
    private const TTL_DEVICE   = 300;    // 5 min — config-class data (identity, model, policy ref, NTP, last config push). Live client counts come from /clients/active, NOT this endpoint, so the M0 §7 60-second guidance does not apply here.
    private const TTL_POLICY   = 300;
    private const TTL_SSID     = 300;
    private const TTL_LOCATION = 600;
    private const TTL_XIQ_ID   = 3600;

    /** Warn the widget view when remaining quota drops below this. */
    public const RATE_LIMIT_WARN_THRESHOLD = 500;

    /** WiFi stats window in seconds — must be ≥ 10 min or XIQ returns []. */
    private const WIFI_STATS_WINDOW_SEC = 900;  // 15 minutes (G6)

    /**
     * Cap on parallel /ssids/{id} fan-out requests.
     *
     * Per M0_XIQ_Device_Config_API §1: TCS-Production has 3 SSIDs; this is a
     * 5× safety margin against a misconfigured policy with an unexpectedly
     * long SSID list (a shared policy with 20+ SSIDs would otherwise spike
     * per-load call count well beyond the per-widget budget).  When this cap
     * is exceeded, getSsids() logs a warning and proceeds with the first N.
     */
    private const MAX_SSID_FAN_OUT = 16;

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
     * Fetch device identity & config for one XIQ device, normalised for the dashboard.
     *
     * Wraps GET /devices/{id}.  Cached {@see self::TTL_DEVICE} seconds (5 min) under
     * `xiq:device:{id}` — config-class data; not per-page-load.
     *
     * Returns a STABLE normalised shape — not the raw XIQ JSON.  This insulates
     * widget.view.php and downstream consumers from XIQ field-name churn (M0
     * marked several fields as "Infer — validate exact name"; aliases for each
     * are accepted below).  The full raw response is preserved under `raw` so
     * debug-mode dumps and ad-hoc field access still work.
     *
     * Timestamp fields are unix SECONDS (XIQ wire format is unix ms; we
     * normalise via {@see self::normaliseUnixTime()} using the same > 1e10 test
     * the Extreme_XIQ_APs.yaml master-item already uses for `last_connect`).
     *
     * The `mac` field is colon-formatted via {@see self::macInsertColons()}
     * because XIQ returns it without delimiters (G3).  This is the wireless
     * BASE MAC, not the eth0 management MAC — see G3 callout for context.
     *
     * The XIQ device ID is stored as a Zabbix host macro {$XIQ_DEVICE_ID} by
     * the fleet discovery template — no lookup required per widget load.
     *
     * @param  int  $xiqDeviceId  XIQ internal device ID (NOT the Zabbix host ID).
     *
     * @return array{
     *     id:                  int,
     *     hostname:            string,
     *     serial:              string,
     *     model:               string,
     *     function:            string,
     *     firmware:            string,
     *     mac:                 string,
     *     ip:                  string,
     *     config_type:         string,
     *     config_mismatch:     bool,
     *     connected:           bool,
     *     admin_state:         string,
     *     ntp_server:          string,
     *     last_config_push:    int,
     *     last_connect:        int,
     *     uptime:              int,
     *     network_policy_id:   int,
     *     network_policy_name: string,
     *     location_id:         int,
     *     connected_clients:   int,
     *     raw:                 array<string,mixed>,
     * }
     *
     * @throws XIQException  On HTTP failure or malformed response.  401 retry
     *                       (fromCredentials path) is transparent inside
     *                       httpGet().  404 propagates as XIQException with
     *                       the XIQ error message attached.
     */
    public function getDevice(int $xiqDeviceId): array
    {
        if ($xiqDeviceId <= 0) {
            throw new XIQException(
                "XIQClient::getDevice() requires a positive device ID, got {$xiqDeviceId}"
            );
        }

        $cacheKey = "xiq:device:{$xiqDeviceId}";

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $raw = $this->httpGet("/devices/{$xiqDeviceId}");

        if (!is_array($raw) || !array_key_exists('id', $raw)) {
            throw new XIQException(
                "XIQClient::getDevice({$xiqDeviceId}) — malformed response (no 'id' field)"
            );
        }

        $normalised = $this->normaliseDevice($raw);

        $this->cacheSet($cacheKey, $normalised, self::TTL_DEVICE);

        return $normalised;
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
     * Fetch a network policy by its policy ID, normalised for the dashboard.
     *
     * Endpoint composition (parallel): GET /network-policies/{id} for policy
     * metadata (name, type, description) + GET /network-policies/{id}/ssids for
     * the authoritative SSID list.  Both calls go through httpGetMulti so the
     * cache miss costs one round-trip instead of two.
     *
     * Why two calls:
     *   • /network-policies/{id} returns name + meta but its SSID-id field
     *     name is inferred ("ssid_profile_ids" / "ssid_ids") — varies by XIQ
     *     build.  The closeout did NOT validate this path against live data.
     *   • /network-policies/{id}/ssids IS live-validated (M0 Closeout §3) and
     *     returns the rich SSID summary — id, name, broadcast_name, and the
     *     access_security nested object.  Authoritative for the SSID id list.
     *
     * Cached {@see self::TTL_POLICY} seconds (5 min) under `xiq:policy:{id}`.
     * Network policy assignments change at most a few times per semester.
     *
     * For the SSID Broadcast table, callers typically want richer per-SSID
     * detail than this method exposes — use {@see self::getPolicySsids()},
     * which composes this method's SSID-id list with {@see self::getSsids()}
     * to produce the full enriched flat list.
     *
     * @param  int  $policyId  XIQ network policy ID, e.g. from
     *                         getDeviceNetworkPolicy()['id'].
     *
     * @return array{
     *     id:       int,
     *     name:     string,
     *     type:     string,
     *     ssid_ids: int[],
     *     raw:      array{meta: array<string,mixed>, ssids: array<int,array<string,mixed>>},
     * }
     *
     * @throws XIQException
     */
    public function getNetworkPolicy(int $policyId): array
    {
        if ($policyId <= 0) {
            throw new XIQException(
                "XIQClient::getNetworkPolicy() requires a positive policy ID, got {$policyId}"
            );
        }

        $cacheKey = "xiq:policy:{$policyId}";

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Two parallel calls: policy meta + SSID list.  httpGetMulti returns
        // results in the order of the input paths.
        $responses = $this->httpGetMulti(
            paths: [
                "/network-policies/{$policyId}",
                "/network-policies/{$policyId}/ssids",
            ],
            params: [],
        );

        $meta     = $responses[0] ?? [];
        $ssidsRaw = $responses[1] ?? [];

        // /network-policies/{id}/ssids may wrap the array in a 'data' envelope
        $ssidArray = is_array($ssidsRaw['data'] ?? null) ? $ssidsRaw['data'] : $ssidsRaw;
        if (!is_array($ssidArray)) {
            $ssidArray = [];
        }

        $normalised = $this->normaliseNetworkPolicy(
            policyId: $policyId,
            meta:     is_array($meta) ? $meta : [],
            ssids:    $ssidArray,
        );

        $this->cacheSet($cacheKey, $normalised, self::TTL_POLICY);

        return $normalised;
    }

    /**
     * Fetch all SSIDs for a network policy, summary + detail merged.
     *
     * High-level convenience method composed of:
     *   1. GET /network-policies/{policyId}/ssids — returns the SSID summary
     *      list with id, name, broadcast_name, and the access_security nested
     *      object (security_type / encryption_method).  Live-validated in M0
     *      Closeout §3.
     *   2. {@see self::getSsids()} — fans out per-SSID detail calls in
     *      parallel via curl_multi for VLAN, auth method, encryption type,
     *      band steering, enabled bands.  Cap at MAX_SSID_FAN_OUT.
     *
     * Each SSID's normalised detail is cached individually under `xiq:ssid:{id}`
     * by getSsids() (5 min).  The merged flat list is also cached under
     * `xiq:policylist:{policyId}` (5 min) so subsequent calls within the
     * cache horizon skip both summary and fan-out work entirely.
     *
     * Returns a flat list of SSID rows.  Each row preserves both the SUMMARY
     * shape (with the `access_security` nested object — required by the M0
     * Closeout §5 SSID Broadcast join recipe) AND the raw fields from
     * /ssids/{id}.  Field order: summary keys first, then any additional
     * detail keys.  Consumers that need normalised detail field access
     * (e.g. `vlan` instead of `vlan_id`) should use {@see self::getSsid()} or
     * {@see self::getSsids()} directly.
     *
     * NOTE (G7): /devices/{id}/ssid/status returns a map keyed by SSID id.
     * JOIN the returned 'id' fields against that map to get per-SSID admin state.
     *
     * @param  int  $policyId  From getDeviceNetworkPolicy()['id'] or
     *                         getNetworkPolicy()['id'].
     * @return array<int,array<string,mixed>>  Flat list of merged SSID rows.
     * @throws XIQException
     */
    public function getPolicySsids(int $policyId): array
    {
        $listKey = "xiq:policylist:{$policyId}";

        $cached = $this->cacheGet($listKey);
        if ($cached !== null) {
            return $cached;
        }

        // Step 1 — SSID summary list.  This is the live-validated path that
        // returns broadcast_name + access_security.  We keep this call here
        // (rather than going through getNetworkPolicy()) because we need the
        // FULL summary entries to merge with details, not just the IDs.
        $list     = $this->httpGet("/network-policies/{$policyId}/ssids");
        $ssidList = $list['data'] ?? $list;   // unwrap pagination envelope if present

        if (!is_array($ssidList) || empty($ssidList)) {
            return [];
        }

        // Step 2 — collect SSID IDs and index summaries by id for O(1) merge.
        $ssidIds   = [];
        $summaries = [];
        foreach ($ssidList as $entry) {
            $sid = (int)($entry['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $ssidIds[]        = $sid;
            $summaries[$sid]  = $entry;
        }

        // Step 3 — fan out per-SSID details via curl_multi (cache-aware).
        $details = $this->getSsids($ssidIds);

        // Step 4 — merge summary + raw detail per SSID.  We use the detail's
        // `raw` field (the unnormalised /ssids/{id} response) so this method
        // continues to return the flat-merged shape it always did — the M0
        // closeout join recipe relies on the summary's nested `access_security`
        // object remaining intact.
        $merged = [];
        foreach ($summaries as $sid => $summary) {
            $detailRaw = $details[$sid]['raw'] ?? [];
            $merged[]  = array_merge($summary, $detailRaw);
        }

        $this->cacheSet($listKey, $merged, self::TTL_POLICY);

        return $merged;
    }

    /**
     * Fetch one SSID's full configuration profile, normalised.
     *
     * Endpoint: GET /ssids/{id}.  Returns the per-SSID config used by the
     * Wireless tab's SSID Broadcast table — VLAN, auth method, encryption
     * type, band steering, enabled bands.
     *
     * Cached {@see self::TTL_SSID} seconds (5 min) under `xiq:ssid:{id}`.
     * SSID config changes only on deliberate network-team action.
     *
     * Field-name resilience: M0_XIQ_Device_Config_API §3 marked several
     * /ssids/{id} fields as "Infer — validate exact name" (the closeout
     * directly validated /network-policies/{id}/ssids but not /ssids/{id}).
     * The normaliser accepts plausible aliases for each so a XIQ build flip
     * (e.g. ssid_name → name, vlan_id → default_vlan) does not take down
     * the widget.
     *
     * VLAN handling: TCS-Wireless uses PF role-based VLANs (100/110/130/150).
     * XIQ likely returns a single configured default VLAN or a "dynamic"
     * marker — it cannot enumerate the PF role map.  The full role-VLAN
     * string for the Wireless tab comes from a Zabbix host inventory field,
     * not this method.  See M0_XIQ_Device_Config_API §3 VLAN callout.
     *
     * @param  int  $ssidId  XIQ SSID ID, e.g. from getNetworkPolicy()['ssid_ids'][0].
     *
     * @return array{
     *     id:             int,
     *     name:           string,
     *     broadcast_name: string,
     *     vlan:           int,
     *     auth_method:    string,
     *     encryption:     string,
     *     band_steering:  bool,
     *     enabled_bands:  string[],
     *     raw:            array<string,mixed>,
     * }
     *
     * @throws XIQException
     */
    public function getSsid(int $ssidId): array
    {
        if ($ssidId <= 0) {
            throw new XIQException(
                "XIQClient::getSsid() requires a positive SSID ID, got {$ssidId}"
            );
        }

        $cacheKey = "xiq:ssid:{$ssidId}";

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $raw = $this->httpGet("/ssids/{$ssidId}");

        if (!is_array($raw) || !array_key_exists('id', $raw)) {
            throw new XIQException(
                "XIQClient::getSsid({$ssidId}) — malformed response (no 'id' field)"
            );
        }

        $normalised = $this->normaliseSsid($raw);

        $this->cacheSet($cacheKey, $normalised, self::TTL_SSID);

        return $normalised;
    }

    /**
     * Batch fetch multiple SSIDs in parallel via curl_multi.
     *
     * The fan-out pattern called out in M0 §1 as "the slowest calls by
     * volume — fan-out pattern is critical".  Cache-aware: any IDs already
     * in the per-SSID cache are served from there; only true cache misses
     * become parallel HTTP calls.
     *
     * Caps the live fan-out at {@see self::MAX_SSID_FAN_OUT} to protect
     * the per-VIQ 7,500/hr quota against a misconfigured shared policy
     * with an unexpectedly long SSID list.  When the cap kicks in, a
     * warning is written to the PHP error log and the first N IDs are
     * fetched.  Callers should not normally hit this — TCS-Production
     * has 3 SSIDs.
     *
     * Each fetched SSID is also written to the per-SSID cache under
     * `xiq:ssid:{id}` so subsequent {@see self::getSsid()} calls hit the
     * same warm entries.
     *
     * Failure semantics (inherited from {@see self::httpGetMulti()}):
     * if ANY single fan-out call returns non-2xx, the entire batch
     * throws.  This is the existing behaviour — getPolicySsids() has
     * always been all-or-nothing in this regard.  A more resilient
     * variant can be added if a single stale SSID id starts blacking
     * out the Wireless tab in production.
     *
     * @param  int[]  $ssidIds  XIQ SSID IDs.  Duplicates and non-positive
     *                          values are filtered out.
     *
     * @return array<int, array<string,mixed>>  Map of ssidId → normalised
     *                                          shape.  Same shape as
     *                                          {@see self::getSsid()}.
     *
     * @throws XIQException
     */
    public function getSsids(array $ssidIds): array
    {
        // Filter to unique positive ints
        $clean = [];
        foreach ($ssidIds as $sid) {
            if (is_int($sid) && $sid > 0) {
                $clean[$sid] = true;
            } elseif (is_numeric($sid) && (int)$sid > 0) {
                $clean[(int)$sid] = true;
            }
        }
        $ssidIds = array_keys($clean);

        if (empty($ssidIds)) {
            return [];
        }

        $results = [];
        $toFetch = [];

        // Cache check pass — collect hits, queue misses
        foreach ($ssidIds as $sid) {
            $cached = $this->cacheGet("xiq:ssid:{$sid}");
            if ($cached !== null) {
                $results[$sid] = $cached;
            } else {
                $toFetch[] = $sid;
            }
        }

        // Apply fan-out cap with a logged warning if exceeded
        if (count($toFetch) > self::MAX_SSID_FAN_OUT) {
            error_log(sprintf(
                'XIQClient::getSsids — fan-out cap hit: %d SSIDs requested, capping to %d. '
                . 'Check XIQ network policy for unexpectedly large SSID list.',
                count($toFetch),
                self::MAX_SSID_FAN_OUT,
            ));
            $toFetch = array_slice($toFetch, 0, self::MAX_SSID_FAN_OUT);
        }

        if (!empty($toFetch)) {
            $rawResponses = $this->httpGetMulti(
                paths:  array_map(
                    fn(int $id): string => "/ssids/{$id}",
                    $toFetch,
                ),
                params: [],
            );

            foreach ($rawResponses as $i => $raw) {
                $sid = $toFetch[$i];

                if (!is_array($raw) || !array_key_exists('id', $raw)) {
                    // Don't throw on a single malformed payload here — the
                    // batch already succeeded HTTP-wise (httpGetMulti throws
                    // on non-2xx).  Skip and continue; caller checks key
                    // presence in the returned map.
                    continue;
                }

                $normalised = $this->normaliseSsid($raw);
                $this->cacheSet("xiq:ssid:{$sid}", $normalised, self::TTL_SSID);
                $results[$sid] = $normalised;
            }
        }

        return $results;
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
     * Fetch per-radio WiFi interface stats for one device, normalised.
     *
     * Endpoint: GET /devices/{id}/interfaces/wifi
     *
     * Path correction: M0 inferred /devices/{id}/wifi-if-stats (the API
     * reference anchor name); the M0 v3+v4+v5 closeout live-validated
     * /devices/{id}/interfaces/wifi against BHS-56-Hallway.  This method
     * uses the live path.
     *
     * G6: startTime + endTime are REQUIRED even though the OpenAPI spec
     * marks them optional.  The snapshot form (no params) returns 400.
     * A window shorter than ~10 min returns [] because XIQ buckets at
     * ~10-minute intervals.  This method always uses a 15-minute trailing
     * window to guarantee data ({@see self::WIFI_STATS_WINDOW_SEC}).
     *
     * G10 — band label source: do NOT derive band from radio_index.  The
     * pilot AP runs dual-5G mode — both wifi0 (channel 40) and wifi1
     * (channel 149) report frequency "5G".  Any 0=2.4 / 1=5 mapping would
     * be wrong on this hardware.  The normaliser uses the XIQ `frequency`
     * field directly.  Other deployments may run 2.4 + 5 + 5 (tri-radio)
     * or other combos — always enumerate from the API response.
     *
     * Not cached — called per page load.  Drives current-value badges
     * for channel utilization and noise floor on the Live Telemetry strip
     * (no XIQ history at this endpoint; sparklines come from
     * /d360/wireless/interfaces-graph instead).
     *
     * Returns one normalised entry per radio in the order XIQ returned.
     *
     * @param  int  $xiqDeviceId
     *
     * @return array<int, array{
     *     radio_index:    int,
     *     interface_name: string,
     *     band:           string,
     *     channel:        int,
     *     channel_width:  int,
     *     tx_power:       int,
     *     channel_util:   int,
     *     noise_floor:    int,
     *     client_count:   int,
     *     ssid_count:     int,
     *     bssid:          string,
     *     profile_name:   string,
     *     retry_frames:   int,
     *     tx_bytes:       int,
     *     rx_bytes:       int,
     *     raw:            array<string,mixed>,
     * }>
     *
     * @throws XIQException
     */
    public function getWifiStats(int $xiqDeviceId): array
    {
        if ($xiqDeviceId <= 0) {
            throw new XIQException(
                "XIQClient::getWifiStats() requires a positive device ID, got {$xiqDeviceId}"
            );
        }

        $now       = time();
        $startTime = ($now - self::WIFI_STATS_WINDOW_SEC) * 1000;   // epoch ms
        $endTime   = $now * 1000;

        $data = $this->httpGet(
            path:   "/devices/{$xiqDeviceId}/interfaces/wifi",
            params: [
                'startTime' => $startTime,
                'endTime'   => $endTime,
            ],
        );

        // Response may be wrapped in a 'data' envelope or be a bare list.
        $radios = $data['data'] ?? (array_is_list($data) ? $data : []);
        if (!is_array($radios)) {
            return [];
        }

        $normalised = [];
        foreach ($radios as $radio) {
            if (!is_array($radio)) {
                continue;
            }
            $normalised[] = $this->normaliseWifiInterface($radio);
        }

        return $normalised;
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
    // PRIVATE — RESPONSE NORMALISERS
    // ═════════════════════════════════════════════════════════════════════════
    //
    // These map raw XIQ JSON onto stable, PHP-native shapes consumed by the
    // widget views.  Goals:
    //
    //   • Insulate the view layer from XIQ field-name churn.  Several fields
    //     were marked "Infer — validate exact name" in the M0 docs; we accept
    //     each documented alias here so a XIQ build that flips e.g.
    //     software_version → firmware_version doesn't take down the widget.
    //   • Normalise unit ambiguity.  XIQ mixes unix-ms and unix-seconds
    //     across timestamp fields with no consistent rule; normaliseUnixTime()
    //     uses the same > 1e10 test the existing Zabbix template YAML uses.
    //   • Preserve the full raw response under `raw` for debug-mode dumps and
    //     ad-hoc access to fields that haven't been promoted to the
    //     normalised set yet.

    /**
     * Map a raw /devices/{id} response onto the dashboard's stable shape.
     *
     * Field-name resilience: where M0 marked a field "Infer — validate exact
     * name", we accept multiple plausible aliases.  The first non-empty value
     * wins.
     *
     * @param  array<string,mixed>  $r  Raw JSON-decoded /devices/{id} body.
     * @return array<string,mixed>      Normalised dashboard shape.
     */
    private function normaliseDevice(array $r): array
    {
        // Firmware: validated as a single field (M0 v3) — sample value
        // "10.7r5b · build c6be9b0" is one string from `software_version`.
        // No split needed; the dashboard wants the joined display anyway.
        $firmware = (string)(
            $r['software_version']
            ?? $r['firmware_version']
            ?? $r['os_version']
            ?? ''
        );

        // Config type / status — M0 marked as "infer enum values".
        // Mock data shows "DHCP_CLIENT_WITHOUT_FALLBACK".
        $configType = (string)(
            $r['config_status']
            ?? $r['config_type']
            ?? ''
        );

        // Policy reference — may arrive as id-only, name-only, or both.
        // The view should call getDeviceNetworkPolicy() for the human-readable
        // name when only the ID is present here.
        $policyId = (int)(
            $r['network_policy_id']
            ?? $r['policy_id']
            ?? 0
        );
        $policyName = (string)(
            $r['network_policy_name']
            ?? $r['policy_name']
            ?? ''
        );

        // Last config push — XIQ wire format is unix ms; same > 1e10 test as
        // the Extreme_XIQ_APs.yaml master-item preprocessing for last_connect.
        $lastConfigPush = $this->normaliseUnixTime(
            $r['last_updated']
            ?? $r['config_update_time']
            ?? 0
        );
        $lastConnect = $this->normaliseUnixTime(
            $r['last_connect_time']
            ?? $r['last_connect']
            ?? 0
        );
        $uptime = $this->normaliseUnixTime(
            $r['system_up_time']
            ?? $r['uptime']
            ?? 0
        );

        // NTP — M0 flagged as TBD; may live on the device profile, not the
        // device object directly.  Empty string here triggers the SNMP/host-
        // inventory fallback path in WidgetView.
        $ntp = (string)($r['ntp_server'] ?? '');

        // MAC: XIQ returns 12-char hex without delimiters (G3).  Insert colons
        // so the dashboard can render directly.
        $macRaw = (string)($r['mac_address'] ?? '');
        $mac    = $macRaw !== '' ? self::macInsertColons($macRaw) : '';

        return [
            'id'                  => (int)    $r['id'],
            'hostname'            => (string) ($r['hostname']           ?? ''),
            'serial'              => (string) ($r['serial_number']      ?? ''),
            'model'               => (string) ($r['product_type']       ?? ''),
            'function'            => (string) ($r['device_function']    ?? ''),
            'firmware'            => $firmware,
            'mac'                 => $mac,
            'ip'                  => (string) ($r['ip_address']         ?? ''),
            'config_type'         => $configType,
            'config_mismatch'     => (bool)   ($r['config_mismatch']    ?? false),
            'connected'           => (bool)   ($r['connected']          ?? false),
            'admin_state'         => (string) ($r['device_admin_state'] ?? ''),
            'ntp_server'          => $ntp,
            'last_config_push'    => $lastConfigPush,
            'last_connect'        => $lastConnect,
            'uptime'              => $uptime,
            'network_policy_id'   => $policyId,
            'network_policy_name' => $policyName,
            'location_id'         => (int) ($r['location_id'] ?? 0),
            'connected_clients'   => (int) ($r['connected_clients'] ?? 0),
            'raw'                 => $r,
        ];
    }

    /**
     * Map a raw /network-policies/{id} + /network-policies/{id}/ssids
     * response pair onto the dashboard's stable shape.
     *
     * @param  int                                        $policyId
     * @param  array<string,mixed>                        $meta
     * @param  array<int,array<string,mixed>>             $ssids
     * @return array<string,mixed>
     */
    private function normaliseNetworkPolicy(int $policyId, array $meta, array $ssids): array
    {
        // SSID-id list authoritatively comes from /network-policies/{id}/ssids
        // (live-validated in M0 Closeout §3).  /network-policies/{id} alone
        // may also include an SSID-id array under "ssid_profile_ids" or
        // "ssid_ids" depending on XIQ build, but we treat the SSID call as
        // the source of truth so the result is stable across builds.
        $ssidIds = [];
        foreach ($ssids as $entry) {
            $sid = (int)($entry['id'] ?? 0);
            if ($sid > 0) {
                $ssidIds[] = $sid;
            }
        }

        // De-dupe in case /network-policies/{id}/ssids ever returns the same
        // id twice (paranoid; not observed).
        $ssidIds = array_values(array_unique($ssidIds));

        return [
            'id'       => (int)   ($meta['id']   ?? $policyId),
            'name'     => (string)($meta['name'] ?? ''),
            'type'     => (string)($meta['type'] ?? ''),
            'ssid_ids' => $ssidIds,
            'raw'      => [
                'meta'  => $meta,
                'ssids' => $ssids,
            ],
        ];
    }

    /**
     * Map a raw /ssids/{id} response onto the dashboard's stable shape.
     *
     * Field-name resilience: M0 marked all of these as "Infer — validate
     * exact name".  The closeout did not directly validate /ssids/{id}.
     * Each field is read with multiple plausible aliases; the first
     * non-empty / present value wins.
     *
     * Two-layer access for security/encryption: XIQ may return them flat
     * at the top level OR nested under an `access_security` object (the
     * latter is the shape /network-policies/{id}/ssids returns for the
     * same fields — closeout-confirmed).  We probe both shapes.
     *
     * @param  array<string,mixed>  $r  Raw JSON-decoded /ssids/{id} body.
     * @return array<string,mixed>      Normalised dashboard shape.
     */
    private function normaliseSsid(array $r): array
    {
        // Two shapes seen in the wild for security fields:
        //   • Flat:   {security_type: "...", encryption_method: "..."}
        //   • Nested: {access_security: {security_type: "...", encryption_method: "..."}}
        $access = is_array($r['access_security'] ?? null) ? $r['access_security'] : [];

        $authMethod = (string)(
            $r['security_type']
            ?? $access['security_type']
            ?? $r['auth_type']
            ?? $r['security_level']
            ?? ''
        );

        $encryption = (string)(
            $r['encryption_method']
            ?? $access['encryption_method']
            ?? $r['cipher_type']
            ?? ''
        );

        // VLAN: int when known, 0 when "dynamic" / role-based / unset.
        // TCS-Wireless uses PF role-based VLANs (M0 §3 callout) — XIQ may
        // return null, 0, or a string sentinel here.  Callers display
        // "—" or "role-based" for 0.
        $vlanRaw = $r['vlan_id']
            ?? $r['default_vlan']
            ?? $r['native_vlan']
            ?? null;
        $vlan = is_numeric($vlanRaw) ? (int)$vlanRaw : 0;

        // Band steering: bool or enum string.  Coerce to bool — true if
        // explicitly enabled, false otherwise.
        $bsRaw = $r['band_steering']
            ?? $r['band_steering_enabled']
            ?? false;
        $bandSteering = is_string($bsRaw)
            ? !in_array(strtoupper($bsRaw), ['', 'OFF', 'DISABLED', 'NONE', 'FALSE', '0'], true)
            : (bool)$bsRaw;

        // Enabled bands: collect from individual booleans, OR a list field
        // if XIQ returns one.  Output is a stable string array like
        // ["2.4", "5"] for downstream display logic ("2.4 + 5", "5 only").
        $enabledBands = [];
        if (!empty($r['enable_24ghz']) || !empty($r['enable_2g']) || !empty($r['enable_2_4ghz'])) {
            $enabledBands[] = '2.4';
        }
        if (!empty($r['enable_5ghz']) || !empty($r['enable_5g'])) {
            $enabledBands[] = '5';
        }
        if (!empty($r['enable_6ghz']) || !empty($r['enable_6g'])) {
            $enabledBands[] = '6';
        }
        // List-shape fallback: some XIQ builds may return enabled_bands or
        // bands as a string array directly.
        if (empty($enabledBands)) {
            foreach (['enabled_bands', 'bands', 'radio_bands'] as $listKey) {
                if (is_array($r[$listKey] ?? null) && !empty($r[$listKey])) {
                    $enabledBands = array_values(array_map('strval', $r[$listKey]));
                    break;
                }
            }
        }

        return [
            'id'             => (int)    $r['id'],
            'name'           => (string) ($r['ssid_name']      ?? $r['name']           ?? ''),
            'broadcast_name' => (string) ($r['broadcast_name'] ?? $r['ssid_broadcast_name'] ?? ''),
            'vlan'           => $vlan,
            'auth_method'    => $authMethod,
            'encryption'     => $encryption,
            'band_steering'  => $bandSteering,
            'enabled_bands'  => $enabledBands,
            'raw'            => $r,
        ];
    }

    /**
     * Map one entry from /devices/{id}/interfaces/wifi onto the dashboard's
     * stable per-radio shape.
     *
     * Closeout-confirmed (M0 v5) field names this normaliser reads, with the
     * M0 inferred names accepted as fallback aliases:
     *   • interface_name (e.g. "wifi0")           — radio identifier
     *   • frequency      (e.g. "5G", "2.4G", "6G")— authoritative band label (G10)
     *   • channel        (int)                    — channel number
     *   • channel_width  (int MHz)
     *   • channel_util   (int %)                  — NOT channel_utilization
     *   • noise_floor    (int dBm, negative)
     *   • power          (int dBm)                — NOT tx_power
     *   • client_count   (int)
     *   • ssid_count     (int)
     *   • mac_address    (12-char hex, no colons) — radio BSSID (G3)
     *   • radio_profile_name (string)
     *   • tx_retry_frame (int)                    — frame COUNT, not a rate
     *   • tx_byte_count / rx_byte_count (int)
     *
     * G10 — band label: do NOT derive from radio_index.  The pilot AP runs
     * dual-5G with both wifi0 and wifi1 reporting frequency "5G".  The XIQ
     * `frequency` field is the source of truth.  We map "2.4G" / "5G" / "6G"
     * to user-facing "2.4GHz" / "5GHz" / "6GHz" for display.
     *
     * `radio_index` is derived from the `wifi<N>` suffix of `interface_name`
     * for callers that genuinely need an integer index (e.g. mapping to
     * Zabbix per-radio item keys), but it is NOT used for band selection.
     *
     * `bssid` is colon-formatted via {@see self::macInsertColons()} since
     * XIQ returns BSSIDs without delimiters (G3).
     *
     * @param  array<string,mixed>  $r  Raw radio entry from the wifi-if response.
     * @return array<string,mixed>      Normalised dashboard shape.
     */
    private function normaliseWifiInterface(array $r): array
    {
        // ── Identity ──────────────────────────────────────────────────────
        $ifName     = (string)($r['interface_name'] ?? $r['name'] ?? '');
        $radioIndex = $this->parseRadioIndex($ifName);

        // ── Band label (G10 — from `frequency` field, never from index) ───
        // Closeout sample shows "5G".  Some builds may use "2.4GHz" / "5GHz"
        // already, or numeric MHz (2400 / 5000 / 6000) — handle all three.
        $frequency = $r['frequency'] ?? $r['frequency_band'] ?? $r['band'] ?? null;
        $band      = $this->normaliseBandLabel($frequency);

        // ── Numeric metrics — accept M0 inferred names as aliases ─────────
        $channel = (int)($r['channel'] ?? 0);

        // channel_width may be int MHz or a string like "20MHz" / "VHT80"
        $channelWidth = $this->parseChannelWidth(
            $r['channel_width'] ?? $r['width'] ?? 0
        );

        $txPower = (int)(
            $r['power']
            ?? $r['tx_power']
            ?? 0
        );

        // channel_util is the simple per-channel utilization %, range 0-100.
        // total_utilization is a different aggregate (Tx + Rx + interference
        // + retries) — accept it only if channel_util is missing.
        $channelUtil = (int)(
            $r['channel_util']
            ?? $r['channel_utilization']
            ?? $r['total_utilization']
            ?? 0
        );

        // Noise floor is a signed integer dBm (e.g. -95).  Don't clamp to
        // unsigned — the closeout sample is -95 / -96.
        $noiseFloor = isset($r['noise_floor']) && is_numeric($r['noise_floor'])
            ? (int)$r['noise_floor']
            : 0;

        $clientCount = (int)(
            $r['client_count']
            ?? $r['associated_clients']
            ?? 0
        );
        $ssidCount = (int)($r['ssid_count'] ?? 0);

        // ── BSSID (G3 — XIQ returns colon-less hex) ───────────────────────
        $bssidRaw = (string)($r['mac_address'] ?? $r['bssid'] ?? '');
        $bssid    = $bssidRaw !== '' ? self::macInsertColons($bssidRaw) : '';

        // ── Profile + retry / byte counters ───────────────────────────────
        $profile = (string)($r['radio_profile_name'] ?? $r['profile_name'] ?? '');

        $retryFrames = (int)(
            $r['tx_retry_frame']
            ?? $r['tx_retry_frames']
            ?? $r['tx_retry_rate']
            ?? 0
        );
        $txBytes = (int)($r['tx_byte_count'] ?? $r['tx_bytes'] ?? 0);
        $rxBytes = (int)($r['rx_byte_count'] ?? $r['rx_bytes'] ?? 0);

        return [
            'radio_index'    => $radioIndex,
            'interface_name' => $ifName,
            'band'           => $band,
            'channel'        => $channel,
            'channel_width'  => $channelWidth,
            'tx_power'       => $txPower,
            'channel_util'   => $channelUtil,
            'noise_floor'    => $noiseFloor,
            'client_count'   => $clientCount,
            'ssid_count'     => $ssidCount,
            'bssid'          => $bssid,
            'profile_name'   => $profile,
            'retry_frames'   => $retryFrames,
            'tx_bytes'       => $txBytes,
            'rx_bytes'       => $rxBytes,
            'raw'            => $r,
        ];
    }

    /**
     * Extract the integer radio index from an XIQ interface name.
     *
     * Examples:
     *   "wifi0"  → 0
     *   "wifi1"  → 1
     *   "wifi2"  → 2
     *   ""       → -1
     *   "ethN"   → -1   (caller can detect non-radio entries)
     *
     * Returns -1 when the name doesn't match the wifi<N> pattern.  The
     * dashboard uses this to map to Zabbix per-radio item keys, which use
     * the same wifi<N> indexing.
     */
    private function parseRadioIndex(string $interfaceName): int
    {
        if (preg_match('/^wifi(\d+)$/i', $interfaceName, $m) === 1) {
            return (int)$m[1];
        }
        return -1;
    }

    /**
     * Map an XIQ frequency value to the user-facing band label.
     *
     * Handles the three observed wire formats:
     *   • Enum string:   "2.4G" / "5G" / "6G"        (closeout-confirmed)
     *   • Display string: "2.4GHz" / "5GHz" / "6GHz" (some builds)
     *   • Numeric MHz:   2400 / 5000 / 5800 / 6000   (rare, defensive)
     *
     * Output is always "2.4GHz" / "5GHz" / "6GHz" or empty string when the
     * input is unrecognised.
     */
    private function normaliseBandLabel(mixed $frequency): string
    {
        if ($frequency === null || $frequency === '') {
            return '';
        }

        // Numeric: treat as MHz of the band centre
        if (is_numeric($frequency)) {
            $mhz = (int)$frequency;
            return match (true) {
                $mhz >= 2400 && $mhz < 2500 => '2.4GHz',
                $mhz >= 5000 && $mhz < 5900 => '5GHz',
                $mhz >= 5900 && $mhz < 7200 => '6GHz',
                default                     => '',
            };
        }

        $s = strtoupper(trim((string)$frequency));
        // Strip "HZ" / "GHZ" suffixes for matching, then re-attach a canonical one
        $s = preg_replace('/\s+/', '', $s);
        $s = preg_replace('/(GHZ|HZ)$/', 'G', $s);

        return match ($s) {
            '2.4G', '24G' => '2.4GHz',
            '5G'          => '5GHz',
            '6G'          => '6GHz',
            default       => '',
        };
    }

    /**
     * Coerce a channel-width value to an integer MHz.
     *
     * XIQ has been observed returning either an int (e.g. 20, 80) or a
     * string ("20MHz", "VHT80").  Returns 0 when no integer can be parsed.
     */
    private function parseChannelWidth(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int)$v;
        }
        if (is_string($v) && preg_match('/(\d+)/', $v, $m) === 1) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Normalise a XIQ timestamp to unix seconds.
     *
     * XIQ documents `int64 (unix ms)` for several timestamp fields but the
     * OpenAPI spec is inconsistent and field-by-field validation is impractical.
     * The pragmatic test is: anything > 9,999,999,999 is milliseconds (any
     * unix-seconds value above that maps to year 2286+).  Mirrors the JS
     * preprocessing in Extreme_XIQ_APs.yaml `xiq.ap.lastconnect`.
     *
     * @param  mixed  $v  int, numeric string, or null/garbage.
     * @return int        Unix seconds.  Returns 0 for unparseable input.
     */
    private function normaliseUnixTime(mixed $v): int
    {
        if ($v === null || $v === '' || $v === false) {
            return 0;
        }
        $n = is_numeric($v) ? (int)$v : 0;
        return match (true) {
            $n <= 0            => 0,
            $n > 9_999_999_999 => intdiv($n, 1000),  // ms → s
            default            => $n,
        };
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
