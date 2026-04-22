<?php
/**
 * Camera Status Grid Widget.
 *
 * Zabbix 7.x custom widget module.
 */

namespace Modules\CameraStatus;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No cameras found' => _('No cameras found'),
                'Loading…' => _('Loading…'),
                'Online' => _('Online'),
                'Offline' => _('Offline'),
                'Recording' => _('Recording'),
                'Not recording' => _('Not recording'),
                'Retention' => _('Retention'),
                'days' => _('days'),
                'Unknown' => _('Unknown'),
            ]
        ];
    }
}
