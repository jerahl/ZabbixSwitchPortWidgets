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

foreach ($TABS as $key => $label) {
    $btn = (new CTag('button', true, $label))
        ->setAttribute('type', 'button')
        ->setAttribute('data-tab', $key)
        ->setAttribute('role', 'tab')
        ->setAttribute('tabindex', $key === $DEFAULT_TAB ? '0' : '-1')
        ->setAttribute('aria-selected', $key === $DEFAULT_TAB ? 'true' : 'false')
        ->addClass('ap-tab');

    if ($key === $DEFAULT_TAB) {
        $btn->addClass('ap-tab--active');
    }

    $tabs_nav->addItem($btn);
}

// ─────────────────────────────────────────────────────────────────────────
//  Overview tab — Device Health card (M2 task #2)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Render a single ring cell (CPU or Memory) with a sparkline behind it.
 *
 * Layout per cell:
 *
 *   ┌─────────────────────────────────────┐
 *   │       ⊙   12%        ← donut + value│
 *   │       CPU            ← label        │
 *   │ /\/\/\/\/\/\/\/\/\/\ ← spark behind │
 *   └─────────────────────────────────────┘
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

    // Donut SVG. r=26 → circumference ≈ 163.4 (matches JS _animateRings constant).
    $ring_svg = (new CTag('svg', true))
        ->addClass('ap-ring__svg')
        ->setAttribute('viewBox', '0 0 64 64')
        ->setAttribute('aria-hidden', 'true')
        ->addItem(
            (new CTag('circle', true))
                ->addClass('ap-ring__track')
                ->setAttribute('cx', '32')->setAttribute('cy', '32')->setAttribute('r', '26')
        )
        ->addItem(
            (new CTag('circle', true))
                ->addClass('ap-ring__fill')
                ->setAttribute('cx', '32')->setAttribute('cy', '32')->setAttribute('r', '26')
                ->setAttribute('stroke-dasharray', '0 999')
                ->setAttribute('data-value', $value === null ? '0' : (string) $value)
        );

    // Inside-ring text overlay — value + label stacked.
    $ring_text = (new CDiv([
        (new CDiv($value_text))->addClass('ap-ring-cell__value'),
        (new CDiv($label))->addClass('ap-ring-cell__label'),
    ]))->addClass('ap-ring-cell__text');

    // Threshold readout — small caption beneath the label.
    $caption = $value === null
        ? _('no data')
        : sprintf(_('alert at %d%%'), (int) $threshold);

    return (new CDiv([
        (new CDiv([$ring_svg, $ring_text]))->addClass('ap-ring-cell__ring'),
        $spark_group,
        (new CDiv($caption))->addClass('ap-ring-cell__caption'),
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

// Overview panel content.
$overview_panel_content = [
    $health_card,
    $telemetry_card,
    // Subsequent M2 tasks insert here:
    //   - M2 #4: System Info KV
    //   - M2 #5: Network Info KV
    //   - M2 #6: Connectivity Issues
    //   - M2 #7: Recent Events feed
    (new CDiv(_('System Info, Network Info, Connectivity Issues, and Recent Events panels — populated in M2 tasks #4–#7.')))
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
