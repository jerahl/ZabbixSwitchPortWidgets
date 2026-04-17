# Zabbix Switch Port Widgets

Two companion Zabbix 7.x dashboard widgets for visualizing Extreme Networks
switches (single units and stacks) as interactive faceplates with drill-down
details.

![Zabbix 7.x](https://img.shields.io/badge/Zabbix-7.0%20/%207.2%20/%207.4-red)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)
![Template: Extreme EXOS](https://img.shields.io/badge/Template-Extreme%20EXOS%20by%20SNMP-green)

## The Pair

| Widget | Role |
|---|---|
| **[Switch Port Status](./switchports/README.md)** | Faceplate overview. Shows every port as a colored icon, a top-line health summary, and a problem count pill. |
| **[Switch Port Detail](./portdetail/README.md)** | Per-port detail view. Shows traffic sparklines, 24h history bars, and graph-link buttons that drive a Graph (classic) widget. |

They are designed to work together: click a port in **Switch Port Status** and
**Switch Port Detail** updates to show that port's metrics. Both widgets can
also be used independently if desired.

## Screenshot-ish Layout

```
┌─ Switch Port Status ──────────────────────────────────────────────────────┐
│ ⬢CPU 4%  ⬢Mem 42%  ⬢66°C  ⬢PSU  ⬢Fan    ⚠3          IP: 10.21.100.7     │
│ stack-sw-01                              12 up / 4 down / 16 total        │
│                                                                           │
│  [■][■][■][■][■][■][■][□][■][■][■][■]            SFP [■][■]               │
│  [■][■][■][■][■][■][■][■][■][■][■][■]                [■][■]               │
│                                                                           │
│ ● Up (12)  ● Down (3)  ● Error (1)                                        │
└───────────────────────────────────────────────────────────────────────────┘
                                   │
                 (user clicks port 5)
                                   │
                                   ▼
┌─ Switch Port Detail ──────────────────────────────────────────────────────┐
│ Port 5    uplink-to-core      [UP]                                        │
│                                                                           │
│ IN  📊   ╱╲╱╲╱───         142.3 Mbps                                       │
│ OUT 📊   ╱╲╱╲╱───         87.1 Mbps                                        │
│ Utilization 📊             14.2%                                           │
│                                                                           │
│ 24h online state 📊                                     now               │
│ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓                           │
│                                                                           │
│ Errors 24h 📊             0 (in 0 / out 0), stable                        │
│ ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░                           │
│                                                                           │
│ Link Speed                1 Gbps                                          │
└───────────────────────────────────────────────────────────────────────────┘
                                   │
          (user clicks 📊 next to "IN")
                                   │
                                   ▼
┌─ Graph (classic) ─────────────────────────────────────────────────────────┐
│                         Traffic In — Port 5                               │
│                         [line graph here]                                 │
└───────────────────────────────────────────────────────────────────────────┘
```

## Quick Start

1. **Deploy both widgets:**
   ```bash
   cd /usr/share/zabbix/ui/modules
   unzip zabbix_switchports_widget.zip
   unzip zabbix_portdetail_widget.zip
   chown -R www-data:www-data switchports portdetail
   ```

2. **Enable in Zabbix UI:**
   Administration → General → Modules → Scan directory → Enable both.

3. **Build a dashboard:**
   - Add a **Host Navigator** widget (built-in) so you can pick which switch to view.
   - Add a **Switch Port Status** widget. Set *Override host → Widget → Host Navigator*.
   - Add a **Switch Port Detail** widget. Set *Override host → Widget → Switch Port Status*.
   - Add a **Graph (classic)** widget. Set *Item → Widget → Switch Port Detail*.

4. **Use it:**
   - Select a host in the Host Navigator → Switch Port Status populates.
   - Click a port → Switch Port Detail populates.
   - Click a chart-icon button in the detail → Graph (classic) renders.

## Data Flow Architecture

```
[Host Navigator] ──_hostid──► [Switch Port Status] ──_hostid──► [Switch Port Detail]
                                                                       │
   click a port in Switch Port Status                                   │
        │                                                               │
        ├─► broadcasts _hostid via ZABBIX.EventHub ────────────────────►┤
        └─► fires DOM event 'sw:portSelected' with snmpIndex ──────────►┤
                                                                       │
                                      click a graph-link button        │
                                            │                          │
                                            └─ broadcasts _itemid ────►[Graph (classic)]
```

- **Host broadcasts** use Zabbix's native EventHub (`_hostid`, `_hostids`).
- **Port selection** piggybacks on hostid broadcast for wiring, plus a custom
  DOM event (`sw:portSelected`) for the SNMP index which isn't a standard
  Zabbix broadcast type.
- **Item broadcasts** from Port Detail use `_itemid` / `_itemids`, same types
  the Item Navigator widget uses — so any third-party graph/item widget that
  speaks the standard Zabbix widget protocol works with them.

## Why Two Widgets

Zabbix's widget framework is built around lightweight, focused dashboard
components that communicate via broadcasts. Trying to put both the faceplate
and the per-port detail into a single widget would mean fetching dozens of
items for every port on every refresh — expensive on a 48-port switch in a
stack of 4. Splitting them keeps the faceplate fast (one batched item query
per host) and defers the heavy per-port history fetching to a separate widget
that only runs when a port is actively selected.

## Compatibility

- **Zabbix 7.0, 7.2, 7.4** — uses `manifest_version: 2.0`, PHP namespaced
  module structure, `CControllerDashboardWidgetView`.
- **PHP 8.1+** — requires named arguments, `match()`, and `str_starts_with()`.
- Does **not** work on Zabbix 6.x — widget API changed significantly in 7.0.
- Extreme EXOS by SNMP template is recommended for full functionality.
  Other templates work for basic port state display if they use similar key
  patterns (`net.if.status[…]`, `net.if.adminstatus[…]`, `snmp.interfaces.poe[…]` etc.). CPU/memory/
  temperature/PSU/fan detail requires matching `sensor.*` and `system.cpu.*` 
  keys from Extreme template specifically.

## Development Notes

### The Zabbix "reference" Field

A widget only appears as a broadcast source for other widgets after it has
been **created on the dashboard**. Zabbix assigns each widget a unique
`reference` value when added, which is used by listening widgets to subscribe
to its broadcasts.

**Practical consequence:** any time you change the `manifest.json` `in` or
`out` declarations, you must delete and re-add every existing instance of
that widget on your dashboards. The existing instances were saved to the
database before those declarations existed and have no `reference` field.

### Manifest `in` vs `out` Format

- **`out`** is an array of `{type}` objects:
  `[{"type": "_hostid"}, {"type": "_hostids"}]`
- **`in`** is an **object** keyed by field name:
  `{"override_hostid": {"type": "_hostids"}}`

Getting these wrong is the most common source of "broadcast silently does
nothing" bugs. The field name on the `in` side must match a real
`CWidgetField` in the widget's `WidgetForm`.

### Broadcast Types

From `CWidgetsData`:

| Constant | Wire format |
|---|---|
| `DATA_TYPE_HOST_ID` | `_hostid` |
| `DATA_TYPE_HOST_IDS` | `_hostids` |
| `DATA_TYPE_ITEM_ID` | `_itemid` |
| `DATA_TYPE_ITEM_IDS` | `_itemids` |
| `DATA_TYPE_TIME_PERIOD` | `_timeperiod` |

These match what Zabbix's own widgets (Host Navigator, Item Navigator, Graph,
Dashboard time selector) use.

### File Layout Convention

Every widget directory under `ui/modules/<id>/` needs:

```
<id>/
├── manifest.json                    Required
├── Widget.php                       Optional - if you need a subclass
├── actions/WidgetView.php           Controller (extends CControllerDashboardWidgetView)
├── includes/WidgetForm.php          Config fields (extends CWidgetForm)
├── views/
│   ├── widget.view.php              Presentation
│   └── widget.edit.php              Config dialog
└── assets/
    ├── js/class.widget.js           JS class (extends CWidget)
    └── css/widget.css
```

Zabbix 7.x auto-discovers any directory here that has a valid `manifest.json`.
The module ID must match the directory name.

## License

MIT. Contributions welcome.

## See Also

- [Switch Port Status detailed docs](./switchports/README.md)
- [Switch Port Detail detailed docs](./portdetail/README.md)
- [Zabbix 7.x custom widget tutorial](https://www.zabbix.com/documentation/current/en/devel/modules/tutorials/widget)
- [Zabbix widget development guide](https://www.zabbix.com/documentation/current/en/devel/modules/widgets)
