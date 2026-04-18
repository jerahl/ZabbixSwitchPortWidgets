# Switch Port Device (PacketFence) Widget

A dashboard widget that queries the PacketFence REST API to show what devices
are currently connected to a switch port. Designed to pair with the Switch
Port Status widget — click a port, and this widget displays the full device
card for every MAC seen on that port: IP address, vendor, OS fingerprint,
802.1X user, VLAN, role, and any open security events.

![Zabbix 7.x](https://img.shields.io/badge/Zabbix-7.x-red)
![PacketFence](https://img.shields.io/badge/PacketFence-15-blue)

## What It Shows

For each device currently authenticated on the selected switch port:

- **MAC address** (monospaced, bold header)
- **Registration status** — Registered / Unregistered / Pending
- **Online state** — On / Off
- **IP address** (from PF's ip4log)
- **Hostname** (from DHCP / fingerprinting)
- **Vendor** (MAC OUI lookup)
- **OS** (Fingerbank device class)
- **802.1X username** or owner PID
- **VLAN** currently assigned
- **Role** currently applied
- **Connection type** (Ethernet-EAP, Ethernet-NoEAP, etc.)
- **Session start time**
- **Open security events** — listed prominently in red if present
- **Action link** — opens the device in PacketFence's admin UI

Multiple devices (voice+data, MAC chaining, trunked phones) stack as separate
cards.

## Selection Mechanism

Same dual-input pattern as the Switch Port Detail widget:

1. **Zabbix broadcast** — inherits the `override_hostid` binding so we know
   which switch is selected.
2. **Custom DOM event `sw:portSelected`** — fired by Switch Port Status
   whenever a port is clicked. Carries the SNMP ifIndex.

The JS injects `sw_snmpIndex` into every PHP update request, and the PHP
controller converts that ifIndex into a port number using the configured
modulus (see below).

## Port Number Mapping

PacketFence tracks connected devices by `switch IP + port`. The port value
depends on how your PF switch profile is configured:

- Some setups store the raw **ifIndex** (Extreme: 1001, 1002, …).
- Most setups store the **port number** (1, 2, 3, … 48).

This widget defaults to **port number mode** with a modulus of **1000**
(Extreme convention). That means:

- ifIndex 1001 → port 1 on stack member 1
- ifIndex 1048 → port 48 on stack member 1
- ifIndex 2017 → port 17 on stack member 2

If your PF setup uses bare ifIndex, set **Port number modulus** to **1** in
the widget config — then snmpIndex is passed through unchanged.

For non-Extreme hardware with different ifIndex-to-port conventions, adjust
the modulus or fork the WidgetView and replace the calculation in STEP 4.

## Configuration

| Field | Default | Purpose |
|---|---|---|
| **Override host** | — | Bind to Switch Port Status widget for host selection |
| **PacketFence API URL** | `https://packetfence.example.com:9999` | Root URL, no trailing slash. Default PF API port is **9999** |
| **Username** | `admin` | PF admin user or a dedicated webservices account |
| **Password** | — | Plaintext (see security note below) |
| **Port number modulus** | `1000` | Divisor used to extract port number from ifIndex. Set to `1` to use ifIndex as port number |
| **Verify TLS certificate** | unchecked | Enable if PF has a valid public CA cert. Disable for self-signed |
| **Show debug info** | ✓ | Render debug panel showing each API call's status |

## Security Considerations

**The PF password is stored in the Zabbix database in plaintext.** Anyone
with read access to `widget_field` table rows can retrieve it. Mitigations:

1. **Use a dedicated PF webservices user** with read-only API scope, not the
   full `admin` account. Create it in PF under *Configuration → System
   Configuration → Web Services*.
2. **Restrict database access** — make sure only the Zabbix web app
   account can read the `widget_field` table, not dashboard operators.
3. **Consider using PacketFence's per-API-endpoint ACL** to limit what this
   account can actually see (nodes read, locationlogs read, security_events
   read — nothing else).
4. **Per-widget TLS cert verification** — if your PF has a proper cert,
   enable *Verify TLS certificate*.

This is a real concern — don't disregard it. For production deployments, a
proper fix would be to store credentials in a module-level config file
readable only by the web server user rather than in widget fields. If you
want that added, open an issue or PR.

## PacketFence API Endpoints Used

| Call | Purpose |
|---|---|
| `POST /api/v1/login` | Obtain auth token |
| `POST /api/v1/locationlogs/search` | Find open locationlogs matching switch IP + port |
| `GET  /api/v1/node/{mac}` | Full device details |
| `GET  /api/v1/security_events?query=…` | Active events for this MAC |

All requests use TLS. The widget re-authenticates on every refresh (no token
caching) for simplicity — acceptable for dashboard polling intervals of 30s+.

## Required PacketFence Setup

1. **API access enabled** — default in PF 15 on port 9999.
2. **Admin or webservices user** with at least read access to:
   - `/api/v1/node/*`
   - `/api/v1/locationlogs/search`
   - `/api/v1/security_events`
3. **Switch registered** in PF with the same management IP that appears in
   its Zabbix host interface. If Zabbix has the switch as hostname-based,
   either change to IP-based, or add a DNS A record PF can resolve to match.
4. **Locationlogs accurate** — requires PF to be actually receiving RADIUS
   accounting or SNMP traps from your switches. If nothing shows up even for
   known-connected devices, verify PF → Status → Nodes shows them as online
   on the right port.

## File Structure

```
packetfence/
├── manifest.json                 Widget registration
├── Widget.php                    Root widget class
├── actions/
│   └── WidgetView.php            PF API client + port lookup logic
├── includes/
│   └── WidgetForm.php            Configuration fields
├── views/
│   ├── widget.view.php           Device card rendering
│   └── widget.edit.php           Configuration dialog
├── assets/
│   ├── js/
│   │   └── class.widget.js       Port-selection event listener
│   └── css/
│       └── widget.css            Cards, pills, debug panel styling
└── README.md                     This file
```

## Installation

1. Copy `packetfence/` to `/usr/share/zabbix/ui/modules/` and chown to the web
   server user.
2. In Zabbix UI: **Administration → General → Modules → Scan directory → Enable**.
3. Edit your dashboard → **Add widget → Switch Port Device (PacketFence)**.
4. In config: set *Override host* → *Widget* → *Switch Port Status*, then
   fill in PacketFence URL and credentials.

## Troubleshooting

- **"Awaiting port selection"** — no port has been clicked in Switch Port
  Status, or this widget isn't bound to it via *Override host*.
- **"PacketFence login failed: HTTP 401"** — wrong username/password.
- **"PacketFence login failed: SSL certificate problem"** — either turn off
  *Verify TLS certificate*, or add PF's CA to the system trust store.
- **"Switch management IP not found"** — the Zabbix host has no main
  interface, or the interface is DNS-based with no resolvable name. Set a
  main SNMP interface with an IP address.
- **"No devices connected"** but you know a device is plugged in — enable
  debug, look at `step_7_locationlogs.count`. If 0, then PF's locationlog
  doesn't contain an open entry for this `switch=<ip> port=<num>` combination.
  Check the port-number modulus, and check PF → Status → Nodes to verify the
  device's recorded port value.
- **Devices show but IP is empty** — PF hasn't correlated a MAC to an IP
  via ip4log. Verify DHCP/accounting is feeding PF.

## Compatibility

- **PacketFence 15.x** — tested against v15 API shape.
- **PacketFence 12–14** — probably works. The `/api/v1/login`,
  `/api/v1/node/{mac}`, and `/api/v1/locationlogs/search` endpoints have been
  stable across versions, but response field names may differ slightly.
- **PacketFence ≤ 11** — untested; token auth header format differed.
- **Zabbix 7.0, 7.2, 7.4** — same requirements as companion widgets.
- **PHP 8.1+** with `curl` extension.
