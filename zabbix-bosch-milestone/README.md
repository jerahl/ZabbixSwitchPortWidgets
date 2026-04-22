# Bosch Cameras via Milestone — Zabbix Monitoring Pack

Custom Zabbix widget, templates, and dashboard for monitoring Bosch security
cameras through a Milestone XProtect Professional+ Management Server, with a
secondary direct-to-camera ICMP/HTTP health check for early warning.

## Contents

```
widgets/camera_status/      Custom Zabbix 7.x frontend widget
templates/                  Two YAML templates (camera + Milestone server)
agent/                      PowerShell + UserParameter config for the Milestone server
dashboard/                  Dashboard export referencing the widget
```

## Architecture

```
  Bosch cameras  ←── ICMP/HTTP direct check ──→  Zabbix Server
       │                                              ↑
       ↓                                              │
  Milestone XProtect Pro+  ──── MIP SDK ──→  PowerShell UserParameter
  (Management + Recording)                   on Milestone host
                                                      │
                                                      └──→ Zabbix agent 2
```

Camera hosts in Zabbix are **auto-created** by LLD on the Milestone server
host. You do not manually add each camera.

## Install order

### 1. Milestone server preparation

1. Install the **MIP SDK** on the Milestone server (Management Client ships
   with a compatible SDK; the standalone MIP SDK gives you the full DLL set).
2. Create a dedicated Milestone user with read-only role, note the credentials.
3. Create `C:\ProgramData\zabbix\milestone.config.json`:
   ```json
   { "ServerUri": "http://localhost/", "Username": "svc_zabbix", "Password": "…" }
   ```
   ACL the file to `SYSTEM` + the Zabbix service account only.
4. Copy `agent/Get-MilestoneCameraStatus.ps1` to
   `C:\Program Files\Zabbix Agent 2\scripts\`.
5. Copy `agent/milestone.conf` to
   `C:\Program Files\Zabbix Agent 2\zabbix_agent2.d\plugins.d\`.
6. Edit `zabbix_agent2.conf` → set `Timeout=30` (PowerShell + MIP cold-start
   is slow).
7. Restart the Zabbix agent service.
8. Sanity check from the Zabbix server:
   ```
   zabbix_get -s milestone-mgmt -k milestone.storage.used_pct
   zabbix_get -s milestone-mgmt -k milestone.camera.discover
   ```

### 2. Import templates

In Zabbix UI → *Data collection → Templates → Import*:

1. `templates/template_bosch_camera.yaml` first
2. `templates/template_milestone_server.yaml` second (references the first
   via host prototype)

### 3. Add the Milestone server as a host

Create a Zabbix host for the Milestone Windows box, assign
`Template Milestone Recording Server`, agent interface on 10050. Within an
hour, LLD will discover all cameras and create `CAM-<guid>` hosts in the
`Cameras/Bosch` group automatically.

For each camera host, if you want the ICMP + HTTP direct checks to work, set
the host's **agent interface IP** to the camera's IP and set macro
`{$CAMERA.IP}` = the same IP (the simple check items need it textually).

### 4. Install the widget

Frontend widgets on Zabbix 7.x are installed as modules:

```
cp -r widgets/camera_status /usr/share/zabbix/modules/
chown -R www-data:www-data /usr/share/zabbix/modules/camera_status
```

Then in the UI: *Administration → General → Modules → Scan directory →
enable `Bosch/Milestone Camera Status`*.

### 5. Import the dashboard

*Data collection → Dashboards → Import* →
`dashboard/dashboard_cameras.yaml`.

## Widget configuration

When adding the widget to a dashboard:

| Field                   | Meaning                                          |
|-------------------------|--------------------------------------------------|
| Host groups             | Usually `Cameras/Bosch`                          |
| Layout                  | Grid = tiles, List = dense one-line rows         |
| Columns                 | Grid columns (2–12)                              |
| Show problems only      | Hide healthy cameras — good for a NOC view       |
| Retention warn threshold| Days below which the tile flips to warning       |
| Frame staleness threshold | Seconds since last frame before warning        |

Tiles are color-coded:
- Green left-border — healthy
- Yellow — one or more warnings (retention low, stale frame, storage >90%,
  online but not recording)
- Red — camera reported offline

Clicking a tile's name opens the camera's host in Zabbix.

## Notes and caveats

- **XProtect Pro+ SNMP is limited.** That's why this pack uses the MIP SDK
  via PowerShell rather than SNMP. If you upgrade to XProtect Corporate
  someday, you can replace the PowerShell items with SNMP traps/polls.
- **MIP SDK login is expensive.** Each invocation initializes the SDK and
  logs in. For a site with >50 cameras, consider rewriting the script as a
  long-running C# service that maintains a session and exposes metrics over
  localhost HTTP, with Zabbix hitting that. Ping me if you want that
  variant — it's a one-afternoon project.
- **Direct ONVIF polling** is *not* done by this pack on purpose. Bosch
  cameras under load can have ONVIF get flaky, and you'd duplicate what
  Milestone already knows. ICMP + HTTP(S) on the camera web UI is enough
  for the early-warning layer.
- **Adjust `{$CAMERA.FRAME_STALE_SEC}`** per camera if some are on motion-
  only recording — a motion-only camera that hasn't seen motion in 5
  minutes isn't actually broken.
