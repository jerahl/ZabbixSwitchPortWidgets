<?php

declare(strict_types=1);

namespace Modules\APDetail\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

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
                    'error'       => _('AP host not found or access denied.'),
                ],
                'user' => ['debug_mode' => $debug_mode],
            ]));
            return;
        }

        // ── 4. Health gather ────────────────────────────────────────────
        $health = $this->gatherHealth(
            items:    $host['items'] ?? [],
            from_ts:  $time_period['from_ts'],
            to_ts:    $time_period['to_ts']
        );

        // ── 5. Respond ──────────────────────────────────────────────────
        $this->setResponse(new CControllerResponseData([
            'name' => $name,
            'data' => [
                'host_id'     => $host_id,
                'host'        => $host,
                'time_period' => $time_period,
                'health'      => $health,
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
