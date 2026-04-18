<?php

namespace Modules\PacketFence\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldTextBox,
	CWidgetFieldIntegerBox
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
			// Port-number divisor: for Extreme where ifIndex=1001→port 1, this is 1000
			// For switches that use bare ifIndex as port number, set to 1
			->addField(
				(new CWidgetFieldIntegerBox('port_modulus', _('Port number modulus'), 1, 100000))
					->setDefault(1000)
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
