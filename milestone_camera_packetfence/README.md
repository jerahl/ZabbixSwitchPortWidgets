# Camera Device (PacketFence) Widget

A dashboard widget that shows what PacketFence knows about a Milestone camera
selected from the **Milestone camera status** widget.

Click a camera row in the camera-status table → this widget renders a
PacketFence node card for that camera, enriched with vendor, OS fingerprint,
registration status, bypass VLAN/ACLs, user-agent, DHCP fingerprint, and any
open security events.

This is a sibling of the original *Switch Port Device (PacketFence)* widget;
the lookup logic and card rendering are nearly identical, but the trigger is
a camera click instead of a switch-port click.

## How It Works

1. **User clicks a camera** in the Milestone camera status widget. The
   camera widget broadcasts a `mcs:cameraSelected` DOM event with
   `{hostid, mac, ip, name, host}`.
2. **This widget listens** for that event and adds `cam_mac` / `cam_ip` /
   `cam_name` / `cam_host` to its update request.
3. **The PHP controller** authenticates to PacketFence and runs a
   `POST /api/v1/nodes/search` filtered by MAC (or IP if MAC isn't
   available).
4. **Open security events** for the matching node are fetched.
5. **A single device card** is rendered, identical in style to the
   switch-port version.

The widget remembers the last selection in `sessionStorage`, so a dashboard
reload restores the most recent camera.

## Configuration fields

| Field | Default | Purpose |
|---|---|---|
| **PacketFence API URL** | `https://packetfence.example.com:9999` | Root URL, no trailing slash. Default PF API port is **9999** |
| **PacketFence admin UI URL** | `https://packetfence.example.com:1443` | Used for the *View in PacketFence* link |
| **Username** | `admin` | PF admin or webservices user |
| **Password** | — | Plaintext (see security note below) |
| **Verify TLS certificate** | unchecked | Enable for properly-signed PF certs |
| **Show debug info** | unchecked | Render a debug panel |

There is **no** override-host selector, **no** MAC-list item key, and **no**
DHCP fallback — the camera widget already provides the MAC and IP directly,
so the lookup needs nothing else.

## Pairing with the camera widget

The Milestone camera status widget must be the patched version that:

- Shows MAC and IP columns in the fault table (driven by
  `milestone.cam.mac[*]` and `milestone.cam.address[*]` items).
- Dispatches the `mcs:cameraSelected` event with `{hostid, mac, ip, name,
  host}` when an operator clicks a camera row.

Both widgets can live on the same dashboard. There's no override-host wiring
to set up — discovery happens entirely via the JS event.

## Security Considerations

The PacketFence password is stored in the Zabbix database in plaintext, same
as the original PacketFence widget. Mitigations are the same:

1. Use a **dedicated PF webservices user** with read-only scope for
   `nodes`, `nodes/search`, and `security_events` only — never the full
   `admin` account.
2. Restrict database access to the `widget_field` table.
3. Enable TLS certificate verification once your PF has a proper cert.

## PacketFence API Calls

| Call | Purpose |
|---|---|
| `POST /api/v1/login` | Obtain auth token |
| `POST /api/v1/nodes/search` | Fetch node record for the camera's MAC (fallback: IP) |
| `POST /api/v1/security_events/search` | Active events for that MAC |

## What the Card Shows

- **MAC address** (monospaced, header)
- **Status pill** — Registered / Unregistered / Pending / Unknown
- **IP** with badge: `PF` if from `ip4log.ip`, `CAM` if from the camera item
- **Hostname** (`computername`)
- **Vendor** (`device_manufacturer`)
- **OS** (`device_type` or `device_class`)
- **Owner** (`pid`, omitted if `default`)
- **Bypass VLAN** (when assigned)
- **DHCP fingerprint** (monospaced)
- **Last seen / Last ARP / Last DHCP**
- **User-Agent** (truncated if long)
- **Open security events** (listed in red if present)
- **View-in-PacketFence** action link

If PacketFence has no record for the camera's MAC, the widget shows a card
with a dashed border labeled "Not in PacketFence" — the IP from the camera
item is still displayed, in case it's useful for further investigation.

## Installation

1. Copy `milestone_camera_packetfence/` to `/usr/share/zabbix/ui/modules/`
   (path may vary by distro), and chown to the web server user.
2. In Zabbix UI: **Users → Modules → Scan directory → Enable**.
3. Edit dashboard → **Add widget → Camera Device (PacketFence)**.
4. Configure: PF API URL, admin URL, username, password.
5. Make sure the **Milestone camera status** widget is also on the same
   dashboard (and patched to emit `mcs:cameraSelected`).

## Troubleshooting

- **"Awaiting camera selection"** — no camera has been clicked yet, or the
  Milestone camera widget on this dashboard is the older version that
  doesn't dispatch `mcs:cameraSelected`.
- **"Not in PacketFence" card** — the camera's MAC is in Zabbix but PF
  doesn't know about it. Common for non-802.1X wired devices that PF
  hasn't observed yet.
- **PF login fails** — enable debug, check `step_3_auth.http` and `error`.
  HTTP 401 = bad credentials; 0 = network / TLS issue.

## Compatibility

- **Zabbix 7.0+** (manifest version 2.0 widgets).
- **PacketFence 15.x** — uses the v1 API shape (`nodes/search` with
  cursor/query). 12–14 should also work, untested.
