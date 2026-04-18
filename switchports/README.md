# Switch Port Status Widget

A visual switch faceplate widget for Zabbix 7.x dashboards. Displays an Extreme
Networks switch (or stack) as a photorealistic faceplate with colored port
status, PoE indicators, hover tooltips, and a top-line health summary.

![Widget type: Switch Port Status](https://img.shields.io/badge/Zabbix-7.x-red)
![Template: Extreme EXOS by SNMP](https://img.shields.io/badge/Template-Extreme%20EXOS-blue)

## What It Shows

A row-per-member stack layout with:

- **Faceplate** — ports laid out like the physical device: odd numbers on
  the top row, even numbers on the bottom row, left-to-right. Colored LEDs
  indicate link state.
- **PoE dot** — a small colored dot below each copper port showing PoE status.
  SFP ports have no PoE dot (auto-detected).
- **Health strip** — a top bar with CPU %, memory %, temperature, PSU status,
  fan status, active problem count, and the device's IP address. Each pill is
  color-coded (green/yellow/red) by severity, with per-unit details in the
  hover tooltip for PSU and fan.
- **Legend** — port-state counts below the faceplate.

## Port-State Color Mapping

| State | Color | When |
|---|---|---|
| **Up** | green | ifOperStatus = 1 |
| **Down** | slate | ifOperStatus = 2 (normal idle, not a fault) |
| **Error** | red | ifOperStatus = 7 (lowerLayerDown), OR PoE fault, OR active Zabbix problem on any of the port's items |
| **Disabled** | grey | ifAdminStatus = 2 |
| **Testing** | yellow | ifOperStatus = 3 |
| **Not Present** | transparent | ifOperStatus = 6 |
| **Unknown** | slate-blue | any other value |

Red is strictly reserved for real fault conditions. A port that is simply
unused and down shows in neutral slate.

## Health Strip Thresholds

| Metric | Green | Yellow | Red |
|---|---|---|---|
| **CPU** | < 80% | 80–89% | ≥ 90% |
| **Memory** | < 80% | 80–89% | ≥ 90% |
| **Temperature** | < 85 °C | 85–89 °C | ≥ 90 °C |
| **PSU** | all `presentOK` | — | any `presentNotOK` |
| **Fan** | all running | — | any off/failed |

The problem count pill uses Zabbix severity colors (blue info / yellow warning /
red disaster+high). The PSU and fan pills show all units in the hover tooltip
regardless of overall rollup state.

## Per-Port Interactions

- **Click a port** to select it. The widget broadcasts the selected host ID
  (`_hostid` / `_hostids`) and fires a `sw:portSelected` DOM event that the
  Switch Port Detail widget listens to. The selection persists in
  `sessionStorage` so it survives dashboard refreshes.
- **Hover a port** to see a tooltip with port number, alias, speed, PoE status,
  and any active fault reasons.

## Configuration

| Field | Default | Purpose |
|---|---|---|
| **Override host** | — | Bind to a Host Navigator widget to show any host's ports, or select a fixed host |
| **Item key prefix** | `net.if.status[ifOperStatus.` | Prefix used to discover ports by scanning items |
| **Show port numbers** | ✓ | Port number labels above and below each port icon |
| **Show port descriptions** | ✓ | Hover tooltips with alias + speed + PoE + fault reasons |
| **Show debug info** | — | Render a debug panel showing discovered indices, item values, and health query output |

## Port Discovery Logic

1. Search for items matching `net.if.status[ifOperStatus.*]` on the configured host.
2. Extract the numeric index from each key.
3. Skip indices `> 10000` (virtual/loopback interfaces).
4. Skip indices divisible by 1000 (`1000`, `2000`, `3000` — Extreme management ports).
5. Group remaining indices into stack members by detecting gaps > 100
   (e.g. 1001–1052 = member 1, 2001–2052 = member 2).
6. Fetch detail items per port (type, speed, admin status, PoE status, alias)
   in a single batch API call.
7. Auto-detect SFP ports: a port with no PoE item is rendered as an SFP slot.

## Required Template Items

This widget is built for the **Extreme EXOS by SNMP** Zabbix template. It uses:

| Data | Key |
|---|---|
| Oper status | `net.if.status[ifOperStatus.N]` |
| Admin status | `net.if.adminstatus[ifIndex.N]` |
| Link speed | `net.if.speed[ifHighSpeed.N]` (Mbps, ×1,000,000 → bps) |
| Interface type | `net.if.type[ifType.N]` |
| PoE status | `snmp.interfaces.poe.dstatus[N]` |
| Alias | `net.if.alias[ifAlias.N]`, `net.if.alias[ifIndex.N]`, or `net.if.alias[N]` (whichever exists) |

Health strip items:

| Data | Key |
|---|---|
| CPU | `system.cpu.util[*]` |
| Memory | `vm.memory.util[*]` |
| Temp value | `sensor.temp.value[*]` |
| Temp alarm | `sensor.temp.status[*]` |
| PSU | `sensor.psu.status[*]` |
| Fan | `sensor.fan.status[*]` |

Stacking detection:

| Data | Key |
|---|---|
| Stack flag | `system.hw.stacking` |
| Member presence | `stacking.member[N]` |

IP address is read from the Zabbix host's configured interfaces (SNMP
preferred), not from template items.

## Broadcasts

The widget's `manifest.json` declares:

```json
"in":  { "override_hostid": { "type": "_hostids" } }
"out": [ { "type": "_hostid" }, { "type": "_hostids" } ]
```

When a port is clicked, the selected host is broadcast to any listening widget
(e.g. Graph, Host Card, Switch Port Detail).

## File Structure

```
switchports/
├── manifest.json                 Widget registration and broadcast declarations
├── Widget.php                    Root widget class
├── actions/
│   └── WidgetView.php            Controller: discovers ports, health, problems
├── includes/
│   └── WidgetForm.php            Configuration fields
├── views/
│   ├── widget.view.php           Faceplate + health strip rendering
│   └── widget.edit.php           Config dialog
├── assets/
│   ├── js/
│   │   └── class.widget.js       Click handling, broadcast, tooltip positioning
│   └── css/
│       └── widget.css            Port LED + faceplate + health strip styling
└── README.md                     This file
```

## Installation

1. Copy `switchports/` to `/usr/share/zabbix/ui/modules/` and chown to the web server user.
2. In Zabbix UI: **Administration → General → Modules → Scan directory → Enable**.
3. Edit a dashboard → **Add widget → Switch Port Status**.

After any `manifest.json` change (for example, adding a new `out` type), you
must **delete and re-add** existing widget instances — Zabbix only assigns a
widget its unique `reference` value when it is first created.

## Troubleshooting

- **No ports shown** — the host isn't returning any `net.if.status[ifOperStatus.*]`
  items. Enable debug mode to see discovered indices and item values.
- **Wrong port numbering** — enable debug; check the `snmp_idx` column in the
  Ports debug table. For Extreme stacked switches, port 1 should be snmp_idx 1001.
- **Health strip blank** — enable debug; the "Health strip data" section shows
  what the health query returned. Null values mean the template items don't
  exist on this host.
- **Red ports on a healthy switch** — check the tooltip: it lists fault reasons
  (PoE fault / active problem). If it's unexpected, there's a Zabbix trigger
  firing on an item with a matching SNMP index.

See the project root README for the broader architecture and troubleshooting
around the Switch Port Detail companion widget.
