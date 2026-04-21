<?php

namespace Modules\PacketFence\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldTextBox
};

/**
 * PacketFence Device Lookup widget form.
 *
 * Stores connection details for the PacketFence API and the port-number
 * computation strategy.
 */
class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			// Bind to the Switch Port Status widget for host selection
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			)
			// PacketFence server URL (e.g. https://pf.example.com:9999)
			->addField(
				(new CWidgetFieldTextBox('pf_url', _('PacketFence API URL')))
					->setDefault('https://packetfence.example.com:9999')
					->setFlags(CWidgetFieldTextBox::FLAG_NOT_EMPTY)
			)
			// PF admin UI URL - separate from API URL, typically on port 1443
			->addField(
				(new CWidgetFieldTextBox('pf_admin_url', _('PacketFence admin UI URL')))
					->setDefault('https://packetfence.example.com:1443')
			)
			// PF admin username (or webservices user)
			->addField(
				(new CWidgetFieldTextBox('pf_username', _('Username')))
					->setDefault('admin')
					->setFlags(CWidgetFieldTextBox::FLAG_NOT_EMPTY)
			)
			// PF password - stored as-is (see README warning about plaintext storage)
			->addField(
				(new CWidgetFieldTextBox('pf_password', _('Password')))
					->setDefault('')
			)
			// DHCP lookup fallback — hostname of the Zabbix host running the DHCP
			// lease exporter. Leave blank to disable DHCP fallback.
			->addField(
				(new CWidgetFieldTextBox('dhcp_host', _('DHCP server Zabbix host name')))
					->setDefault('')
			)
			// Item key on that host returning the JSON lease list
			->addField(
				(new CWidgetFieldTextBox('dhcp_item_key', _('DHCP lease item key')))
					->setDefault('dhcp.leases')
			)
			// Prefix used to construct the MAC-list item key.
			// Full key: <prefix><snmpIndex>]  — e.g. "port.mac.list[1001]"
			->addField(
				(new CWidgetFieldTextBox('mac_item_prefix', _('MAC-list item key prefix')))
					->setDefault('port.mac.list[')
			)
			// Verify TLS cert (disable for self-signed / internal CA)
			->addField(
				(new CWidgetFieldCheckBox('verify_ssl', _('Verify TLS certificate')))
					->setDefault(0)
			)
			// Show debug panel
			->addField(
				(new CWidgetFieldCheckBox('show_debug', _('Show debug info')))->setDefault(1)
			);
	}
}
