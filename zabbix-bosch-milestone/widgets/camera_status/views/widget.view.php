<?php declare(strict_types = 0);

/**
 * Camera Status widget view.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CameraStatus\Includes\WidgetForm;

$view = new CWidgetView($data);

$layout  = $data['config']['layout'];
$columns = max(2, min(12, (int) $data['config']['columns']));

$wrapper = (new CDiv())
	->addClass('camera-status-widget')
	->setAttribute('data-columns', (string) $columns)
	->setAttribute('data-layout', $layout === WidgetForm::LAYOUT_LIST ? 'list' : 'grid')
	->setAttribute('data-frame-stale', (string) $data['config']['frame_stale'])
	->setAttribute('data-retention-warn', (string) $data['config']['retention_warn']);

if (!$data['cameras']) {
	$wrapper->addItem(
		(new CDiv(_('No cameras found')))->addClass('no-data-message')
	);
}
else {
	foreach ($data['cameras'] as $cam) {
		$wrapper->addItem(render_camera_tile($cam));
	}
}

$view->addItem($wrapper);
$view->show();


function render_camera_tile(array $cam): CDiv {
	$tile = (new CDiv())
		->addClass('camera-tile')
		->addClass('state-' . $cam['state'])
		->setAttribute('data-hostid', (string) $cam['hostid']);

	// Header: name + IP
	$header = (new CDiv())->addClass('camera-header');
	$header->addItem(
		(new CLink($cam['name'], 'zabbix.php?action=host.view&filter_hostids[]=' . $cam['hostid']))
			->addClass('camera-name')
			->setTitle($cam['name'])
	);
	if ($cam['ip'] !== '') {
		$header->addItem((new CDiv($cam['ip']))->addClass('camera-ip'));
	}
	$tile->addItem($header);

	// State badge
	$state_text = match ($cam['state']) {
		'offline' => _('OFFLINE'),
		'warning' => _('WARNING'),
		default   => _('OK')
	};
	$tile->addItem((new CDiv($state_text))->addClass('camera-state-badge'));

	// Metrics grid
	$metrics = (new CDiv())->addClass('camera-metrics');

	$metrics->addItem(metric_row(_('Online'),
		$cam['online'] === null ? '—' : ($cam['online'] ? _('Yes') : _('No')),
		$cam['online'] === 1 ? 'good' : ($cam['online'] === 0 ? 'bad' : '')
	));

	$metrics->addItem(metric_row(_('Recording'),
		$cam['recording'] === null ? '—' : ($cam['recording'] ? _('Yes') : _('No')),
		$cam['recording'] === 1 ? 'good' : ($cam['recording'] === 0 ? 'bad' : '')
	));

	$metrics->addItem(metric_row(_('Last frame'),
		$cam['frame_age'] === null ? '—' : format_age($cam['frame_age']),
		''
	));

	$metrics->addItem(metric_row(_('Retention'),
		$cam['retention'] === null ? '—' : sprintf('%.1f d', $cam['retention']),
		''
	));

	$metrics->addItem(metric_row(_('Storage'),
		$cam['storage'] === null ? '—' : sprintf('%.0f%%', $cam['storage']),
		$cam['storage'] !== null && $cam['storage'] >= 90 ? 'bad' : ''
	));

	$tile->addItem($metrics);

	if (!empty($cam['reasons'])) {
		$tile->addItem(
			(new CDiv(implode(' • ', $cam['reasons'])))->addClass('camera-reasons')
		);
	}

	return $tile;
}

function metric_row(string $label, string $value, string $class): CDiv {
	$row = (new CDiv())->addClass('metric-row');
	$row->addItem((new CSpan($label))->addClass('metric-label'));
	$v = (new CSpan($value))->addClass('metric-value');
	if ($class !== '') {
		$v->addClass('metric-' . $class);
	}
	$row->addItem($v);
	return $row;
}

function format_age(int $seconds): string {
	if ($seconds < 60)      return $seconds . 's';
	if ($seconds < 3600)    return floor($seconds / 60) . 'm';
	if ($seconds < 86400)   return floor($seconds / 3600) . 'h';
	return floor($seconds / 86400) . 'd';
}
