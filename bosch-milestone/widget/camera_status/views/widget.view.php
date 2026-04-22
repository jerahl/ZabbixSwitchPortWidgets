<?php
/**
 * @var CView $this
 * @var array $data
 *
 * Renders the camera status grid. Heavy lifting (live updates, filtering,
 * tooltips) is done client-side in assets/js/class.widget.js.
 */

$tile_sizes = [
    0 => 'cam-tile--sm',
    1 => 'cam-tile--md',
    2 => 'cam-tile--lg',
];
$tile_class = $tile_sizes[$data['fields']['tile_size'] ?? 1] ?? 'cam-tile--md';
$group_by_rs = !empty($data['fields']['show_rs_grouping']);
$show_retention = !empty($data['fields']['show_retention']);

// Tally for the header summary strip.
$totals = [0, 0, 0, 0, 0];
foreach ($data['cameras'] as $c) {
    $totals[$c['state']]++;
}

(new CDiv())
    ->addClass('camera-status-widget')
    ->setAttribute('data-tile-size', $tile_class)
    ->setAttribute('data-show-retention', $show_retention ? '1' : '0')
    ->addItem(
        // Summary strip
        (new CDiv())
            ->addClass('cam-summary')
            ->addItem((new CSpan($totals[0]))->addClass('cam-pill cam-pill--ok')->setTitle(_('Healthy')))
            ->addItem((new CSpan($totals[1]))->addClass('cam-pill cam-pill--warn')->setTitle(_('Online, not recording')))
            ->addItem((new CSpan($totals[2]))->addClass('cam-pill cam-pill--rs')->setTitle(_('ICMP alive, Milestone offline')))
            ->addItem((new CSpan($totals[3]))->addClass('cam-pill cam-pill--down')->setTitle(_('Hard down')))
            ->addItem((new CSpan($totals[4]))->addClass('cam-pill cam-pill--unknown')->setTitle(_('Unknown')))
    )
    ->addItem(
        (new CDiv())
            ->addClass('cam-grid-wrap')
            ->addItem((function() use ($data, $tile_class, $group_by_rs, $show_retention) {
                if (empty($data['cameras'])) {
                    return (new CDiv(_('No cameras found')))->addClass('cam-empty');
                }

                $container = new CDiv();
                $container->addClass('cam-grid');

                if ($group_by_rs) {
                    $by_rs = [];
                    foreach ($data['cameras'] as $c) {
                        $by_rs[$c['rs_name']][] = $c;
                    }
                    foreach ($by_rs as $rs => $cams) {
                        $container->addItem(
                            (new CDiv())
                                ->addClass('cam-rs-header')
                                ->addItem((new CSpan($rs))->addClass('cam-rs-name'))
                                ->addItem((new CSpan(count($cams)))->addClass('cam-rs-count'))
                        );
                        foreach ($cams as $c) {
                            $container->addItem(renderTile($c, $tile_class, $show_retention));
                        }
                    }
                } else {
                    foreach ($data['cameras'] as $c) {
                        $container->addItem(renderTile($c, $tile_class, $show_retention));
                    }
                }
                return $container;
            })())
    )
    ->show();


function renderTile(array $c, string $tile_class, bool $show_retention): CDiv {
    $state_class = [
        0 => 'cam-tile--ok',
        1 => 'cam-tile--warn',
        2 => 'cam-tile--rs',
        3 => 'cam-tile--down',
        4 => 'cam-tile--unknown',
    ][$c['state']] ?? 'cam-tile--unknown';

    $tile = (new CDiv())
        ->addClass("cam-tile {$tile_class} {$state_class}")
        ->setAttribute('data-hostid', $c['hostid'])
        ->setAttribute('data-state', $c['state'])
        ->setAttribute('data-retention', $c['retention'] ?? '')
        ->setAttribute('title', buildTooltip($c));

    // Status dot
    $tile->addItem((new CDiv())->addClass('cam-tile__dot'));

    // Name
    $tile->addItem(
        (new CDiv($c['name']))->addClass('cam-tile__name')
    );

    // Footer line: recording indicator + retention
    $footer = new CDiv();
    $footer->addClass('cam-tile__footer');

    $rec_label = $c['recording'] === 1 ? '● REC' : '○';
    $rec_cls = $c['recording'] === 1 ? 'cam-rec cam-rec--on' : 'cam-rec cam-rec--off';
    $footer->addItem((new CSpan($rec_label))->addClass($rec_cls));

    if ($show_retention && $c['retention'] !== null) {
        $ret = (float)$c['retention'];
        $ret_cls = $ret < 7 ? 'cam-ret cam-ret--low' : ($ret < 14 ? 'cam-ret cam-ret--warn' : 'cam-ret');
        $footer->addItem(
            (new CSpan(sprintf('%.1fd', $ret)))->addClass($ret_cls)
        );
    }

    $tile->addItem($footer);
    return $tile;
}


function buildTooltip(array $c): string {
    $lines = [
        sprintf('%s', $c['name']),
        sprintf('RS: %s', $c['rs_name']),
        sprintf('Milestone: %s', $c['online'] === 1 ? 'Online' : ($c['online'] === 0 ? 'Offline' : 'Unknown')),
        sprintf('ICMP: %s', $c['icmp'] === 1 ? 'Alive' : ($c['icmp'] === 0 ? 'Down' : 'Unknown')),
        sprintf('Recording: %s', $c['recording'] === 1 ? 'Yes' : ($c['recording'] === 0 ? 'No' : 'Unknown')),
    ];
    if ($c['retention'] !== null) {
        $lines[] = sprintf('Retention: %.2f d', $c['retention']);
    }
    if ($c['last_seen']) {
        $lines[] = sprintf('Updated: %s', date('Y-m-d H:i:s', $c['last_seen']));
    }
    return implode("\n", $lines);
}
