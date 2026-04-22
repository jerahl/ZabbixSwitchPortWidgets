<?php
/**
 * WidgetView - server-side handler that queries the Zabbix API for cameras
 * matching the configured host group(s) and returns a JSON payload the
 * client-side widget renders.
 *
 * Cameras are identified by hosts carrying the tag
 *     milestone_role = camera
 *
 * For each camera we pull the latest values of three items (by tag):
 *     metric=online_milestone
 *     metric=recording_state
 *     metric=retention_days
 *     metric=icmp            (independent cross-check)
 */

namespace Modules\CameraStatus\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $fields = $this->getForm()->getFieldsValues();

        $groupids = $fields['groupids'] ?: null;

        // Pull camera hosts (by tag), limited to selected groups if any.
        $hosts = API::Host()->get([
            'output'  => ['hostid', 'host', 'name', 'status'],
            'groupids' => $groupids,
            'selectTags' => ['tag', 'value'],
            'selectInterfaces' => ['ip', 'dns'],
            'evaltype' => TAG_EVAL_TYPE_AND_OR,
            'tags' => [
                ['tag' => 'milestone_role', 'value' => 'camera', 'operator' => TAG_OPERATOR_EQUAL]
            ],
            'preservekeys' => true,
        ]);

        $cameras = [];
        if ($hosts) {
            $hostids = array_keys($hosts);

            // One bulk item query, then fan out by tag.
            $items = API::Item()->get([
                'output' => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'lastvalue', 'lastclock', 'prevvalue'],
                'hostids' => $hostids,
                'selectTags' => ['tag', 'value'],
                'evaltype' => TAG_EVAL_TYPE_AND_OR,
                'tags' => [
                    ['tag' => 'component', 'value' => 'camera', 'operator' => TAG_OPERATOR_EQUAL]
                ],
                'preservekeys' => true,
            ]);

            // Index items by host + metric tag for O(1) lookup.
            $by_host = [];
            foreach ($items as $it) {
                $metric = null;
                foreach ($it['tags'] as $t) {
                    if ($t['tag'] === 'metric') {
                        $metric = $t['value'];
                        break;
                    }
                }
                if ($metric === null) continue;
                $by_host[$it['hostid']][$metric] = $it;
            }

            foreach ($hosts as $hid => $h) {
                $metrics   = $by_host[$hid] ?? [];
                $rs_name   = self::tagValue($h['tags'], 'rs_name');
                $cam_name  = self::tagValue($h['tags'], 'camera_name') ?: $h['name'];
                $online_ms = self::metricVal($metrics, 'online_milestone');
                $recording = self::metricVal($metrics, 'recording_state');
                $retention = self::metricVal($metrics, 'retention_days');
                $icmp      = self::metricVal($metrics, 'icmp');

                $cameras[] = [
                    'hostid'    => $hid,
                    'name'      => $cam_name,
                    'rs_name'   => $rs_name ?: 'Unassigned',
                    'online'    => self::toInt($online_ms),
                    'icmp'      => self::toInt($icmp),
                    'recording' => self::toInt($recording),
                    'retention' => ($retention === null) ? null : (float)$retention,
                    'last_seen' => self::lastClock($metrics, 'online_milestone'),
                    'state'     => self::deriveState($online_ms, $icmp, $recording),
                ];
            }

            // Sort by state severity desc, then RS, then name.
            usort($cameras, function($a, $b) {
                if ($a['state'] !== $b['state']) {
                    return $b['state'] <=> $a['state'];
                }
                if ($a['rs_name'] !== $b['rs_name']) {
                    return strcmp($a['rs_name'], $b['rs_name']);
                }
                return strcmp($a['name'], $b['name']);
            });
        }

        $this->setResponse(new CControllerResponseData([
            'name'      => $this->getInput('name', $this->widget->getDefaultName()),
            'cameras'   => $cameras,
            'user'      => ['debug_mode' => $this->getDebugMode()],
            'fields'    => $fields,
        ]));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function tagValue(array $tags, string $key): ?string {
        foreach ($tags as $t) {
            if ($t['tag'] === $key) return $t['value'];
        }
        return null;
    }

    private static function metricVal(array $metrics, string $key) {
        if (!isset($metrics[$key])) return null;
        $v = $metrics[$key]['lastvalue'];
        return ($v === '' || $v === null) ? null : $v;
    }

    private static function lastClock(array $metrics, string $key): int {
        return isset($metrics[$key]) ? (int)$metrics[$key]['lastclock'] : 0;
    }

    private static function toInt($v): ?int {
        return ($v === null) ? null : (int)$v;
    }

    /**
     * Derive a single state bucket from the raw metrics, used for sorting
     * and coloring. Higher = worse.
     *   0 = healthy (online + recording)
     *   1 = online but not recording (high severity)
     *   2 = milestone says offline but ICMP says alive (RS issue suspected)
     *   3 = hard down (milestone offline AND icmp failing)
     *   4 = unknown / no data
     */
    private static function deriveState($online_ms, $icmp, $recording): int {
        if ($online_ms === null && $icmp === null) return 4;
        $ms_online   = ((int)$online_ms === 1);
        $icmp_alive  = ((int)$icmp === 1);
        $is_recording = ((int)$recording === 1);

        if ($ms_online && $is_recording)      return 0;
        if ($ms_online && !$is_recording)     return 1;
        if (!$ms_online && $icmp_alive)       return 2;
        if (!$ms_online && !$icmp_alive)      return 3;
        return 4;
    }
}
