<?php

/**
 * AP Detail — widget view template.
 *
 * Variables injected by WidgetView::doAction() via CControllerResponseData:
 *
 * @var string                          $name           Widget instance title
 * @var array                           $host           Zabbix host object
 * @var int                             $host_id
 * @var string                          $ap_ip          AP management IP (from primary SNMP interface)
 * @var string|null                     $xiq_device_id  XIQ device id from {$XIQ_DEVICE_ID} host macro
 * @var array                           $health         CPU / Memory only — AP305C has no temp or PoE-PSE
 * @var array                           $xiq_device     XIQ device identity fields
 * @var array                           $xiq_radios     Per-radio wireless metrics (point-in-time)
 * @var array                           $xiq_clients    Connected client list (XIQ active clients, source of truth)
 * @var array                           $xiq_issues     Connectivity Issues panel data
 * @var array                           $xiq_alarms     Recent Events secondary feed
 * @var array                           $xiq_location   Building / floor metadata
 * @var array                           $pf_nodes       PF connected client records (enrichment)
 * @var array                           $pf_radius      PF auth failure log entries
 * @var array                           $pf_locations   PF locationlog rows for client-count sparkline
 * @var array{from:string, to:string}   $time_period    Active dashboard time range
 * @var array{debug_mode:bool}          $user
 * @var string|null                     $error          Set when no host selected
 *
 * All data passed here is already collected and sanitised in WidgetView.php.
 * No API calls, no business logic in this file — presentation only.
 */

declare(strict_types=1);

// ── Error state ───────────────────────────────────────────────────────────────
if (isset($error)) : ?>
<div class="ap-error">
    <span class="ap-error__icon">⚠</span>
    <span class="ap-error__msg"><?= htmlspecialchars($error) ?></span>
</div>
<?php return; endif; ?>

<!-- ── AP Detail root ─────────────────────────────────────────────────────── -->
<div class="ap-detail"
     data-hostid="<?= (int) $host_id ?>"
     data-from="<?= htmlspecialchars((string) ($time_period['from'] ?? 'now-1h')) ?>"
     data-to="<?= htmlspecialchars((string) ($time_period['to'] ?? 'now')) ?>">

    <!-- Tab bar ── rendered by class.widget.js after mount -->
    <nav class="ap-tabs" role="tablist" aria-label="AP detail tabs">
        <button class="ap-tab ap-tab--active" data-tab="overview"   role="tab">Overview</button>
        <button class="ap-tab"               data-tab="wireless"   role="tab">Wireless</button>
        <button class="ap-tab"               data-tab="wired"      role="tab">Wired</button>
        <button class="ap-tab"               data-tab="clients"    role="tab">Clients</button>
        <button class="ap-tab"               data-tab="events"     role="tab">Events</button>
        <button class="ap-tab"               data-tab="alerts"     role="tab">Alerts</button>
    </nav>

    <!-- Tab panels ─────────────────────────────────────────────────────────── -->

    <!-- Overview tab (M2) -->
    <section class="ap-panel ap-panel--active" data-panel="overview" role="tabpanel">
        <!-- Health rings: CPU + Memory only ──────────────────────────
             AP305C has no HOST-RESOURCES-MIB and no POWER-ETHERNET-MIB,
             so temperature and PoE rings are not available.  CPU and
             Memory items come from vendor enterprises.26928 OIDs in the
             Extreme AP template. -->
        <div class="ap-health-rings" id="ap-health-rings">
            <?php foreach (['cpu' => 'CPU', 'mem' => 'Memory'] as $key => $label) : ?>
            <div class="ap-ring" data-metric="<?= $key ?>">
                <svg class="ap-ring__svg" viewBox="0 0 64 64" aria-hidden="true">
                    <circle class="ap-ring__track" cx="32" cy="32" r="26"/>
                    <circle class="ap-ring__fill"  cx="32" cy="32" r="26"
                            stroke-dasharray="0 163.4"
                            data-value="<?= htmlspecialchars((string)($health[$key]['value'] ?? 0)) ?>"/>
                </svg>
                <div class="ap-ring__label"><?= $label ?></div>
                <div class="ap-ring__value">
                    <?= $health[$key]['value'] !== null
                        ? htmlspecialchars(number_format((float)$health[$key]['value'], 1)) . '%'
                        : '—' ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Live Telemetry sparkline strip (M2) ──────────────────────
             JS populates sparkline canvases from Zabbix history.get
             (CPU, memory, uplink in/out, latency, packet loss) and from
             XIQ /d360/wireless/interfaces-graph + /d360/client/graph
             (channel utilization per radio, AP-total client count).

             Slot count is enumerated from $xiq_radios at render time —
             do not hardcode 2.4/5 GHz labels (closeout G10: pilot AP
             runs dual-5G, other deployments may differ).  Channel
             utilization and noise floor are point-in-time only — no
             Zabbix history (closeout PF Historical API §0). -->
        <div class="ap-telemetry" id="ap-telemetry">
            <div class="ap-telemetry__loading">Loading telemetry…</div>
        </div>

        <!-- System Information KV table (M2) ────────────────────────── -->
        <div class="ap-kv-section">
            <h3 class="ap-kv-section__title">System Information</h3>
            <table class="ap-kv" id="ap-sysinfo">
                <tbody>
                <tr><th>Hostname</th>
                    <td><?= htmlspecialchars((string) ($host['name'] ?? '')) ?></td>
                    <td><span class="ap-source ap-source--zbx">ZBX</span></td></tr>
                <?php if (!empty($xiq_device)) : ?>
                <tr><th>Model</th>
                    <td><?= htmlspecialchars($xiq_device['model'] ?? '—') ?></td>
                    <td><span class="ap-source ap-source--xiq">XIQ</span></td></tr>
                <tr><th>Serial</th>
                    <td><?= htmlspecialchars($xiq_device['serial_number'] ?? '—') ?></td>
                    <td><span class="ap-source ap-source--xiq">XIQ</span></td></tr>
                <tr><th>Firmware</th>
                    <td><?= htmlspecialchars($xiq_device['software_version'] ?? '—') ?></td>
                    <td><span class="ap-source ap-source--xiq">XIQ</span></td></tr>
                <?php else : ?>
                <tr><td colspan="3" class="ap-kv__pending">XIQ data pending — M1</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Network Information KV table (M2) ───────────────────────── -->
        <div class="ap-kv-section">
            <h3 class="ap-kv-section__title">Network Information</h3>
            <table class="ap-kv" id="ap-netinfo">
                <tbody>
                <!-- Zabbix SNMP items: IP, MAC, LLDP neighbor, uptime    -->
                <!-- XIQ: NTP server, last config push — added in M2      -->
                <tr><td colspan="3" class="ap-kv__pending">Populated in M2</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Connectivity Issues (M2) ─────────────────────────────────── -->
        <div class="ap-conn-issues" id="ap-conn-issues">
            <div class="ap-conn-issues__loading">Loading…</div>
        </div>

        <!-- Recent Events (M2, back-filled with PF events in M5) ────── -->
        <div class="ap-events-feed" id="ap-events-feed">
            <div class="ap-events-feed__loading">Loading events…</div>
        </div>
    </section>

    <!-- Wireless tab (M3) -->
    <section class="ap-panel" data-panel="wireless" role="tabpanel" hidden>
        <div class="ap-panel__pending">Wireless tab — M3</div>
    </section>

    <!-- Wired tab (M3) -->
    <section class="ap-panel" data-panel="wired" role="tabpanel" hidden>
        <div class="ap-panel__pending">Wired tab — M3</div>
    </section>

    <!-- Clients tab (M4) -->
    <section class="ap-panel" data-panel="clients" role="tabpanel" hidden>
        <div class="ap-panel__pending">Clients tab — M4</div>
    </section>

    <!-- Events tab (M5) -->
    <section class="ap-panel" data-panel="events" role="tabpanel" hidden>
        <div class="ap-panel__pending">Unified events — M5</div>
    </section>

    <!-- Alerts tab (M5) -->
    <section class="ap-panel" data-panel="alerts" role="tabpanel" hidden>
        <div class="ap-panel__pending">Alerts — M5</div>
    </section>

</div><!-- /.ap-detail -->


