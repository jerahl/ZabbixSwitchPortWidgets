<?php declare(strict_types=0);
/**
 * @var CView $this
 * @var array $data
 *
 * Note: CWidgetField*View classes live in the global namespace.
 */

(new CWidgetFormView($data))
	->addField(new CWidgetFieldMultiSelectHostView($data['fields']['hostids']))
	->addField(new CWidgetFieldIntegerBoxView($data['fields']['max_rows']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_disconnected_first']))
	->addField(new CWidgetFieldTextBoxView($data['fields']['xiq_url']))
	->addField(new CWidgetFieldTextBoxView($data['fields']['xiq_admin_url']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['verify_ssl']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['enable_refresh']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['enable_reboot']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['enable_manage']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['enable_cli']))
	->addField(new CWidgetFieldTextBoxView($data['fields']['action_token_path']))
	->addField(new CWidgetFieldTextAreaView($data['fields']['cli_allowlist']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_debug']))
	->show();
