// Main app shell

const Sidebar = ({ tab, setTab }) => (
  <aside className="sidebar">
    <div className="brand">
      <div className="brand-mark">Z·P</div>
      <div>
        <div className="brand-name">Zabbix · TCS</div>
        <div className="brand-sub">+ PacketFence NAC</div>
      </div>
    </div>

    <div className="nav-section">
      <div className="nav-label">Monitoring</div>
      <div className="nav-item"><Icon name="map" /> Dashboards</div>
      <div className="nav-item"><Icon name="ap" /> Hosts <span className="nav-count">2,418</span></div>
      <div className="nav-item active"><Icon name="wifi" /> Wireless APs <span className="nav-count">1,184</span></div>
      <div className="nav-item"><Icon name="ethernet" /> Switches <span className="nav-count">312</span></div>
      <div className="nav-item"><Icon name="alert" /> Problems <span className="nav-count warn">23</span></div>
      <div className="nav-item"><Icon name="events" /> Events</div>
    </div>

    <div className="nav-section">
      <div className="nav-label">Identity (PacketFence)</div>
      <div className="nav-item"><Icon name="clients" /> Connected Devices <span className="nav-count">12,847</span></div>
      <div className="nav-item"><Icon name="shield" /> NAC Policies</div>
      <div className="nav-item"><Icon name="user" /> User Sessions</div>
      <div className="nav-item"><Icon name="lock" /> Quarantine <span className="nav-count warn">2</span></div>
    </div>

    <div className="nav-section">
      <div className="nav-label">Sites</div>
      <div className="nav-item">Bryant High School</div>
      <div className="nav-item muted">Central High School</div>
      <div className="nav-item muted">Northridge High School</div>
      <div className="nav-item muted">+ 23 more sites</div>
    </div>

    <div className="sidebar-footer">
      <div className="row"><span>Zabbix Server</span><span className="ok">● 7.0.4</span></div>
      <div className="row"><span>PacketFence API</span><span className="ok">● v12.3</span></div>
      <div className="row"><span>Proxy zbx-proxy-tcs-01</span><span className="ok">● online</span></div>
    </div>
  </aside>
);

const Topbar = ({ onCmdK }) => (
  <div className="topbar">
    <div className="icon-btn" title="Back"><Icon name="back" /></div>
    <div className="crumb">
      <span>Wireless APs</span>
      <span className="sep">/</span>
      <span>Bryant HS</span>
      <span className="sep">/</span>
      <span>1st Floor</span>
      <span className="sep">/</span>
      <span className="seg">BHS-56-Hallway</span>
    </div>
    <div className="spacer" />
    <div className="search" onClick={onCmdK}>
      <Icon name="search" />
      <input placeholder="Find host, MAC, user, IP…" readOnly />
      <kbd>⌘K</kbd>
    </div>
    <div className="icon-btn" title="Refresh"><Icon name="refresh" /></div>
    <div className="icon-btn" title="More"><Icon name="more" /></div>
  </div>
);

const PageHeader = ({ timeRange, setTimeRange, host }) => (
  <div className="page-header">
    <div className="icon-btn" style={{ marginTop: 4 }}><Icon name="back" /></div>
    <div style={{ flex: 1 }}>
      <div className="host-title">
        <h1>{host.host}</h1>
        <span className="ip">{host.ip}</span>
        <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>AP_305C</span>
      </div>
      <div className="host-meta">
        <span className="pill"><span className="dot" style={{ background: "var(--ok)" }} /> Connected</span>
        <span className="pill"><span className="lbl">Active since</span> <span className="v">7d 23h 49m</span></span>
        <span className="pill"><span className="lbl">Site</span> <span>Bryant High School · 1st Floor</span></span>
        <span className="pill"><span className="lbl">Zabbix Host ID</span> <span className="v">10847</span></span>
        <span className="pill"><span className="lbl">PF Switch ID</span> <span className="v">{host.ip}</span></span>
        <span className="pill"><span className="lbl">Polled via</span> <span>{host.proxy}</span></span>
      </div>
    </div>
    <div className="timerange">
      <Icon name="calendar" />
      <span className="range-val">{timeRange}</span>
      <Icon name="chevron" />
    </div>
  </div>
);

const Tabs = ({ tab, setTab }) => {
  const tabs = [
    ["overview", "Overview", null],
    ["wireless", "Wireless", null],
    ["wired", "Wired", null],
    ["clients", "Clients", "271"],
    ["events", "Events", null],
    ["alerts", "Alerts", "2"],
    ["graphs", "Graphs", null],
    ["latest", "Latest Data", null],
    ["config", "Configuration", null],
  ];
  return (
    <div className="tabs">
      {tabs.map(([k, l, b]) => (
        <div key={k} className={`tab ${tab === k ? "active" : ""}`} onClick={() => setTab(k)}>
          {l}
          {b && <span className={`badge ${k === "alerts" ? "warn" : ""}`}>{b}</span>}
        </div>
      ))}
    </div>
  );
};

const DeviceSidecar = ({ host }) => (
  <div className="card device-card">
    <div className="device-hero">
      <div className="status-line">
        <StatusDot state="ok" /> <span style={{ color: "var(--ok)" }}>Connected</span>
        <span className="muted" style={{ marginLeft: 6 }}>· active 7d 23h</span>
      </div>
      <div className="device-img">
        {/* Stylized AP illustration */}
        <svg width="60" height="60" viewBox="0 0 60 60">
          <ellipse cx="30" cy="46" rx="22" ry="4" fill="rgba(0,0,0,0.3)" />
          <rect x="6" y="22" width="48" height="20" rx="10" fill="#e8ecf4" />
          <rect x="6" y="22" width="48" height="6" rx="10" fill="#f4f7fc" />
          <circle cx="30" cy="32" r="3" fill="#181f2c" />
          <circle cx="30" cy="32" r="1" fill="var(--ok)" />
        </svg>
      </div>
      <div className="device-name">{host.host}</div>
      <div className="uptime">uptime · 8d 03h 12m</div>
    </div>

    <div className="floorplan">
      <div className="floorplan-tag">Bryant HS · 1st Floor</div>
      {/* Synthetic floor plan */}
      <svg width="100%" height="100%" viewBox="0 0 280 160" style={{ position: "absolute", inset: 0 }}>
        <g stroke="#2c3650" strokeWidth="1" fill="none">
          {/* outer wall */}
          <path d="M20 30 L260 30 L260 130 L180 130 L180 140 L60 140 L60 130 L20 130 Z" />
          {/* corridor */}
          <path d="M20 80 L260 80" />
          {/* room dividers */}
          <path d="M60 30 L60 80 M100 30 L100 80 M140 30 L140 80 M180 30 L180 80 M220 30 L220 80" />
          <path d="M80 80 L80 130 M120 80 L120 130 M160 80 L160 130 M200 80 L200 130 M240 80 L240 130" />
        </g>
        <g fontFamily="var(--mono)" fontSize="6" fill="#4a5572">
          <text x="32" y="55">A101</text>
          <text x="72" y="55">A102</text>
          <text x="112" y="55">A103</text>
          <text x="152" y="55">A104</text>
          <text x="192" y="55">A105</text>
          <text x="232" y="55">A106</text>
          <text x="32" y="105">B101</text>
          <text x="92" y="105">B102</text>
          <text x="132" y="105">B103</text>
          <text x="172" y="105">B104</text>
          <text x="212" y="105">B105</text>
          <text x="252" y="105">B106</text>
        </g>
        {/* other APs */}
        <circle cx="55" cy="80" r="3" fill="#4a5572" />
        <circle cx="145" cy="80" r="3" fill="#4a5572" />
        <circle cx="225" cy="80" r="3" fill="#4a5572" />
        {/* this AP */}
        <g>
          <circle cx="105" cy="80" r="14" fill="rgba(217,41,41,0.12)" />
          <circle cx="105" cy="80" r="8"  fill="rgba(217,41,41,0.22)" />
          <circle cx="105" cy="80" r="4"  fill="var(--zbx)" />
          <text x="115" y="74" fontSize="6" fontFamily="var(--mono)" fill="var(--fg-2)">BHS-56</text>
        </g>
      </svg>
    </div>

    <div className="device-actions">
      <button className="btn primary"><Icon name="refresh" size={12} /> Reboot</button>
      <button className="btn"><Icon name="external" size={12} /> SSH</button>
      <button className="btn ghost"><Icon name="more" size={12} /></button>
    </div>

    <div className="location-block">
      <div className="label">Location</div>
      <div className="v">Tuscaloosa City Schools / Tuscaloosa<br/>Bryant High School · 1st Floor<br/>Hallway 100s wing · ceiling mount</div>
    </div>
    <div className="location-block">
      <div className="label">Installation</div>
      <div className="v">Installed 2023-10-19 10:26<br/><a style={{ color: "var(--accent)" }}>Open install report ↗</a> · <a style={{ color: "var(--accent)" }}>Media gallery ↗</a></div>
    </div>
    <div className="location-block">
      <div className="label">Zabbix Templates</div>
      <div className="v" style={{ display: "flex", flexDirection: "column", gap: 4 }}>
        <span style={{ fontFamily: "var(--mono)", fontSize: 11 }}>• Extreme AP via SNMPv3</span>
        <span style={{ fontFamily: "var(--mono)", fontSize: 11 }}>• ICMP Ping</span>
        <span style={{ fontFamily: "var(--mono)", fontSize: 11 }}>• PacketFence NAC Integration</span>
      </div>
    </div>
  </div>
);

const CommandPalette = ({ onClose }) => {
  const items = [
    { cat: "Host", label: "BHS-56-Hallway", sub: "172.16.97.59" },
    { cat: "Host", label: "BHS-57-Library", sub: "172.16.97.60" },
    { cat: "Host", label: "CHS-12-Cafeteria", sub: "172.17.4.18" },
    { cat: "Action", label: "Reboot AP", sub: "Zabbix · executescript" },
    { cat: "Client", label: "MAC A4:83:E7:91:2C:14", sub: "j.harris@tcs · ChromeOS" },
    { cat: "Client", label: "MAC F4:5C:89:0B:32:71", sub: "Quarantined · VLAN 666" },
    { cat: "User", label: "k.davis@tcs", sub: "Faculty · 1 active session" },
    { cat: "Site", label: "Bryant High School / 1st Floor", sub: "47 APs" },
  ];
  const [q, setQ] = React.useState("");
  const [sel, setSel] = React.useState(0);
  const filtered = items.filter(i => i.label.toLowerCase().includes(q.toLowerCase()) || i.sub.toLowerCase().includes(q.toLowerCase()));
  React.useEffect(() => {
    const onKey = (e) => {
      if (e.key === "Escape") onClose();
      if (e.key === "ArrowDown") { setSel(s => Math.min(filtered.length - 1, s + 1)); e.preventDefault(); }
      if (e.key === "ArrowUp")   { setSel(s => Math.max(0, s - 1)); e.preventDefault(); }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [filtered.length, onClose]);
  return (
    <div className="scrim" onClick={onClose}>
      <div className="palette" onClick={e => e.stopPropagation()}>
        <input className="palette-input" autoFocus placeholder="Search hosts, clients, users, MACs, IPs…" value={q} onChange={e => { setQ(e.target.value); setSel(0); }} />
        <div className="palette-list">
          {filtered.map((it, i) => (
            <div key={i} className={`palette-item ${i === sel ? "active" : ""}`} onMouseEnter={() => setSel(i)}>
              <Icon name={it.cat === "Host" ? "ap" : it.cat === "Client" ? "clients" : it.cat === "User" ? "user" : it.cat === "Site" ? "map" : "events"} size={14} />
              <div>
                <div>{it.label}</div>
                <div className="pi-mac">{it.sub}</div>
              </div>
              <span className="pi-cat">{it.cat}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

// ───────── Tweaks ─────────
const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "accent": "#d92929",
  "showSourceBadges": true,
  "showFloorplan": true,
  "showSidecar": true,
  "fontMono": "JetBrains Mono"
}/*EDITMODE-END*/;

const Tweaks = ({ t, setTweak }) => (
  <TweaksPanel title="Tweaks">
    <TweakSection title="Layout">
      <TweakRadio label="Density" value={t.density} options={[
        { value: "spacious", label: "Spacious" },
        { value: "balanced", label: "Balanced" },
        { value: "dense", label: "Dense" }
      ]} onChange={v => setTweak("density", v)} />
      <TweakToggle label="Show device sidecar (image, floor plan)" value={t.showSidecar} onChange={v => setTweak("showSidecar", v)} />
      <TweakToggle label="Show floor plan map" value={t.showFloorplan} onChange={v => setTweak("showFloorplan", v)} />
    </TweakSection>
    <TweakSection title="Visual">
      <TweakColor label="Primary accent" value={t.accent} options={["#d92929","#5b8cff","#34d399","#7c5cff","#f5b300"]} onChange={v => setTweak("accent", v)} />
      <TweakSelect label="Mono font" value={t.fontMono} options={[
        { value: "JetBrains Mono", label: "JetBrains Mono" },
        { value: "IBM Plex Mono",  label: "IBM Plex Mono" },
        { value: "ui-monospace",   label: "System mono" }
      ]} onChange={v => setTweak("fontMono", v)} />
      <TweakToggle label="Show data-source badges (ZBX/PF/EXT)" value={t.showSourceBadges} onChange={v => setTweak("showSourceBadges", v)} />
    </TweakSection>
    <TweakSection title="Quick actions">
      <TweakButton onClick={() => alert("This would re-poll Zabbix items via API.")}>Force Zabbix re-poll</TweakButton>
      <TweakButton onClick={() => alert("This would request a fresh PacketFence client snapshot.")}>Refresh PacketFence cache</TweakButton>
    </TweakSection>
  </TweaksPanel>
);

window.Sidebar = Sidebar;
window.Topbar = Topbar;
window.PageHeader = PageHeader;
window.Tabs = Tabs;
window.DeviceSidecar = DeviceSidecar;
window.CommandPalette = CommandPalette;
window.Tweaks = Tweaks;
window.TWEAK_DEFAULTS = TWEAK_DEFAULTS;
