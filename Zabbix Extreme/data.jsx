// Mock data simulating Zabbix + PacketFence integration
// All data is fictional but plausible for a Tuscaloosa City Schools deployment

const ZBX_HOST = {
  hostid: "10847",
  host: "BHS-56-Hallway",
  visible_name: "BHS-56-Hallway",
  ip: "172.16.97.59",
  status: "monitored",
  available: 1, // 1 = available, 2 = unavailable
  maintenance: 0,
  proxy: "zbx-proxy-tcs-01",
  templates: ["Extreme AP via SNMPv3", "ICMP Ping", "PacketFence NAC Integration"],
  groups: ["Wireless/Bryant HS", "Wireless/All APs", "Site/BHS"],
  uptime: 691740, // seconds — ~8 days
  lastSeen: "now",
};

const ZBX_ITEMS = {
  cpu: { value: 1, prev: 2, unit: "%", trigger: 80, history: gen(48, 1, 4) },
  memory: { value: 43, prev: 42, unit: "%", trigger: 85, history: gen(48, 40, 48) },
  temp: { value: 47.2, prev: 46.8, unit: "°C", trigger: 75, history: gen(48, 44, 50) },
  poeDraw: { value: 12.4, prev: 12.3, unit: "W", trigger: 25.5, history: gen(48, 11, 14) },
  uplinkIn: { value: 184.2, unit: "Mbps", history: gen(48, 80, 240) },
  uplinkOut: { value: 41.8, unit: "Mbps", history: gen(48, 20, 90) },
  pktLoss: { value: 0.02, unit: "%", trigger: 1.0, history: gen(48, 0, 0.3) },
  latency: { value: 1.4, unit: "ms", trigger: 50, history: gen(48, 0.8, 3.2) },
  noise24: { value: -94, unit: "dBm", history: gen(48, -96, -88) },
  noise5: { value: -98, unit: "dBm", history: gen(48, -100, -92) },
  channelUtil24: { value: 38, unit: "%", history: gen(48, 25, 60) },
  channelUtil5: { value: 22, unit: "%", history: gen(48, 12, 35) },
};

function gen(n, lo, hi) {
  const arr = [];
  let v = (lo + hi) / 2;
  for (let i = 0; i < n; i++) {
    v += (Math.random() - 0.5) * (hi - lo) * 0.25;
    v = Math.max(lo, Math.min(hi, v));
    arr.push(Number(v.toFixed(2)));
  }
  return arr;
}

const SYSTEM_INFO = [
  ["Host Name", "BHS-56-Hallway", "zbx"],
  ["Visible Name", "BHS 1F Hallway · Rm 100s", "zbx"],
  ["Network Policy", "TCS-Production", "ext"],
  ["SSID", "TCS-Wireless · TCS-Guest-Registration · TCS-VIPGuest", "ext"],
  ["Device Model", "AP_305C", "ext"],
  ["Function", "Access Point", "ext"],
  ["Device Template", "Extreme AP via SNMPv3", "zbx"],
  ["Configuration Type", "DHCP_CLIENT_WITHOUT_FALLBACK", "ext"],
  ["Serial Number", "03051910292405", "ext"],
  ["IQ Engine", "10.7r5b · build c6be9b0", "ext"],
];

const NETWORK_INFO = [
  ["Device Status", "MANAGED", "zbx", "ok"],
  ["Mgt0 IPv4", "172.16.97.59 / 22", "zbx"],
  ["Mgt0 IPv6", "—", "zbx"],
  ["IPv4 Default Gateway", "172.16.97.1", "zbx"],
  ["DNS", "10.10.1.177 · 10.10.1.178", "zbx"],
  ["NTP", "0.aerohive.pool.ntp.org", "ext"],
  ["MAC Address", "BC:F3:10:04:C0:40", "zbx"],
  ["LLDP Neighbor", "BHS-CORE-SW01 · Gi1/0/56", "zbx"],
  ["Uplink VLAN", "97 (mgmt)", "zbx"],
  ["Last Config Push", "2026-04-29 14:02:11", "ext"],
];

// PacketFence NAC clients
const PF_CLIENTS = [
  { mac: "A4:83:E7:91:2C:14", host: "BHS-CHROME-1142", user: "j.harris@tcs", role: "Student-9-12", vlan: 110, ssid: "TCS-Wireless", auth: "EAP-TLS", posture: "compliant", since: "2h 14m", rssi: -52, rate: "433 Mbps", band: "5 GHz", os: "ChromeOS 122", ip: "10.110.42.18" },
  { mac: "F0:18:98:4A:11:D2", host: "MacBook-Air-Davis", user: "k.davis@tcs", role: "Faculty", vlan: 100, ssid: "TCS-Wireless", auth: "EAP-TLS", posture: "compliant", since: "6h 02m", rssi: -48, rate: "866 Mbps", band: "5 GHz", os: "macOS 14.4", ip: "10.100.18.73" },
  { mac: "DC:A6:32:88:09:7E", host: "iPad-A115", user: "—", role: "Student-BYOD", vlan: 130, ssid: "TCS-Wireless", auth: "PEAP", posture: "compliant", since: "1h 47m", rssi: -61, rate: "300 Mbps", band: "5 GHz", os: "iPadOS 17", ip: "10.130.88.12" },
  { mac: "8C:85:90:22:11:AF", host: "BHS-CHROME-0884", user: "m.thompson@tcs", role: "Student-9-12", vlan: 110, ssid: "TCS-Wireless", auth: "EAP-TLS", posture: "compliant", since: "3h 28m", rssi: -57, rate: "390 Mbps", band: "5 GHz", os: "ChromeOS 122", ip: "10.110.42.41" },
  { mac: "3C:22:FB:7E:01:09", host: "iPhone-15-Wilson", user: "guest:wilson", role: "Guest-Registered", vlan: 200, ssid: "TCS-Guest-Registration", auth: "Captive", posture: "n/a", since: "32m", rssi: -68, rate: "144 Mbps", band: "5 GHz", os: "iOS 17.4", ip: "172.20.4.91" },
  { mac: "B8:27:EB:44:55:99", host: "RPi-AVCart-12", user: "system", role: "AV-Equipment", vlan: 150, ssid: "TCS-Wireless", auth: "MAC-Auth", posture: "compliant", since: "7d 22h", rssi: -54, rate: "72 Mbps", band: "2.4 GHz", os: "Linux", ip: "10.150.7.12" },
  { mac: "F4:5C:89:0B:32:71", host: "—", user: "—", role: "Quarantine", vlan: 666, ssid: "TCS-Wireless", auth: "EAP-TLS", posture: "non-compliant", since: "8m", rssi: -72, rate: "78 Mbps", band: "2.4 GHz", os: "Android 13", ip: "172.30.1.4", flag: "outdated-os" },
  { mac: "00:1B:63:84:45:E6", host: "BHS-CHROME-1408", user: "a.brown@tcs", role: "Student-9-12", vlan: 110, ssid: "TCS-Wireless", auth: "EAP-TLS", posture: "compliant", since: "4h 12m", rssi: -63, rate: "200 Mbps", band: "5 GHz", os: "ChromeOS 122", ip: "10.110.42.88" },
  { mac: "9C:8E:CD:11:B0:42", host: "—", user: "—", role: "Unknown", vlan: 999, ssid: "—", auth: "Failed", posture: "rejected", since: "14m", rssi: -78, rate: "—", band: "2.4 GHz", os: "—", ip: "—", flag: "auth-failed" },
];

const PF_AUTH_FAILS = [
  { ts: "09:38:14", mac: "9C:8E:CD:11:B0:42", reason: "EAP-TLS · cert expired", ssid: "TCS-Wireless", attempts: 12 },
  { ts: "09:31:02", mac: "F4:5C:89:0B:32:71", reason: "Posture · OS version below policy", ssid: "TCS-Wireless", attempts: 1 },
  { ts: "08:14:55", mac: "5A:11:88:CC:42:01", reason: "Username unknown in AD", ssid: "TCS-Wireless", attempts: 4 },
  { ts: "07:52:18", mac: "A0:CE:C8:9E:7D:31", reason: "EAP-TLS · cert revoked", ssid: "TCS-Wireless", attempts: 2 },
  { ts: "07:11:42", mac: "9C:8E:CD:11:B0:42", reason: "EAP-TLS · cert expired", ssid: "TCS-Wireless", attempts: 8 },
  { ts: "06:58:07", mac: "DA:A1:19:42:F0:11", reason: "MAC not in registered devices", ssid: "TCS-Wireless", attempts: 1 },
  { ts: "Yesterday 22:14", mac: "F4:5C:89:0B:32:71", reason: "Posture · missing AV signature", ssid: "TCS-Wireless", attempts: 3 },
];

const ZBX_EVENTS = [
  { ts: "09:31:02", severity: "warning", source: "PacketFence", obj: "F4:5C:89:0B:32:71", msg: "Client moved to quarantine VLAN 666 (posture non-compliant)" },
  { ts: "09:14:58", severity: "info", source: "Zabbix", obj: "BHS-56-Hallway", msg: "Channel utilization 5GHz crossed 30% (current: 32%)" },
  { ts: "08:47:11", severity: "info", source: "Zabbix", obj: "BHS-56-Hallway", msg: "Client count peak: 271 associated clients" },
  { ts: "07:11:42", severity: "warning", source: "PacketFence", obj: "9C:8E:CD:11:B0:42", msg: "8 consecutive auth failures from same MAC" },
  { ts: "06:02:00", severity: "info", source: "Zabbix", obj: "BHS-56-Hallway", msg: "Daily config backup pulled from ExtremeCloud IQ" },
  { ts: "Yesterday 23:48", severity: "info", source: "Zabbix", obj: "BHS-56-Hallway", msg: "Memory usage normalized (was 78%, now 41%)" },
  { ts: "Yesterday 23:42", severity: "warning", source: "Zabbix", obj: "BHS-56-Hallway", msg: "Memory usage above 75% threshold (peak 78%)" },
  { ts: "Yesterday 18:14", severity: "info", source: "PacketFence", obj: "Faculty role", msg: "After-hours connection from k.davis@tcs (whitelisted)" },
];

const ALERTS_SUMMARY = {
  associationFailures: 0,
  authFailures: 7,
  networkIssues: 0,
  packetLoss: 0,
  totalClients: 271,
  activeClients: 9,
};

const WIRED_PORTS = [
  { name: "eth0 (uplink)", state: "up", speed: "2.5 Gbps", duplex: "full", poe: "—", in: "184.2 Mbps", out: "41.8 Mbps", err: 0, neighbor: "BHS-CORE-SW01 Gi1/0/56" },
  { name: "eth1", state: "down", speed: "—", duplex: "—", poe: "—", in: "—", out: "—", err: 0, neighbor: "—" },
];

window.ZBX_HOST = ZBX_HOST;
window.ZBX_ITEMS = ZBX_ITEMS;
window.SYSTEM_INFO = SYSTEM_INFO;
window.NETWORK_INFO = NETWORK_INFO;
window.PF_CLIENTS = PF_CLIENTS;
window.PF_AUTH_FAILS = PF_AUTH_FAILS;
window.ZBX_EVENTS = ZBX_EVENTS;
window.ALERTS_SUMMARY = ALERTS_SUMMARY;
window.WIRED_PORTS = WIRED_PORTS;
