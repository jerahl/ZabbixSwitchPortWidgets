# Switch Port Detail Widget

A companion widget that displays detailed per-port statistics when a port is
selected in the Switch Port Status widget. Shows traffic sparklines, utilization,
24-hour online state and error/discard history, plus quick-launch buttons that
drive Graph (classic) widgets on the same dashboard.

![Widget type: Switch Port Detail](https://img.shields.io/badge/Zabbix-7.x-red)
![Companion: Switch Port Status](https://img.shields.io/badge/Requires-Switch%20Port%20Status-orange)

## What It Shows

- **Header** — port number, interface alias, oper status badge (`UP`, `DOWN`,
  `ERROR`, `DISABLED`, etc.), and a `MAINTENANCE: ON` flag if the host is in maintenance.
- **Traffic rows** — inline sparkline graphs for IN and OUT with live values,
  plus a utilization percentage against link speed.
- **PoE row** — appears when the port has a PoE item, showing a colored badge
  with the current state.
- **24h online state bar** — 48 segments, one per 30 minutes, colored green
  for up, slate for down, dark for unknown.
- **Errors bar** — 48 segments of summed in+out errors per time slice,
  intensity-scaled by count.
- **Discards bar** — same as errors for in+out discards.
- **Link Speed** footer — formatted speed label.
- **Graph-link buttons** — small chart icons next to each metric label. Click
  to retarget a Graph (classic) widget on the same dashboard to that item.

## Selection Mechanism

Two channels feed the widget:

1. **Zabbix broadcast** — the Switch Port Status widget declares
   `out: [_hostid, _hostids]`, and this widget has an `override_hostid` field
   that subscribes to those broadcasts. The framework resolves the hostid into
   `fields_values['override_hostid']` automatically.
2. **Custom DOM event `sw:portSelected`** — carries the SNMP index along with
   the hostid. The JS injects `sw_snmpIndex` into every update request so PHP
   knows which port to query.

The selection persists in `sessionStorage` (`sw_port_selection`) so the widget
remembers the last-selected port across page refreshes.

## Time Range

The sparklines and bars respect the **dashboard's time period** by default.
The widget declares `in: { time_period: { type: "_timeperiod" } }` and its form
uses `CWidgetFieldTimePeriod` defaulting to a reference to `REFERENCE_DASHBOARD`.
Labels adapt automatically:

- Dashboard set to 1h → "1h online state", "Errors 1h", "Discards 1h"
- Dashboard set to 24h → "24h online state", etc.
- Dashboard set to 7d → "7d online state", etc.

You can override the range per-widget in its configuration dialog.

## State Color Mapping

| State | Badge | When |
|---|---|---|
| **UP** | green | ifOperStatus = 1 |
| **DOWN** | neutral slate | ifOperStatus = 2 |
| **ERROR** | red | ifOperStatus = 7 (lowerLayerDown) |
| **DISABLED** | grey | ifAdminStatus = 2 |
| **TESTING** | yellow | ifOperStatus = 3 |
| **UNKNOWN** | slate-blue | other |

The 24h online-state bar uses the same neutral-slate treatment for down
segments — red is reserved for the Errors bar.

## Graph-Link Buttons

Each metric label has a small chart icon beside it:

| Row | Buttons |
|---|---|
| IN | traffic_in item |
| OUT | traffic_out item |
| Utilization | traffic_in + traffic_out combined |
| 24h online state | status (oper status) item |
| Errors | errors_in + errors_out combined |
| Discards | discards_in + discards_out combined |

Clicking a button broadcasts `_itemid` + `_itemids` via `ZABBIX.EventHub`. Any
dashboard widget that subscribes to these types (Graph classic, Piechart,
Scatter plot) will re-render to show the clicked item.

## Configuration

| Field | Default | Purpose |
|---|---|---|
| **Override host** | Bound to Switch Port Status widget | Host whose port is being shown |
| **Time period** | Dashboard (default) | Time range for sparklines and 24h bars |
| **Show debug info** | ✓ | Render a diagnostic panel below the widget |

## Debug Panel

When enabled, displays:

- **step_1_hostid** — how the hostid was resolved (from broadcast vs direct input)
- **step_2_snmpindex** — the snmpIndex value received from the JS
- **step_3_all_inputs** — every POST parameter Zabbix received
- **step_4_fields_values** — all resolved form field values
- **step_5_query** — candidate item keys searched and matched counts
- **step_6_by_type** — which items got categorized into which metric bucket
- **step_8_time_range** — the time range actually used for history queries

## Broadcasts

```json
"in":  { "time_period":       { "type": "_timeperiod" } }
"out": [ { "type": "_itemid" }, { "type": "_itemids" } ]
```

The widget does NOT declare the `override_hostid` subscription in the manifest
`in` block — that binding happens through the form field's `setInType()`
internally.

## File Structure

```
portdetail/
├── manifest.json                 Widget registration, in/out declarations
├── Widget.php                    Root widget class
├── actions/
│   └── WidgetView.php            Controller: resolves hostid + snmp index, fetches items
├── includes/
│   └── WidgetForm.php            Override host + time period + debug fields
├── views/
│   ├── widget.view.php           Sparklines, bars, graph-link buttons, debug panel
│   └── widget.edit.php           Config dialog
├── assets/
│   ├── js/
│   │   └── class.widget.js       Event listener, broadcast handlers, click wiring
│   └── css/
│       └── widget.css            Layout, state badges, debug panel styling
└── README.md                     This file
```

## Installation

1. Copy `portdetail/` to `/usr/share/zabbix/ui/modules/` and chown to the web server user.
2. In Zabbix UI: **Administration → General → Modules → Scan directory → Enable**.
3. Edit a dashboard → **Add widget → Switch Port Detail**.
4. In the widget config, set **Override host → Widget → Switch Port Status**.

After any `manifest.json` change (adding or removing `in`/`out` types), you
must **delete and re-add** existing widget instances. Zabbix only assigns the
widget its `reference` value when first created — without one, other widgets
cannot list it as a broadcast source.

## Hooking Up a Graph (Classic) Widget

1. Add a **Graph (classic)** widget to the dashboard.
2. In its config, set **Item → Widget → Switch Port Detail**.
3. Click any graph-link button in the Port Detail widget — the Graph widget
   will re-render to show that metric's data over the dashboard's time range.

## Troubleshooting

- **"Awaiting port selection"** — either no port has been clicked in the Switch
  Port Status widget yet, or the widget isn't bound to it. Check that Override
  host is set to Widget → Switch Port Status in the config.
- **"Port Detail widget isn't listed as a source"** — the widget was added
  before `out` broadcasts were declared in its manifest. Delete the instance
  and re-add it to force Zabbix to generate a `reference` value.
- **Sparklines always show 24h regardless of dashboard range** — the widget
  was added before `in.time_period` was declared. Same fix: delete and re-add.
- **No items found for port index N** — the SNMP index in the click event
  doesn't match any items on the host. Enable debug and check `step_5_query`
  to see which keys were tried vs. which exist on the host.
- **Graph buttons do nothing** — open browser devtools. The click should fire
  `broadcast()`. If the call throws "Cannot broadcast data of undeclared type",
  the widget wasn't re-added after the `out` manifest change.
