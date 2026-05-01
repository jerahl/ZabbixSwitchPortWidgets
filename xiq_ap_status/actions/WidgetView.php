<?php
/**
 * XIQ AP status widget — view action.
 *
 * Pulls latest values for xiq.ap.connected[*] / xiq.ap.configmismatch[*] /
 * xiq.ap.ip[*] / xiq.ap.mac[*] across the configured hosts, groups them by
 * serial, and returns a per-AP row with a synthesized fault status. Mirrors
 * the milestone_camera_status data flow so the JS layer can render it the
 * same way.
 *
 * Click-to-lookup: each fault row carries data-row-index on the JS side; a
 * delegated click handler dispatches a 'pf:deviceSelected' DOM event so the
 * unified PacketFence widget (planned next) can react. The legacy
 * 'mcs:cameraSelected' event is also dispatched for compatibility with the
 * existing milestone_camera_packetfence widget while the unified widget is
 * in flight.
 *
 * MAC item is optional — the shipping XIQ template doesn't expose it as a
 * per-AP item, only as the {#MAC} LLD macro. If you add an item prototype
 * `xiq.ap.mac[{#SERIAL}]` to the template, this widget picks it up
 * automatically; otherwise rows just show '—' in the MAC column.
 */

declare(strict_types=1);

namespace Modules\XiqApStatus\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView
{
    /** Synthesized per-AP status codes used by both controller and JS. */
    private const STATUS_OK              = 0;
    private const STATUS_CONFIG_MISMATCH = 1;
    private const STATUS_DISCONNECTED    = 2;

    private const KEY_CONNECTED  = 'xiq.ap.connected';
    private const KEY_MISMATCH   = 'xiq.ap.configmismatch';
    private const KEY_IP         = 'xiq.ap.ip';
    private const KEY_MAC        = 'xiq.ap.mac';

    protected function doAction(): void
    {
        $max_rows = (int)($this->fields_values['max_rows'] ?? 100);

        $hostids = [];
        if (!empty($this->fields_values['hostids'])) {
            $hostids = (array)$this->fields_values['hostids'];
        }

        $payload = [
            'name'    => $this->getInput('name', $this->widget->getDefaultName()),
            'summary' => [
                'total'           => 0,
                'ok'              => 0,
                'config_mismatch' => 0,
                'disconnected'    => 0,
                'no_data'         => 0,
            ],
            'rows'      => [],
            'truncated' => false,
            'error'     => null,
            'debug_info' => [
                '_VERSION_MARKER'    => 'xiq-v1-2026-05-01',
                'hostids_queried'    => count($hostids),
                'items_found'        => 0,
                'phase'              => 'init',
                'fields_values_keys' => array_keys($this->fields_values ?? []),
            ],
            'user' => ['debug_mode' => $this->getDebugMode()],
        ];

        if (!$hostids) {
            $payload['error'] = _('No host selected.');
            $this->setResponse(new CControllerResponseData($payload));
            return;
        }

        // ------------------------------------------------------------------
        // Single batched API call: pull every xiq.ap.* item across the
        // configured hosts. searchByAny=true makes the four key prefixes
        // OR together. We'll bucket per-(hostid, serial) below.
        // ------------------------------------------------------------------
        try {
            $payload['debug_info']['phase'] = 'querying_items';
            $items = API::Item()->get([
                'output'       => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock'],
                'selectHosts'  => ['hostid', 'host', 'name'],
                'hostids'      => $hostids,
                'search'       => ['key_' => [
                    self::KEY_CONNECTED,
                    self::KEY_MISMATCH,
                    self::KEY_IP,
                    self::KEY_MAC,
                ]],
                'startSearch'  => true,
                'searchByAny'  => true,
                'preservekeys' => true,
            ]);
        } catch (\Throwable $e) {
            $payload['debug_info']['phase']     = 'item_query_failed';
            $payload['debug_info']['exception'] = $e->getMessage();
            $payload['error'] = _('Item API call failed: ').$e->getMessage();
            $this->setResponse(new CControllerResponseData($payload));
            return;
        }

        $payload['debug_info']['items_found'] = is_array($items) ? count($items) : 0;
        $payload['debug_info']['phase']       = 'items_returned';

        if (!$items) {
            $this->setResponse(new CControllerResponseData($payload));
            return;
        }

        // ------------------------------------------------------------------
        // Bucket items by (hostid, serial). Each AP becomes a single row
        // built from up to four sibling items.
        // ------------------------------------------------------------------
        $aps = []; // [hostid][serial] => ['host'=>..., 'connected'=>?, 'mismatch'=>?, 'mac'=>?, 'ip'=>?, 'lastclock'=>?, 'hostname'=>?, 'itemid_connected'=>?]

        foreach ($items as $item) {
            $host = $item['hosts'][0] ?? null;
            if (!$host) {
                continue;
            }
            $key = (string)$item['key_'];

            // Match key prefix and pull serial out of [..]. We require the
            // bracketed parameter — bare keys (no serial) wouldn't be from
            // the XIQ template and are skipped.
            if (!preg_match('/^(xiq\.ap\.(?:connected|configmismatch|ip|mac))\[([^\]]+)\]$/', $key, $m)) {
                continue;
            }
            [$_, $base, $serial] = $m;

            $hid = (string)$host['hostid'];
            if (!isset($aps[$hid][$serial])) {
                $aps[$hid][$serial] = [
                    'hostid'    => $hid,
                    'host_name' => $host['name'],
                    'host_tech' => $host['host'],
                    'serial'    => $serial,
                    'connected' => null,
                    'mismatch'  => null,
                    'mac'       => null,
                    'ip'        => null,
                    'hostname'  => null,
                    'lastclock' => 0,
                    'itemid_connected' => null,
                ];
            }
            $row = &$aps[$hid][$serial];

            $raw = $item['lastvalue'] ?? null;
            $lc  = (int)($item['lastclock'] ?? 0);
            if ($lc > $row['lastclock']) {
                $row['lastclock'] = $lc;
            }

            switch ($base) {
                case self::KEY_CONNECTED:
                    $row['connected'] = ($raw === null || $raw === '') ? null : (int)$raw;
                    $row['itemid_connected'] = $item['itemid'];
                    if ($row['hostname'] === null) {
                        $row['hostname'] = self::extractApHostname((string)$item['name']);
                    }
                    break;
                case self::KEY_MISMATCH:
                    $row['mismatch'] = ($raw === null || $raw === '') ? null : (int)$raw;
                    break;
                case self::KEY_IP:
                    $row['ip'] = ($raw === null || $raw === '') ? null : trim((string)$raw);
                    break;
                case self::KEY_MAC:
                    $row['mac'] = ($raw === null || $raw === '') ? null : self::normalizeMac((string)$raw);
                    break;
            }
            unset($row);
        }

        // ------------------------------------------------------------------
        // Tally summary and pull fault rows. An AP is only counted in the
        // summary if it has a connected item — otherwise it's "no_data".
        // ------------------------------------------------------------------
        $rows = [];
        foreach ($aps as $hid => $by_serial) {
            foreach ($by_serial as $serial => $ap) {
                $payload['summary']['total']++;
                $status = self::synthesizeStatus($ap['connected'], $ap['mismatch']);
                if ($status === null) {
                    $payload['summary']['no_data']++;
                    continue;
                }
                switch ($status) {
                    case self::STATUS_OK:
                        $payload['summary']['ok']++;
                        break;
                    case self::STATUS_CONFIG_MISMATCH:
                        $payload['summary']['config_mismatch']++;
                        break;
                    case self::STATUS_DISCONNECTED:
                        $payload['summary']['disconnected']++;
                        break;
                }

                // Only fault rows go into the table.
                if ($status === self::STATUS_OK) {
                    continue;
                }
                $rows[] = [
                    'host_name' => $ap['host_name'],
                    'host_tech' => $ap['host_tech'],
                    'hostid'    => $ap['hostid'],
                    'itemid'    => $ap['itemid_connected'],
                    'ap_name'   => $ap['hostname'] ?: $serial,
                    'serial'    => $serial,
                    'mac'       => $ap['mac'],
                    'ip'        => $ap['ip'],
                    'status'    => $status,
                    'lastclock' => $ap['lastclock'],
                ];
            }
        }

        // Stable sort: severity desc (Disconnected before Config-mismatch),
        // then host name, then AP name.
        usort($rows, static function (array $a, array $b): int {
            return [$b['status'], $a['host_name'], $a['ap_name']]
                <=> [$a['status'], $b['host_name'], $b['ap_name']];
        });

        if (count($rows) > $max_rows) {
            $rows = array_slice($rows, 0, $max_rows);
            $payload['truncated']    = true;
            $payload['truncated_at'] = $max_rows;
        }
        $payload['rows']                = $rows;
        $payload['debug_info']['phase'] = 'done';

        $this->setResponse(new CControllerResponseData($payload));
    }

    /**
     * Combine the connected (0/1) and config_mismatch (0/1) inputs into a
     * single status code. Returns null if connected is unknown — the AP is
     * counted as "no_data" and not displayed.
     */
    private static function synthesizeStatus(?int $connected, ?int $mismatch): ?int
    {
        if ($connected === null) {
            return null;
        }
        if ($connected === 0) {
            return self::STATUS_DISCONNECTED;
        }
        if ($mismatch === 1) {
            return self::STATUS_CONFIG_MISMATCH;
        }
        return self::STATUS_OK;
    }

    /**
     * Item names produced by the XIQ template look like
     * "AP <hostname>: Connected". Extract the hostname so the table can
     * show a friendly label instead of just the serial number.
     */
    private static function extractApHostname(string $item_name): ?string
    {
        if (preg_match('/^AP\s+(.+?):\s+Connected\s*$/u', $item_name, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Normalize a MAC string to lowercase colon-separated form. Returns
     * null if the value doesn't parse cleanly. XIQ returns colon form
     * already, but accept the usual variants.
     */
    private static function normalizeMac(string $raw): ?string
    {
        $hex = preg_replace('/[^0-9a-f]/', '', strtolower(trim($raw)));
        if ($hex === null || strlen($hex) !== 12) {
            return null;
        }
        return implode(':', str_split($hex, 2));
    }
}
