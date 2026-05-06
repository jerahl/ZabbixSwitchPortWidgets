<?php

declare(strict_types=1);

namespace Modules\APDetail\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;

use API;

/**
 * AP Detail — widget view controller.
 *
 * doAction() is the single entry point called by Zabbix on every widget
 * refresh.  It is responsible for:
 *
 *  1. Reading the resolved host from the _hostid broadcast (or the form's
 *     static hostid field if no broadcast is active).
 *  2. Fetching Zabbix data via the internal API (no HTTP — we are inside
 *     the Zabbix PHP process).
 *  3. Calling XIQClient and PFClient for live API data.
 *  4. Passing all collected data to the view via setResponse().
 *
 * Convention: match switchports/actions/WidgetView.php structure exactly.
 * Use named arguments on multi-param API calls for readability.
 *
 * Status: M1 scaffold — the client libraries are stubbed pending
 * implementation in M1 step 1 (XIQClient) and step 2 (PFClient).  All
 * payload fields are present and correctly typed but populated as empty
 * arrays.  This file's job in M1 step 0 is to make the response shape
 * correct so the smoke test in step 3 only has to swap stubs for real
 * client calls.
 */
final class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        // ── 1. Resolve host ───────────────────────────────────────────────
        // getInputHost() resolves the broadcast _hostid or falls back to
        // the statically configured hostid field in WidgetForm.
        $host_id = $this->getInputHost();

        if ($host_id === null) {
            $this->setResponse(new CControllerResponseData([
                'name'  => $this->getInput('name', $this->widget->getDefaultName()),
                'error' => _('No AP host selected.'),
                'user'  => ['debug_mode' => $this->getDebugMode()],
            ]));
            return;
        }

        // ── 2. Zabbix host object + interfaces + macros ───────────────────
        // selectInterfaces is required to derive the AP management IP
        // (PFClient uses it as switch_ip for every locationlog and
        // radius_audit_log query — closeout G3 sidesteps the dual-MAC
        // problem by always filtering PF logs on switch_ip rather than MAC).
        // selectMacros surfaces the host-scoped {$XIQ_DEVICE_ID} macro
        // which is the only reliable Zabbix → XIQ join key (see closeout
        // §7 final decisions: full-scan fallback only on first onboard).
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectItems'      => ['itemid', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type'],
            'selectGroups'     => ['groupid', 'name'],
            'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'useip', 'type', 'main'],
            'selectMacros'     => ['macro', 'value'],
            'hostids'          => [$host_id],
            'limit'            => 1,
        ]);

        $host = $hosts[0] ?? null;

        if ($host === null) {
            $this->setResponse(new CControllerResponseData([
                'name'  => $this->getInput('name', $this->widget->getDefaultName()),
                'error' => _('AP host not found or access denied.'),
                'user'  => ['debug_mode' => $this->getDebugMode()],
            ]));
            return;
        }

        // AP management IP — primary SNMP interface address.  The Extreme
        // template polls the AP exclusively over SNMP, so the main SNMP
        // interface is also the management interface PF logs against.
        $ap_ip = $this->resolveApIp($host['interfaces'] ?? []);

        // XIQ device id from host-scoped macro {$XIQ_DEVICE_ID}.  Set per
        // host during onboarding (one-time XIQ /devices full-scan, then
        // stamped on the Zabbix host).  Returned as a string here — cast
        // to int when calling XIQClient methods (XIQ uses int64 ids).
        $xiq_device_id = $this->resolveHostMacro($host['macros'] ?? [], '{$XIQ_DEVICE_ID}');

        // ── 3. Widget config fields (consumed by clients in M1 step 1) ────
        // These are only used when constructing the client instances — not
        // returned to the view.  Defaults mirror WidgetForm::addFields().
        $xiq_host = (string) $this->getInput('xiq_host', 'https://api.extremecloudiq.com');
        $pf_url   = (string) $this->getInput('pf_url',   '');
        $pf_user  = (string) $this->getInput('pf_user',  '');
        $pf_pass  = (string) $this->getInput('pf_pass',  '');

        // ── 4. Zabbix health items ────────────────────────────────────────
        // Reduced to CPU + Memory only.  M0 SNMP analysis confirmed
        // HOST-RESOURCES-MIB (.1.3.6.1.2.1.25) and POWER-ETHERNET-MIB
        // (.1.3.6.1.2.1.105) are both absent on AP305C — there is no
        // standard temperature OID and the AP is a PoE consumer, not a
        // PSE.  Vendor CPU/memory items in the Extreme AP template are
        // SNMP-polled OIDs from enterprises.26928, not standard MIB.
        // TODO (M2): implement gatherHealth() key matching for the AP
        //            template's CPU + memory item keys + threshold colors.
        $health = $this->gatherHealth($host['items']);

        // ── 5. XIQ data ───────────────────────────────────────────────────
        // XIQClient (built in M1 step 1) — shared OAuth2 library at
        // includes/XIQClient.php.  Bearer-token auth via /login.  OAuth2
        // credentials from Zabbix global macros {$XIQ_CLIENT_ID} and
        // {$XIQ_CLIENT_SECRET}; base URL from the xiq_host config field.
        //
        // Method set used by Overview (per closeout §6 signatures):
        //   getDevice($xiqId)                   identity, software_version
        //   getDeviceNetworkPolicy($xiqId)      policy id + name
        //   getRadioStats($xiqId, 15)           per-radio current values (G6: 15-min window)
        //   getActiveClients($xiqId)            connected clients (G4: deviceIds=, G5: views=FULL)
        //   getDeviceIssues($xiqId, $f, $t)     Connectivity Issues panel
        //   getDeviceAlarms($xiqId, $f, $t)     Recent Events secondary feed
        //   getWirelessGraph($xiqId, $r, …)     telemetry sparklines (G8: $r ∈ WIFI0|WIFI1|WIFI2, G9: sort)
        //   getClientGraph($xiqId, $f, $t)      AP-total client-count sparkline
        //   getLocation($locationId)            building / floor metadata
        //
        // Wireless tab (M3) additionally calls getPolicySsids() and
        // getSsidStatus() — both 5-min cached, joined on SSID id (G7).
        //
        // TODO (M1 step 1): instantiate XIQClient and replace these stubs.
        $xiq_device   = [];   // GET /devices/{id}
        $xiq_radios   = [];   // GET /devices/{id}/wifi-interfaces/stats?startTime=&endTime=
        $xiq_clients  = [];   // GET /clients/active?deviceIds=X&views=FULL
        $xiq_issues   = [];   // GET /d360/device/issues
        $xiq_alarms   = [];   // GET /devices/{id}/alarms
        $xiq_location = [];   // GET /locations/{id}

        // ── 6. PacketFence data ───────────────────────────────────────────
        // PFClient (built in M1 step 2) — extracted from the existing
        // packetfence/ widget at includes/PFClient.php.  Bearer-token auth
        // via /api/v1/login (~50 min cache).  Credentials from widget
        // config: pf_url / pf_user / pf_pass.
        //
        // Filter is always switch_ip = AP management IP — sidesteps the
        // dual-MAC problem (G3) and is required to capture both Accept
        // (switch_ip_address populated) and Reject (nas_ip_address
        // populated) records via op:or in radius_audit_log (G1).
        //
        // Method set (per closeout §6 signatures):
        //   activeSessionMacs($apIp)            input MAC list for nodesByMac()
        //   nodesByMac($macs)                   batch enrichment (G12: op:or, NOT op:in)
        //   radiusAuditLog($apIp, 100)          Auth Failures table
        //   locationlogSearch($apIp, $f, $t)    raw rows for client-count sparkline buckets
        //
        // CAVEAT: do NOT use PF's locationlog active sessions as the
        // connected-clients list (G11).  PF's active sentinel
        // (end_time = '0000-00-00 00:00:00') is sticky and stale.  XIQ
        // /clients/active is the source of truth; PF enriches per MAC.
        //
        // TODO (M1 step 2): instantiate PFClient and replace these stubs.
        $pf_nodes     = [];   // POST /api/v1/nodes/search (enriched per MAC)
        $pf_radius    = [];   // GET  /api/v1/radius_audit_log
        $pf_locations = [];   // POST /api/v1/locationlogs/search (raw rows for bucketing)

        // ── 7. Time period ────────────────────────────────────────────────
        // Received via the _timeperiod broadcast — passed to the sparkline
        // JS so it renders the correct history window.  Same pattern as
        // portdetail/actions/WidgetView.php.
        //
        // CAVEAT: PF locationlog retention is unconfirmed (closeout §7 —
        // PF locationlog retention DEFER).  Cap the dashboard time-period
        // selector at 24h until SSH-verified against pf.conf.
        $time_period = $this->getTimePeriod();

        // ── 8. Respond ────────────────────────────────────────────────────
        $this->setResponse(new CControllerResponseData([
            'name'          => $this->getInput('name', $this->widget->getDefaultName()),
            'host'          => $host,
            'host_id'       => (int) $host_id,
            'ap_ip'         => $ap_ip,
            'xiq_device_id' => $xiq_device_id,
            'health'        => $health,
            'xiq_device'    => $xiq_device,
            'xiq_radios'    => $xiq_radios,
            'xiq_clients'   => $xiq_clients,
            'xiq_issues'    => $xiq_issues,
            'xiq_alarms'    => $xiq_alarms,
            'xiq_location'  => $xiq_location,
            'pf_nodes'      => $pf_nodes,
            'pf_radius'     => $pf_radius,
            'pf_locations'  => $pf_locations,
            'time_period'   => $time_period,
            'user'          => ['debug_mode' => $this->getDebugMode()],
        ]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Collect AP health metrics from the host's last-value item list.
     *
     * Mirrors switchports/WidgetView.php gatherHealth() — same item key
     * pattern matching, same return shape style.  Reduced to CPU + Memory
     * only after M0 SNMP analysis confirmed HOST-RESOURCES-MIB and
     * POWER-ETHERNET-MIB are absent on AP305C.
     *
     * @param  array<int,array<string,mixed>> $items  All items for the host.
     * @return array{
     *     cpu: array{value:float|null, status:string},
     *     mem: array{value:float|null, status:string},
     * }
     *
     * TODO (M2): fill in key matching logic and threshold constants.
     */
    private function gatherHealth(array $items): array {
        // Index by key_ for O(1) lookup in M2's matcher.
        $by_key = [];
        foreach ($items as $item) {
            $by_key[$item['key_']] = $item;
        }

        return [
            'cpu' => ['value' => null, 'status' => 'unknown'],
            'mem' => ['value' => null, 'status' => 'unknown'],
        ];
    }

    /**
     * Resolve the host ID from the widget's _hostid broadcast input.
     *
     * Returns null when no host is selected (no broadcast active and no
     * static host configured in the form).  The field name 'hostid'
     * matches the manifest.json "in" key and the CWidgetFieldHost declared
     * in WidgetForm::addFields().
     */
    private function getInputHost(): ?int {
        $raw = $this->getInput('hostid', null);
        return $raw !== null ? (int) $raw : null;
    }

    /**
     * Return the active time period from the _timeperiod broadcast.
     *
     * Falls back to a 1-hour default so sparklines always have a range.
     * Matches the pattern in portdetail/actions/WidgetView.php.
     *
     * Defensively coerces each axis individually because Zabbix 7.x can
     * deliver the broadcast input as a partial array — e.g.
     * ['from' => null, 'to' => null] when no time-period broadcaster is
     * wired on the dashboard, or [...,'from_ts' => 12345] without the
     * human-readable 'from' string.  We accept the structure as long as
     * it's an array, and fall back per-axis to defaults otherwise.
     *
     * The proper long-term fix is to declare a CWidgetFieldTimePeriod
     * in WidgetForm so the field validates the input shape — deferred
     * to M2 when sparkline rendering actually consumes the time range.
     *
     * @return array{from:string, to:string}
     */
    private function getTimePeriod(): array {
        $period = $this->getInput('time_period', null);
        $period = is_array($period) ? $period : [];

        $from = (isset($period['from']) && is_string($period['from']) && $period['from'] !== '')
            ? $period['from']
            : 'now-1h';

        $to = (isset($period['to']) && is_string($period['to']) && $period['to'] !== '')
            ? $period['to']
            : 'now';

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Pick the AP's management IP from the host's interface list.
     *
     * Prefers the primary SNMP interface (type=2, main=1) since the
     * Extreme AP template polls exclusively over SNMP and that interface
     * holds the address PF logs against.  Falls back to the first
     * interface if no primary SNMP entry exists.
     *
     * @param  array<int,array<string,mixed>> $interfaces
     */
    private function resolveApIp(array $interfaces): string {
        // Zabbix interface type constants — type=2 is SNMP.
        $primary_snmp = null;
        foreach ($interfaces as $iface) {
            if ((int) ($iface['type'] ?? 0) === 2 && (int) ($iface['main'] ?? 0) === 1) {
                $primary_snmp = $iface;
                break;
            }
        }
        $iface = $primary_snmp ?? ($interfaces[0] ?? null);

        if ($iface === null) {
            return '';
        }

        return ((int) ($iface['useip'] ?? 1) === 1)
            ? (string) ($iface['ip'] ?? '')
            : (string) ($iface['dns'] ?? '');
    }

    /**
     * Pull a named macro value out of API::Host()->get(... selectMacros).
     *
     * Returns null when the macro is not defined on the host.  Reads only
     * host-scoped macros — global macros and template-inherited macros
     * are intentionally out of scope here, since {$XIQ_DEVICE_ID} must be
     * unique per AP (set during onboarding) and inheritance would defeat
     * that.  Global OAuth2 credentials are resolved inside XIQClient via
     * CMacrosResolverHelper, not here.
     *
     * @param  array<int,array{macro:string,value:string}> $macros
     */
    private function resolveHostMacro(array $macros, string $name): ?string {
        foreach ($macros as $m) {
            if (($m['macro'] ?? '') === $name) {
                return (string) ($m['value'] ?? '');
            }
        }
        return null;
    }
}
