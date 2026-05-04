<?php declare(strict_types = 0);

namespace Modules\XiqApStatus\Includes;

use Zabbix\Widgets\{
	CWidgetField,
	CWidgetForm
};
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldTextArea,
	CWidgetFieldTextBox
};

/**
 * Configuration form for the XIQ AP Status widget.
 *
 * The widget is read-driven by per-AP items discovered via the
 * "Extreme XIQ APs by API" template. The form lets the dashboard owner:
 *   - pick the XIQ host whose discovered AP items the widget should display
 *   - cap how many rows to render
 *   - opt into per-row actions (reboot, manage/unmanage, refresh, CLI)
 *   - configure the XIQ API URL and admin UI URL (for "Open in XIQ" links)
 *   - point at a server-side file holding the action token
 *   - allow-list which CLI commands users can run
 *
 * SECURITY NOTE: The action token is read server-side from a file on the
 * Zabbix frontend (default /etc/zabbix/secrets/xiq_action_token). That file
 * MUST be owned root:apache (or root:nginx, etc.) with mode 0640 so only
 * the web server process can read it. The widget never sends the token to
 * the browser; it lives entirely inside WidgetAction.php's PHP context.
 */
class WidgetForm extends CWidgetForm {

	public const DEFAULT_XIQ_URL       = 'https://api.extremecloudiq.com';
	public const DEFAULT_XIQ_ADMIN_URL = 'https://extremecloudiq.com';
	public const DEFAULT_TOKEN_PATH    = '/etc/zabbix/secrets/xiq_action_token';

	/**
	 * The default CLI allow-list. All entries must start with "show " — the
	 * controller enforces this server-side too, regardless of what's in the
	 * widget config. If you want to expand this, add lines here AND ensure
	 * your action token has the device:cli scope.
	 */
	public const DEFAULT_CLI_ALLOWLIST = "show version\nshow system\nshow ap-info\nshow interface\nshow interface brief\nshow wireless\nshow wireless ap stats\nshow clients\nshow neighbor\nshow running-config";

	public function addFields(): self {
		return $this
			// Host whose XIQ-template-discovered items we read.
			->addField(
				(new CWidgetFieldMultiSelectHost('hostids', _('XIQ host')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('max_rows', _('Max table rows'), 1, 5000))
					->setDefault(1500)
			)
			// Display options
			->addField(
				(new CWidgetFieldCheckBox('show_disconnected_first', _('Sort disconnected APs first')))
					->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_debug', _('Show debug panel')))->setDefault(0)
			)
			// XIQ URLs
			->addField(
				(new CWidgetFieldTextBox('xiq_url', _('XIQ API URL')))
					->setDefault(self::DEFAULT_XIQ_URL)
					->setFlags(CWidgetFieldTextBox::FLAG_NOT_EMPTY)
			)
			->addField(
				(new CWidgetFieldTextBox('xiq_admin_url', _('XIQ admin UI URL (for "Open in XIQ")')))
					->setDefault(self::DEFAULT_XIQ_ADMIN_URL)
			)
			->addField(
				(new CWidgetFieldCheckBox('verify_ssl', _('Verify TLS certificate on action calls')))
					->setDefault(1)
			)
			// Action toggles — all default OFF so a fresh install is read-only.
			->addField(
				(new CWidgetFieldCheckBox('enable_refresh', _('Enable "Refresh now" action')))
					->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('enable_reboot', _('Enable "Reboot AP" action')))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldCheckBox('enable_manage', _('Enable Managed/Unmanaged toggle')))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldCheckBox('enable_cli', _('Enable "Run show command" action')))
					->setDefault(0)
			)
			// Path to the file holding the action token. Read server-side.
			->addField(
				(new CWidgetFieldTextBox('action_token_path', _('Action token file path')))
					->setDefault(self::DEFAULT_TOKEN_PATH)
			)
			// CLI allow-list. One command per line. All must start with "show ".
			->addField(
				(new CWidgetFieldTextArea('cli_allowlist', _('CLI allow-list (one "show ..." command per line)')))
					->setDefault(self::DEFAULT_CLI_ALLOWLIST)
			);
	}
}
