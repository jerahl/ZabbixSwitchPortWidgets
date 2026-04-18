<?php
/**
 * PacketFence Device Lookup widget – configuration form.
 *
 * @var CView  $this
 * @var array  $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['pf_url'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['pf_username'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['pf_password'])
	)
	->addField(
		new CWidgetFieldIntegerBoxView($data['fields']['port_modulus'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['verify_ssl'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_debug'])
	)
	->show();
