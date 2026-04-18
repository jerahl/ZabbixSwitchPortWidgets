<?php

namespace Modules\SwitchPorts;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	// Widget ID constant — must match manifest.json "id"
	public const TYPE = 'switchports';

	public function getDefaultName(): string {
		return _('Switch Port Status');
	}
}
