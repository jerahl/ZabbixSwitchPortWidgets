<?php
/**
 * Widget configuration form.
 *
 * Exposes: host group filter (which cameras to show), tile size, and a
 * toggle for showing retention days on the tile footer.
 */

namespace Modules\CameraStatus\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{
    CWidgetFieldMultiSelectGroup,
    CWidgetFieldRadioButtonList,
    CWidgetFieldCheckBox
};

class WidgetForm extends CWidgetForm {

    public const TILE_SMALL  = 0;
    public const TILE_MEDIUM = 1;
    public const TILE_LARGE  = 2;

    public function addFields(): self {
        return $this
            ->addField(
                (new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
                    ->setDefault([])
            )
            ->addField(
                (new CWidgetFieldRadioButtonList('tile_size', _('Tile size'), [
                    self::TILE_SMALL  => _('Small'),
                    self::TILE_MEDIUM => _('Medium'),
                    self::TILE_LARGE  => _('Large'),
                ]))->setDefault(self::TILE_MEDIUM)
            )
            ->addField(
                (new CWidgetFieldCheckBox('show_retention', _('Show retention days')))
                    ->setDefault(1)
            )
            ->addField(
                (new CWidgetFieldCheckBox('show_rs_grouping', _('Group by Recording Server')))
                    ->setDefault(1)
            );
    }
}
