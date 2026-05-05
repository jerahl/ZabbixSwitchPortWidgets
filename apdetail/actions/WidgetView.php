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
 *  2. Fetching Zabbix data via the internal API (no HTTP — we are inside the
 *     Zabbix PHP process).
 *  3. Calling XIQClient and the PacketFence HTTP client for live API data.
 *  4. Passing all collected data to the view via setResponse().
 *
 * This scaffold contains the skeleton only.  Data-fetching stubs are clearly
 * marked TODO — they will be filled in during M1 and M2.
 *
 * Convention: match switchports/actions/WidgetView.php structure exactly.
 * Use named arguments on multi-param API calls for readability.
 */
final class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        // ── 1. Resolve host ───────────────────────────────────────────────
        // getInputHost() resolves the broadcast _hostid or falls back to the
        // statically configured hostid field in WidgetForm.
        $host_id = $this->getInputHost();

        if ($host_id === null) {
            $this->setResponse(new CControllerResponseData([
                'name'  => $this->getInput('name', $this->widget->getDefaultName()),
                'error' => _('No AP host selected.'),
                'user'  => ['debug_mode' => $this->getDebugMode()],
            ]));
            return;
        }

        // ── 2. Zabbix host object ─────────────────────────────────────────
        $hosts = API::Host()->get([
            'output'       => ['hostid', 'host', 'name', 'status'],
            'selectItems'  => ['itemid', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type'],
            'selectGroups' => ['groupid', 'name'],
            'hostids'      => [$host_id],
            'limit'        => 1,
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

        // ── 3. Zabbix health items (CPU, memory, temperature, PoE) ────────
        // Adapted from switchports/WidgetView.php gatherHealth().
        // Key patterns to collect — exact key strings resolved against $host['items'].
        // TODO (M2): implement gatherHealth() for AP item key patterns.
        $health = $this->gatherHealth($host['items']);

        // ── 4. XIQ data ───────────────────────────────────────────────────
        // XIQClient is the shared OAuth2 library built in M1.
        // Credentials come from Zabbix global macros {$XIQ_CLIENT_ID} /
        // {$XIQ_CLIENT_SECRET} resolved here, or from widget config fields.
        // TODO (M1): instantiate XIQClient and fetch device, wifi-data, clients.
        $xiq_device  = [];   // GET /xapi/v1/devices/{id}
        $xiq_radios  = [];   // GET /xapi/v1/monitor/devices/{id}/wifi-data
        $xiq_clients = [];   // GET /xapi/v1/monitor/devices/{id}/clients
        $xiq_location = [];  // GET /xapi/v1/location/{id}

        // ── 5. PacketFence data ───────────────────────────────────────────
        // Reuse the PF HTTP client auth pattern from packetfence/ widget.
        // TODO (M4): POST /api/v1/nodes/search with switch_ip = AP Mgt0 IP.
        $pf_nodes   = [];   // connected clients
        $pf_radius  = [];   // auth failure log

        // ── 6. Time period ────────────────────────────────────────────────
        // Received via _timeperiod broadcast — passed to sparkline JS so it
        // renders the correct history window (same as portdetail/ widget).
        $time_period = $this->getTimePeriod();

        // ── 7. Respond ────────────────────────────────────────────────────
        $this->setResponse(new CControllerResponseData([
            'name'        => $this->getInput('name', $this->widget->getDefaultName()),
            'host'        => $host,
            'host_id'     => (int) $host_id,
            'health'      => $health,
            'xiq_device'  => $xiq_device,
            'xiq_radios'  => $xiq_radios,
            'xiq_clients' => $xiq_clients,
            'xiq_location' => $xiq_location,
            'pf_nodes'    => $pf_nodes,
            'pf_radius'   => $pf_radius,
            'time_period' => $time_period,
            'user'        => ['debug_mode' => $this->getDebugMode()],
        ]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Collect AP health metrics from the host's last-value item list.
     *
     * Mirrors switchports/WidgetView.php gatherHealth() — same item key
     * patterns, same return shape.  AP-specific additions: PoE draw (single
     * port, not a discovery-based list) and no PSU/fan items (AP_305C has
     * neither in the Zabbix template).
     *
     * @param  array<int,array<string,mixed>> $items  All items for the host.
     * @return array{
     *     cpu:   array{value:float|null, status:string},
     *     mem:   array{value:float|null, status:string},
     *     temp:  array{value:float|null, status:string},
     *     poe:   array{draw:float|null,  budget:float|null},
     * }
     *
     * TODO (M2): fill in key matching logic and threshold constants.
     */
    private function gatherHealth(array $items): array {
        // Index items by key_ for O(1) lookup.
        $by_key = [];
        foreach ($items as $item) {
            $by_key[$item['key_']] = $item;
        }

        // Placeholder — actual key matching and threshold evaluation in M2.
        return [
            'cpu'  => ['value' => null, 'status' => 'unknown'],
            'mem'  => ['value' => null, 'status' => 'unknown'],
            'temp' => ['value' => null, 'status' => 'unknown'],
            'poe'  => ['draw'  => null, 'budget' => null],
        ];
    }

    /**
     * Resolve the host ID from the widget's _hostid broadcast input.
     *
     * Returns null when no host is selected (no broadcast active and no
     * static host configured in the form).
     */
    private function getInputHost(): ?int {
        // The field name 'hostid' matches the manifest.json "in" key and the
        // CWidgetFieldHost declared in WidgetForm::addFields().
        $raw = $this->getInput('hostid', null);
        return $raw !== null ? (int) $raw : null;
    }

    /**
     * Return the active time period from the _timeperiod broadcast.
     *
     * Falls back to a 1-hour default so sparklines always have a range.
     * Matches the pattern in portdetail/actions/WidgetView.php.
     *
     * @return array{from:string, to:string}
     */
    private function getTimePeriod(): array {
        $period = $this->getInput('time_period', null);

        if (is_array($period) && isset($period['from'], $period['to'])) {
            return $period;
        }

        // Default: last hour, expressed as relative timestamps (same format
        // Zabbix uses internally for the _timeperiod broadcast value).
        return ['from' => 'now-1h', 'to' => 'now'];
    }
}
