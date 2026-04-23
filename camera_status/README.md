# Bosch Cameras via Milestone — Zabbix Monitoring Pack

Custom Zabbix widget, templates, and dashboard for monitoring Bosch security
cameras through a Milestone XProtect Management Server.

**No external scripts or agents required on the Milestone host.** Both
templates use native Zabbix SCRIPT items (JavaScript running on the Zabbix
server/proxy) and HTTP_AGENT items to talk directly to the Milestone MIP VMS
REST API and the Bosch camera web API.

## Contents

```
widgets/camera_status/      Custom Zabbix 7.x frontend widget
templates/                  Two YAML templates (Milestone server + camera)
dashboard/                  Dashboard export referencing the widget
```

## Architecture

```
Zabbix Server / Proxy
  │
  ├── SCRIPT item (JS) ────── OAuth2 token ──► Milestone API Gateway
  │   milestone.info                            /API/IDP/connect/token
  │                           REST GET ───────► /api/rest/v1/cameras
  │                                             /api/rest/v1/recordingServers
  │                                             /api/rest/v1/recordingServers/{id}/storages
  │
  └── Per-camera host (auto-created by LLD)
        │
        ├── SCRIPT item (JS) ─── per-camera token ──► /api/rest/v1/cameras/{guid}
        │   milestone.cam.info                         /api/rest/v1/cameras/{guid}/currentStatus
        │                                              /api/rest/v1/cameras/{guid}/recordedSegments
        │
        ├── HTTP_AGENT ────────────────────────────► Bosch /api/1.0/status/general  (JSON REST)
        │   bosch.api.health
        │
        ├── HTTP_AGENT (headers only) ─────────────► Bosch /rcp.xml?command=0x0a04 (legacy RCP+)
        │   bosch.rcp.alive
        │
        └── SIMPLE ─────────────────────────────────► ICMP ping
            icmpping
```

### What changed from the PowerShell/MIP SDK approach

| Before | After |
|---|---|
| PowerShell + MIP SDK DLLs on the Milestone server | No software installed on Milestone host |
| Zabbix agent UserParameters on Milestone server | Zabbix server/proxy calls REST directly |
| EXTERNAL check placeholder script | Native SCRIPT items (JavaScript in Zabbix) |
| SIMPLE `web.page.get` RCP+ probe | HTTP_AGENT items with auth, JSONPath preprocessing |

## Requirements

- **Zabbix 7.0+** (SCRIPT item type with JavaScript runtime, HTTP_AGENT `query_fields`)
- **XProtect 2023 R2+** — REST API under `/api/rest/v1/` with per-camera status endpoint.  
  XProtect 2021 R2–2023 R1 has partial REST support; `currentStatus` and `recordedSegments`
  may not be available. Use the SOAP branch for older installs.
- **API Gateway** co-installed with the Management Server (standard for all current XProtect editions)
- A Milestone user with these role permissions:
  - *Info → Allow Web Client Login*
  - *Overall Security → Management Server → Connect*
  - *Overall Security → Management Server → Status API*
  - *Overall Security → Cameras → Read*

## Install order

### 1. Import templates

In Zabbix UI → *Data collection → Templates → Import*:

1. `templates/template_bosch_camera.yaml` first (camera child template)
2. `templates/template_milestone_server.yaml` second (references the first via host prototype)

### 2. Add the Milestone server host

Create a Zabbix host for the Milestone Management Server:

- **Agent interface** — point to the Management Server IP/DNS (used as `{HOST.CONN}` for API calls)
- **Template** — `Template Milestone Recording Server`
- **Macros** (set on the host, not the template):

| Macro | Value |
|---|---|
| `{$MILESTONE.USER}` | XProtect local user (Basic auth) or Windows domain user |
| `{$MILESTONE.PASSWORD}` | Password (mark as Secret Text) |
| `{$MILESTONE.CONN}` | `https` (or `http` if your API Gateway is not TLS) |

No Zabbix agent needs to be installed on the Milestone server.

Within one LLD cycle (`{$MILESTONE.LLD_FREQ}`, default 50 min) Zabbix will
discover every enabled camera and auto-create hosts in the `Cameras/Bosch` group.

### 3. Configure per-camera hosts

Each discovered camera host inherits `{$MILESTONE.USER}` / `{$MILESTONE.PASSWORD}` /
`{$MILESTONE.CONN}` from the parent Management Server host via prototype macros.
You only need to set additional macros if they differ per camera:

| Macro | Default | When to override |
|---|---|---|
| `{$BOSCH.USER}` | `service` | Camera uses a different account |
| `{$BOSCH.PASSWORD}` | _(empty)_ | Always set this per-camera or at group level |
| `{$BOSCH.CONN}` | `http` | Set to `https` for TLS-enabled cameras |
| `{$BOSCH.PORT}` | `80` | Change if camera listens on a non-default port |
| `{$FRAME.STALE.SEC}` | `120` | Increase for motion-only cameras |
| `{$RETENTION.WARN_DAYS}` | `14` | Adjust to your retention policy |
| `{$RETENTION.CRIT_DAYS}` | `7` | Adjust to your retention policy |

For direct Bosch checks (ICMP, RCP+, `/api/1.0/`), the host's **agent interface
IP must point to the camera's IP address**.

### 4. Install the dashboard widget

```bash
cp -r widgets/camera_status /usr/share/zabbix/modules/
chown -R www-data:www-data /usr/share/zabbix/modules/camera_status
```

In Zabbix UI: *Administration → General → Modules → Scan directory →*
enable `Bosch/Milestone Camera Status`.

### 5. Import the dashboard

*Monitoring → Dashboards → Import* → `dashboard/dashboard_cameras.yaml`.

## Widget configuration

| Field | Meaning |
|---|---|
| Host groups | Usually `Cameras/Bosch` |
| Layout | Grid = tiles, List = dense rows |
| Columns | Grid columns (2–12) |
| Show problems only | Hide healthy cameras — good for a NOC view |
| Retention warn threshold | Days below which the tile turns yellow |
| Frame staleness threshold | Seconds since last frame before warning |

Tiles are colour-coded:
- **Green** border — all checks healthy
- **Yellow** — at least one warning (retention low, stale frame, not recording)
- **Red** — camera reported offline by Milestone

## Item keys produced by the camera template

These are the keys the dashboard widget reads from each camera host:

| Key | Type | Description |
|---|---|---|
| `camera.online` | Unsigned | 1 = online (Milestone), 0 = offline, -1 = unknown |
| `camera.recording` | Unsigned | 1 = recording, 0 = not recording, -1 = unknown |
| `camera.last_frame_ts` | Unsigned | Unix timestamp of newest recorded segment end |
| `camera.retention_days` | Float | Days of recordings available |
| `camera.storage_used_pct` | Float | Placeholder (0); real storage % is on the Milestone host |
| `camera.stream_name` | Char | Camera display name from Milestone |
| `camera.milestone_guid` | Char | Camera GUID in XProtect |
| `bosch.rcp.alive` | Unsigned | 1 = HTTP 200 from RCP+ endpoint, 0 = fail |
| `bosch.api.health` | Text | Raw JSON from Bosch `/api/1.0/status/general` |
| `bosch.api.firmware` | Char | Firmware version extracted from Bosch API |
| `icmpping` | Unsigned | 1 = reachable, 0 = ICMP timeout |

## Notes

- **Token lifetime** — The Milestone IDP token defaults to 3600 s. The
  `{$MILESTONE.LLD_FREQ}` macro defaults to 50 min so discovery always
  re-authenticates inside the token window. Per-camera SCRIPT items each
  acquire their own token on every 2-minute cycle; token cost is negligible
  against the REST calls.
- **Bosch `/api/1.0/` availability** — Only cameras running firmware with the
  RESTful configuration API expose this path. Older FLEXIDOME and AUTODOME
  cameras may return 404; `bosch.rcp.alive` covers them via legacy RCP+.
- **Motion-only cameras** — Raise `{$FRAME.STALE.SEC}` per camera if they
  record on motion only. A camera that hasn't triggered motion recently is
  not broken.
- **XProtect editions without full REST** — If `currentStatus` returns 404,
  the per-camera SCRIPT item falls back to `-1` (unknown) for online/recording
  rather than raising an error. The camera host will still appear in the widget
  with unknown state.
