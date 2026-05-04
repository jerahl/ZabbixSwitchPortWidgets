<?php declare(strict_types=0);
/**
 * XIQ AP Status — view template.
 *
 * Renders summary tiles, an empty table shell, and a JSON payload blob.
 * The JS reads the payload, fills the table, and wires up kebab actions.
 *
 * Toolbar (search + page-size) and .xiq-pagination are rendered as empty
 * placeholder elements here; JS fills and wires them via _wireSearch(),
 * _wirePageSizeSelect(), and _renderPagination().
 *
 * @var CView $this
 * @var array $data
 */

$payload = $data['data'] ?? [];

$body = (new CDiv())->addClass('xiq-body');

// JSON-in-HTML payload — consistent with how the camera-status widget
// hands data to its JS class (Zabbix's widget framework strips extra keys
// from the AJAX response, so this is the reliable channel).
$body->addItem(
	(new CTag('script', true, json_encode(
		$payload,
		JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	)))
		->setAttribute('type', 'application/json')
		->addClass('xiq-data')
);

// Summary tile row.
$summary = (new CDiv())->addClass('xiq-summary');
foreach ([
	'total'         => [_('Total APs'),     'xiq-tile--total'],
	'connected'     => [_('Connected'),     'xiq-tile--connected'],
	'disconnected'  => [_('Disconnected'),  'xiq-tile--disconnected'],
	'cfg_mismatch'  => [_('Config drift'),  'xiq-tile--mismatch'],
	'clients_total' => [_('Active clients'),'xiq-tile--clients'],
] as $field => [$label, $cls]) {
	$summary->addItem(
		(new CDiv())
			->addClass('xiq-tile')
			->addClass($cls)
			->setAttribute('data-field', $field)
			->addItem((new CDiv(''))->addClass('xiq-tile__count'))
			->addItem((new CDiv($label))->addClass('xiq-tile__label'))
	);
}
$body->addItem($summary);

// Error banner placeholder.
$body->addItem((new CDiv())->addClass('xiq-error-banner')->addStyle('display:none'));

// Truncation banner placeholder.
$body->addItem((new CDiv())->addClass('xiq-truncated-banner')->addStyle('display:none'));

// ── Toolbar: search input + page-size selector ────────────────────────
// JS wires these up in _wireSearch() and _wirePageSizeSelect().
$toolbar = (new CDiv())->addClass('xiq-toolbar');

// Search wrapper (includes the × clear button)
$search_wrap = (new CDiv())->addClass('xiq-search-wrap');
$search_input = (new CTag('input', false))
	->setAttribute('type', 'search')
	->setAttribute('placeholder', _('Filter by name, serial, MAC, IP…'))
	->setAttribute('autocomplete', 'off')
	->setAttribute('spellcheck', 'false')
	->addClass('xiq-search');
$search_clear = (new CTag('button', true, '×'))
	->setAttribute('type', 'button')
	->setAttribute('aria-label', _('Clear filter'))
	->addClass('xiq-search-clear');
$search_wrap->addItem($search_input);
$search_wrap->addItem($search_clear);

// Page-size selector
$page_size_wrap = (new CDiv())->addClass('xiq-page-size-wrap');
$page_size_label = (new CTag('label', true, _('Rows:')))->addClass('xiq-page-size-label');
$page_size_sel = (new CTag('select', true, ''))->addClass('xiq-page-size');
foreach ([10, 25, 50, 100] as $n) {
	$page_size_sel->addItem(
		(new CTag('option', true, (string)$n))
			->setAttribute('value', (string)$n)
			->setAttribute($n === 25 ? 'selected' : 'x', $n === 25 ? 'selected' : null)
	);
}
$page_size_wrap->addItem($page_size_label);
$page_size_wrap->addItem($page_size_sel);

$toolbar->addItem($search_wrap);
$toolbar->addItem($page_size_wrap);
$body->addItem($toolbar);

// Table shell — JS replaces tbody contents.
$table = (new CTable())
	->addClass('xiq-table')
	->setHeader([
		(new CColHeader(_('AP')))->setAttribute('data-sort', 'name'),
		(new CColHeader(_('Status')))->setAttribute('data-sort', 'connected'),
		(new CColHeader(_('IP')))->setAttribute('data-sort', 'ip'),
		(new CColHeader(_('Clients')))->setAttribute('data-sort', 'clients'),
		(new CColHeader(_('Version')))->setAttribute('data-sort', 'version'),
		(new CColHeader(_('Last seen')))->setAttribute('data-sort', 'last_connect'),
		(new CColHeader(_('Uptime')))->setAttribute('data-sort', 'uptime'),
		new CColHeader(''), // kebab column, not sortable
	]);
// Empty tbody — JS fills it.
$table->addItem((new CTag('tbody', true))->addClass('xiq-tbody'));
$body->addItem($table);

// ── Pagination bar — JS renders content into this container ───────────
$body->addItem((new CDiv())->addClass('xiq-pagination'));

// Optional debug panel (server-rendered; JS shows/hides as needed).
if (!empty($payload['show_debug'])) {
	$body->addItem(
		(new CDiv())
			->addClass('xiq-debug')
			->addItem(new CTag('h4', true, _('Debug')))
			->addItem((new CTag('pre', true, json_encode(
				$payload['debug'] ?? [],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)))->addClass('xiq-debug__pre'))
	);
}

(new CWidgetView($data))
	->addItem($body)
	->show();
