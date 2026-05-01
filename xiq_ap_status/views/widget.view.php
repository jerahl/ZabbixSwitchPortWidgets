<?php
/**
 * XIQ AP status widget — view template.
 *
 * Mirrors milestone_camera_status: server-renders the summary tile shells
 * + table header, and embeds the live data payload as a JSON blob inside
 * <script type="application/json">. The JS class extracts the blob on
 * each setContents call. We can't return data alongside the body because
 * Zabbix's widget framework strips everything except {name, body, debug}.
 *
 * @var CView $this
 * @var array $data
 */

declare(strict_types=0);

$body = (new CDiv())->addClass('xas-body');

$payload = [
    'summary'      => $data['summary']      ?? [],
    'rows'         => $data['rows']         ?? [],
    'error'        => $data['error']        ?? null,
    'truncated'    => $data['truncated']    ?? false,
    'truncated_at' => $data['truncated_at'] ?? null,
    'debug_info'   => $data['debug_info']   ?? null,
];

$body->addItem(
    (new CTag('script', true, json_encode(
        $payload,
        JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )))
        ->setAttribute('type', 'application/json')
        ->addClass('xas-data')
);

$summary = (new CDiv())->addClass('xas-summary');
foreach ([
    'total'           => [_('Total'),     'xas-tile--total'],
    'ok'              => [_('OK'),        'xas-tile--ok'],
    'config_mismatch' => [_('Mismatch'),  'xas-tile--mismatch'],
    'disconnected'    => [_('Offline'),   'xas-tile--offline'],
    'no_data'         => [_('No data'),   'xas-tile--nodata'],
] as $field => [$label, $cls]) {
    $summary->addItem(
        (new CDiv())
            ->addClass('xas-tile')
            ->addClass($cls)
            ->setAttribute('data-field', $field)
            ->addItem((new CDiv('—'))->addClass('xas-tile-value'))
            ->addItem((new CDiv($label))->addClass('xas-tile-label'))
    );
}
$body->addItem($summary);

$thead = (new CTag('thead', true))->addItem(
    (new CTag('tr', true))
        ->addItem((new CTag('th', true, _('Severity')))->setAttribute('data-sort', 'status'))
        ->addItem((new CTag('th', true, _('AP')))->setAttribute('data-sort', 'ap_name'))
        ->addItem((new CTag('th', true, _('MAC')))->setAttribute('data-sort', 'mac'))
        ->addItem((new CTag('th', true, _('IP')))->setAttribute('data-sort', 'ip'))
        ->addItem((new CTag('th', true, _('Last check')))->setAttribute('data-sort', 'lastclock'))
);
$tbody = (new CTag('tbody', true))->addClass('xas-rows');

$table = (new CTag('table', true))
    ->addClass('xas-table')
    ->addItem($thead)
    ->addItem($tbody);

$body->addItem(
    (new CDiv())
        ->addClass('xas-table-wrap')
        ->addItem($table)
);

$body->addItem(
    (new CDiv())
        ->addClass('xas-empty')
        ->setAttribute('hidden', 'hidden')
        ->addItem((new CDiv())->addClass('xas-empty-msg'))
);

(new CWidgetView($data))
    ->addItem($body)
    ->show();
