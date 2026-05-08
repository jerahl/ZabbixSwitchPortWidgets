<?php declare(strict_types = 0);

/**
 * AP Detail — widget edit/config panel view.
 *
 * Canonical 7.4 pattern: CWidgetFormView wraps the form, each field
 * rendered via its corresponding CWidgetField*View class. Defensive
 * array_key_exists() guards mirror the built-in hostcard widget.
 *
 * Field-to-View mapping:
 *   hostids      → CWidgetFieldMultiSelectHostView
 *   time_period  → CWidgetFieldTimePeriodView
 *   xiq_host     → CWidgetFieldTextBoxView
 *   pf_url       → CWidgetFieldTextBoxView
 *   pf_user      → CWidgetFieldTextBoxView
 *   pf_pass      → CWidgetFieldTextBoxView
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
    ->addField(array_key_exists('hostids', $data['fields'])
        ? new CWidgetFieldMultiSelectHostView($data['fields']['hostids'])
        : null
    )
    ->addField(array_key_exists('time_period', $data['fields'])
        ? new CWidgetFieldTimePeriodView($data['fields']['time_period'])
        : null
    )
    ->addField(array_key_exists('xiq_host', $data['fields'])
        ? new CWidgetFieldTextBoxView($data['fields']['xiq_host'])
        : null
    )
    ->addField(array_key_exists('pf_url', $data['fields'])
        ? new CWidgetFieldTextBoxView($data['fields']['pf_url'])
        : null
    )
    ->addField(array_key_exists('pf_user', $data['fields'])
        ? new CWidgetFieldTextBoxView($data['fields']['pf_user'])
        : null
    )
    ->addField(array_key_exists('pf_pass', $data['fields'])
        ? new CWidgetFieldTextBoxView($data['fields']['pf_pass'])
        : null
    )
    ->show();
