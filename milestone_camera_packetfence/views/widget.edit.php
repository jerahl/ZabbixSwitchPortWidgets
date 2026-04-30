<?php
/**
 * Camera Device (PacketFence) widget – configuration form.
 *
 * @var CView  $this
 * @var array  $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['pf_url'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['pf_admin_url'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['pf_username'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['pf_password'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['verify_ssl'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_debug'])
	)
	->show();
