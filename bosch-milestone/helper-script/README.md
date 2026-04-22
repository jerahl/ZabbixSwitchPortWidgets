# milestone_zbx.py — install & operational notes

## 1. Put the script on the Zabbix server (or proxy)

```bash
sudo install -o zabbix -g zabbix -m 0750 milestone_zbx.py \
  /usr/lib/zabbix/externalscripts/milestone_zbx.py
sudo install -o zabbix -g zabbix -m 0600 milestone.ini.example \
  /etc/zabbix/milestone.ini
sudo mkdir -p /var/lib/zabbix /var/log/zabbix
sudo chown zabbix:zabbix /var/lib/zabbix /var/log/zabbix
```

Then edit `/etc/zabbix/milestone.ini` with your real Management Server
hostname, service account, and password.

## 2. Python dependencies

```bash
sudo pip3 install requests requests-ntlm zeep
```

If your Milestone box uses Kerberos/Windows auth end-to-end, also:

```bash
sudo pip3 install requests-kerberos
```

## 3. Service account in Milestone

Create a dedicated user in Management Client:
- **Role**: either create a read-only "Zabbix" role, or use the built-in
  "Administrators" role scoped to read access only. The script only calls
  `Login`, `GetChildItems`, `GetItemState`, `GetProperty`, and
  `GetProperties` — no writes.
- **Login type**: BasicUser (for `auth_type = basic`) or Windows user
  (for `ntlm`/`windows`).

## 4. Smoke test from the Zabbix host

```bash
sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_zbx.py discover recording_servers
sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_zbx.py discover cameras
```

The first command should return a JSON array of Recording Servers. If you
see `ZBX_NOTSUPPORTED:` check `/var/log/zabbix/milestone_zbx.log` for the
actual error.

## 5. Token cache

Tokens are cached at `/var/lib/zabbix/milestone_token.json` and refreshed
automatically. If you ever rotate the service account password, delete
that file so the next call re-authenticates cleanly.

## 6. Performance note

Each external check forks a process and re-reads the token cache. For a
large site (>200 cameras), consider running the script as a long-lived
Zabbix trapper feeder instead — one process, one auth, batch pushes via
`zabbix_sender`. The template as shipped uses external checks with
per-camera item intervals of 5 minutes, which is fine up to ~500 cameras.
