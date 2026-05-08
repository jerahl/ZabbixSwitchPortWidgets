<?php

declare(strict_types=1);

namespace Modules\APDetail\Includes;

use Zabbix\Widgets\CWidgetField;
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectHost;
use Zabbix\Widgets\Fields\CWidgetFieldTextBox;
use Zabbix\Widgets\Fields\CWidgetFieldTimePeriod;

use CWidgetsData;

/**
 * AP Detail — widget configuration form.
 *
 * Declared fields (must match manifest "in" keys and WidgetView reads):
 *
 *   hostids      — single AP host. CWidgetFieldMultiSelectHost ·
 *                  setMultiple(false). Receives the _hostid broadcast.
 *   time_period  — dashboard time-range receiver. Defaults to a foreign
 *                  reference of the dashboard's _timeperiod broadcast,
 *                  falling back to last-1-hour when nothing's wired.
 *                  Framework populates fields_values['time_period'] with
 *                  from / to / from_ts / to_ts (unix); WidgetView reads
 *                  the *_ts pair for API::History calls.
 *   xiq_host     — XIQ API base URL.
 *   pf_url       — PacketFence API URL.
 *   pf_user      — PacketFence read-only webservices user.
 *   pf_pass      — PacketFence read-only webservices password.
 *
 * Notes (M1 framework lessons + this revision):
 *   - G22: CWidgetFieldMultiSelectHost · setMultiple(false), field
 *          name 'hostids'.
 *   - G24: refresh_rate is reserved — Zabbix's edit dialog injects its
 *          own; we do not redeclare.
 *   - PHP 8.0.30 (G21) — no readonly, enums, never, intersection types.
 *   - time_period pattern: lifted verbatim from built-in itemhistory +
 *     svggraph widgets. setDefault() wires the field to dashboard's
 *     broadcast time period as the default source; setDefaultPeriod()
 *     is the hardcoded fallback when no broadcast exists. This guarantees
 *     fields_values['time_period'] always exposes the four expected keys.
 */
final class WidgetForm extends CWidgetForm {

    public function addFields(): self {
        return $this
            ->addField(
                (new CWidgetFieldMultiSelectHost('hostids', _('AP host')))
                    ->setMultiple(false)
                    ->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
            )

            ->addField(
                (new CWidgetFieldTimePeriod('time_period', _('Time period')))
                    ->setDefault([
                        CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
                            CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_TIME_PERIOD
                        )
                    ])
                    ->setDefaultPeriod(['from' => 'now-1h', 'to' => 'now'])
                    ->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
            )

            ->addField(
                (new CWidgetFieldTextBox('xiq_host', _('XIQ API host')))
                    ->setDefault('https://api.extremecloudiq.com')
            )

            ->addField(
                (new CWidgetFieldTextBox('pf_url', _('PacketFence URL')))
                    ->setDefault('https://packetfence.tcs.local:9090')
            )

            ->addField(
                (new CWidgetFieldTextBox('pf_user', _('PacketFence user')))
                    ->setDefault('zbx-readonly')
            )

            ->addField(
                (new CWidgetFieldTextBox('pf_pass', _('PacketFence password')))
            );
    }
}
