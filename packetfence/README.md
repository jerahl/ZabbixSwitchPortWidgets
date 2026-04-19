# Switch Port Device (PacketFence) Widget

A dashboard widget that shows what devices are connected to a switch port by
cross-referencing the switch's **bridge forwarding table** (learned MACs per
port, polled by Zabbix via SNMP) with PacketFence's node database.

Click a port in the Switch Port Status widget → this widget renders a card
for every MAC currently learned on that port, enriched with PacketFence's
node info: vendor, OS fingerprint, registration status, bypass VLAN/ACLs,
user-agent, DHCP fingerprint, and any open security events.

![Zabbix 7.x](https://img.shields.io/badge/Zabbix-7.x-red)
![PacketFence](https://img.shields.io/badge/PacketFence-15-blue)

## How It Works

1. **Zabbix polls the switch's FDB** via SNMP (see template setup below).
   For each port, Zabbix stores the list of MACs currently learned on that
   port as a text item (`port.mac.list[<ifIndex>]`).
2. **User clicks a port** in Switch Port Status. The widget receives the
   host and SNMP ifIndex.
3. **Widget reads the MAC-list item** from Zabbix's item API — no SNMP, no
   shelling out, just a standard API call against already-polled data.
4. **Widget searches PacketFence** for those MACs via
   `POST /api/v1/nodes/search`, asking for fields like `computername`,
   `device_manufacturer`, `device_type`, `status`, `dhcp_fingerprint`, etc.
5. **Widget renders a card per MAC** with the enriched info.

The flow is fast (one Zabbix API call + one PF login + one PF search = 3
HTTPS round trips) and robust: if PF is unreachable, you still see the raw
MACs learned on the port.

## Required Template Setup

The MAC-list items need to exist on the host. This is a one-time template
change — add these two item types to your switch template:

### 1. Master item: FDB walk

| Property | Value |
|---|---|
| Name | `FDB port map` |
| Type | SNMP agent |
| Key | `fdb.raw` |
| SNMP OID | `walk[1.3.6.1.2.1.17.4.3.1.2,1.3.6.1.2.1.17.1.4.1.2]` |
| Type of information | Text |
| Update interval | 2m |
| Preprocessing | *(none — leave the preprocessing list empty)* |

This item walks both the bridge forwarding table (`dot1dTpFdbPort`) and the
bridge-port-to-ifIndex mapping (`dot1dBasePortIfIndex`) in one SNMP pass.
The `walk[...]` OID form makes Zabbix return plain text with one line per
OID, like:

```
.1.3.6.1.2.1.17.4.3.1.2.0.26.164.18.52.86 = INTEGER: 12
.1.3.6.1.2.1.17.4.3.1.2.0.80.86.43.203.171 = INTEGER: 7
.1.3.6.1.2.1.17.1.4.1.2.12 = INTEGER: 1005
.1.3.6.1.2.1.17.1.4.1.2.7 = INTEGER: 1003
```

The parsing happens on the dependent item, not here. Do **not** add SNMP
walk to JSON preprocessing — that requires pre-declared field names which
don't fit this use case (the FDB is keyed by 6-octet MAC, the bp-to-ifIndex
table is keyed by bridge-port integer).

### 2. Dependent item prototype (under your interface LLD rule)

| Property | Value |
|---|---|
| Name | `Interface {#IFNAME}: MACs learned` |
| Type | Dependent item |
| Key | `port.mac.list[{#SNMPINDEX}]` |
| Master item | `FDB port map` |
| Type of information | Text |
| History storage period | 1h (raw FDB changes constantly) |
| Trends | Do not keep |

Add this **JavaScript preprocessing step**:

```javascript
// Returns comma-separated list of MACs currently learned on this interface,
// based on dot1dTpFdbTable + dot1dBasePortIfIndex walk.
//
// Input `value` is the raw walk[] output:
//   .1.3.6.1.2.1.17.4.3.1.2.0.26.164.18.52.86 = INTEGER: 12
//   .1.3.6.1.2.1.17.1.4.1.2.12 = INTEGER: 1005

var snmpIndex = Number("{#SNMPINDEX}");

var FDB_PREFIX      = '.1.3.6.1.2.1.17.4.3.1.2.';
var BRIDGE_PREFIX   = '.1.3.6.1.2.1.17.1.4.1.2.';

var bridgeToIf = {};        // bridgePort -> ifIndex
var fdb = [];               // [{macOid, bridgePort}]

var lines = value.split(/\r?\n/);
for (var i = 0; i < lines.length; i++) {
    var line = lines[i].trim();
    if (!line) continue;

    // Split "OID = TYPE: VALUE" into oid and trailing value
    var eq = line.indexOf('=');
    if (eq < 0) continue;
    var oid = line.substring(0, eq).trim();
    var tail = line.substring(eq + 1).trim();

    // Drop the "INTEGER: " / "STRING: " / etc. prefix
    var colon = tail.indexOf(':');
    var v = colon >= 0 ? tail.substring(colon + 1).trim() : tail;

    if (oid.indexOf(BRIDGE_PREFIX) === 0) {
        var bp = oid.substring(BRIDGE_PREFIX.length);
        bridgeToIf[bp] = Number(v);
    } else if (oid.indexOf(FDB_PREFIX) === 0) {
        var macOid = oid.substring(FDB_PREFIX.length);
        fdb.push({ macOid: macOid, bridgePort: String(v) });
    }
}

var macs = [];
for (var j = 0; j < fdb.length; j++) {
    var ifIdx = bridgeToIf[fdb[j].bridgePort];
    if (ifIdx !== snmpIndex) continue;
    var parts = fdb[j].macOid.split('.').map(function(x) {
        return ('0' + Number(x).toString(16)).slice(-2);
    });
    if (parts.length === 6) {
        macs.push(parts.join(':'));
    }
}

return macs.join(',');
```

Once applied, every interface on the host gets an item whose last value is
either empty (no MACs learned) or a comma-separated list:
`aa:bb:cc:dd:ee:ff,11:22:33:44:55:66`.

### Alternative key formats

If your template uses a different key pattern, configure the widget's
**MAC-list item key prefix** field accordingly. The widget will also
automatically try these fallback keys:

- `port.mac.list[<ifIndex>]` (default)
- `port.mac.list[ifIndex.<ifIndex>]`
- `net.if.mac[ifIndex.<ifIndex>]`

## Configuration

| Field | Default | Purpose |
|---|---|---|
| **Override host** | — | Bind to Switch Port Status widget |
| **PacketFence API URL** | `https://packetfence.example.com:9999` | Root URL, no trailing slash. Default PF API port is **9999** |
| **Username** | `admin` | PF admin or webservices user |
| **Password** | — | Plaintext (see security note below) |
| **MAC-list item key prefix** | `port.mac.list[` | Prefix for constructing MAC-list item key. The widget appends `<snmpIndex>]` |
| **Verify TLS certificate** | unchecked | Enable for properly-signed PF certs |
| **Show debug info** | ✓ | Render a debug panel |

## Security Considerations

**The PacketFence password is stored in the Zabbix database in plaintext.**
Mitigations:

1. Use a **dedicated PF webservices user** with read-only scope for
   `nodes`, `nodes/search`, and `security_events` only — never the full
   `admin` account.
2. Restrict database access to the `widget_field` table.
3. Enable TLS certificate verification once your PF has a proper cert.

For production, a better solution would be storing credentials in a
module-level config file readable only by the web server user. Not
implemented yet.

## PacketFence API Calls

| Call | Purpose |
|---|---|
| `POST /api/v1/login` | Obtain auth token |
| `POST /api/v1/nodes/search` | Fetch node records for the MACs found on the port |
| `GET  /api/v1/security_events?query=…` | Active events per MAC |

The `nodes/search` payload requests these fields:

> `autoreg`, `bandwidth_balance`, `bypass_acls`, `bypass_role_id`,
> `bypass_vlan`, `category_id`, `computername`, `detect_date`, `device_class`,
> `device_manufacturer`, `device_score`, `device_type`, `device_version`,
> `dhcp6_enterprise`, `dhcp6_fingerprint`, `dhcp_fingerprint`, `dhcp_vendor`,
> `last_arp`, `last_dhcp`, `last_seen`, `mac`, `machine_account`, `notes`,
> `pid`, `regdate`, `sessionid`, `status`, `time_balance`, `unregdate`,
> `user_agent`, `voip`

## What the Card Shows

Per MAC:

- **MAC address** (monospaced, header)
- **Status pill** — Registered / Unregistered / Pending / Unknown
- **IP address** (from `last_arp` or `last_dhcp`)
- **Hostname** (`computername`)
- **Vendor** (`device_manufacturer`)
- **OS** (`device_type` or `device_class`)
- **Owner** (`pid`, omitted if `default`)
- **Bypass VLAN** (when assigned)
- **DHCP fingerprint** (monospaced)
- **Last seen**
- **User-Agent** (truncated if long)
- **Open security events** (listed in red if present)
- **View-in-PacketFence** action link

MACs found in the switch FDB but absent from PacketFence show a card with a
dashed border labeled "Not in PacketFence".

## Installation

1. Copy `packetfence/` to `/usr/share/zabbix/ui/modules/` and chown to the web server user.
2. In Zabbix UI: **Administration → General → Modules → Scan directory → Enable**.
3. Edit dashboard → **Add widget → Switch Port Device (PacketFence)**.
4. Configure: Override host → Widget → Switch Port Status, then fill in PF URL + credentials.

## Troubleshooting

- **"Awaiting port selection"** — no port has been clicked yet, or widget
  isn't bound to Switch Port Status via Override host.
- **"No MAC-list item found on host"** — the template preprocessing hasn't
  been applied yet. Check your host inherits the template and has a recent
  item with key `port.mac.list[<ifIndex>]`.
- **"No devices on this port"** — the port has no MACs in the FDB. Either
  nothing's connected, or FDB aging expired entries since the last poll.
- **MAC-list is stale** — see the header age indicator. The master FDB
  item polls every 2 minutes by default.
- **"Not in PacketFence" cards** — the MAC is on the switch but PF doesn't
  know about it. Happens for devices PF hasn't seen via RADIUS accounting
  or SNMP traps (e.g. purely wired with no 802.1X).
- **PF login fails** — enable debug, look at `step_6_auth.http` and `error`.
  HTTP 401 = bad credentials; 0 = network / TLS issue.
- **Device count mismatch** — the FDB shows MACs learned including voice+data
  on the same port. PF may return fewer results if some MACs aren't in its
  database.

## Compatibility

- **PacketFence 15.x** — uses the v1 API shape (`nodes/search` with cursor/query).
- **PacketFence 12–14** — should work, untested.
- **Zabbix 7.0+** — requires SNMP walk preprocessing (introduced in 6.4).
- **Any SNMPv2/v3 switch** that exposes standard BRIDGE-MIB
  (`dot1dTpFdbTable` + `dot1dBasePortIfIndex`). Works on Extreme, Cisco,
  HPE, Aruba, Juniper, and generic L2 switches.
