<?php declare(strict_types = 0);

namespace Modules\CameraStatus;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public function getDefaults(): array {
		return [
			'name' => $this->getDefaultName(),
			'size' => [
				'width' => 24,
				'height' => 8
			],
			'js_class' => 'CWidgetCameraStatus'
		];
	}

	public function getTranslationStrings(): array {
		return [
			'class.widget.js' => [
				'No cameras found' => _('No cameras found'),
				'Loading...' => _('Loading...'),
				'Online' => _('Online'),
				'Offline' => _('Offline'),
				'Recording' => _('Recording'),
				'Not recording' => _('Not recording'),
				'Unknown' => _('Unknown')
			]
		];
	}
}
