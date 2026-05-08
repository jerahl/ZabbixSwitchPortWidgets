<?php

declare(strict_types=1);

/**
 * AP Detail — widget body view.
 *
 * G25: Output goes through CWidgetView->addItem->show; do not echo HTML.
 * G26: Operator-visible payload is at $data['data'].
 *
 * @var CView $this
 * @var array $data {
 *     name: string,
 *     data: array{
 *         host_id:     int,
 *         host:        array|null,
 *         time_period: array{from:string, to:string, from_ts:int, to_ts:int},
 *         health:      array{
 *             cpu:    array,
 *             mem:    array,
 *             uptime: array
 *         },
 *         error:       string|null
 *     },
 *     user: array{debug_mode:bool}
 * }
 */

$payload     = $data['data'] ?? [];
$error       = $payload['error']       ?? null;
$host        = $payload['host']        ?? null;
$host_id     = (int) ($payload['host_id'] ?? 0);
$time_period = $payload['time_period'] ?? ['from' => 'now-1h', 'to' => 'now', 'from_ts' => 0, 'to_ts' => 0];
$health      = $payload['health']      ?? null;
$telemetry   = $payload['telemetry']   ?? [];
$connectivity = $payload['connectivity'] ?? ['issues' => [], 'count' => 0, 'worst' => 'ok', 'reason' => ''];
$system_info  = is_array($payload['system_info'] ?? null) ? $payload['system_info'] : [];
$network_info = is_array($payload['network_info'] ?? null) ? $payload['network_info'] : [];

// ── Error state ───────────────────────────────────────────────────────────
if ($error !== null) {
    $error_body = (new CDiv([
        (new CSpan('⚠'))->addClass('ap-error__icon'),
        (new CSpan($error))->addClass('ap-error__msg'),
    ]))->addClass('ap-error');

    (new CWidgetView($data))->addItem($error_body)->show();
    return;
}

// ── Tab definitions ───────────────────────────────────────────────────────
$TABS = [
    'overview' => _('Overview'),
    'wireless' => _('Wireless'),
    'wired'    => _('Wired'),
    'clients'  => _('Clients'),
    'events'   => _('Events'),
    'alerts'   => _('Alerts'),
];
$DEFAULT_TAB = 'overview';

// ── Tab bar ───────────────────────────────────────────────────────────────
$tabs_nav = (new CTag('nav', true))
    ->addClass('ap-tabs')
    ->setAttribute('role', 'tablist')
    ->setAttribute('aria-label', _('AP detail tabs'));

// Issue badge attached to the Overview tab when Connectivity Issues panel
// reports any rule firing. Severity drives the badge colour (crit > warn).
$conn_count = (int) ($connectivity['count'] ?? 0);
$conn_worst = (string) ($connectivity['worst'] ?? 'ok');

foreach ($TABS as $key => $label) {
    $btn = (new CTag('button', true))
        ->setAttribute('type', 'button')
        ->setAttribute('data-tab', $key)
        ->setAttribute('role', 'tab')
        ->setAttribute('tabindex', $key === $DEFAULT_TAB ? '0' : '-1')
        ->setAttribute('aria-selected', $key === $DEFAULT_TAB ? 'true' : 'false')
        ->addClass('ap-tab');

    $btn->addItem((new CSpan($label))->addClass('ap-tab__label'));

    if ($key === 'overview' && $conn_count > 0) {
        $btn->addItem(
            (new CSpan((string) $conn_count))
                ->addClass('ap-tab__badge')
                ->addClass('ap-tab__badge--' . $conn_worst)
                ->setAttribute('aria-label', sprintf(
                    _n('%d connectivity issue', '%d connectivity issues', $conn_count),
                    $conn_count
                ))
        );
    }

    if ($key === $DEFAULT_TAB) {
        $btn->addClass('ap-tab--active');
    }

    $tabs_nav->addItem($btn);
}

// ─────────────────────────────────────────────────────────────────────────
//  Overview tab — Device Health card (M2 task #2)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Render a single ring cell (CPU or Memory).
 *
 * Layout per cell (matches the React mockup):
 *
 *   ┌─────────────────────────────────┐
 *   │           ┌──────┐              │
 *   │           │  12% │   ← ring     │
 *   │           └──────┘              │
 *   │            CPU                  │   ← label
 *   │       alert at 80%              │   ← caption
 *   │ /\/\/\/\/\/\/\/\/\/\            │   ← sparkline
 *   └─────────────────────────────────┘
 *
 * The donut SVG arc length is set client-side by class.widget.js's
 * _animateRings() so the fill animates in. We pre-compute a no-op
 * stroke-dasharray here ("0 999") so the unanimated server render
 * shows an empty ring rather than a fully-filled one before JS runs.
 */
$render_ring_cell = static function (string $key, string $label, array $ring): CDiv {
    $value      = $ring['value'];        // float|null
    $status     = (string) ($ring['status'] ?? 'unknown');
    $threshold  = (float) ($ring['threshold'] ?? 0.0);
    $spark_line = (string) ($ring['spark_line'] ?? '');
    $spark_fill = (string) ($ring['spark_fill'] ?? '');
    $points_n   = (int) ($ring['points'] ?? 0);

    // Big-number text inside the donut.
    $value_text = $value === null
        ? '—'
        : (round($value) . '%');

    // Sparkline group — only emit paths if we have a non-empty d="..." string.
    $spark_group = (new CTag('svg', true))
        ->addClass('ap-ring-cell__spark')
        ->setAttribute('viewBox', '0 0 200 36')
        ->setAttribute('preserveAspectRatio', 'none')
        ->setAttribute('aria-hidden', 'true');

    if ($spark_line !== '') {
        if ($spark_fill !== '') {
            $spark_group->addItem(
                (new CTag('path', true))
                    ->addClass('ap-ring-cell__spark-fill')
                    ->setAttribute('d', $spark_fill)
            );
        }
        $spark_group->addItem(
            (new CTag('path', true))
                ->addClass('ap-ring-cell__spark-line')
                ->setAttribute('d', $spark_line)
        );
    }
    else {
        // Empty-state label inside the sparkline strip.
        $spark_group->addItem(
            (new CTag('text', true, _('no history yet')))
                ->setAttribute('x', '100')
                ->setAttribute('y', '22')
                ->setAttribute('text-anchor', 'middle')
                ->addClass('ap-ring-cell__spark-empty')
        );
    }

    // Donut SVG. r=40 → circumference ≈ 251.3 (matches JS _animateRings).
    // Larger radius matches the mockup's 92px ring with strokeWidth 6.
    $ring_svg = (new CTag('svg', true))
        ->addClass('ap-ring__svg')
        ->setAttribute('viewBox', '0 0 92 92')
        ->setAttribute('aria-hidden', 'true')
        ->addItem(
            (new CTag('circle', true))
                ->addClass('ap-ring__track')
                ->setAttribute('cx', '46')->setAttribute('cy', '46')->setAttribute('r', '40')
        )
        ->addItem(
            (new CTag('circle', true))
                ->addClass('ap-ring__fill')
                ->setAttribute('cx', '46')->setAttribute('cy', '46')->setAttribute('r', '40')
                ->setAttribute('stroke-dasharray', '0 999')
                ->setAttribute('data-value', $value === null ? '0' : (string) $value)
        );

    // Centred value lives inside the ring; label + caption stack below it.
    $ring_text = (new CDiv(
        (new CDiv($value_text))->addClass('ap-ring-cell__value')
    ))->addClass('ap-ring-cell__text');

    $caption = $value === null
        ? _('no data')
        : sprintf(_('alert at %d%%'), (int) $threshold);

    return (new CDiv([
        (new CDiv([$ring_svg, $ring_text]))->addClass('ap-ring-cell__ring'),
        (new CDiv($label))->addClass('ap-ring-cell__label'),
        (new CDiv($caption))->addClass('ap-ring-cell__caption'),
        $spark_group,
    ]))
        ->addClass('ap-ring-cell')
        ->addClass('ap-ring-cell--' . $status)
        ->setAttribute('data-metric', $key)
        ->setAttribute('data-points', (string) $points_n);
};

// Build the two ring cells.
$cpu_ring = $health['cpu'] ?? [];
$mem_ring = $health['mem'] ?? [];
$uptime   = $health['uptime'] ?? ['formatted' => '—', 'seconds' => null];

$health_grid = (new CDiv([
    $render_ring_cell('cpu', _('CPU'),    $cpu_ring),
    $render_ring_cell('mem', _('Memory'), $mem_ring),
]))->addClass('ap-health-grid');

// Uptime KV row — sits beneath the rings.
$uptime_value = $uptime['formatted'] ?? '—';
$uptime_title = $uptime['since_epoch'] !== null
    ? sprintf(_('Last reboot: %s'), zbx_date2str(DATE_TIME_FORMAT, $uptime['since_epoch']))
    : '';

$uptime_row = (new CDiv([
    (new CDiv(_('Uptime')))->addClass('ap-health-uptime__label'),
    (new CDiv($uptime_value))
        ->addClass('ap-health-uptime__value')
        ->setAttribute('title', $uptime_title),
]))->addClass('ap-health-uptime');

// Card header — title + source badge + small meta line.
$health_card_head = (new CDiv([
    (new CTag('h3', true, _('Device Health')))->addClass('ap-card__title'),
    (new CSpan('ZBX'))->addClass('ap-source')->addClass('ap-source--zbx'),
    (new CDiv())->addClass('ap-card__spacer'),
    (new CSpan(_('SNMP via Extreme AP template · 1m polling · 90d history')))
        ->addClass('ap-card__meta'),
]))->addClass('ap-card__head');

$health_card = (new CDiv([
    $health_card_head,
    $health_grid,
    $uptime_row,
]))->addClass('ap-card')->addClass('ap-card--health');

// ─────────────────────────────────────────────────────────────────────────
//  Overview tab — Live Telemetry strip (M2 task #3)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Render a single telemetry cell — label · value · sparkline.
 *
 * Cells with a Zabbix item_id are click-broadcast targets for the native
 * Graph (classic) widget — class.widget.js intercepts clicks and emits
 * _itemids. XIQ-source cells render the same shell but lack the
 * data-item-id hook and have a non-interactive cursor.
 */
$render_telemetry_cell = static function (array $cell): CDiv {
    $key        = (string) ($cell['key']    ?? '');
    $label      = (string) ($cell['label']  ?? '');
    $source     = (string) ($cell['source'] ?? 'ZBX');
    $value_text = (string) ($cell['value_text'] ?? '—');
    $spark_line = (string) ($cell['spark_line'] ?? '');
    $spark_fill = (string) ($cell['spark_fill'] ?? '');
    $points_n   = (int)    ($cell['points'] ?? 0);
    $item_id    = $cell['item_id'] ?? null;

    $spark = (new CTag('svg', true))
        ->addClass('ap-tele-cell__spark')
        ->setAttribute('viewBox', '0 0 200 36')
        ->setAttribute('preserveAspectRatio', 'none')
        ->setAttribute('aria-hidden', 'true');

    if ($spark_line !== '') {
        if ($spark_fill !== '') {
            $spark->addItem(
                (new CTag('path', true))
                    ->addClass('ap-tele-cell__spark-fill')
                    ->setAttribute('d', $spark_fill)
            );
        }
        $spark->addItem(
            (new CTag('path', true))
                ->addClass('ap-tele-cell__spark-line')
                ->setAttribute('d', $spark_line)
        );
    }
    else {
        $spark->addItem(
            (new CTag('text', true, _('no data')))
                ->setAttribute('x', '100')
                ->setAttribute('y', '22')
                ->setAttribute('text-anchor', 'middle')
                ->addClass('ap-tele-cell__spark-empty')
        );
    }

    $head = (new CDiv([
        (new CSpan($label))->addClass('ap-tele-cell__label'),
        (new CSpan($source))
            ->addClass('ap-source')
            ->addClass('ap-source--' . strtolower($source)),
    ]))->addClass('ap-tele-cell__head');

    $value = (new CDiv($value_text))->addClass('ap-tele-cell__value');

    $cell_div = (new CDiv([$head, $value, $spark]))
        ->addClass('ap-tele-cell')
        ->addClass('ap-tele-cell--' . strtolower($source))
        ->setAttribute('data-tele-key', $key)
        ->setAttribute('data-points', (string) $points_n);

    if (is_int($item_id) && $item_id > 0) {
        $cell_div
            ->addClass('ap-tele-cell--clickable')
            ->setAttribute('data-itemid', (string) $item_id)
            ->setAttribute('role', 'button')
            ->setAttribute('tabindex', '0')
            ->setAttribute('title', sprintf(_('Open %s in Graph widget'), $label));
    }

    return $cell_div;
};

// Cell render order matches the project plan §8.3 table.
$telemetry_keys = [
    'uplink_in', 'uplink_out', 'latency', 'loss',
    'noise_w0', 'noise_w1',
    'chan_util_w0', 'chan_util_w1', 'ap_clients',
];

$telemetry_grid = (new CDiv())->addClass('ap-tele__grid');
foreach ($telemetry_keys as $tk) {
    $cell = $telemetry[$tk] ?? null;
    if (!is_array($cell)) {
        continue;
    }
    $telemetry_grid->addItem($render_telemetry_cell($cell));
}

$telemetry_card_head = (new CDiv([
    (new CTag('h3', true, _('Live Telemetry')))->addClass('ap-card__title'),
    (new CDiv())->addClass('ap-card__spacer'),
    (new CSpan(_('Click a Zabbix cell to open it in the Graph widget')))
        ->addClass('ap-card__meta'),
]))->addClass('ap-card__head');

$telemetry_card = (new CDiv([
    $telemetry_card_head,
    $telemetry_grid,
]))->addClass('ap-card')->addClass('ap-card--telemetry');

// ─────────────────────────────────────────────────────────────────────────
//  Overview tab — Connectivity Issues panel (M2 task #6)
// ─────────────────────────────────────────────────────────────────────────
//
// Zabbix-only computation (G30 — XIQ /d360/device/issues wrapper does not
// exist on XIQClient). All five rules read item lastvalues already on the
// host, so this panel adds no extra round-trip beyond Health + Telemetry.

$conn_issues = is_array($connectivity['issues'] ?? null) ? $connectivity['issues'] : [];
$conn_reason = (string) ($connectivity['reason'] ?? '');

$conn_card_head = (new CDiv([
    (new CTag('h3', true, _('Connectivity Issues')))->addClass('ap-card__title'),
    (new CSpan('ZBX'))->addClass('ap-source')->addClass('ap-source--zbx'),
    (new CDiv())->addClass('ap-card__spacer'),
    (new CSpan(
        $conn_count === 0
            ? _('All clear')
            : sprintf(_n('%d issue', '%d issues', $conn_count), $conn_count)
    ))
        ->addClass('ap-card__meta')
        ->addClass('ap-card__meta--' . $conn_worst),
]))->addClass('ap-card__head');

if ($conn_count === 0) {
    if ($conn_reason !== '') {
        $conn_body = (new CDiv([
            (new CSpan('—'))->addClass('ap-conn-empty__icon'),
            (new CSpan($conn_reason))->addClass('ap-conn-empty__msg'),
        ]))->addClass('ap-conn-empty')->addClass('ap-conn-empty--missing');
    }
    else {
        $conn_body = (new CDiv([
            (new CSpan('✓'))->addClass('ap-conn-empty__icon'),
            (new CSpan(_('No connectivity issues')))->addClass('ap-conn-empty__msg'),
        ]))->addClass('ap-conn-empty');
    }
}
else {
    $conn_list = (new CTag('ul', true))->addClass('ap-conn-list');
    foreach ($conn_issues as $issue) {
        $sev   = (string) ($issue['severity'] ?? 'warn');
        $code  = (string) ($issue['code']     ?? '');
        $msg   = (string) ($issue['msg']      ?? '');
        $sev_label = $sev === 'crit' ? _('CRITICAL') : _('WARNING');

        $row = (new CTag('li', true))
            ->addClass('ap-conn-row')
            ->addClass('ap-conn-row--' . $sev)
            ->setAttribute('data-code', $code)
            ->addItem((new CSpan($sev_label))->addClass('ap-conn-row__sev'))
            ->addItem((new CSpan($msg))->addClass('ap-conn-row__msg'));
        $conn_list->addItem($row);
    }
    $conn_body = $conn_list;
}

$conn_card = (new CDiv([
    $conn_card_head,
    $conn_body,
]))->addClass('ap-card')->addClass('ap-card--connectivity')->addClass('ap-card--worst-' . $conn_worst);

// ─────────────────────────────────────────────────────────────────────────
//  Overview tab — System Information KV (M2 task #5)
// ─────────────────────────────────────────────────────────────────────────
//
// All rows come from already-loaded Zabbix items. SNMP-direct rows carry
// the ZBX badge; XIQ-cached rows (populated by the fleet template's
// dependent items) carry the EXT badge.

/**
 * Render one row of the System Information grid. Each row contributes
 * three direct children to the parent .ap-kv container so the CSS
 * `grid-template-columns: 160px 1fr auto` lays them out per the mockup.
 *
 * @param array{
 *   key: string, label: string, kind: string, value: string,
 *   source: string, tone?: string, hint?: ?string
 * } $row
 * @return CDiv[]  three CDivs: .ap-kv__k, .ap-kv__v, .ap-kv__b
 */
$render_kv_row = static function (array $row): array {
    $kind   = (string) ($row['kind']   ?? 'text');
    $label  = (string) ($row['label']  ?? '');
    $value  = (string) ($row['value']  ?? '—');
    $source = (string) ($row['source'] ?? 'ZBX');
    $hint   = $row['hint'] ?? null;

    // Value column — content varies by kind.
    $value_node = (new CDiv())
        ->addClass('ap-kv__v')
        ->setAttribute('data-key', (string) ($row['key'] ?? ''));

    switch ($kind) {
        case 'pill':
            $tone = (string) ($row['tone'] ?? 'unknown');
            $value_node->addItem(
                (new CSpan($value))
                    ->addClass('ap-kv__pill')
                    ->addClass('ap-kv__pill--' . $tone)
            );
            break;

        case 'firmware':
            $value_node->addItem((new CSpan($value))->addClass('ap-kv__text'));
            if (is_string($hint) && $hint !== '') {
                $value_node->addItem(
                    (new CSpan('⚠'))
                        ->addClass('ap-kv__warn')
                        ->setAttribute('title', $hint)
                        ->setAttribute('aria-label', $hint)
                );
            }
            break;

        case 'when':
            $value_node->addItem((new CSpan($value))->addClass('ap-kv__text'));
            if (is_string($hint) && $hint !== '') {
                $value_node->setAttribute('title', $hint);
            }
            break;

        case 'text':
        default:
            $value_node->addItem((new CSpan($value))->addClass('ap-kv__text'));
            if (is_string($hint) && $hint !== '') {
                $value_node->setAttribute('title', $hint);
            }
            break;
    }

    return [
        (new CDiv($label))->addClass('ap-kv__k'),
        $value_node,
        (new CDiv(
            (new CSpan($source))
                ->addClass('ap-source')
                ->addClass('ap-source--' . strtolower($source))
        ))->addClass('ap-kv__b'),
    ];
};

$sysinfo_grid = (new CDiv())->addClass('ap-kv');
foreach ($system_info as $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach ($render_kv_row($row) as $node) {
        $sysinfo_grid->addItem($node);
    }
}

$sysinfo_card_head = (new CDiv([
    (new CTag('h3', true, _('System Information')))->addClass('ap-card__title'),
    (new CDiv())->addClass('ap-card__spacer'),
    (new CSpan(_('merged from Zabbix host + ExtremeCloud IQ')))
        ->addClass('ap-card__meta'),
]))->addClass('ap-card__head');

$sysinfo_card = (new CDiv([
    $sysinfo_card_head,
    $sysinfo_grid,
]))->addClass('ap-card')->addClass('ap-card--sysinfo');

// ─────────────────────────────────────────────────────────────────────────
//  Overview tab — Network Information KV (M2 task #6)
// ─────────────────────────────────────────────────────────────────────────
//
// Same render path as System Info — reuses $render_kv_row.  Rows that
// depend on items the per-AP template doesn't yet ship (IPv6, gateway,
// DNS via SNMP, LLDP) gracefully render "—" until those items are
// added (per CLAUDE_CODE_PLAN §8.6).

$netinfo_grid = (new CDiv())->addClass('ap-kv');
foreach ($network_info as $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach ($render_kv_row($row) as $node) {
        $netinfo_grid->addItem($node);
    }
}

$netinfo_card_head = (new CDiv([
    (new CTag('h3', true, _('Network Information')))->addClass('ap-card__title'),
    (new CDiv())->addClass('ap-card__spacer'),
    (new CSpan(_('SNMPv3 · Zabbix host interface · ExtremeCloud IQ')))
        ->addClass('ap-card__meta'),
]))->addClass('ap-card__head');

$netinfo_card = (new CDiv([
    $netinfo_card_head,
    $netinfo_grid,
]))->addClass('ap-card')->addClass('ap-card--netinfo');

// Overview panel content.
$overview_panel_content = [
    $health_card,
    $telemetry_card,
    $conn_card,
    $sysinfo_card,
    $netinfo_card,
    // Subsequent M2 tasks insert here:
    //   - M2 #7: Recent Events feed
    (new CDiv(_('Recent Events panel — populated in M2 task #7.')))
        ->addClass('ap-panel__pending'),
];

// ── Tab panels ────────────────────────────────────────────────────────────
$panels = [];
foreach ($TABS as $key => $label) {
    $panel = (new CTag('section', true))
        ->addClass('ap-panel')
        ->setAttribute('data-panel', $key)
        ->setAttribute('role', 'tabpanel')
        ->setAttribute('aria-label', $label);

    if ($key === $DEFAULT_TAB) {
        $panel->addClass('ap-panel--active');
        foreach ($overview_panel_content as $node) {
            $panel->addItem($node);
        }
    }
    else {
        $panel->setAttribute('hidden', 'hidden');
        $panel->addItem(
            (new CDiv(sprintf(_('%s tab — pending build'), $label)))
                ->addClass('ap-panel__pending')
        );
    }

    $panels[] = $panel;
}

// ── Root container ────────────────────────────────────────────────────────
$host_name = $host !== null ? (string) ($host['name'] ?? '') : '';

$root = (new CDiv())
    ->addClass('ap-detail')
    ->setAttribute('data-hostid',   (string) $host_id)
    ->setAttribute('data-hostname', $host_name)
    ->setAttribute('data-from',     (string) $time_period['from'])
    ->setAttribute('data-to',       (string) $time_period['to'])
    ->setAttribute('data-from-ts',  (string) $time_period['from_ts'])
    ->setAttribute('data-to-ts',    (string) $time_period['to_ts'])
    ->addItem($tabs_nav);

foreach ($panels as $panel) {
    $root->addItem($panel);
}

// ── Render through CWidgetView ────────────────────────────────────────────
(new CWidgetView($data))
    ->addItem($root)
    ->show();
