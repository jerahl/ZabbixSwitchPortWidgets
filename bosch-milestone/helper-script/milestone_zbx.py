#!/usr/bin/env python3
"""
milestone_zbx.py - Zabbix external check helper for Milestone XProtect Professional+

Bridges Zabbix to the Milestone Management Server + Event Server. Handles the
SOAP auth token dance once, caches the token, and exposes a simple CLI that
returns either JSON for LLD or a single scalar value for items.

Usage (called by Zabbix as an external check):
    milestone_zbx.py discover cameras
    milestone_zbx.py discover recording_servers
    milestone_zbx.py camera_state <camera_guid>
    milestone_zbx.py recording_state <camera_guid>
    milestone_zbx.py retention_days <camera_guid>
    milestone_zbx.py rs_service <rs_guid>
    milestone_zbx.py rs_storage_free_pct <rs_guid>

Config is read from /etc/zabbix/milestone.ini:
    [milestone]
    mgmt_host = milestone.example.local
    mgmt_port = 80
    username  = zabbix_svc
    password  = ********
    domain    = EXAMPLE         # leave empty for BasicUser
    auth_type = ntlm            # ntlm | basic | windows

Token cache: /var/lib/zabbix/milestone_token.json
Log:         /var/log/zabbix/milestone_zbx.log

Dependencies:
    pip install requests requests-ntlm zeep
"""
import sys
import os
import json
import time
import logging
import configparser
from datetime import datetime, timedelta, timezone
from pathlib import Path

try:
    import requests
    from requests_ntlm import HttpNtlmAuth
    from zeep import Client, Settings
    from zeep.transports import Transport
except ImportError as exc:
    print(f"ZBX_NOTSUPPORTED: missing dependency: {exc}", file=sys.stderr)
    sys.exit(1)

CONFIG_PATH = os.environ.get("MILESTONE_INI", "/etc/zabbix/milestone.ini")
TOKEN_CACHE = Path(os.environ.get("MILESTONE_TOKEN_CACHE",
                                  "/var/lib/zabbix/milestone_token.json"))
LOG_PATH = os.environ.get("MILESTONE_LOG", "/var/log/zabbix/milestone_zbx.log")

# Token refresh buffer - reauth this many seconds before the token's listed
# expiry so we never hand a stale token to a request.
TOKEN_REFRESH_BUFFER_SECONDS = 60

logging.basicConfig(
    filename=LOG_PATH,
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("milestone_zbx")


# ---------------------------------------------------------------------------
# Config + auth
# ---------------------------------------------------------------------------

def load_config():
    cfg = configparser.ConfigParser()
    if not cfg.read(CONFIG_PATH):
        raise SystemExit(f"ZBX_NOTSUPPORTED: cannot read {CONFIG_PATH}")
    return cfg["milestone"]


def build_session(cfg):
    """Build a requests.Session with the correct auth handler."""
    s = requests.Session()
    auth_type = cfg.get("auth_type", "ntlm").lower()
    user = cfg["username"]
    domain = cfg.get("domain", "").strip()
    pw = cfg["password"]

    if auth_type == "ntlm":
        principal = f"{domain}\\{user}" if domain else user
        s.auth = HttpNtlmAuth(principal, pw)
    elif auth_type == "basic":
        s.auth = (user, pw)
    elif auth_type == "windows":
        # Requires requests-kerberos on a domain-joined box; left as a hook.
        from requests_kerberos import HTTPKerberosAuth, OPTIONAL
        s.auth = HTTPKerberosAuth(mutual_authentication=OPTIONAL)
    else:
        raise SystemExit(f"ZBX_NOTSUPPORTED: unknown auth_type {auth_type}")
    return s


def get_token(cfg):
    """Return a valid Milestone login token, caching to disk.

    The Management Server login service issues a token good for ~4h by default.
    We refresh TOKEN_REFRESH_BUFFER_SECONDS before listed expiry.
    """
    now = datetime.now(timezone.utc)
    if TOKEN_CACHE.exists():
        try:
            cache = json.loads(TOKEN_CACHE.read_text())
            expires = datetime.fromisoformat(cache["expires"])
            if expires - timedelta(seconds=TOKEN_REFRESH_BUFFER_SECONDS) > now:
                return cache["token"]
        except Exception as exc:
            log.warning("token cache unreadable: %s", exc)

    # Call ServerCommandService.Login(loginType=0, currentToken="")
    host = cfg["mgmt_host"]
    port = cfg.get("mgmt_port", "80")
    wsdl = f"http://{host}:{port}/ManagementServer/ServerCommandService.svc?wsdl"
    session = build_session(cfg)
    transport = Transport(session=session, timeout=15)
    settings = Settings(strict=False, xml_huge_tree=True)
    client = Client(wsdl=wsdl, transport=transport, settings=settings)

    log.info("requesting new Milestone login token")
    resp = client.service.Login(loginType=0, currentToken="")
    token = resp["Token"]
    # TimeToLive is returned in microseconds in some builds, seconds in others.
    ttl_raw = int(resp["TimeToLive"]["MicroSeconds"])
    ttl_sec = ttl_raw / 1_000_000 if ttl_raw > 1_000_000 else ttl_raw
    expires = now + timedelta(seconds=ttl_sec)

    TOKEN_CACHE.parent.mkdir(parents=True, exist_ok=True)
    TOKEN_CACHE.write_text(json.dumps({
        "token": token,
        "expires": expires.isoformat(),
    }))
    TOKEN_CACHE.chmod(0o600)
    return token


# ---------------------------------------------------------------------------
# API client wrappers
# ---------------------------------------------------------------------------

class Milestone:
    def __init__(self, cfg):
        self.cfg = cfg
        self.host = cfg["mgmt_host"]
        self.port = cfg.get("mgmt_port", "80")
        self.session = build_session(cfg)
        self.token = get_token(cfg)

        cfg_wsdl = (f"http://{self.host}:{self.port}"
                    "/ManagementServer/ConfigurationApiService.svc?wsdl")
        self._cfg_client = Client(
            wsdl=cfg_wsdl,
            transport=Transport(session=self.session, timeout=15),
            settings=Settings(strict=False, xml_huge_tree=True),
        )

    # ----- Camera + RS topology -------------------------------------------

    def list_cameras(self):
        """Return [{camera_guid, camera_name, rs_guid, rs_name, enabled}]."""
        items = self._cfg_client.service.GetChildItems(
            token=self.token,
            path="/Camera",
        )
        out = []
        for it in items or []:
            props = {p["Key"]: p["Value"] for p in (it.get("Properties") or [])}
            out.append({
                "camera_guid": it["DisplayName"] and it["Path"].split("/")[-1],
                "camera_name": it["DisplayName"],
                "rs_guid": props.get("ParentItemPath", "").split("/")[-1],
                "rs_name": props.get("ParentDisplayName", ""),
                "enabled": props.get("Enabled", "True") == "True",
            })
        return out

    def list_recording_servers(self):
        items = self._cfg_client.service.GetChildItems(
            token=self.token,
            path="/RecordingServer",
        )
        out = []
        for it in items or []:
            out.append({
                "rs_guid": it["Path"].split("/")[-1],
                "rs_name": it["DisplayName"],
            })
        return out

    # ----- State queries ---------------------------------------------------

    def camera_state(self, camera_guid):
        """Online / offline state from the Event Server state feed."""
        path = f"/Camera[{camera_guid}]"
        state = self._cfg_client.service.GetItemState(
            token=self.token,
            itemPath=path,
        )
        # 1 = Responding/Online, 0 = Not Responding/Offline, -1 = Unknown
        return self._map_state(state)

    def recording_state(self, camera_guid):
        """Whether the camera is currently being recorded."""
        path = f"/Camera[{camera_guid}]/Recording"
        state = self._cfg_client.service.GetItemState(
            token=self.token,
            itemPath=path,
        )
        return self._map_state(state)

    def retention_days(self, camera_guid):
        """Oldest recording timestamp → days of retained footage."""
        path = f"/Camera[{camera_guid}]/RecordingOldestTime"
        val = self._cfg_client.service.GetProperty(
            token=self.token,
            itemPath=path,
        )
        if not val:
            return 0
        oldest = datetime.fromisoformat(val.replace("Z", "+00:00"))
        return round((datetime.now(timezone.utc) - oldest).total_seconds() / 86400, 2)

    def rs_service_state(self, rs_guid):
        path = f"/RecordingServer[{rs_guid}]"
        state = self._cfg_client.service.GetItemState(
            token=self.token,
            itemPath=path,
        )
        return self._map_state(state)

    def rs_storage_free_pct(self, rs_guid):
        """Primary storage free percentage (0-100)."""
        path = f"/RecordingServer[{rs_guid}]/Storage/Primary"
        props = self._cfg_client.service.GetProperties(
            token=self.token,
            itemPath=path,
        )
        props = {p["Key"]: p["Value"] for p in props or []}
        used = float(props.get("UsedSpace", 0))
        total = float(props.get("TotalSpace", 0))
        if total <= 0:
            return -1
        return round(100 * (1 - used / total), 2)

    @staticmethod
    def _map_state(raw):
        if raw in ("Responding", "Running", "Recording", "True", True, 1, "1"):
            return 1
        if raw in ("NotResponding", "Stopped", "NotRecording", "False", False, 0, "0"):
            return 0
        return -1


# ---------------------------------------------------------------------------
# CLI dispatch
# ---------------------------------------------------------------------------

def cmd_discover(ms, what):
    if what == "cameras":
        rows = ms.list_cameras()
        lld = [{
            "{#CAM.GUID}": r["camera_guid"],
            "{#CAM.NAME}": r["camera_name"],
            "{#RS.GUID}": r["rs_guid"],
            "{#RS.NAME}": r["rs_name"],
            "{#CAM.ENABLED}": "1" if r["enabled"] else "0",
        } for r in rows if r["camera_guid"]]
        print(json.dumps(lld))
    elif what == "recording_servers":
        rows = ms.list_recording_servers()
        lld = [{
            "{#RS.GUID}": r["rs_guid"],
            "{#RS.NAME}": r["rs_name"],
        } for r in rows]
        print(json.dumps(lld))
    else:
        print(f"ZBX_NOTSUPPORTED: unknown discovery target {what}", file=sys.stderr)
        sys.exit(1)


def main():
    if len(sys.argv) < 2:
        print("usage: milestone_zbx.py <command> [args...]", file=sys.stderr)
        sys.exit(1)

    cfg = load_config()
    ms = Milestone(cfg)
    cmd = sys.argv[1]

    try:
        if cmd == "discover":
            cmd_discover(ms, sys.argv[2])
        elif cmd == "camera_state":
            print(ms.camera_state(sys.argv[2]))
        elif cmd == "recording_state":
            print(ms.recording_state(sys.argv[2]))
        elif cmd == "retention_days":
            print(ms.retention_days(sys.argv[2]))
        elif cmd == "rs_service":
            print(ms.rs_service_state(sys.argv[2]))
        elif cmd == "rs_storage_free_pct":
            print(ms.rs_storage_free_pct(sys.argv[2]))
        else:
            print(f"ZBX_NOTSUPPORTED: unknown command {cmd}", file=sys.stderr)
            sys.exit(1)
    except Exception as exc:
        log.exception("command %s failed", cmd)
        print(f"ZBX_NOTSUPPORTED: {exc}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
