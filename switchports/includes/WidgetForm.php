<?php

namespace Modules\SwitchPorts\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldTextBox
};

/**
 * Switch Port Status widget form.
 *
 * override_hostid: receives a host from Host Navigator (or can be set statically).
 *   The manifest declares in: {"override_hostid": {"type": "_hostids"}} so the
 *   Zabbix framework calls setInType() on this field, enabling EventHub subscription.
 *
 * The manifest out: [{type:_hostid},{type:_hostids}] means when a port is clicked
 *   and broadcast() is called, listening widgets (Port Detail) receive the host.
 */
class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			)
			->addField(
				(new CWidgetFieldTextBox('item_prefix', _('Item key prefix')))
					->setDefault('net.if.status[ifOperStatus.')
			)
			->addField(
				(new CWidgetFieldCheckBox('show_labels', _('Show port numbers')))->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_desc', _('Show port descriptions')))->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_debug', _('Show debug info')))->setDefault(0)
			);
	}
}
