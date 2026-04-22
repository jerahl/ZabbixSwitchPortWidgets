<?php declare(strict_types = 0);

namespace Modules\CameraStatus\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldCheckBox,
	CWidgetFieldIntegerBox,
	CWidgetFieldRadioButtonList
};

class WidgetForm extends CWidgetForm {

	public const LAYOUT_GRID = 0;
	public const LAYOUT_LIST = 1;

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
					->setDefault([])
			)
			->addField(
				(new CWidgetFieldRadioButtonList('layout', _('Layout'), [
					self::LAYOUT_GRID => _('Grid'),
					self::LAYOUT_LIST => _('List')
				]))->setDefault(self::LAYOUT_GRID)
			)
			->addField(
				(new CWidgetFieldIntegerBox('columns', _('Columns'), 2, 12))
					->setDefault(6)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_offline_only', _('Show problems only')))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldIntegerBox('retention_warn_days', _('Retention warning threshold (days)'), 1, 365))
					->setDefault(7)
			)
			->addField(
				(new CWidgetFieldIntegerBox('frame_stale_seconds', _('Frame staleness threshold (seconds)'), 10, 3600))
					->setDefault(120)
			);
	}
}
