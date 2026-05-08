<?php

declare(strict_types=1);

namespace Modules\APDetail;

use Zabbix\Core\CWidget;

/**
 * AP Detail widget module entry point.
 *
 * Minimal CWidget subclass — all logic lives in WidgetView.php and
 * class.widget.js. This file exists so Zabbix can locate the module
 * namespace and register its actions.
 *
 * G20: MUST extend Zabbix\Core\CWidget, NOT CModule. CModule registers
 *      as a generic non-widget module — the dashboard widget picker
 *      filters CModule out and the widget never appears in the picker.
 *
 * G16: If this widget is missing from the picker AND another widget on
 *      the system also breaks when this module is enabled, the namespace
 *      `Modules\APDetail` has collided with another installed module.
 *      Rename to a deployment-unique prefix (e.g. `Modules\TcsApDetail`)
 *      in this file, WidgetView.php, WidgetForm.php, and update the JS
 *      class name + manifest.widget.js_class to match.
 */
final class Widget extends CWidget {
}
