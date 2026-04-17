<?php
/**
 * Switch Port Status Widget – configuration form view.
 *
 * @var CView  $this
 * @var array  $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['item_prefix'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_labels'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_desc'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_debug'])
	)
	->show();
