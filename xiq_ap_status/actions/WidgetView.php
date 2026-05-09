<?php declare(strict_types = 0);

namespace Modules\XiqApStatus\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;
use CWebUser;
use Modules\XiqApStatus\Includes\WidgetForm;

/**
 * XIQ AP Status — read-side controller.
 *
 * Pulls items discovered by the "Extreme XIQ APs by API" template, groups
 * them by AP using the per-item `ap_serial` / `ap_mac` / `ap_id` tags, and
 * builds one row per AP for the table. Action eligibility flags are passed
 * through to the JS so the kebab menu only renders enabled actions.
 *
 * The action token does NOT flow through this controller. Actions are
 * dispatched separately to WidgetAction.php which reads the token from the
 * filesystem at the moment of the action.
 */
class WidgetView extends CControllerDashboardWidgetView {

	/** Item-key prefixes the template defines. */
	private const KEY_CONNECTED   = 'xiq.ap.connected[';
	private const KEY_CLIENTS     = 'xiq.ap.clients[';
	private const KEY_VERSION     = 'xiq.ap.version[';
	private const KEY_LAST        = 'xiq.ap.lastconnect[';
	private const KEY_IP          = 'xiq.ap.ip[';
	private const KEY_UPTIME      = 'xiq.ap.uptime[';
	private const KEY_CFGMISMATCH = 'xiq.ap.configmismatch[';

	protected function doAction(): void {
		$debug = ['step_0_init' => ['user_type' => CWebUser::getType()]];

		$name        = $this->getInput('name', $this->widget->getDefaultName());
		$max_rows    = (int) ($this->fields_values['max_rows'] ?? 1500);
		$sort_down   = (bool) ($this->fields_values['show_disconnected_first'] ?? true);
		$show_debug  = (bool) ($this->fields_values['show_debug'] ?? false);

		// The host(s) the user picked in the form. Single-select in practice
		// for this widget — we use the first one if a list is provided.
		$hostids = (array) ($this->fields_values['hostids'] ?? []);
		$hostid  = $hostids ? (int) reset($hostids) : 0;
		$debug['step_1_hostid'] = $hostid;

		if ($hostid <= 0) {
			$this->respond($name, [
				'rows'    => [],
				'summary' => self::emptySummary(),
				'error'   => _('No XIQ host configured. Set "XIQ host" in the widget configuration.'),
				'flags'   => $this->actionFlags(),
				'urls'    => $this->urlFields(),
				'config'  => $this->configFields(),
				'debug'   => $debug,
				'show_debug' => $show_debug,
			]);
			return;
		}

		// Resolve host display name for the panel header.
		$hosts = API::Host()->get([
			'output'  => ['hostid', 'host', 'name'],
			'hostids' => [$hostid],
		]);
		$host_label = $hosts ? ($hosts[0]['name'] ?: $hosts[0]['host']) : '';
		$debug['step_2_host_label'] = $host_label;

		// Fetch ALL items on the host whose key starts with one of the AP
		// key prefixes. We pull everything in one call and group locally —
		// way fewer DB hits than per-prototype calls.
		$items = API::Item()->get([
			'output'      => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock', 'value_type'],
			'hostids'     => [$hostid],
			'selectTags'  => ['tag', 'value'],
			'search'      => [
				'key_' => [
					self::KEY_CONNECTED,
					self::KEY_CLIENTS,
					self::KEY_VERSION,
					self::KEY_LAST,
					self::KEY_IP,
					self::KEY_UPTIME,
					self::KEY_CFGMISMATCH,
				],
			],
			'searchByAny'    => true,
			'startSearch'    => true,
			'preservekeys'   => false,
		]);
		$debug['step_3_items_fetched'] = count($items);

		// Group by serial (from item tags). We expect 7 items per AP.
		$grouped = [];
		foreach ($items as $it) {
			$serial = '';
			$mac    = '';
			$xiq_id = '';
			foreach ($it['tags'] as $tag) {
				if ($tag['tag'] === 'ap_serial') $serial = $tag['value'];
				elseif ($tag['tag'] === 'ap_mac') $mac    = $tag['value'];
				elseif ($tag['tag'] === 'ap_id')  $xiq_id = $tag['value'];
			}
			if ($serial === '') continue;

			if (!isset($grouped[$serial])) {
				$grouped[$serial] = [
					'serial' => $serial,
					'mac'    => $mac,
					'xiq_id' => $xiq_id,
					'name'   => '',          // hostname; pulled from item name suffix
				];
			} else {
				// Backfill from later items if the first one we processed for
				// this serial happened to lack the ap_mac / ap_id tag. Zabbix's
				// item-search API doesn't guarantee ordering, so a single
				// prototype missing a tag would otherwise blank these fields
				// permanently for the affected AP.
				if ($grouped[$serial]['mac']    === '' && $mac    !== '') $grouped[$serial]['mac']    = $mac;
				if ($grouped[$serial]['xiq_id'] === '' && $xiq_id !== '') $grouped[$serial]['xiq_id'] = $xiq_id;
			}
			// Item names are shaped "AP <hostname>: <metric>" (per template) — we
			// capture the first one we see and parse <hostname> out of it.
			if ($grouped[$serial]['name'] === '' && !empty($it['name'])) {
				if (preg_match('/^AP\s+(.+?):\s+/', (string) $it['name'], $m)) {
					$grouped[$serial]['name'] = $m[1];
				}
			}

			// Bucket the lastvalue by item-key prefix.
			$key = (string) $it['key_'];
			$lv  = $it['lastvalue'];
			$lc  = (int) $it['lastclock'];

			if     (str_starts_with($key, self::KEY_CONNECTED))   { $grouped[$serial]['connected']    = (int) $lv; $grouped[$serial]['connected_age'] = $lc; }
			elseif (str_starts_with($key, self::KEY_CLIENTS))     { $grouped[$serial]['clients']      = (int) $lv; }
			elseif (str_starts_with($key, self::KEY_VERSION))     { $grouped[$serial]['version']      = (string) $lv; }
			elseif (str_starts_with($key, self::KEY_LAST))        { $grouped[$serial]['last_connect'] = (int) $lv; }
			elseif (str_starts_with($key, self::KEY_IP))          { $grouped[$serial]['ip']           = (string) $lv; }
			elseif (str_starts_with($key, self::KEY_UPTIME))      { $grouped[$serial]['uptime']       = (int) $lv; }
			elseif (str_starts_with($key, self::KEY_CFGMISMATCH)) { $grouped[$serial]['cfg_mismatch'] = (int) $lv; }
		}
		$debug['step_4_aps'] = count($grouped);

		// Build the row array.
		$rows = [];
		foreach ($grouped as $g) {
			$rows[] = [
				'serial'       => $g['serial'],
				'mac'          => $g['mac']    ?? '',
				'xiq_id'       => $g['xiq_id'] ?? '',
				'name'         => $g['name']   ?: $g['serial'],
				'ip'           => $g['ip']           ?? '',
				'connected'    => $g['connected']    ?? 0,
				'clients'      => $g['clients']      ?? 0,
				'version'      => $g['version']      ?? '',
				'last_connect' => $g['last_connect'] ?? 0,
				'uptime'       => $g['uptime']       ?? 0,
				'cfg_mismatch' => $g['cfg_mismatch'] ?? 0,
				'hostid'       => 0,
			];
		}

		// Resolve each AP's per-AP Zabbix host id so a row click can
		// broadcast _hostid to a peer AP Detail widget. The AP's hostname
		// (parsed from "AP <hostname>: ..." item names above) is matched
		// against Zabbix host technical-name and visible-name. Best-effort:
		// rows whose AP name doesn't resolve get hostid=0 and silently
		// skip the broadcast on click.
		$ap_names = [];
		foreach ($rows as $r) {
			if ($r['name'] !== '') $ap_names[$r['name']] = true;
		}
		if ($ap_names) {
			$ap_hosts = API::Host()->get([
				'output' => ['hostid', 'host', 'name'],
				'filter' => ['host' => array_keys($ap_names)],
			]);
			$by_name = [];
			foreach ($ap_hosts as $h) {
				$by_name[$h['host']] = (int) $h['hostid'];
				$by_name[$h['name']] = (int) $h['hostid'];
			}
			// Second pass for any names not found by technical name.
			$missing = array_diff(array_keys($ap_names), array_keys($by_name));
			if ($missing) {
				$more = API::Host()->get([
					'output' => ['hostid', 'host', 'name'],
					'filter' => ['name' => array_values($missing)],
				]);
				foreach ($more as $h) {
					$by_name[$h['host']] = (int) $h['hostid'];
					$by_name[$h['name']] = (int) $h['hostid'];
				}
			}
			foreach ($rows as &$r) {
				if (isset($by_name[$r['name']])) {
					$r['hostid'] = $by_name[$r['name']];
				}
			}
			unset($r);
			$debug['step_4b_hostid_resolved'] = count(array_filter($rows, fn($r) => $r['hostid'] > 0));
		}

		// Summary tiles.
		$summary = self::emptySummary();
		$summary['total'] = count($rows);
		foreach ($rows as $r) {
			if ($r['connected']) $summary['connected']++; else $summary['disconnected']++;
			if ($r['cfg_mismatch']) $summary['cfg_mismatch']++;
			$summary['clients_total'] += (int) $r['clients'];
		}

		// Sort: disconnected first (if enabled), then by name.
		usort($rows, function ($a, $b) use ($sort_down) {
			if ($sort_down) {
				$cmp = ($a['connected'] <=> $b['connected']);
				if ($cmp !== 0) return $cmp;
			}
			return strcasecmp($a['name'], $b['name']);
		});

		// Truncation.
		$truncated = false;
		if (count($rows) > $max_rows) {
			$truncated = true;
			$rows = array_slice($rows, 0, $max_rows);
		}

		$this->respond($name, [
			'rows'        => $rows,
			'summary'     => $summary,
			'error'       => null,
			'truncated'   => $truncated,
			'truncated_at'=> $truncated ? $max_rows : null,
			'host_label'  => $host_label,
			'flags'       => $this->actionFlags(),
			'urls'        => $this->urlFields(),
			'config'      => $this->configFields(),
			'debug'       => $debug,
			'show_debug'  => $show_debug,
		]);
	}

	/** Action flags computed once and applied client-side AND server-side. */
	private function actionFlags(): array {
		$is_admin = in_array(CWebUser::getType(), [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN]);
		return [
			'is_admin'        => $is_admin,
			'enable_refresh'  => $is_admin && (bool) ($this->fields_values['enable_refresh'] ?? false),
			'enable_reboot'   => $is_admin && (bool) ($this->fields_values['enable_reboot']  ?? false),
			'enable_manage'   => $is_admin && (bool) ($this->fields_values['enable_manage']  ?? false),
			'enable_cli'      => $is_admin && (bool) ($this->fields_values['enable_cli']     ?? false),
		];
	}

	private function urlFields(): array {
		// Trim only trailing slashes from the host; leave the path template
		// alone — it's interpolated client-side and may legitimately end
		// in something other than the device ID placeholder.
		$path = (string) ($this->fields_values['xiq_admin_path'] ?? '');
		if ($path === '') {
			$path = WidgetForm::DEFAULT_XIQ_ADMIN_PATH;
		}
		return [
			'xiq_url'        => rtrim((string) ($this->fields_values['xiq_url']       ?? ''), '/'),
			'xiq_admin_url'  => rtrim((string) ($this->fields_values['xiq_admin_url'] ?? ''), '/'),
			'xiq_admin_path' => $path,
		];
	}

	/**
	 * Non-secret config the JS needs to forward back to WidgetAction.php.
	 * Note: action_token_path is a path, not a value — even if a malicious
	 * client tampers with it, WidgetAction.php enforces the prefix allowlist.
	 */
	private function configFields(): array {
		$allowlist_raw = (string) ($this->fields_values['cli_allowlist'] ?? '');
		$allowlist = [];
		foreach (preg_split('/\R+/', $allowlist_raw) as $line) {
			$line = trim($line);
			if ($line !== '' && str_starts_with($line, 'show ')) {
				$allowlist[] = $line;
			}
		}
		return [
			'hostid'            => (int) (($this->fields_values['hostids'] ?? [0])[0] ?? 0),
			'action_token_path' => (string) ($this->fields_values['action_token_path'] ?? ''),
			'verify_ssl'        => (bool)   ($this->fields_values['verify_ssl']        ?? true),
			'cli_allowlist'     => $allowlist,
		];
	}

	private static function emptySummary(): array {
		return [
			'total'         => 0,
			'connected'     => 0,
			'disconnected'  => 0,
			'cfg_mismatch'  => 0,
			'clients_total' => 0,
		];
	}

	private function respond(string $name, array $data): void {
		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'data' => $data,
			'user' => ['debug_mode' => $this->getDebugMode()],
		]));
	}
}
