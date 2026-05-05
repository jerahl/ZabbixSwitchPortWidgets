// Main app
const { useState, useEffect } = React;

const App = () => {
  const [tab, setTab] = useState("overview");
  const [timeRange, setTimeRange] = useState("May 4, 2026 09:40 — May 5, 2026 09:40");
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [clientFilter, setClientFilter] = useState("all");
  const [t, setTweak] = useTweaks(window.TWEAK_DEFAULTS);

  // Apply tweaks
  useEffect(() => {
    document.documentElement.style.setProperty("--zbx", t.accent);
    document.documentElement.style.setProperty("--mono", `"${t.fontMono}", ui-monospace, "SF Mono", Menlo, monospace`);
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.accent, t.fontMono, t.showSourceBadges]);

  useEffect(() => {
    const onKey = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") { setPaletteOpen(true); e.preventDefault(); }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const TabView = (() => {
    switch (tab) {
      case "overview": return <OverviewTab density={t.density} />;
      case "wireless": return <WirelessTab />;
      case "wired":    return <WiredTab />;
      case "clients":  return <ClientsTab filter={clientFilter} setFilter={setClientFilter} />;
      case "events":   return <EventsTab />;
      case "alerts":   return <AlertsTab />;
      default:         return <ComingSoon name={tab} />;
    }
  })();

  // density
  const densityVar = t.density === "spacious" ? 1.15 : t.density === "dense" ? 0.85 : 1;
  const showSide = t.showSidecar && (tab === "overview");

  return (
    <div className="app" data-density={t.density} style={{ fontSize: `${13 * densityVar}px` }}>
      <Sidebar tab={tab} setTab={setTab} />
      <div className="main">
        <Topbar onCmdK={() => setPaletteOpen(true)} />
        <PageHeader timeRange={timeRange} setTimeRange={setTimeRange} host={window.ZBX_HOST} />
        <Tabs tab={tab} setTab={setTab} />

        <div className="body" data-screen-label={`AP Detail · ${tab}`}>
          {showSide ? (
            <div style={{ display: "grid", gridTemplateColumns: "300px 1fr", gap: 14 }}>
              <DeviceSidecar host={window.ZBX_HOST} />
              <div>{TabView}</div>
            </div>
          ) : TabView}
        </div>
      </div>
      {paletteOpen && <CommandPalette onClose={() => setPaletteOpen(false)} />}
      <Tweaks t={t} setTweak={setTweak} />
    </div>
  );
};

const ComingSoon = ({ name }) => (
  <div className="card" style={{ padding: 60, textAlign: "center", color: "var(--muted)" }}>
    <div style={{ fontSize: 14 }}>The <span style={{ color: "var(--fg)", textTransform: "capitalize" }}>{name}</span> tab is part of the roadmap.</div>
    <div style={{ fontSize: 11, marginTop: 6 }}>Backed by Zabbix history API + PacketFence /api/v1/reports.</div>
  </div>
);

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
