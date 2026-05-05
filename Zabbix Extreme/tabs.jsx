// Per-tab views

const SectionTitle = ({ children, src }) => (
  <h2 className="section-title">
    {children}
    {src && <SourceBadge src={src} />}
  </h2>
);

// ───────── Overview tab ─────────
const OverviewTab = ({ density }) => {
  const A = window.ALERTS_SUMMARY;
  const I = window.ZBX_ITEMS;
  return (
    <div className="overview">
      {/* Health rings + Connectivity issues + Excessive packet loss */}
      <div className="row" style={{ gridTemplateColumns: "1.4fr 1fr .9fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h">
            <h3>Device Health</h3>
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">polling every 60s · template Extreme AP via SNMPv3</span>
          </div>
          <div className="health-grid">
            <HealthRing label="CPU Usage"     value={I.cpu.value}    color={I.cpu.value > I.cpu.trigger ? "var(--warn)" : "var(--zbx)"} sub={`prev ${I.cpu.prev}%`} />
            <HealthRing label="Memory Usage"  value={I.memory.value} color="var(--info)" sub={`peak 78%`} />
            <HealthRing label="PoE Draw"      value={I.poeDraw.value} max={25.5} color="var(--ok)" unit="W" sub={`of 25.5W`} />
            <HealthRing label="Temperature"   value={I.temp.value}    max={75} color={I.temp.value > 60 ? "var(--warn)" : "var(--ok)"} unit="°C" sub={`${I.temp.value}°C`} />
          </div>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>Connectivity Issues</h3>
            <SourceBadge src="pf" />
            <div className="h-spacer" />
            <span className="h-meta">Total Clients: <b style={{ color: "var(--fg)" }}>{A.totalClients}</b></span>
          </div>
          <div className="issues">
            <Issue n={A.associationFailures} label="Association Failures" tone="ok" icon="check" />
            <Issue n={A.authFailures}        label="Authentication Failures" tone="warn" icon="alert" />
            <Issue n={A.networkIssues}       label="Network Issues" tone="ok" icon="check" />
          </div>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>Excessive Packet Loss</h3>
            <SourceBadge src="zbx" />
          </div>
          <div className="issues" style={{ gridTemplateColumns: "1fr" }}>
            <Issue n={0} label="Packet Loss Events (24h)" tone="ok" icon="check" big />
          </div>
        </div>
      </div>

      {/* Live throughput + radios */}
      <div className="row" style={{ gridTemplateColumns: "1fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h">
            <h3>Live Telemetry</h3>
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">last 24h · {I.uplinkIn.history.length} samples</span>
            <span className="h-link">Open in Grafana <Icon name="external" size={11} /></span>
          </div>
          <div className="spark-strip">
            <SparkCell label="Uplink In"  value={I.uplinkIn.value}  unit="Mbps" data={I.uplinkIn.history}  color="var(--zbx)" />
            <SparkCell label="Uplink Out" value={I.uplinkOut.value} unit="Mbps" data={I.uplinkOut.history} color="var(--info)" />
            <SparkCell label="Latency"    value={I.latency.value}   unit="ms"   data={I.latency.history}   color="var(--ok)" />
            <SparkCell label="Pkt Loss"   value={I.pktLoss.value}   unit="%"    data={I.pktLoss.history}   color="var(--warn)" />
          </div>
          <div className="spark-strip" style={{ borderTop: "1px solid var(--line)" }}>
            <SparkCell label="Ch Util 2.4 GHz" value={I.channelUtil24.value} unit="%" data={I.channelUtil24.history} color="var(--pf)" />
            <SparkCell label="Ch Util 5 GHz"   value={I.channelUtil5.value}  unit="%" data={I.channelUtil5.history}  color="var(--pf)" />
            <SparkCell label="Noise 2.4 GHz"   value={I.noise24.value}       unit="dBm" data={I.noise24.history}     color="var(--info)" />
            <SparkCell label="Noise 5 GHz"     value={I.noise5.value}        unit="dBm" data={I.noise5.history}      color="var(--info)" />
          </div>
        </div>
      </div>

      {/* System Info + Network Info */}
      <div className="row" style={{ gridTemplateColumns: "1fr 1fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h"><h3>System Information</h3><div className="h-spacer" /><span className="h-meta">merged from Zabbix host + ExtremeCloud IQ</span></div>
          <div className="kv">
            {window.SYSTEM_INFO.map(([k, v, src]) => (
              <React.Fragment key={k}>
                <div className="k">{k}</div>
                <div className="v">{v}</div>
                <div className="b"><SourceBadge src={src} /></div>
              </React.Fragment>
            ))}
          </div>
        </div>

        <div className="card">
          <div className="card-h"><h3>Network Information</h3><div className="h-spacer" /><span className="h-meta">SNMPv3 · 172.16.97.59</span></div>
          <div className="kv">
            {window.NETWORK_INFO.map(([k, v, src]) => (
              <React.Fragment key={k}>
                <div className="k">{k}</div>
                <div className="v">
                  {k === "Device Status"
                    ? <><StatusDot state="ok" /> {v}</>
                    : v}
                </div>
                <div className="b"><SourceBadge src={src} /></div>
              </React.Fragment>
            ))}
          </div>
        </div>
      </div>

      {/* Recent events tail */}
      <div className="card">
        <div className="card-h">
          <h3>Recent Events</h3>
          <div className="h-spacer" />
          <span className="h-meta">live merge: Zabbix triggers + PacketFence audit</span>
          <span className="h-link">Open events log <Icon name="external" size={11} /></span>
        </div>
        <div className="events">
          {window.ZBX_EVENTS.slice(0, 6).map((e, i) => (
            <div className="event" key={i}>
              <div className="ts">{e.ts}</div>
              <div className={`src ${e.source === "Zabbix" ? "zbx" : "pf"}`}>{e.source === "Zabbix" ? "ZBX" : "PF"}</div>
              <Sev level={e.severity} />
              <div className="msg">{e.msg} <span className="obj">· {e.obj}</span></div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

const HealthRing = ({ label, value, color, sub, max = 100, unit = "%" }) => (
  <div className="health-cell">
    <Ring value={value} max={max} color={color} label={`${value}${unit === "%" ? "%" : ""}`} sub={unit !== "%" ? `${unit}` : null} />
    <div className="h-label">{label}</div>
  </div>
);

const Issue = ({ n, label, tone, icon, big }) => (
  <div className={`issue ${tone}`}>
    <div className="ico"><Icon name={icon} size={16} /></div>
    <div className="num" style={big ? { fontSize: 22 } : {}}>{n}</div>
    <div className="lbl">{label}</div>
  </div>
);

const SparkCell = ({ label, value, unit, data, color }) => (
  <div className="spark-cell">
    <div className="lbl">{label}</div>
    <div className="val">{value}<span className="u">{unit}</span></div>
    <Sparkline data={data} color={color} width={240} height={30} />
  </div>
);

// ───────── Wireless tab ─────────
const WirelessTab = () => {
  const I = window.ZBX_ITEMS;
  return (
    <div className="row" style={{ gridTemplateColumns: "1fr 1fr", gap: 14 }}>
      <RadioCard band="2.4 GHz" channel="6 / HT20" txPower="14 dBm" util={I.channelUtil24} noise={I.noise24} clients={42} />
      <RadioCard band="5 GHz"   channel="44 / VHT80" txPower="20 dBm" util={I.channelUtil5} noise={I.noise5} clients={229} />
      <div className="card" style={{ gridColumn: "1 / -1" }}>
        <div className="card-h"><h3>SSIDs Broadcast</h3><SourceBadge src="ext" /><div className="h-spacer"/><span className="h-meta">applied via TCS-Production policy</span></div>
        <table className="tbl">
          <thead><tr><th>SSID</th><th>VLAN</th><th>Auth</th><th>Encryption</th><th>Band</th><th>Clients</th><th>NAC Role</th></tr></thead>
          <tbody>
            <tr><td className="fg">TCS-Wireless</td><td>100/110/130/150</td><td>WPA2-Enterprise · EAP-TLS</td><td>AES</td><td>2.4 + 5</td><td>248</td><td><span className="role-tag faculty">role-based</span></td></tr>
            <tr><td className="fg">TCS-Guest-Registration</td><td>200</td><td>Captive Portal</td><td>Open + HTTPS</td><td>5</td><td>22</td><td><span className="role-tag guest">Guest-Registered</span></td></tr>
            <tr><td className="fg">TCS-VIPGuest</td><td>201</td><td>WPA2-PSK (rotated weekly)</td><td>AES</td><td>5</td><td>1</td><td><span className="role-tag guest">VIPGuest</span></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  );
};

const RadioCard = ({ band, channel, txPower, util, noise, clients }) => (
  <div className="card">
    <div className="card-h">
      <h3>Radio · {band}</h3>
      <SourceBadge src="zbx" />
      <div className="h-spacer" />
      <span className="h-meta">{channel} · {txPower}</span>
    </div>
    <div className="card-b" style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
      <MiniMetric label="Channel Utilization" v={util.value} unit="%" data={util.history} threshold={70} color="var(--pf)" />
      <MiniMetric label="Noise Floor" v={noise.value} unit="dBm" data={noise.history} color="var(--info)" />
      <MiniMetric label="Associated Clients" v={clients} unit="" color="var(--ok)" />
      <MiniMetric label="Retry Rate" v={2.4} unit="%" data={I_gen()} color="var(--warn)" />
    </div>
  </div>
);

function I_gen() {
  const arr = []; let v = 2.5;
  for (let i = 0; i < 48; i++) { v += (Math.random() - 0.5) * 0.6; v = Math.max(0.5, Math.min(6, v)); arr.push(Number(v.toFixed(2))); }
  return arr;
}

const MiniMetric = ({ label, v, unit, data, color, threshold }) => (
  <div style={{ background: "var(--bg-2)", borderRadius: 8, padding: 12, border: "1px solid var(--line)" }}>
    <div style={{ fontSize: 10, color: "var(--muted)", textTransform: "uppercase", letterSpacing: 0.5, marginBottom: 6 }}>{label}</div>
    <div style={{ fontFamily: "var(--mono)", fontSize: 20, fontWeight: 600 }}>
      {v}<span style={{ fontSize: 11, color: "var(--muted)", marginLeft: 3 }}>{unit}</span>
    </div>
    {data && <Sparkline data={data} color={color} width={240} height={28} threshold={threshold} />}
  </div>
);

// ───────── Wired tab ─────────
const WiredTab = () => (
  <div className="row" style={{ gridTemplateColumns: "1fr", gap: 14 }}>
    <div className="card">
      <div className="card-h"><h3>Wired Interfaces</h3><SourceBadge src="zbx" /><div className="h-spacer"/><span className="h-meta">SNMP IF-MIB poll · 30s</span></div>
      <table className="tbl">
        <thead><tr><th>Port</th><th>State</th><th>Speed/Duplex</th><th>In</th><th>Out</th><th>Errors</th><th>LLDP Neighbor</th></tr></thead>
        <tbody>
          {window.WIRED_PORTS.map(p => (
            <tr key={p.name}>
              <td className="fg">{p.name}</td>
              <td><StatusDot state={p.state}/> <span style={{textTransform:"uppercase"}}>{p.state}</span></td>
              <td>{p.speed} · {p.duplex}</td>
              <td>{p.in}</td>
              <td>{p.out}</td>
              <td>{p.err}</td>
              <td>{p.neighbor}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    <div className="card">
      <div className="card-h"><h3>PoE Power Budget</h3><SourceBadge src="zbx" /></div>
      <div className="card-b">
        <div style={{ display: "flex", alignItems: "center", gap: 16, marginBottom: 12 }}>
          <div style={{ fontFamily: "var(--mono)", fontSize: 28, fontWeight: 600 }}>12.4<span style={{ fontSize: 14, color: "var(--muted)" }}> / 25.5 W</span></div>
          <div style={{ flex: 1, background: "var(--bg-2)", borderRadius: 4, height: 8, overflow: "hidden", border: "1px solid var(--line)" }}>
            <div style={{ width: `${(12.4/25.5)*100}%`, height: "100%", background: "linear-gradient(90deg, var(--ok), var(--pf))" }} />
          </div>
          <div style={{ fontFamily: "var(--mono)", fontSize: 11, color: "var(--muted)" }}>49% of budget · Class 4 (802.3at)</div>
        </div>
      </div>
    </div>
  </div>
);

// ───────── Clients tab (PacketFence-driven) ─────────
const ClientsTab = ({ filter, setFilter }) => {
  const all = window.PF_CLIENTS;
  const filtered = all.filter(c => {
    if (filter === "all") return true;
    if (filter === "issues") return c.posture !== "compliant" && c.posture !== "n/a";
    if (filter === "students") return c.role.includes("Student");
    if (filter === "faculty") return c.role === "Faculty";
    if (filter === "guests") return c.role.includes("Guest");
    return true;
  });
  return (
    <div>
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h">
          <h3>Connected Clients</h3>
          <SourceBadge src="pf" />
          <div className="h-spacer" />
          <div style={{ display: "flex", gap: 4 }}>
            {[["all","All"],["issues","Issues"],["students","Students"],["faculty","Faculty"],["guests","Guests"]].map(([k,l]) =>
              <button key={k} className={`btn sm ${filter===k?"primary":"ghost"}`} onClick={()=>setFilter(k)}>{l}</button>
            )}
          </div>
        </div>
        <table className="tbl">
          <thead>
            <tr>
              <th>Status</th>
              <th>MAC / Hostname</th>
              <th>User</th>
              <th>NAC Role</th>
              <th>VLAN</th>
              <th>SSID / Auth</th>
              <th>RSSI</th>
              <th>Rate</th>
              <th>OS</th>
              <th>Connected</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {filtered.map(c => <ClientRow key={c.mac} c={c} />)}
          </tbody>
        </table>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>Recent Authentication Failures</h3>
          <SourceBadge src="pf" />
          <div className="h-spacer" />
          <span className="h-meta">RADIUS audit · last 24h</span>
        </div>
        <table className="tbl">
          <thead><tr><th>Time</th><th>Client MAC</th><th>SSID</th><th>Reason</th><th>Attempts</th></tr></thead>
          <tbody>
            {window.PF_AUTH_FAILS.map((f, i) => (
              <tr key={i}>
                <td>{f.ts}</td>
                <td className="fg">{f.mac}</td>
                <td>{f.ssid}</td>
                <td style={{ color: "var(--warn)" }}>{f.reason}</td>
                <td>{f.attempts}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

const ClientRow = ({ c }) => {
  const roleClass = (() => {
    if (c.role === "Faculty") return "faculty";
    if (c.role.startsWith("Student-9-12")) return "student";
    if (c.role === "Student-BYOD") return "byod";
    if (c.role.includes("Guest")) return "guest";
    if (c.role === "AV-Equipment") return "av";
    if (c.role === "Quarantine") return "quarantine";
    return "unknown";
  })();
  const bars = c.rssi >= -55 ? 4 : c.rssi >= -65 ? 3 : c.rssi >= -75 ? 2 : 1;
  return (
    <tr>
      <td><StatusDot state={c.posture} /></td>
      <td><div className="fg">{c.host}</div><div style={{ color: "var(--muted)", fontSize: 10.5 }}>{c.mac}</div></td>
      <td>{c.user}</td>
      <td><span className={`role-tag ${roleClass}`}>{c.role}</span></td>
      <td>{c.vlan}</td>
      <td>{c.ssid}<div style={{ color: "var(--muted)", fontSize: 10.5 }}>{c.auth}</div></td>
      <td>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <span className="rssi-bar">
            {[1,2,3,4].map(n => <i key={n} className={n <= bars ? "on" : ""} />)}
          </span>
          {c.rssi} dBm
        </div>
      </td>
      <td>{c.rate}<div style={{ color: "var(--muted)", fontSize: 10.5 }}>{c.band}</div></td>
      <td>{c.os}</td>
      <td>{c.since}</td>
      <td><Icon name="more" size={14}/></td>
    </tr>
  );
};

// ───────── Events tab ─────────
const EventsTab = () => (
  <div className="card">
    <div className="card-h">
      <h3>All Events</h3>
      <div className="h-spacer" />
      <span className="h-meta">unified Zabbix triggers + PacketFence audit log</span>
    </div>
    <div className="events">
      {window.ZBX_EVENTS.map((e, i) => (
        <div className="event" key={i}>
          <div className="ts">{e.ts}</div>
          <div className={`src ${e.source === "Zabbix" ? "zbx" : "pf"}`}>{e.source === "Zabbix" ? "ZBX" : "PF"}</div>
          <Sev level={e.severity} />
          <div className="msg">{e.msg} <span className="obj">· {e.obj}</span></div>
        </div>
      ))}
    </div>
  </div>
);

// ───────── Alerts tab ─────────
const AlertsTab = () => (
  <div className="row" style={{ gridTemplateColumns: "1fr 1fr", gap: 14 }}>
    <div className="card">
      <div className="card-h"><h3>Active Triggers</h3><SourceBadge src="zbx" /></div>
      <div style={{ padding: 30, textAlign: "center", color: "var(--muted)" }}>
        <Icon name="check" size={32} />
        <div style={{ marginTop: 8, fontSize: 14, color: "var(--ok)" }}>No active Zabbix triggers</div>
        <div style={{ fontSize: 11, marginTop: 4 }}>4 triggers monitored · last fired 10h ago</div>
      </div>
    </div>
    <div className="card">
      <div className="card-h"><h3>NAC Violations (24h)</h3><SourceBadge src="pf" /></div>
      <div style={{ padding: 18 }}>
        <div style={{ display: "flex", gap: 10, alignItems: "center", marginBottom: 10 }}>
          <div style={{ width: 8, height: 8, borderRadius: 50, background: "var(--warn)" }} />
          <div style={{ fontFamily: "var(--mono)", fontSize: 16, fontWeight: 600 }}>2 active</div>
          <div className="muted" style={{ fontSize: 11 }}>· 7 resolved</div>
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          <ViolationRow mac="F4:5C:89:0B:32:71" rule="OS version below policy" since="14m" action="Quarantine VLAN 666" />
          <ViolationRow mac="9C:8E:CD:11:B0:42" rule="Repeated EAP cert failures" since="14m" action="Auth blocked" />
        </div>
      </div>
    </div>
  </div>
);

const ViolationRow = ({ mac, rule, since, action }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 10, padding: 10, background: "var(--bg-2)", borderRadius: 6, border: "1px solid var(--line)" }}>
    <Icon name="alert" size={14} />
    <div style={{ flex: 1 }}>
      <div className="mono" style={{ fontSize: 12 }}>{mac}</div>
      <div style={{ fontSize: 11, color: "var(--muted)" }}>{rule} · {action}</div>
    </div>
    <div className="mono muted" style={{ fontSize: 11 }}>{since}</div>
  </div>
);

window.OverviewTab = OverviewTab;
window.WirelessTab = WirelessTab;
window.WiredTab = WiredTab;
window.ClientsTab = ClientsTab;
window.EventsTab = EventsTab;
window.AlertsTab = AlertsTab;
