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
- **Cycle PoE button** — appears next to the PoE badge when the port has a
  PoE item. Clicking it dispatches a power-cycle to rConfig via the rConfig
  REST API (snippet deployment). The API token never travels to the browser
  — see "Cycle PoE via rConfig" below.

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

## Cycle PoE via rConfig

When the selected port has a PoE item, the widget renders a **Cycle** button
next to the PoE state badge. Clicking it (after a confirmation prompt) calls
the rConfig REST API and deploys a pre-configured snippet against the host's
rConfig device, with the resolved interface name passed as the `port`
dynamic variable.

### Architecture

```
Browser ──POST──► Zabbix (widget.portdetail.cyclepoe action)
                       │  reads {$RCONFIG.*} macros for the host
                       │  resolves rConfig device id by host IP/name
                       │  (or uses {$RCONFIG.DEVICE_ID} override)
                       ▼
                  rConfig API  ──deploys snippet to──►  Network device
                  (HTTPS + apitoken)
```

The browser only sends `hostid`, `snmp_index`, and the resolved interface
name. The rConfig URL, API token, and snippet ID never reach the client —
they live in Zabbix host macros and are read by PHP at request time. The
rConfig device id is looked up server-side from the rConfig API.

### Required Zabbix host macros

Define these on each switch host (or on a template they inherit from):

| Macro | Type | Required | Example | Notes |
|---|---|---|---|---|
| `{$RCONFIG.URL}` | Text | yes | `https://rconfig.example.com` | Base URL. Must be HTTPS — plaintext is rejected. |
| `{$RCONFIG.TOKEN}` | **Secret text** | yes | `eyJ0eXAi…` | Define as **Secret** so it's masked in the UI. |
| `{$RCONFIG.POE_SNIPPET_ID}` | Text | yes | `7` | Numeric ID of the PoE-cycle snippet in rConfig. |
| `{$RCONFIG.DEVICE_ID}` | Text | **no — optional override** | `1042` | When set, pins this host to a specific rConfig device id and skips the lookup. Useful when auto-resolution is ambiguous. |

The first three macros are typically defined once at a template level and
inherited; only `{$RCONFIG.DEVICE_ID}` (when used at all) is per-host.

### How the rConfig device id is resolved

When `{$RCONFIG.DEVICE_ID}` is **not** set, the action calls
`GET /api/v2/devices` and matches the Zabbix host against rConfig records
in this priority order. The first key that yields a single match wins:

1. SNMP-interface IP → `device_ip`
2. Any-interface IP → `device_ip`
3. Zabbix technical hostname → `device_name` (case-insensitive)
4. Zabbix visible name → `device_name` (case-insensitive)

If a key produces more than one match, the action stops and asks the user
to disambiguate by setting `{$RCONFIG.DEVICE_ID}` on the host. The
resolution path used (`auto:snmp_ip`, `auto:hostname`, `macro_override`,
`ambiguous:visible_name`, etc.) is written to the audit log line so you
can confirm what matched.

The lookup paginates `/api/v2/devices` with `per_page=100` up to a 2000-
device ceiling, which covers all but the largest deployments. For deployments
beyond that, set `{$RCONFIG.DEVICE_ID}` per host.

### The rConfig snippet

The snippet must declare a single dynamic variable named `interface_name`.
The value sent is the stack-member/port colon form derived from ifIndex —
ifIndex `1007` becomes `"1:7"`, ifIndex `2014` becomes `"2:14"`, etc.
The exact configuration syntax is platform-dependent; for example:

```
interface {interface_name}
 power inline never
!
interface {interface_name}
 power inline auto
```

When the user clicks Cycle, the widget posts:

```json
POST /api/v1/snippets/<POE_SNIPPET_ID>/deploy
{
  "devices":      [<resolved_device_id>],
  "dynamic_vars": { "interface_name": "1:7" }
}
```

with the `apitoken: <TOKEN>` header. The `<resolved_device_id>` is either
the value of `{$RCONFIG.DEVICE_ID}` (when set) or the result of matching
the host against `GET /api/v2/devices`.

### Security model

- **Token never on the client.** The token is read from a Zabbix Secret
  Macro by the PHP action. It is not embedded in widget config, item
  responses, the DOM, or any HTTP response sent to the browser.
- **HTTPS enforced.** The action refuses any `{$RCONFIG.URL}` that is not
  `https://`. cURL TLS verification (`CURLOPT_SSL_VERIFYPEER` /
  `VERIFYHOST=2`) is on with no override.
- **Authorization.** Only users with role ≥ Zabbix Admin may invoke the
  action. Read-only users see the button but the call returns a permission
  error.
- **Audit.** Every cycle attempt is written to the PHP error log with
  `hostid`, `snmp_index`, interface name, snippet ID, device ID, and HTTP
  result — never the token.
- **Confirmation prompt.** Browser-side `confirm()` warns that connected
  devices will lose power. No accidental clicks.
- **Per-host scope.** API::Host->get() honours the user's host
  permissions, so a user who can't see the host can't cycle its ports.

### Token-rotation procedure

1. In the rConfig UI: **Settings → REST API → New Token**, name it
   descriptively, copy the value.
2. In Zabbix: edit the relevant host (or template) → **Macros** tab →
   update `{$RCONFIG.TOKEN}` (keep it as type **Secret text**) → Save.
3. In rConfig: revoke the old token.
4. No widget reload is required — the next cycle request reads the
   updated macro.

### Disabling the feature

Uncheck **Show "Cycle PoE" button** in the widget configuration. The button
will not be rendered. Existing host macros are untouched.



| Field | Default | Purpose |
|---|---|---|
| **Override host** | Bound to Switch Port Status widget | Host whose port is being shown |
| **Time period** | Dashboard (default) | Time range for sparklines and 24h bars |
| **Show "Cycle PoE" button** | ✓ | Render the rConfig PoE-cycle button next to the PoE badge |
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
├── manifest.json                 Widget registration, in/out declarations, action routes
├── Widget.php                    Root widget class
├── actions/
│   ├── WidgetView.php            Controller: resolves hostid + snmp index, fetches items
│   └── CyclePoe.php              AJAX action: dispatches PoE cycle to rConfig API
├── includes/
│   └── WidgetForm.php            Override host + time period + cycle toggle + debug fields
├── views/
│   ├── widget.view.php           Sparklines, bars, graph-link buttons, cycle button, debug panel
│   └── widget.edit.php           Config dialog
├── assets/
│   ├── js/
│   │   └── class.widget.js       Event listener, broadcast handlers, click + cycle wiring
│   └── css/
│       └── widget.css            Layout, state badges, cycle button, debug panel styling
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
- **Cycle PoE button missing** — the PoE item must exist for the port (so
  the badge is rendered), the **Show "Cycle PoE" button** option must be
  enabled in the widget config, and the controller must be able to extract
  an interface name from item names. If the widget shows a small ⚠ next to
  the PoE badge instead of the Cycle button, the interface name couldn't be
  parsed — verify your SNMP template names items like
  `Interface GigabitEthernet1/0/12: Bits received`.
- **"Missing required host macros"** — define all four `{$RCONFIG.*}`
  macros on the host (or an inherited template). The error message lists
  which ones are missing.
- **"rConfig URL must use HTTPS"** — change `{$RCONFIG.URL}` to start with
  `https://`. The action refuses plaintext to protect the token.
- **"Connection error: SSL certificate problem"** — the Zabbix server's
  trust store doesn't include the rConfig server's CA. Install the CA in
  the system trust bundle (e.g. `update-ca-certificates`). TLS verification
  cannot be disabled by design.
- **"No rConfig device matches this host by IP or name"** — neither the
  Zabbix host's interface IPs nor its hostname/visible name match any
  `device_ip`/`device_name` in rConfig. Either update one side to match
  the other, or set `{$RCONFIG.DEVICE_ID}` on the host to pin the device
  id explicitly.
- **"Multiple rConfig devices match by …"** — two or more rConfig devices
  share the same IP or name. Pin the right one with `{$RCONFIG.DEVICE_ID}`.
- **"Could not list rConfig devices (HTTP 401)"** — the API token doesn't
  have permission to read `/api/v2/devices`. Generate a token with the
  required scope.
- **HTTP 401/403 from rConfig** — token is invalid or revoked. Generate a
  new one in rConfig **Settings → REST API**, then update
  `{$RCONFIG.TOKEN}` on the host/template.
- **"Permission denied" when clicking Cycle** — the user is below Zabbix
  Admin role. Cycle is restricted to Admin and Super-admin.
