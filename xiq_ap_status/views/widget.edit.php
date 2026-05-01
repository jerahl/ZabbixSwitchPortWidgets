<?php
/**
 * @var CView $this
 * @var array $data
 */

declare(strict_types=0);

(new CWidgetFormView($data))
    ->addField(
        new CWidgetFieldMultiSelectHostView($data['fields']['hostids'])
    )
    ->addField(
        new CWidgetFieldIntegerBoxView($data['fields']['max_rows'])
    )
    ->show();
