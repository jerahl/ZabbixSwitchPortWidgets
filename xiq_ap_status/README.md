# Extreme XIQ AP Status — Zabbix 7.4 widget

A dashboard widget that displays ExtremeCloud IQ access points discovered
via the **Extreme XIQ APs by API** template, plus per-row actions for
common operational tasks (reboot, manage/unmanage, on-demand refresh, run
allow-listed `show` commands).

## Prerequisites

1. The **Extreme XIQ APs by API** template must be installed and linked to
   a host in Zabbix. The widget reads items the template creates
   (`xiq.ap.connected[*]`, `xiq.ap.clients[*]`, etc.).
2. The template must include the `ap_serial`, `ap_mac`, and `ap_id` tags
   on each item prototype. The current shipped template does this; if you
   imported an older version, re-import.
3. Zabbix 7.4 or later.

## Installation

1. Drop the `xiq_ap_status/` directory into Zabbix's modules location:
   ```
   /usr/share/zabbix/modules/xiq_ap_status/
   ```
2. Enable the module: **Administration → General → Modules → Scan
   directory**, then enable "Extreme XIQ AP Status".
3. Add the widget to a dashboard. In its config:
   - **XIQ host**: pick the Zabbix host with the XIQ template linked.
   - **Max table rows**: default 1500 — fine for fleets up to that size.
   - **XIQ admin UI URL**: used for the "Open in XIQ" link in the kebab
     menu. Default `https://extremecloudiq.com`. The widget appends
     `/devices/{xiq_id}` to this.
   - **Action toggles**: all OFF by default. Enable only what you want
     dashboard users to be able to do.

The widget is read-only with no actions configured. To enable actions,
keep reading.

## Enabling actions (Path C: file-based action token)

The action token never enters the database, never enters the browser, and
is not retrievable through the Zabbix API. It lives in a file on the
Zabbix frontend filesystem, owned `root:apache`, mode 0640.

### 1. Mint an XIQ API token with action scopes

Use the [XIQ Swagger UI](https://api.extremecloudiq.com/) to call
`POST /auth/apitoken`. Required scopes by action:

| Action | Required scope |
|---|---|
| Reboot | `device:reboot` |
| Set Managed | `device:manage` |
| Set Unmanaged | `device:unmanage` |
| Run show command | `device:cli` |
| (LRO polling, all actions) | `lro:r` |

Recommended request body for the full Tier-1 + reboot + CLI surface:

```json
{
  "description": "Zabbix XIQ widget actions",
  "expire_time": 4102444800000,
  "permissions": ["device:reboot", "device:manage", "device:unmanage", "device:cli", "lro:r"]
}
```

Copy the `access_token` from the response.

### 2. Place the token file on the Zabbix frontend

```bash
sudo mkdir -p /etc/zabbix/secrets
sudo chown root:apache /etc/zabbix/secrets
sudo chmod 0750 /etc/zabbix/secrets

# Write the token (replace the placeholder)
sudo sh -c 'umask 027; cat > /etc/zabbix/secrets/xiq_action_token' <<'EOF'
PASTE_TOKEN_HERE
EOF
sudo chown root:apache /etc/zabbix/secrets/xiq_action_token
sudo chmod 0640 /etc/zabbix/secrets/xiq_action_token

# Verify Apache can read it (replace 'apache' with your web group if different)
sudo -u apache cat /etc/zabbix/secrets/xiq_action_token | head -c 20
```

If `sudo -u apache cat ...` errors with "Permission denied", check the
file's group ownership matches the user Apache runs as (`apache` on
Red Hat / Rocky / AlmaLinux; `www-data` on Debian / Ubuntu; `nginx` if
you run nginx instead).

### 3. Configure the widget

In the widget config:
- **Action token file path**: `/etc/zabbix/secrets/xiq_action_token`
  (the default — change only if you put it elsewhere under
  `/etc/zabbix/secrets/`).
- **Enable Reboot AP / Managed-Unmanaged toggle / Run show command**:
  flip on the actions you want exposed.

The path field accepts only files under `/etc/zabbix/secrets/`. Any
other location is refused server-side regardless of the widget config.

### 4. Smoke test

Open the dashboard. On any AP row, click the kebab menu → **Refresh
now**. The status should briefly read "Refresh queued" then disappear.
That confirms the widget can talk to the action controller and the
controller has the necessary Zabbix permissions.

Then click **Reboot AP** on a non-production AP. Confirm. The status
should read "Rebooting… (LRO ...)" then transition to "Done" once XIQ
acknowledges the reboot.

## Permission model

| Capability | Required Zabbix user type |
|---|---|
| See the widget | Any user with read access to the XIQ host |
| See action menu items | Zabbix Admin or Super Admin |
| Fire actions | Zabbix Admin or Super Admin (also enforced server-side) |
| Read the action token file | Apache process only (filesystem perms) |

The widget's view controller checks the user type before rendering action
buttons in the kebab menu, AND the action controller checks the user type
before honoring any action POST. A regular user crafting a POST directly
will be rejected.

## Audit logging

Every action (success or failure) writes to Zabbix's audit log:
- userid, username, source IP
- op (`reboot`, `manage`, `unmanage`, `cli`, `refresh`)
- device IDs
- token resolution success/failure
- per-op detail: LRO id on success, error message on failure
- For CLI actions: the command and a 4 KB excerpt of the output

Reports → Audit log → filter by username to review activity.

## CLI allow-list

When **Run show command** is enabled, the widget config exposes a CLI
allow-list (textarea, one command per line). Defaults to a sensible set
of `show` commands. The action controller enforces:

1. The command must start with `show ` (hard-coded — not configurable).
2. The command must appear verbatim in the allow-list.

A user choosing from the dropdown is restricted to allow-list entries.
A user crafting a POST directly with a non-allow-listed command is
rejected server-side.

To expand the allow-list, add lines in the widget config. Adding
mutating commands (anything that's not `show ...`) is blocked by the
hard-coded prefix check regardless.

## Token rotation

Rotating the token requires no widget config changes:

```bash
# 1. Mint a new token via Swagger (POST /auth/apitoken).
# 2. Replace the file atomically:
sudo sh -c 'umask 027; cat > /etc/zabbix/secrets/xiq_action_token.new' <<'EOF'
PASTE_NEW_TOKEN_HERE
EOF
sudo chown root:apache /etc/zabbix/secrets/xiq_action_token.new
sudo chmod 0640 /etc/zabbix/secrets/xiq_action_token.new
sudo mv /etc/zabbix/secrets/xiq_action_token.new \
        /etc/zabbix/secrets/xiq_action_token

# 3. Revoke the old token via XIQ:
#    DELETE /auth/apitoken/{old-token-id}
```

No restart, no widget edit, no dashboard reload. The next action picks
up the new token on its next file read.

## Troubleshooting

**"Action token could not be resolved..."** when firing an action.
The file path either doesn't exist, isn't under `/etc/zabbix/secrets/`,
or isn't readable by the Apache user. Verify:
```bash
sudo -u apache test -r /etc/zabbix/secrets/xiq_action_token && echo OK
```

**Action returns "XIQ HTTP 401: Unauthorized".**
Token is invalid or revoked. Mint a new one. Also possible: the token
was scoped without the action you're trying to fire (e.g., trying to
reboot with a token that lacks `device:reboot`).

**Action returns "XIQ HTTP 403: Forbidden".**
Token is valid but lacks scope for this action. Mint a new token with
the required scopes.

**LRO times out.**
The action was queued in XIQ but the AP didn't ack within 120s. This
sometimes happens with reboot when the AP is genuinely offline at the
moment of the action (XIQ has nothing to instruct). Click "Refresh now"
to update the AP's connection status.

**"Master item xiq.devices.raw not found on host"** when clicking
Refresh now.
The XIQ template isn't linked to the host you picked, or it's an older
template that doesn't include the master item. Re-link the template.

**Items don't have ap_serial/ap_mac/ap_id tags.**
You're using an older version of the template. Re-import the latest
template YAML. Existing discovered items pick up the new tags on the
next LLD cycle.

**CLI command returns "no output" but ran successfully.**
Some XIQ firmware variants return CLI output in a different shape than
the widget expects. Enable the debug panel in the widget config and
check the raw data block — the field name to look for is usually
`output`, `responses[].output`, or similar.

## Security notes

- The action token transits the local filesystem only. It is never sent
  to the browser, logged in audit details (only its file path is), or
  retrievable via Zabbix's API.
- The path allowlist (`/etc/zabbix/secrets/` prefix) is defense-in-depth
  against a malicious widget config attempting to read other files
  Apache can read. `realpath()` is used to resolve symlinks before the
  prefix check, so symlink escape is also prevented.
- The CLI allow-list is two-layer (allow-list + hard-coded `show `
  prefix). Even if the allow-list is somehow bypassed, mutating
  commands cannot be executed.
- CSRF protection compares the user's session ID to the token presented
  on the action POST, using `hash_equals()` for constant-time comparison.
- All action calls log to Zabbix's audit log including failed attempts,
  giving a clear trail of who attempted what.

## Known limitations

- **Bulk actions** (multi-row select → one action) are not yet
  implemented. The XIQ endpoints used here all support bulk by design,
  so this is purely a UI addition for v2.
- **Per-AP managed-state display** isn't shown; both "Set Managed" and
  "Set Unmanaged" appear in the menu regardless. The XIQ template
  doesn't currently surface admin state as an item — adding it is a
  template-level enhancement.
- **CLI on multiple devices at once** is not exposed; CLI is single-device
  only. The XIQ `:cli` endpoint is per-device anyway.
- **Multi-frontend Zabbix deployments** must place the token file on
  every frontend node. The file is not synchronized automatically.
