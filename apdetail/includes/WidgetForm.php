<?php

declare(strict_types=1);

namespace Modules\APDetail\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldHost;
use Zabbix\Widgets\Fields\CWidgetFieldTextBox;
use Zabbix\Widgets\Fields\CWidgetFieldIntegerBox;

/**
 * AP Detail — widget configuration form.
 *
 * Fields declared here must match the keys used in manifest.json "in" config
 * and in WidgetView::doAction() when reading $this->getInput().
 *
 * Field naming convention:
 *   hostid        — broadcast receiver for _hostid (Host Navigator → this widget)
 *   xiq_host      — XIQ API base URL (default api.extremecloudiq.com)
 *   pf_url        — PacketFence API base URL
 *   pf_user       — PacketFence API username (read-only webservices account)
 *   pf_pass       — PacketFence API password
 *   refresh_rate  — seconds between auto-refresh (0 = dashboard default)
 *
 * XIQ OAuth2 credentials are intentionally NOT stored here — they come from
 * Zabbix global macros {$XIQ_CLIENT_ID} and {$XIQ_CLIENT_SECRET} resolved
 * at render time inside XIQClient.php.
 */
final class WidgetForm extends CWidgetForm {

    public function addFields(): static {
        return $this
            // Host selector — receives _hostid broadcast from Host Navigator.
            // CWidgetFieldHost renders the standard host picker in the edit panel
            // and also acts as the broadcast sink for the _hostid type declared
            // in manifest.json "in".
            ->addField(
                (new CWidgetFieldHost('hostid', _('AP host')))
                    ->setFlags(CWidgetFieldHost::FLAG_NOT_EMPTY)
            )

            // PacketFence API endpoint — full base URL, e.g. https://pf.example.com:9090
            ->addField(
                (new CWidgetFieldTextBox('pf_url', _('PacketFence URL')))
                    ->setDefault('https://packetfence.tcs.local:9090')
                    ->setMaxLength(255)
            )

            // PacketFence credentials — dedicated read-only webservices account.
            // Stored as widget config (plaintext in Zabbix DB), same as the
            // existing packetfence/ widget — use a least-privilege PF user,
            // never the admin account.
            ->addField(
                (new CWidgetFieldTextBox('pf_user', _('PacketFence user')))
                    ->setDefault('zbx-readonly')
                    ->setMaxLength(128)
            )
            ->addField(
                (new CWidgetFieldTextBox('pf_pass', _('PacketFence password')))
                    ->setMaxLength(255)
            )

            // XIQ API base URL — overridable for on-prem or regional endpoints
            ->addField(
                (new CWidgetFieldTextBox('xiq_host', _('XIQ API host')))
                    ->setDefault('https://api.extremecloudiq.com')
                    ->setMaxLength(255)
            )

            // Explicit refresh rate override (seconds). 0 = inherit dashboard rate.
            ->addField(
                (new CWidgetFieldIntegerBox('refresh_rate', _('Refresh interval (s)')))
                    ->setDefault(0)
                    ->setMin(0)
                    ->setMax(3600)
            );
    }
}
