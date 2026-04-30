<?php

namespace Modules\MilestoneCameraPacketFence\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldTextBox
};

/**
 * Camera Device (PacketFence) widget form.
 *
 * Connection details for the PacketFence API. Unlike the original
 * Switch Port Device widget this one doesn't need:
 *   - an override-host selector (the camera widget broadcasts hostid + MAC + IP
 *     directly via the mcs:cameraSelected event)
 *   - a MAC-list item prefix (the camera widget gives us a single MAC)
 *   - DHCP fallback (the camera widget already knows the IP)
 */
class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
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
			// Verify TLS cert (disable for self-signed / internal CA)
			->addField(
				(new CWidgetFieldCheckBox('verify_ssl', _('Verify TLS certificate')))
					->setDefault(0)
			)
			// Show debug panel
			->addField(
				(new CWidgetFieldCheckBox('show_debug', _('Show debug info')))->setDefault(0)
			);
	}
}
