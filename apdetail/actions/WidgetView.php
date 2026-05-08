<?php

declare(strict_types=1);

namespace Modules\APDetail\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;
use Modules\APDetail\Includes\XIQClient;
use Throwable;

/**
 * AP Detail — widget view controller.
 *
 * M2 task #2 scope: gather Device Health metrics (CPU, Memory, Uptime)
 * from the Extreme AP via SNMPv3 template's items and fetch enough
 * history.get data to render server-side SVG sparkline paths.
 *
 * Subsequent M2 tasks layer on Live Telemetry sparklines, System Info
 * KV, Network Info KV, Connectivity Issues, and Recent Events; each
 * extends gatherHealth's pattern of resolve-items → fetch-values →
 * pack-into-data.
 *
 * Framework lessons baked in (M1 closeout, see project plan §B):
 *   G22: hostids field is plural (single-host with setMultiple(false));
 *        fields_values['hostids'] is an array — take first.
 *   G26: All operator-visible payload packed under top-level 'data' key.
 *        Custom keys outside 'data' get stripped by the AJAX layer.
 *   G29: Read field values via $this->fields_values, not getInput().
 *
 * PHP 8.0.30 (G21) — no readonly, no enums, no never, no intersection
 *   types, no new in initializers.
 */
final class WidgetView extends CControllerDashboardWidgetView {

    /** Item key for CPU utilization (Aerohive enterprise OID .26928.1.2.4.0). */
    private const ITEM_KEY_CPU = 'extremeap.cpu.util';

    /** Item key for Memory utilization (Aerohive enterprise OID .26928.1.2.10.0). */
    private const ITEM_KEY_MEM = 'extremeap.mem.util';

    /** Item key for system uptime (standard Zabbix SNMP item). */
    private const ITEM_KEY_UPTIME = 'system.uptime';

    /** CPU alert threshold — matches {$EXTREMEAP.CPU.MAX} default in the per-AP template. */
    private const CPU_THRESHOLD = 80.0;

    /** Memory alert threshold — matches {$EXTREMEAP.MEM.MAX} default in the per-AP template. */
    private const MEM_THRESHOLD = 85.0;

    /** Sparkline viewBox width (SVG user units, stretches via preserveAspectRatio="none"). */
    private const SPARK_W = 200;

    /** Sparkline viewBox height. */
    private const SPARK_H = 36;

    /** Maximum sparkline plot points after downsampling. */
    private const SPARK_POINTS_MAX = 120;

    /** API::History row cap before downsampling — generous enough for 24h@1m polling. */
    private const HISTORY_LIMIT = 1500;

    /** value_type=0 means FLOAT in Zabbix's history table. CPU/Mem are FLOAT. */
    private const VALUE_TYPE_FLOAT = 0;

    /** value_type=3 means UNSIGNED. system.uptime is UNSIGNED. */
    private const VALUE_TYPE_UNSIGNED = 3;

    // ── Live Telemetry strip — 9 sparklines (M2 task #3) ────────────────
    //
    // 6 Zabbix items + 3 XIQ d360 series. The XIQ series come from two
    // /d360/wireless/interfaces-graph calls (one per radio); each response
    // carries both `channel_utilization` and `connected_clients` per bucket,
    // so the third sparkline (AP-total clients) reuses the WIFI0 response.

    /** Uplink in — bps (FLOAT after CHANGE_PER_SECOND + ×8). */
    private const ITEM_KEY_UPLINK_IN  = 'net.if.in[ifHCInOctets.10]';

    /** Uplink out — bps. */
    private const ITEM_KEY_UPLINK_OUT = 'net.if.out[ifHCOutOctets.10]';

    /** ICMP latency item-key prefix — actual key includes [{HOST.IP}]. */
    private const ITEM_KEY_PREFIX_LATENCY = 'icmppingsec[';

    /** ICMP packet loss item-key prefix. */
    private const ITEM_KEY_PREFIX_LOSS    = 'icmppingloss[';

    /** Noise floor — dBm, dependent items off the .26928.1.3.1.0 master string. */
    private const ITEM_KEY_NOISE_W0 = 'extremeap.noise.wifi0';
    private const ITEM_KEY_NOISE_W1 = 'extremeap.noise.wifi1';

    /** Host macros consulted for XIQ access. Resolved against host + parent templates + globals. */
    private const MACRO_XIQ_TOKEN     = '{$XIQ_TOKEN}';
    private const MACRO_XIQ_DEVICE_ID = '{$XIQ_DEVICE_ID}';

    protected function doAction(): void {
        // ── 1. Resolve host from broadcast/form ─────────────────────────
        $field_hostids = $this->fields_values['hostids'] ?? [];
        $host_id = 0;

        if (is_array($field_hostids)) {
            $first = reset($field_hostids);
            $host_id = $first !== false ? (int) $first : 0;
        }
        elseif (is_numeric($field_hostids)) {
            $host_id = (int) $field_hostids;
        }

        $name = $this->getInput('name', $this->widget->getDefaultName());
        $debug_mode = $this->getDebugMode();

        $time_period = $this->normalizeTimePeriod(
            $this->fields_values['time_period'] ?? null
        );

        // ── 2. No-host state — render shell only ────────────────────────
        if ($host_id <= 0) {
            $this->setResponse(new CControllerResponseData([
                'name' => $name,
                'data' => [
                    'host_id'     => 0,
                    'host'        => null,
                    'time_period' => $time_period,
                    'health'      => $this->emptyHealth(),
                    'telemetry'   => $this->emptyTelemetry(),
                    'error'       => _('No AP host selected. Wire this widget to a Host Navigator broadcast or configure a host in the widget settings.'),
                ],
                'user' => ['debug_mode' => $debug_mode],
            ]));
            return;
        }

        // ── 3. Resolve host via internal API ────────────────────────────
        // selectItems pulls the per-host items list with last values; we
        // index by key_ for O(1) lookup in gatherHealth().
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectItems'      => ['itemid', 'key_', 'lastvalue', 'lastclock', 'value_type', 'units'],
            'selectInterfaces' => ['interfaceid', 'ip', 'main', 'type', 'useip', 'dns'],
            'selectHostGroups' => ['groupid', 'name'],
            'hostids'          => [$host_id],
            'limit'            => 1,
        ]);

        $host = $hosts[0] ?? null;

        if ($host === null) {
            $this->setResponse(new CControllerResponseData([
                'name' => $name,
                'data' => [
                    'host_id'     => $host_id,
                    'host'        => null,
                    'time_period' => $time_period,
                    'health'      => $this->emptyHealth(),
                    'telemetry'   => $this->emptyTelemetry(),
                    'error'       => _('AP host not found or access denied.'),
                ],
                'user' => ['debug_mode' => $debug_mode],
            ]));
            return;
        }

        // ── 4. Health gather ────────────────────────────────────────────
        $items = $host['items'] ?? [];

        $health = $this->gatherHealth(
            items:    $items,
            from_ts:  $time_period['from_ts'],
            to_ts:    $time_period['to_ts']
        );

        // ── 5. Live Telemetry gather (M2 #3) ───────────────────────────
        $telemetry = $this->gatherTelemetry(
            items:    $items,
            host_id:  $host_id,
            from_ts:  $time_period['from_ts'],
            to_ts:    $time_period['to_ts']
        );

        // ── 6. Respond ──────────────────────────────────────────────────
        $this->setResponse(new CControllerResponseData([
            'name' => $name,
            'data' => [
                'host_id'     => $host_id,
                'host'        => $host,
                'time_period' => $time_period,
                'health'      => $health,
                'telemetry'   => $telemetry,
                'error'       => null,
            ],
            'user' => ['debug_mode' => $debug_mode],
        ]));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Health gather — CPU + Memory rings + Uptime KV
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build the health metrics payload consumed by widget.view.php.
     *
     * Returns a dict shaped like:
     *   {
     *     cpu:    { value, status, threshold, item_id, spark_line, spark_fill, points },
     *     mem:    { value, status, threshold, item_id, spark_line, spark_fill, points },
     *     uptime: { seconds, formatted, since_epoch, item_id }
     *   }
     *
     * `value` is the most-recent percentage as a float (or null on missing
     * item). `status` is one of 'ok' | 'warn' | 'crit' | 'unknown'.
     * `spark_line` / `spark_fill` are SVG path d="..." strings ready to
     * drop into <path d="..."/>; pre-rendered server-side because the
     * data is small (≤120 points, ~1.5 KB) and PHP rendering keeps the
     * client JS minimal.
     *
     * @param array<int, array<string, mixed>> $items  All host items.
     * @param int $from_ts  Sparkline window start (unix seconds).
     * @param int $to_ts    Sparkline window end (unix seconds).
     * @return array<string, mixed>
     */
    private function gatherHealth(array $items, int $from_ts, int $to_ts): array {
        // Index items by key_ for direct lookup.
        $by_key = [];
        foreach ($items as $item) {
            $by_key[$item['key_']] = $item;
        }

        return [
            'cpu' => $this->buildRing(
                $by_key[self::ITEM_KEY_CPU] ?? null,
                self::CPU_THRESHOLD,
                $from_ts,
                $to_ts
            ),
            'mem' => $this->buildRing(
                $by_key[self::ITEM_KEY_MEM] ?? null,
                self::MEM_THRESHOLD,
                $from_ts,
                $to_ts
            ),
            'uptime' => $this->buildUptime($by_key[self::ITEM_KEY_UPTIME] ?? null),
        ];
    }

    /**
     * Resolve a single ring metric — current value + status + sparkline.
     */
    private function buildRing(?array $item, float $threshold, int $from_ts, int $to_ts): array {
        if ($item === null) {
            return [
                'value'      => null,
                'status'     => 'unknown',
                'threshold'  => $threshold,
                'item_id'    => null,
                'spark_line' => '',
                'spark_fill' => '',
                'points'     => 0,
                'last_clock' => null,
            ];
        }

        $value = is_numeric($item['lastvalue'] ?? null) ? (float) $item['lastvalue'] : null;
        $points = $this->fetchHistoryPoints((int) $item['itemid'], $from_ts, $to_ts);
        $paths  = $this->buildSparklinePath($points);

        return [
            'value'      => $value,
            'status'     => $this->valueStatus($value, $threshold),
            'threshold'  => $threshold,
            'item_id'    => (int) $item['itemid'],
            'spark_line' => $paths['line'],
            'spark_fill' => $paths['fill'],
            'points'     => count($points),
            'last_clock' => is_numeric($item['lastclock'] ?? null) ? (int) $item['lastclock'] : null,
        ];
    }

    /**
     * Resolve uptime — current seconds + human-readable formatting.
     */
    private function buildUptime(?array $item): array {
        $seconds = is_numeric($item['lastvalue'] ?? null) ? (int) $item['lastvalue'] : null;

        return [
            'seconds'     => $seconds,
            'formatted'   => $seconds !== null ? $this->formatUptime($seconds) : '—',
            'since_epoch' => $seconds !== null ? (time() - $seconds) : null,
            'item_id'     => $item !== null ? (int) $item['itemid'] : null,
        ];
    }

    /**
     * Pull and downsample history rows for a single item.
     *
     * Returns rows shaped [{clock:int, value:float}, …] sorted ascending
     * by clock so the sparkline draws left-to-right in chronological order.
     * Downsamples bucket-averaged to SPARK_POINTS_MAX so wide windows
     * (24 h, 7 d, 90 d) don't produce crowded path strings.
     *
     * @return list<array{clock:int, value:float}>
     */
    private function fetchHistoryPoints(int $item_id, int $from_ts, int $to_ts): array {
        if ($from_ts <= 0 || $to_ts <= $from_ts) {
            return [];
        }

        $rows = API::History()->get([
            'output'    => ['clock', 'value'],
            'history'   => self::VALUE_TYPE_FLOAT,
            'itemids'   => [$item_id],
            'time_from' => $from_ts,
            'time_till' => $to_ts,
            'sortfield' => ['clock'],
            'sortorder' => ZBX_SORT_UP,
            'limit'     => self::HISTORY_LIMIT,
        ]);

        if (!is_array($rows) || count($rows) === 0) {
            return [];
        }

        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'clock' => (int) $row['clock'],
                'value' => (float) $row['value'],
            ];
        }

        return $this->downsample($points, self::SPARK_POINTS_MAX);
    }

    /**
     * Bucket-average downsample to at most $target points.
     */
    private function downsample(array $points, int $target): array {
        $n = count($points);
        if ($n <= $target || $target <= 0) {
            return $points;
        }

        $bucket = (int) ceil($n / $target);
        $out = [];

        for ($i = 0; $i < $n; $i += $bucket) {
            $chunk = array_slice($points, $i, $bucket);
            $sum_v = 0.0;
            $sum_c = 0;
            $count = count($chunk);
            foreach ($chunk as $p) {
                $sum_v += $p['value'];
                $sum_c += $p['clock'];
            }
            $out[] = [
                'clock' => (int) ($sum_c / $count),
                'value' => $sum_v / $count,
            ];
        }

        return $out;
    }

    /**
     * Build SVG path d="..." strings for a sparkline line and its fill area.
     *
     * Auto-scales y between min and max of the data with a 5% range floor
     * so a near-flat signal doesn't get amplified to look chaotic. Returns
     * empty strings when there are no points (view renders an empty state).
     *
     * @param list<array{clock:int, value:float}> $points
     * @return array{line:string, fill:string}
     */
    private function buildSparklinePath(array $points): array {
        $n = count($points);
        if ($n === 0) {
            return ['line' => '', 'fill' => ''];
        }

        $w = (float) self::SPARK_W;
        $h = (float) self::SPARK_H;
        $pad = 1.5;
        $usable_h = $h - 2 * $pad;

        if ($n === 1) {
            $y = $h / 2;
            $line = sprintf('M0,%.2f L%.2f,%.2f', $y, $w, $y);
            $fill = sprintf('M0,%.2f L%.2f,%.2f L%.2f,%.2f L0,%.2f Z', $y, $w, $y, $w, $h, $h);
            return ['line' => $line, 'fill' => $fill];
        }

        $values = array_column($points, 'value');
        $min = min($values);
        $max = max($values);
        $range = max($max - $min, 5.0);   // 5% floor — avoids amplifying jitter on flat signals.

        // Recenter window when range was bumped to the floor — keeps the
        // line centered vertically rather than hugging the bottom edge.
        $headroom = ($range - ($max - $min)) / 2;
        $plot_min = $min - $headroom;

        $step = $w / ($n - 1);
        $xys = [];
        foreach ($values as $i => $v) {
            $x = $i * $step;
            $y = $h - $pad - (($v - $plot_min) / $range) * $usable_h;
            $xys[] = [$x, $y];
        }

        $line_parts = [];
        foreach ($xys as $i => $xy) {
            $line_parts[] = ($i === 0 ? 'M' : 'L') . sprintf('%.2f,%.2f', $xy[0], $xy[1]);
        }
        $line = implode(' ', $line_parts);

        // Fill = same path then drop to the bottom-right and bottom-left and close.
        $first = $xys[0];
        $last  = $xys[$n - 1];
        $fill = sprintf('M%.2f,%.2f', $first[0], $h)
              . ' ' . implode(' ', array_map(
                    static fn($p) => 'L' . sprintf('%.2f,%.2f', $p[0], $p[1]),
                    $xys
                ))
              . sprintf(' L%.2f,%.2f Z', $last[0], $h);

        return ['line' => $line, 'fill' => $fill];
    }

    /**
     * Map a metric value to a status bucket relative to its alert threshold.
     *
     *   value >= threshold              → 'crit'  (red)   triggers will fire
     *   value >= threshold * 0.85       → 'warn'  (amber) approaching alert
     *   value <  threshold * 0.85       → 'ok'    (green) healthy
     *   value === null                  → 'unknown'       no data
     */
    private function valueStatus(?float $value, float $threshold): string {
        if ($value === null) {
            return 'unknown';
        }
        if ($value >= $threshold) {
            return 'crit';
        }
        if ($value >= $threshold * 0.85) {
            return 'warn';
        }
        return 'ok';
    }

    /**
     * Human-readable uptime: "12d 4h 13m" or "47m 12s" for short uptimes.
     * Returns "—" for negative or wildly large nonsense values.
     */
    private function formatUptime(int $seconds): string {
        if ($seconds < 0 || $seconds > 365 * 86400 * 10) {
            return '—';
        }

        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($d > 0) {
            return sprintf('%dd %dh %dm', $d, $h, $m);
        }
        if ($h > 0) {
            return sprintf('%dh %dm', $h, $m);
        }
        if ($m > 0) {
            return sprintf('%dm %ds', $m, $s);
        }
        return sprintf('%ds', $s);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Live Telemetry strip — 9 sparklines (M2 #3)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build the Live Telemetry payload — six Zabbix-history sparklines
     * plus three XIQ d360 sparklines (channel utilisation per radio +
     * AP-total connected clients).
     *
     * Shape returned (always 9 keys; missing data → empty paths):
     *   [
     *     {key, label, source, units, value, item_id|null,
     *      spark_line, spark_fill, points}, …
     *   ]
     *
     * Order matches the dashboard layout:
     *   uplink_in, uplink_out, latency, loss,
     *   noise_w0, noise_w1,
     *   chan_util_w0, chan_util_w1, ap_clients
     *
     * XIQ calls are skipped silently when {$XIQ_TOKEN} or {$XIQ_DEVICE_ID}
     * macros aren't resolvable — the cells render as empty placeholders
     * (M5 §11.5 graceful-degradation pattern).  Any XIQ exception is
     * caught, logged, and treated the same way so a single XIQ outage
     * never breaks the Overview tab's Zabbix-only sparklines.
     *
     * @param array<int, array<string, mixed>> $items   Host items.
     * @param int $host_id  Zabbix host id (for macro resolution).
     * @param int $from_ts  Sparkline window start (unix s).
     * @param int $to_ts    Sparkline window end   (unix s).
     * @return array<string, array<string, mixed>>
     */
    private function gatherTelemetry(array $items, int $host_id, int $from_ts, int $to_ts): array {
        // Index host items — exact-key lookup for fixed keys, prefix scan
        // for icmppingsec/loss (whose key includes [{HOST.IP}]).
        $by_key    = [];
        $by_prefix = [];
        foreach ($items as $item) {
            $by_key[$item['key_']] = $item;
            foreach ([self::ITEM_KEY_PREFIX_LATENCY, self::ITEM_KEY_PREFIX_LOSS] as $pfx) {
                if (str_starts_with($item['key_'], $pfx) && !isset($by_prefix[$pfx])) {
                    $by_prefix[$pfx] = $item;
                }
            }
        }

        // ── Zabbix sparklines ──────────────────────────────────────────
        $zbx_specs = [
            'uplink_in'  => [_('Uplink In'),    'bps',  $by_key[self::ITEM_KEY_UPLINK_IN]  ?? null],
            'uplink_out' => [_('Uplink Out'),   'bps',  $by_key[self::ITEM_KEY_UPLINK_OUT] ?? null],
            'latency'    => [_('ICMP Latency'), 'ms',   $by_prefix[self::ITEM_KEY_PREFIX_LATENCY] ?? null],
            'loss'       => [_('Packet Loss'),  '%',    $by_prefix[self::ITEM_KEY_PREFIX_LOSS]    ?? null],
            'noise_w0'   => [_('Noise W0'),     'dBm',  $by_key[self::ITEM_KEY_NOISE_W0]   ?? null],
            'noise_w1'   => [_('Noise W1'),     'dBm',  $by_key[self::ITEM_KEY_NOISE_W1]   ?? null],
        ];

        // Single batched History.get for all six items (all FLOAT → history=0).
        $itemids = [];
        foreach ($zbx_specs as [$_label, $_units, $item]) {
            if ($item !== null) {
                $itemids[] = (int) $item['itemid'];
            }
        }
        $history_by_id = $this->fetchTelemetryHistory($itemids, $from_ts, $to_ts);

        $cells = [];
        foreach ($zbx_specs as $key => [$label, $units, $item]) {
            $cells[$key] = $this->buildTelemetryCell(
                key:    $key,
                label:  $label,
                source: 'ZBX',
                units:  $units,
                value:  $item !== null && is_numeric($item['lastvalue'] ?? null)
                            ? (float) $item['lastvalue']
                            : null,
                points: $item !== null
                            ? ($history_by_id[(int) $item['itemid']] ?? [])
                            : [],
                item_id: $item !== null ? (int) $item['itemid'] : null,
            );
        }

        // ── XIQ d360 sparklines ────────────────────────────────────────
        // Per G30 the only available source for channel utilisation /
        // per-bucket client count is /d360/wireless/interfaces-graph.
        // One call per radio.  Each response carries both metrics, so
        // ap_clients reuses the WIFI0 response (same AP-total either way).
        [$chan_w0_points, $chan_w1_points, $clients_points] =
            $this->fetchXiqGraphSeries($host_id, $from_ts, $to_ts);

        $cells['chan_util_w0'] = $this->buildTelemetryCell(
            key: 'chan_util_w0', label: _('Ch Util W0'), source: 'XIQ', units: '%',
            value: $this->lastValue($chan_w0_points), points: $chan_w0_points, item_id: null
        );
        $cells['chan_util_w1'] = $this->buildTelemetryCell(
            key: 'chan_util_w1', label: _('Ch Util W1'), source: 'XIQ', units: '%',
            value: $this->lastValue($chan_w1_points), points: $chan_w1_points, item_id: null
        );
        $cells['ap_clients'] = $this->buildTelemetryCell(
            key: 'ap_clients', label: _('Clients'), source: 'XIQ', units: '',
            value: $this->lastValue($clients_points), points: $clients_points, item_id: null
        );

        return $cells;
    }

    /**
     * Batched History.get for the (up-to-six) Zabbix telemetry items,
     * indexed by itemid for O(1) attach in gatherTelemetry().
     *
     * All six items are FLOAT → single call against history=0.  Each
     * series is downsampled to SPARK_POINTS_MAX so wide windows don't
     * produce kilobyte-scale path strings.
     *
     * @param  list<int> $item_ids
     * @return array<int, list<array{clock:int,value:float}>>
     */
    private function fetchTelemetryHistory(array $item_ids, int $from_ts, int $to_ts): array {
        if ($item_ids === [] || $from_ts <= 0 || $to_ts <= $from_ts) {
            return [];
        }

        $rows = API::History()->get([
            'output'    => ['itemid', 'clock', 'value'],
            'history'   => self::VALUE_TYPE_FLOAT,
            'itemids'   => $item_ids,
            'time_from' => $from_ts,
            'time_till' => $to_ts,
            'sortfield' => ['clock'],
            'sortorder' => ZBX_SORT_UP,
            'limit'     => self::HISTORY_LIMIT * count($item_ids),
        ]);

        if (!is_array($rows)) {
            return [];
        }

        $by_id = [];
        foreach ($rows as $row) {
            $id = (int) $row['itemid'];
            $by_id[$id][] = ['clock' => (int) $row['clock'], 'value' => (float) $row['value']];
        }

        foreach ($by_id as $id => $points) {
            $by_id[$id] = $this->downsample($points, self::SPARK_POINTS_MAX);
        }

        return $by_id;
    }

    /**
     * Resolve XIQ access for $host_id, then fetch per-radio interface
     * graphs.  Returns three downsampled point lists in fixed order:
     *
     *   [chan_util_w0, chan_util_w1, ap_clients]
     *
     * Returns three empty lists when:
     *   - macros {$XIQ_TOKEN} or {$XIQ_DEVICE_ID} are absent
     *   - the time window is unset
     *   - any XIQ call throws (logged via error_log, not surfaced to UI;
     *     M5 §11.5 stale-data banner is the future home for surfacing).
     *
     * @return array{0: list<array{clock:int,value:float}>, 1: list<array{clock:int,value:float}>, 2: list<array{clock:int,value:float}>}
     */
    private function fetchXiqGraphSeries(int $host_id, int $from_ts, int $to_ts): array {
        $empty = [[], [], []];

        if ($host_id <= 0 || $from_ts <= 0 || $to_ts <= $from_ts) {
            return $empty;
        }

        $macros = $this->resolveMacros($host_id, [
            self::MACRO_XIQ_TOKEN,
            self::MACRO_XIQ_DEVICE_ID,
        ]);

        $token       = $macros[self::MACRO_XIQ_TOKEN]     ?? '';
        $device_id   = (int) ($macros[self::MACRO_XIQ_DEVICE_ID] ?? 0);
        $xiq_baseurl = (string) ($this->fields_values['xiq_host'] ?? '');

        if ($token === '' || $device_id <= 0) {
            return $empty;
        }

        try {
            $client = $xiq_baseurl !== ''
                ? XIQClient::fromToken($token, $xiq_baseurl)
                : XIQClient::fromToken($token);

            // Two sequential calls — XIQClient::httpGetMulti shares query
            // params, which doesn't fit a per-radio fan-out.  Each response
            // is small (~7 buckets at ~10 min interval), so wall-clock cost
            // is bounded by network RTT.  If profiling shows this is hot,
            // expose a public batch wrapper that takes per-call params.
            $w0 = $client->getInterfacesGraph($device_id, 'WIFI0', $from_ts, $to_ts);
            $w1 = $client->getInterfacesGraph($device_id, 'WIFI1', $from_ts, $to_ts);
        }
        catch (Throwable $e) {
            error_log('[apdetail.telemetry] XIQ d360 fetch failed: ' . $e->getMessage());
            return $empty;
        }

        return [
            $this->xiqGraphSeries($w0, 'channel_utilization'),
            $this->xiqGraphSeries($w1, 'channel_utilization'),
            $this->xiqGraphSeries($w0, 'connected_clients'),
        ];
    }

    /**
     * Convert an XIQ /d360/wireless/interfaces-graph payload into the
     * {clock, value} shape the sparkline renderer expects.
     *
     * G9: the d360 endpoint returns rows in arbitrary order — we always
     * sort ascending by timestamp before plotting.
     *
     * Timestamps in the d360 response are unix milliseconds; convert to
     * seconds to match the Zabbix history rows.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @param  string $field   'channel_utilization' | 'connected_clients'
     * @return list<array{clock:int, value:float}>
     */
    private function xiqGraphSeries(array $rows, string $field): array {
        $points = [];
        foreach ($rows as $row) {
            $ts_raw = $row['timestamp'] ?? null;
            if (!is_numeric($ts_raw) || !isset($row[$field]) || !is_numeric($row[$field])) {
                continue;
            }
            $ts  = (int) $ts_raw;
            $clk = $ts > 1_000_000_000_000 ? intdiv($ts, 1000) : $ts;
            $points[] = ['clock' => $clk, 'value' => (float) $row[$field]];
        }
        usort($points, static fn(array $a, array $b): int => $a['clock'] <=> $b['clock']);
        return $points;
    }

    /**
     * Latest value of a points list, or null when empty.
     *
     * @param  list<array{clock:int, value:float}> $points
     */
    private function lastValue(array $points): ?float {
        if ($points === []) {
            return null;
        }
        $last = end($points);
        return $last !== false ? (float) $last['value'] : null;
    }

    /**
     * Pack a single telemetry cell — current value + sparkline paths +
     * metadata the view layer needs to render and to broadcast _itemids
     * on click.
     *
     * @param  list<array{clock:int, value:float}> $points
     */
    private function buildTelemetryCell(
        string $key,
        string $label,
        string $source,
        string $units,
        ?float $value,
        array  $points,
        ?int   $item_id
    ): array {
        $paths = $this->buildSparklinePath($points);
        return [
            'key'        => $key,
            'label'      => $label,
            'source'     => $source,           // 'ZBX' | 'XIQ'
            'units'      => $units,
            'value'      => $value,
            'value_text' => $this->formatTelemetryValue($value, $units),
            'item_id'    => $item_id,
            'spark_line' => $paths['line'],
            'spark_fill' => $paths['fill'],
            'points'     => count($points),
        ];
    }

    /**
     * Render a numeric value at human scale for the cell's "current value"
     * caption.  Tuned per-units so a 950 Mbps uplink, 0.7 ms latency, and
     * a 4% loss number all read at a glance.
     */
    private function formatTelemetryValue(?float $value, string $units): string {
        if ($value === null) {
            return '—';
        }
        switch ($units) {
            case 'bps':
                if ($value >= 1_000_000_000) return sprintf('%.2f Gbps', $value / 1_000_000_000);
                if ($value >= 1_000_000)     return sprintf('%.1f Mbps', $value / 1_000_000);
                if ($value >= 1_000)         return sprintf('%.1f Kbps', $value / 1_000);
                return sprintf('%d bps', (int) round($value));
            case 'ms':
                // icmppingsec is in seconds; render as ms.
                $ms = $value * 1000;
                return $ms < 10 ? sprintf('%.2f ms', $ms) : sprintf('%.1f ms', $ms);
            case '%':
                return sprintf('%.1f%%', $value);
            case 'dBm':
                return sprintf('%d dBm', (int) round($value));
            case '':
                return (string) (int) round($value);
            default:
                return sprintf('%g %s', $value, $units);
        }
    }

    /**
     * Empty-state telemetry payload — same nine keys as gatherTelemetry()
     * so the view's iteration order is stable across host-selected,
     * host-missing, and error renders.
     */
    private function emptyTelemetry(): array {
        $blank = static fn(string $key, string $label, string $source, string $units): array => [
            'key'        => $key,
            'label'      => $label,
            'source'     => $source,
            'units'      => $units,
            'value'      => null,
            'value_text' => '—',
            'item_id'    => null,
            'spark_line' => '',
            'spark_fill' => '',
            'points'     => 0,
        ];

        return [
            'uplink_in'    => $blank('uplink_in',    _('Uplink In'),    'ZBX', 'bps'),
            'uplink_out'   => $blank('uplink_out',   _('Uplink Out'),   'ZBX', 'bps'),
            'latency'      => $blank('latency',      _('ICMP Latency'), 'ZBX', 'ms'),
            'loss'         => $blank('loss',         _('Packet Loss'),  'ZBX', '%'),
            'noise_w0'     => $blank('noise_w0',     _('Noise W0'),     'ZBX', 'dBm'),
            'noise_w1'     => $blank('noise_w1',     _('Noise W1'),     'ZBX', 'dBm'),
            'chan_util_w0' => $blank('chan_util_w0', _('Ch Util W0'),   'XIQ', '%'),
            'chan_util_w1' => $blank('chan_util_w1', _('Ch Util W1'),   'XIQ', '%'),
            'ap_clients'   => $blank('ap_clients',   _('Clients'),      'XIQ', ''),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Macro resolver — host → parent templates → globals
    // ──────────────────────────────────────────────────────────────────

    /**
     * Resolve a list of macro names against $host_id.  Returns
     * [macro_name => value] only for macros found.  Closer entities
     * (host, then direct templates, then grandparents, then globals)
     * win over more distant ones — same priority CMacrosResolverGeneral
     * applies internally.
     *
     * Direct DB read on hostmacro/globalmacro because API::UserMacro()
     * masks Secret-type values for every caller; we need the cleartext
     * server-side to authenticate to XIQ.  Vault-backed macros (type 2)
     * would store a vault path here and would need separate handling —
     * the deployment uses Secret type for {$XIQ_TOKEN}.
     *
     * Lifted from portdetail/actions/CyclePoe.php::resolveMacros().
     *
     * @param  list<string> $names
     * @return array<string, string>
     */
    private function resolveMacros(int $host_id, array $names): array {
        $out = [];

        if ($host_id <= 0 || $names === []) {
            return $out;
        }

        $chain = $this->getHostMacroChain($host_id);

        if ($chain && $names) {
            $rows = \DBfetchArray(\DBselect(
                'SELECT hm.hostid,hm.macro,hm.value,hm.type'.
                ' FROM hostmacro hm'.
                ' WHERE '.\dbConditionInt('hm.hostid', $chain).
                ' AND '.\dbConditionString('hm.macro', $names)
            ));

            $priority = array_flip($chain);
            $best = [];
            foreach ($rows as $row) {
                $prio = $priority[$row['hostid']] ?? PHP_INT_MAX;
                if (!isset($best[$row['macro']]) || $prio < $best[$row['macro']][0]) {
                    $best[$row['macro']] = [$prio, (string) ($row['value'] ?? '')];
                }
            }
            foreach ($best as $macro => [, $value]) {
                $out[$macro] = $value;
            }
        }

        $missing = array_values(array_diff($names, array_keys($out)));
        if ($missing) {
            $global_rows = \DBfetchArray(\DBselect(
                'SELECT gm.macro,gm.value'.
                ' FROM globalmacro gm'.
                ' WHERE '.\dbConditionString('gm.macro', $missing)
            ));
            foreach ($global_rows as $row) {
                $out[$row['macro']] = (string) ($row['value'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Returns [host_id, template_id_1, …] in BFS order so callers can use
     * array index as merge priority.  Caps recursion at 10 templates as a
     * sanity guard against pathological circular template references.
     *
     * @return list<int>
     */
    private function getHostMacroChain(int $host_id): array {
        $chain = [$host_id];

        $hosts = API::Host()->get([
            'output'                => ['hostid'],
            'hostids'               => [$host_id],
            'selectParentTemplates' => ['templateid'],
        ]);
        if (!$hosts) {
            return $chain;
        }

        $frontier = array_map('intval',
            array_column($hosts[0]['parentTemplates'] ?? [], 'templateid')
        );

        $depth = 10;
        while ($frontier && $depth-- > 0) {
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
                    $tid = (int) $pt['templateid'];
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
     * Empty-state health payload — used when there's no host yet so the
     * view can still render its skeleton without conditionals.
     */
    private function emptyHealth(): array {
        return [
            'cpu'    => $this->buildRing(null, self::CPU_THRESHOLD, 0, 0),
            'mem'    => $this->buildRing(null, self::MEM_THRESHOLD, 0, 0),
            'uptime' => $this->buildUptime(null),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Time period normalization
    // ──────────────────────────────────────────────────────────────────

    /**
     * Coerce a fields_values['time_period'] entry into the shape the
     * view + history.get calls need. CWidgetFieldTimePeriod populates
     * 'from', 'to', 'from_ts', 'to_ts' — we forward all four. The *_ts
     * values are unix timestamps already resolved from relative strings
     * by the framework.
     *
     * @param mixed $period Raw value from fields_values['time_period'].
     * @return array{from:string, to:string, from_ts:int, to_ts:int}
     */
    private function normalizeTimePeriod($period): array {
        $now = time();
        $hour_ago = $now - 3600;

        if (!is_array($period)) {
            return ['from' => 'now-1h', 'to' => 'now', 'from_ts' => $hour_ago, 'to_ts' => $now];
        }

        $from_ts = isset($period['from_ts']) && is_numeric($period['from_ts'])
            ? (int) $period['from_ts'] : $hour_ago;
        $to_ts = isset($period['to_ts']) && is_numeric($period['to_ts'])
            ? (int) $period['to_ts'] : $now;

        return [
            'from'    => (string) ($period['from'] ?? 'now-1h'),
            'to'      => (string) ($period['to']   ?? 'now'),
            'from_ts' => $from_ts,
            'to_ts'   => $to_ts,
        ];
    }
}
