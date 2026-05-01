<?php

declare(strict_types=1);

namespace Modules\XiqApStatus\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm
};
use Zabbix\Widgets\Fields\{
    CWidgetFieldMultiSelectHost,
    CWidgetFieldIntegerBox
};

class WidgetForm extends CWidgetForm
{
    public function addFields(): self
    {
        return $this
            ->addField(
                (new CWidgetFieldMultiSelectHost('hostids', _('Hosts')))
                    ->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
            )
            ->addField(
                (new CWidgetFieldIntegerBox('max_rows', _('Max table rows'), 1, 5000))
                    ->setDefault(100)
            );
    }
}
