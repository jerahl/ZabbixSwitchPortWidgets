# XIQ AP Status Widget

Summary tiles + sortable fault table for ExtremeCloud IQ access points,
sourced from the **Extreme XIQ APs by API** template.

Mirrors the Milestone Camera Status widget in shape and interaction. Click a
fault row to broadcast the AP's MAC/IP for a companion PacketFence widget.

## Items consumed

| Key prefix | Required | Purpose |
|---|---|---|
| `xiq.ap.connected[<serial>]` | yes | 0/1 connection state — drives the primary status |
| `xiq.ap.configmismatch[<serial>]` | recommended | 0/1 — adds the *Mismatch* tile/severity |
| `xiq.ap.ip[<serial>]` | recommended | IPv4 used in the table and broadcast on click |
| `xiq.ap.mac[<serial>]` | optional | MAC used in the table and broadcast on click |

The MAC item isn't in the shipping XIQ template — only the LLD `{#MAC}` macro
is exposed. To populate the MAC column add this item prototype to the LLD
rule (alongside `xiq.ap.ip[*]`):

```yaml
- name: 'AP {#HOSTNAME}: MAC'
  type: DEPENDENT
  key: 'xiq.ap.mac[{#SERIAL}]'
  delay: '0'
  history: '30d'
  value_type: CHAR
  master_item:
    key: xiq.devices.raw
  preprocessing:
    - type: JSONPATH
      parameters:
        - "$[?(@.serial=='{#SERIAL}')].mac.first()"
      error_handler: DISCARD_VALUE
    - type: DISCARD_UNCHANGED_HEARTBEAT
      parameters:
        - 6h
```

Without it the widget still works; the MAC column just renders `—` and the
PacketFence companion has to fall back to IP-only matching.

## Status synthesis

| connected | config_mismatch | Bucket |
|---|---|---|
| null | — | No data (not displayed) |
| 0 | * | **Offline** (red) |
| 1 | 1 | **Mismatch** (amber) |
| 1 | 0 | OK (not displayed) |

## Selection event

On row click the widget dispatches a `pf:deviceSelected` DOM event with:

```js
{ source: 'xiq_ap', hostid, mac, ip, name, host, serial }
```

The legacy `mcs:cameraSelected` event is also dispatched and the selection is
mirrored into both `pf_device_selection` and `mcs_camera_selection`
sessionStorage keys, so the existing camera-PacketFence widget keeps working
until the unified PacketFence widget replaces it.

## Configuration

| Field | Default | Purpose |
|---|---|---|
| **Hosts** | — (required) | Hosts running the XIQ template (usually one host per XIQ tenant) |
| **Max table rows** | 100 | Cap on rows; truncation note appears below the table |

## Installation

1. Copy `xiq_ap_status/` to `/usr/share/zabbix/ui/modules/` and chown to the
   web server user.
2. Zabbix UI → **Users → Modules → Scan directory → Enable**.
3. Edit dashboard → **Add widget → XIQ AP status** → pick the host running
   the XIQ template.
