# Zabbix 7.4 monitoring for Bosch IP cameras via Milestone XProtect

Two templates plus a consolidated dashboard, ported from the excellent
[`lestoilfante/zabbix-integrations` MilestoneSys 6.0 template](https://github.com/lestoilfante/zabbix-integrations/tree/master/MilestoneSys)
and extended with:

* Zabbix 7.4 schema (`version: '7.4'`, new `vendor` field, `dashboards:`
  block at template level, `SCRIPT` item type).
* Low-level discovery (LLD) of **cameras** in addition to recording servers.
* A template dashboard on each host and a **system-wide dashboard** that
  summarises every Milestone site and every Bosch camera.
* A second template — **Bosch IP camera by HTTP** — that talks directly
  to cameras via RCP+ over CGI, so you see what the camera itself reports
  (uptime, encoder load, firmware) alongside what XProtect says about it.

## Files

| File | What it is |
| --- | --- |
| `template_milestone_bosch_74.yaml` | Both templates plus their per-host template dashboards. Import this first. |
| `dashboard_video_overview.yaml` | Standalone, system-wide dashboard. Import after templates, after you have at least one Milestone host. |
| `milestone_ess_state.py` | Optional: WebSocket helper that fetches per-camera ESS state. Called by the refresh wrapper, not by Zabbix directly. |
| `milestone_ess_refresh.sh` | Optional: cron wrapper around the Python helper; writes JSON to disk atomically. |
| `milestone_ess_read.sh` | Optional: tiny reader that Zabbix invokes. Reads the cached JSON file. |

Both are importable through **Data collection → Templates → Import** and
**Dashboards → Import** in the Zabbix 7.4 frontend, or via the
`configuration.import` API method.

## Architecture

```
┌────────────────────┐    OAuth2 + REST     ┌─────────────────────┐
│ Zabbix server/     │ ───────────────────▶ │ Milestone API       │
│ proxy (Script      │ ◀─────────────────── │ Gateway + IDP       │
│  items in JS)      │     JSON responses   │ (/api/rest/v1, /IDP)│
└────────────────────┘                      └─────────────────────┘
         │                                           │
         │  HTTPS + Digest auth                      │ pass-through to
         │                                           │ recording servers
         ▼                                           ▼
┌────────────────────┐                      ┌─────────────────────┐
│ Bosch IP camera    │                      │ XProtect Recording  │
│ /rcp.xml (RCP+)    │                      │ Servers & cameras   │
└────────────────────┘                      └─────────────────────┘
```

The **Milestone template** uses Zabbix 7.x **Script items** rather than
plain HTTP-agent items. This is important and non-obvious: HTTP-agent
items cannot reference another item's value in their headers, so the
classic "fetch a token, then inject it into every other request"
pattern does not work. Instead, each Script item runs a tiny piece of
JavaScript inside Zabbix that does IDP login + API call in one go,
returns the JSON blob, and lets dependent items parse it with JSONPath.
Every dependent item runs instantly (no HTTP) when the master fires.

**Scale-out pattern.** The template only has **four** Script items
regardless of how many recording servers or cameras exist:

| Script item | Delay | Scales with |
| --- | --- | --- |
| `milestone.sites.get` | 5 min | fixed (1 site) |
| `milestone.license.get` | 1 hour | fixed |
| `milestone.rs.getall` | 2 min | independent of RS count |
| `milestone.cam.getall` | 5 min | independent of camera count |

The two `getall` items each return the full collection in a single
API call (cameras are fetched with `?page=0&size=10000` to bypass
default pagination) and preprocess the response into a JSON object
keyed by entity id. The LLD rules for recording servers and cameras
are `DEPENDENT` discoveries on those masters. Per-entity items —
`milestone.rs.raw[{#RS.ID}]` and `milestone.cam.raw[{#CAM.ID}]` —
are dependent items that JSONPath-extract their own record from
the batch response. Individual field items are dependent on the
per-entity master.

This means 25 recording servers × 6 items = 150 dependents and
2500 cameras × 5 items = 12,500 dependents all ride on two
Script polls per cycle. The Management Server sees two REST
calls every few minutes rather than thousands. This is the only
shape that works at your scale — the original per-entity Script
pattern saturates the Management Server IIS worker above a few
hundred cameras.

The **Bosch template** uses plain HTTP-agent items because RCP+ over
CGI is stateless Digest auth — no token flow, so the simpler item type
works directly.

## Milestone template (`Milestone XProtect by HTTP`)

### What it monitors

**Management-server level (static):**
* Site name, Management Server version, physical memory
* Last status handshake + derived "seconds since last handshake"
* API Gateway TCP reachability on 443
* License overview JSON (cached 1h)

**Recording Server LLD (`{#RS.ID}`, `{#RS.NAME}`):**
* Last handshake timestamp + age in seconds
* Version
* Enabled flag
* Hostname
* Trigger: handshake stale > 5 minutes (HIGH)
* Trigger: recording server disabled (WARNING)
* Trigger: no API data for 15 minutes (AVERAGE)

**Camera LLD (`{#CAM.ID}`, `{#CAM.NAME}`):**
* Raw detail JSON
* Enabled flag, last-modified timestamp, channel, parent recording server id
* Trigger: camera disabled in XProtect (WARNING)
* Trigger: camera no data for 15m (AVERAGE)
* Regex filters: `{$MILESTONE.CAM.NAME.MATCHES}` / `{$MILESTONE.CAM.NAME.NOT_MATCHES}`
  to include/exclude cameras by display name.

**ESS live state (daily snapshot, `{#CAM.ID}`):**
* Raw state record per camera (raw ESS state objects + keyed lookup by state-group GUID)
* Communication-state type GUID (current)
* Recording-state type GUID (current)
* Communication-state last-change timestamp
* Trigger: communication not OK per last snapshot (HIGH) — **dormant until macros set**
* Trigger: not recording per last snapshot (AVERAGE) — **dormant until macros set**

The ESS (Event & States Service) provides per-camera live state that is
not available through the standard REST API — whether a camera is
currently communicating with its recording server, whether it's
actively recording, and so on. Milestone exposes this only via the
Events and State WebSocket API at `wss://<api-gateway>/api/ws/events/v1`,
which Zabbix's Script item runtime (Duktape) cannot speak directly.
A small Python helper, `milestone_ess_state.py`, wraps the WebSocket
call and is invoked as an external check. The snapshot runs once per
day by default — it's intended as a daily audit rather than a real-time
alerting channel.

### ESS state groups and the one-time GUID mapping step

Milestone's ESS returns current state as a list of *stateful events*,
each tagged with a `stategroupid` (GUID of the state group — e.g.
communication, recording, motion) and a `type` (GUID of the specific
state within that group — e.g. "Communication OK", "Communication Lost").
**Both GUIDs are install-specific**: they're stable within one XProtect
installation, but they differ between installations, and Milestone does
not publish a canonical list. So the template cannot hardcode them —
you need to discover the four GUIDs for your install once, then set
macros on the host.

The helper has a `--list-stategroups` mode that prints the unique
`(stategroupid, type)` pairs it saw in one snapshot, along with how
many cameras reported each pair. Run it once, correlate with which
cameras you know are up/down/recording/idle, and fill in the macros.

```bash
sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_ess_state.py \
    milestone.example.com zbx_monitor 'password' \
    --scheme https --list-stategroups | python3 -m json.tool | head -80
```

Output will look like:

```json
{
  "total_states": 5000,
  "unique_pairs": 6,
  "pairs": [
    {"stategroupid": "aaaa...", "type": "bbbb...", "cameras": 2480},
    {"stategroupid": "aaaa...", "type": "cccc...", "cameras": 20},
    {"stategroupid": "dddd...", "type": "eeee...", "cameras": 2470},
    {"stategroupid": "dddd...", "type": "ffff...", "cameras": 30},
    ...
  ]
}
```

In this example, `aaaa...` is one state group (call it communication
for now), with the vast majority of cameras reporting type `bbbb...`
(likely "OK") and a small number reporting `cccc...` (likely "Lost").
Confirm by looking at specific cameras you know are offline — their
GUID should be in the 20-camera group. Then set on the host:

| Macro | Value |
|---|---|
| `{$MILESTONE.ESS.STATEGROUP.COMMUNICATION}` | `aaaa...` |
| `{$MILESTONE.ESS.TYPE.COMMUNICATION_OK}` | `bbbb...` |
| `{$MILESTONE.ESS.STATEGROUP.RECORDING}` | `dddd...` |
| `{$MILESTONE.ESS.TYPE.RECORDING_STARTED}` | `eeee...` |

Until the GUID macros are set, the ESS-derived items will be empty
and the ESS triggers will stay quiet (they include a `length(...) > 0`
guard on the state value, so an empty-on-both-sides comparison can't
fire). There's no false-alarm risk from importing the template before
you've done the mapping.

### Installing the ESS helper (split architecture)

Zabbix caps all external-check timeouts at the server-wide `Timeout=`
setting, which itself is capped at 30 seconds. A full `getState` call
at 2500+ cameras takes 1–2 minutes, so we can't invoke the WebSocket
fetch synchronously from Zabbix. Instead we split the work:

| File | Role | Who runs it |
|---|---|---|
| `milestone_ess_state.py` | Does the WebSocket fetch (slow, 1-2 min) | Called by the refresh wrapper |
| `milestone_ess_refresh.sh` | Cron wrapper: fetches, atomically writes JSON to disk, locks to prevent overlaps | **cron or systemd timer** |
| `milestone_ess_read.sh` | Reads the cached JSON file (fast, sub-second) | **Zabbix external check** |

The three files all live in `/usr/lib/zabbix/externalscripts/`. The
Zabbix item only calls the reader, so it completes well under the
30-second cap. The actual cadence of snapshots is controlled by your
cron schedule — `{$MILESTONE.ESS.DELAY}` only governs how often Zabbix
re-reads the cached file (every 5–15 min is fine, it's cheap).

#### 1. Install the three files

```bash
cd /usr/lib/zabbix/externalscripts/
sudo install -o zabbix -g zabbix -m 0750 milestone_ess_state.py .
sudo install -o zabbix -g zabbix -m 0750 milestone_ess_refresh.sh .
sudo install -o zabbix -g zabbix -m 0750 milestone_ess_read.sh .
```

Also ensure the writable directories exist and are owned by zabbix:

```bash
sudo install -d -o zabbix -g zabbix /var/lib/zabbix
sudo install -d -o zabbix -g zabbix /var/log/zabbix
```

#### 2. Install Python deps

```bash
sudo pip3 install 'websockets>=11' 'aiohttp>=3.8'
```

#### 3. Smoke-test the fetch

Run the helper directly first (CLI path, no Zabbix involved):

```bash
sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_ess_state.py \
    milestone.example.com zbx_monitor 'password' --scheme https
```

Expect JSON starting with `{"count":NNNN,"cameras":{...}}`. If this
fails you'll see a JSON error on stderr — fix that before moving on.

#### 4. Smoke-test the refresh wrapper

```bash
sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_ess_refresh.sh \
    milestone.example.com zbx_monitor 'password' --scheme https
# Check output
sudo ls -la /var/lib/zabbix/milestone_ess_state.json
sudo tail /var/log/zabbix/milestone_ess_state.log
```

The wrapper writes the JSON atomically (via `.tmp` + `mv`) so the
reader never sees a half-written file. It also locks on
`/var/lib/zabbix/milestone_ess_state.lock` so overlapping runs can't
stack up.

#### 5. Install a cron entry

Pick a time when the Milestone server has spare CPU (not during peak
recording hours). Once a day at 3:15 AM is a reasonable default:

```cron
# /etc/cron.d/milestone_ess_refresh
15 3 * * * zabbix /usr/lib/zabbix/externalscripts/milestone_ess_refresh.sh milestone.example.com zbx_monitor 'password' --scheme https
```

Or, for a systemd timer variant (cleaner logging, no plaintext
password in a crontab):

```ini
# /etc/systemd/system/milestone_ess_refresh.service
[Unit]
Description=Milestone ESS snapshot refresh
After=network-online.target

[Service]
Type=oneshot
User=zabbix
# Load creds from a root-owned mode-600 env file
EnvironmentFile=/etc/zabbix/milestone_ess.env
ExecStart=/usr/lib/zabbix/externalscripts/milestone_ess_refresh.sh \
    ${MILESTONE_HOST} ${MILESTONE_USER} ${MILESTONE_PASS} --scheme https
```

```ini
# /etc/systemd/system/milestone_ess_refresh.timer
[Unit]
Description=Daily Milestone ESS snapshot refresh

[Timer]
OnCalendar=*-*-* 03:15:00
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl enable --now milestone_ess_refresh.timer
```

#### 6. Smoke-test the reader

```bash
sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_ess_read.sh
```

Should return the same JSON as step 3, but in milliseconds.

#### 7. Import the template

The Zabbix item calls `milestone_ess_read.sh[]`. With the reader being
local-disk-only, the 10-second item timeout is plenty. The normal
server `Timeout=` in `zabbix_server.conf` does not need to change.

#### Staleness detection

If the cron stops running or starts failing, the reader returns a
JSON error object (`{"error":"stale",...}` or `{"error":"no_snapshot",...}`).
A top-level trigger matches on these and fires as a WARNING so the
operator notices. Check the log at
`/var/log/zabbix/milestone_ess_state.log` for failure details.

Nothing about ESS is required — if you don't install the helper, the
ESS master item will report the "no snapshot" error, which is fine.
The rest of the template keeps working. You can delete the ESS master
item + prototypes entirely without breaking anything else.

The staleness tolerance defaults to 48 hours (2× the daily refresh
cadence). Override by passing an argument to the reader key, e.g.
`milestone_ess_read.sh[86400]` for 24 hours.

### Required host macros

| Macro | Default | Purpose |
|---|---|---|
| `{$MILESTONE.HOST}` | `127.0.0.1` | API Gateway FQDN or IP |
| `{$MILESTONE.SCHEME}` | `https` | http or https |
| `{$MILESTONE.USER}` | *(empty)* | XProtect Basic user |
| `{$MILESTONE.PASSWORD}` | *(empty, `Secret text`)* | Password |
| `{$MILESTONE.CLIENT_ID}` | `GrantValidatorClient` | Built-in IDP client |
| `{$MILESTONE.CAM.NAME.MATCHES}` | `.*` | Camera include regex |
| `{$MILESTONE.CAM.NAME.NOT_MATCHES}` | `^$` | Camera exclude regex |
| `{$MILESTONE.ESS.DELAY}` | `1d` | ESS snapshot interval |
| `{$MILESTONE.ESS.STATEGROUP.COMMUNICATION}` | *(empty)* | Communication state-group GUID (discover via `--list-stategroups`) |
| `{$MILESTONE.ESS.STATEGROUP.RECORDING}` | *(empty)* | Recording state-group GUID |
| `{$MILESTONE.ESS.TYPE.COMMUNICATION_OK}` | *(empty)* | Type GUID for "communication OK" within that group |
| `{$MILESTONE.ESS.TYPE.RECORDING_STARTED}` | *(empty)* | Type GUID for "recording started/ongoing" |

### Setting up on the Milestone side

In the **XProtect Management Client**:

1. Create a role (e.g. `Zabbix monitor`) with **Read** permission on
   Management Server, Recording Servers, Hardware, Cameras.
2. Create an **XProtect Basic user** and assign this role.
3. Make sure the API Gateway is installed and reachable from the Zabbix
   host; you can test with:
   ```bash
   curl --insecure https://<api-gw>/api/.well-known/uris
   ```
   The response lists the IDP endpoint used by the template.

### Setting up on the Zabbix side

1. Import `template_milestone_bosch_74.yaml`.
2. Create a host (type: any interface, or `No interfaces`) in group
   `Video/Milestone`.
3. Set the five `{$MILESTONE.*}` macros on the host.
4. Link `Milestone XProtect by HTTP` to the host.
5. Wait one discovery cycle (10–15 min) or run discovery manually via
   *Latest data* → test item.

Token acquisition happens *inside every Script item* for resilience —
if one script's token fetch fails, others are not affected. That costs
one IDP call per 1–15 min per server/camera, which is trivially cheap,
but if you run a very large site (hundreds of recording servers), raise
the `delay:` values on the LLD-generated master items or add a global
macro and a per-host Zabbix proxy.

## Bosch template (`Bosch IP camera by HTTP`)

### What it monitors

* `icmpping`, `icmppingloss`, `icmppingsec` — reachability.
* TCP service check on `{$BOSCH.PORT}` (default 443).
* RCP+ over CGI calls to `/rcp.xml` with Digest auth, parsed with XMLPath:
  | Tag | Command | Item |
  |-----|---------|------|
  | `0x002e` | `CONF_HARDWARE_VERSION` | `bosch.hw.version` |
  | `0x002f` | `CONF_FIRMWARE_VERSION` | `bosch.fw.version` |
  | `0x0032` | `CONF_DEVICE_NAME` | `bosch.device.name` |
  | `0x0a07` | `CONF_ENCODER_LOAD` | `bosch.encoder.load` (%) |
  | `0x0a36` | `CONF_DEVICE_UPTIME` | `bosch.uptime` |
* Triggers:
  * ICMP unreachable (HIGH)
  * Web UI down (AVERAGE)
  * Encoder load >85% for 5m (WARNING)
  * RCP+ not responding 30m (AVERAGE)
  * Uptime went backwards → reboot (INFO)
  * Firmware string changed (INFO)

### Required host macros

| Macro | Default | Purpose |
|---|---|---|
| `{$BOSCH.USER}` | `service` | Service-level user on the camera |
| `{$BOSCH.PASSWORD}` | *(empty, `Secret text`)* | Password |
| `{$BOSCH.SCHEME}` | `https` | http or https |
| `{$BOSCH.PORT}` | `443` | Web port |

### Typical setup

Each Bosch camera is its own Zabbix host. If you already have the
Milestone template telling you XProtect's view of that camera, use this
template to add the device-level view:

1. Create a host for each camera, interface = camera IP, group
   `Video/Bosch cameras`.
2. Set the two `{$BOSCH.*}` macros (or define them at host-group level
   and let each host inherit).
3. Link `Bosch IP camera by HTTP` to the host.

You'll typically end up with two "views" of each camera: the
MilestoneSys entry inside the site host (discovered by LLD), and the
per-camera host with the Bosch template. The system-wide dashboard
cross-references both.

## System-wide dashboard (`dashboard_video_overview.yaml`)

Top-down layout (72 columns × rows):

```
┌──────────────────────────────────────────────────────────────────┐
│ Active problems tagged component=recording-server OR =camera    │
├───────────────────────────────┬─────────────────────────────────┤
│ Milestone host availability   │ Bosch camera availability       │
├───────────────────────────────┼─────────────────────────────────┤
│ Top 10 cameras: encoder load  │ Top 10 cameras: ICMP latency    │
├───────────────────────────────┼──────────────┬──────────────────┤
│ RS handshake age (top 10)     │  Clock       │  Mgmt Client URL │
└───────────────────────────────┴──────────────┴──────────────────┘
```

Import instructions:

1. In **Dashboards** → click **Import** (top-right menu).
2. Select `dashboard_video_overview.yaml`.
3. At least one Milestone host and one Bosch host must already exist
   (otherwise the widgets will show "No data").

## Caveats & tips

**TLS verification is off by default.** Production XProtect deployments
typically use a self-signed CA. Both templates set `verify_peer: NO`
and `verify_host: NO` for pragmatic reasons. If you have a proper CA
chain in place, flip both to `YES` after import.

**Clock drift** matters — the "Seconds since last handshake" item
computes `Date.now() - lastStatusHandshake` in the Zabbix JS runtime.
Make sure the Zabbix server and the Milestone management server are
both NTP-synced, otherwise you'll get false-positive "handshake stale"
triggers.

**Token expiry:** the built-in IDP returns tokens with `expires_in: 3600`.
Because each Script item fetches its own token per poll, this is a non-
issue — the template never *uses* a token older than the poll interval.

**Camera name stability:** LLD uses the camera GUID (`{#CAM.ID}`) as the
stable item key, and the display name (`{#CAM.NAME}`) only as a friendly
label in the item name. Renaming a camera in XProtect will rename items
but not re-create them — no history is lost.

**Rate limit considerations:** the API Gateway does not enforce rate
limits, but the management server's SOAP layer can be slow for very
large sites. If you see `502 Bad Gateway` errors in the master scripts,
lengthen `delay:` and/or `timeout:`. The defaults (2–15 min) are tuned
for sites with <500 cameras.

**Bosch user permissions.** The `service` account is the default
service-level login; if that has been disabled on your cameras, create
a dedicated user with user-level access (level `USER` is enough for
read-only RCP+ configuration commands — no `LIVE` or `SERVICE` needed
for the tags in this template).

## Extending the template

Useful Milestone endpoints not yet wired up (add as Script items using
the same pattern as `milestone.sites.get`):

* `/api/rest/v1/recordingServers/{id}/storages` — media storage capacity
  and current usage (great for disk-fill trending).
* `/api/rest/v1/recordingServers/{id}?resources` — enumerate child
  objects that belong to each server, then discover things like
  `failoverRecorders`.
* WebSocket Events API (`/api/ws/events/v1`) — if you want real-time
  motion/alarm events rather than polled state. Not suitable for a
  native Zabbix item; would require a sidecar that feeds Zabbix Trapper
  items.

Useful Bosch RCP+ tags not yet wired up:

* `0x0a3e` / `0x0a21` — network packet counters (bytes/errors per interface).
* `0x0b00-range` — alarm/motion detection state messages (require the
  poll mechanism with `?message=` rather than `?command=`).
* `0x0811` — SD-card health / filesystem state (where present).

## License

Derivative work from the `lestoilfante/zabbix-integrations` project,
which is licensed under GPLv3. This derivative is released under the
same terms.
