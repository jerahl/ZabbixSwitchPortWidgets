<?php
/**
 * Switch Port Detail Widget – configuration form.
 *
 * @var CView  $this
 * @var array  $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
	)
	->addField(
		new CWidgetFieldTimePeriodView($data['fields']['time_period'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['enable_poe_cycle'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_debug'])
	)
	->show();
