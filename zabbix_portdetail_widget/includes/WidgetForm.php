<?php

namespace Modules\PortDetail\Includes;

use CWidgetsData;
use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldTimePeriod
};

/**
 * Switch Port Detail widget form.
 *
 * The Override host field binds to the Switch Port Status widget so we know
 * which host is selected. The time_period field defaults to the dashboard's
 * time period so sparklines automatically respect the dashboard zoom.
 */
class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			)
			->addField(
				(new CWidgetFieldTimePeriod('time_period', _('Time period')))
					->setDefault([
						CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
							CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_TIME_PERIOD
						)
					])
					->setDefaultPeriod(['from' => 'now-1h', 'to' => 'now'])
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_debug', _('Show debug info')))->setDefault(1)
			);
	}
}
