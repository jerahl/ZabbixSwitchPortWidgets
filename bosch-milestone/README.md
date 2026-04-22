# Bosch Cameras via Milestone XProtect — Zabbix Monitoring Pack

Hybrid monitoring for Bosch IP cameras through a Milestone XProtect
Professional+ Management Server, with independent ICMP/HTTP sanity probes
direct to each camera.

## What's in this pack

```
helper-script/
  milestone_zbx.py          # External-check bridge to Milestone SOAP API
  milestone.ini.example     # Service account config
  README.md                 # Helper install notes
template/
  template_bosch_milestone.yaml   # Zabbix 7.x template (import)
widget/
  camera_status/            # Custom dashboard widget (module)
dashboard/
  dashboard_surveillance.yaml     # Prebuilt dashboard (import)
```

## Architecture

```
┌──────────────────┐          ┌──────────────────────────┐
│  Zabbix server   │          │ Milestone Mgmt Server    │
│                  │  SOAP    │ (XProtect Professional+) │
│  external check ─┼─────────►│ ServerCommandService.svc │
│  milestone_zbx.py│          │ ConfigurationApiService  │
│                  │          └──────────────────────────┘
│        │         │
│        │ ICMP    ┌──────────────────────────┐
│        └────────►│  Bosch camera (direct)   │
│        │ HTTP    │  /rcp.xml?command=0x0a04 │
│        └────────►│                          │
│                  └──────────────────────────┘
└──────────────────┘
```

Milestone is the primary source for "is this camera online / recording /
retained?". Direct ICMP and a Bosch RCP+ HTTP probe run in parallel so we
can distinguish:

- **Healthy**: online + recording
- **Online, not recording**: camera responds but Milestone isn't writing
  it to disk — licensing, retention rule, archive failure
- **Milestone says offline, ICMP alive**: Recording Server has lost the
  feed but the camera itself is fine — RS issue
- **Hard down**: Milestone offline AND ICMP failing — genuine camera/network
  failure

The camera grid widget color-codes each of these so the split is obvious
at a glance.

## Install order

### 1. Helper script

See `helper-script/README.md`. Summary:

```bash
sudo install -o zabbix -g zabbix -m 0750 helper-script/milestone_zbx.py \
     /usr/lib/zabbix/externalscripts/milestone_zbx.py
sudo install -o zabbix -g zabbix -m 0600 helper-script/milestone.ini.example \
     /etc/zabbix/milestone.ini
sudo pip3 install requests requests-ntlm zeep
# Edit /etc/zabbix/milestone.ini with your Management Server details
```

Smoke test:

```bash
sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_zbx.py discover recording_servers
```

### 2. Template

Zabbix UI → Data collection → Templates → Import → pick
`template/template_bosch_milestone.yaml`.

Then link the **master** template (`Bosch Cameras via Milestone XProtect`)
to a single "seed" host — this is where the LLD rules run. A dummy host
with just an agent interface works; the template doesn't use the interface
for its discovery items.

Within ~1 hour (or force-run the discovery rule) you should see host
prototypes materialize as real hosts:

- `RS <name>` hosts for each Recording Server
- `CAM <name>` hosts for each enabled camera

### 3. Widget

The widget is a Zabbix 7 module. Copy the folder into the frontend
modules path:

```bash
# On the Zabbix frontend host
sudo cp -r widget/camera_status /usr/share/zabbix/modules/
sudo chown -R www-data:www-data /usr/share/zabbix/modules/camera_status
```

Then in the UI: **Administration → General → Modules → Scan directory**,
enable **Camera Status Grid**, click **Update**.

### 4. Dashboard

**Dashboards → Create / import dashboard**, upload
`dashboard/dashboard_surveillance.yaml`.

The dashboard references the `Discovered/Bosch Cameras` and
`Discovered/Milestone Recording Servers` host groups, which the template
creates automatically via its group prototypes the first time hosts
materialize from LLD. If the dashboard import runs before any cameras
have been discovered, the widgets will simply show "no data" until
discovery completes.

## Tuning

Template-level macros (override per host as needed):

| Macro                        | Default | What it does                          |
|------------------------------|---------|----------------------------------------|
| `{$MILESTONE.HELPER}`        | `milestone_zbx.py` | External script name       |
| `{$RETENTION.WARN.DAYS}`     | `14`    | Warn if retention drops below         |
| `{$RETENTION.HIGH.DAYS}`     | `7`     | High-sev if retention drops below     |
| `{$STORAGE.FREE.WARN.PCT}`   | `15`    | Warn if RS storage free % drops below |
| `{$STORAGE.FREE.HIGH.PCT}`   | `5`     | High-sev if RS storage free % drops   |
| `{$CAMERA.HTTP.TIMEOUT}`     | `5`     | Bosch HTTP probe timeout (seconds)    |

## Scaling notes

The external-check pattern is straightforward but forks a Python process
per item collection. Practical ceiling is ~500 cameras with 2-minute
state intervals on a reasonably sized Zabbix server. Beyond that, convert
the helper to a long-lived trapper process that pushes via
`zabbix_sender`; the script's `Milestone` class is already structured to
make that port trivial.

## Known limitations

- **XProtect Professional+** SOAP endpoints differ slightly between 2022
  R3, 2023 Rx, and 2024. The script uses only the stable subset
  (`Login`, `GetChildItems`, `GetItemState`, `GetProperty`,
  `GetProperties`). If a new release renames one of these you'll see
  `ZBX_NOTSUPPORTED` errors in `/var/log/zabbix/milestone_zbx.log` —
  report the WSDL changes and the client wrapper is the only thing that
  needs touching.
- **Bosch RCP+ probe** returns the raw HTTP status code. If your cameras
  require Basic/Digest auth on `/rcp.xml`, create a username/password on
  the camera with the `user` level and add it to a macro, then switch
  the item key from `web.page.get` to `vfs.file.contents[...]` with a
  curl wrapper — ask and I'll add that variant.
- **Host interface for camera LLD**: the discovered camera hosts are
  created without an IP interface. The ICMP/HTTP items reference
  `{HOST.CONN}`, so for those to work you either need to add an
  interface after discovery (via a Zabbix API script), or extend
  `milestone_zbx.py` to pull the camera IP out of Milestone and emit it
  as an `{#CAM.IP}` LLD macro with an interface prototype in the
  template. The latter is the cleaner answer once you verify the IP is
  actually populated in your Milestone config; some shops leave it
  blank and use DNS.
