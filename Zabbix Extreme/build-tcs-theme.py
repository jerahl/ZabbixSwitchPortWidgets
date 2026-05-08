#!/usr/bin/env python3
"""Build tcs-theme.css from dark-theme.css by remapping the dark-theme
palette onto the Zabbix Dashboard mockup palette (see styles.css).

Run from the Zabbix Extreme directory:
    python build-tcs-theme.py
"""
import re
from pathlib import Path

SRC = Path("dark-theme.css")
DST = Path("tcs-theme.css")

# Mapping from the dark-theme palette to the TCS/mockup palette.
# Keys must be lowercase 6-digit hex without the leading '#'.
# The 3-digit shorthand "fff"/"FFF" is handled separately and left alone.
MAP = {
    # ── Page / surface backgrounds ────────────────────────────
    "010a0f": "0d1117",
    "0d0d0d": "0d1117",
    "0d0f11": "0d1117",
    "0e0e0e": "0d1117",
    "0e1012": "0d1117",  # main page bg
    "010101": "07090d",
    "121212": "131822",
    "191919": "131822",
    "1c1c1c": "181f2c",
    "1e1e1e": "181f2c",
    "1f1f1f": "131822",  # sidebar / panel surface
    "202020": "181f2c",
    "212121": "181f2c",
    "232323": "1f2738",
    "2a353a": "1f2738",
    "2b2b2b": "1f2738",  # most common card bg
    # ── Borders / dividers ────────────────────────────────────
    "303030": "232c3f",
    "353535": "232c3f",
    "383838": "2c3650",
    "3a3a3a": "2c3650",
    "3d3d3d": "2c3650",
    "3d4b53": "2c3650",
    "3d5059": "2c3650",
    "404040": "2c3650",
    "414141": "2c3650",
    "454545": "353f5c",
    "454f55": "353f5c",
    "4a4a4a": "3a4566",
    "4f4f4f": "3a4566",
    "525252": "3a4566",
    "5e737e": "3a4566",
    "69808d": "4a5572",
    "69818d": "4a5572",
    "696969": "4a5572",
    # ── Muted / secondary text ────────────────────────────────
    "737373": "6b7793",
    "768d99": "6b7793",
    "787878": "6b7793",
    "808080": "6b7793",
    "8599a4": "8090a8",
    "8f8f8f": "8090a8",
    "97aab3": "8090a8",
    "9c9c9c": "8090a8",
    "9d9d9d": "8090a8",
    # ── Foreground / light text ───────────────────────────────
    "a2b1ba": "a0aabe",
    "a3a3a3": "a0aabe",
    "acbbc2": "a0aabe",
    "b1b1b1": "b8c2d4",
    "b2b2b2": "b8c2d4",
    "c0c0c0": "b8c2d4",
    "c2c2c2": "b8c2d4",
    "c5c5c5": "b8c2d4",
    "ccd5d9": "b8c2d4",
    "dedede": "d4dbe8",
    "dfe4e7": "d4dbe8",
    "e1e3ed": "d4dbe8",
    "eeeeee": "e6ecf5",
    "f2f2f2": "e6ecf5",
    "fdfdfd": "f0f4fa",
    # ── Status: green (ok) ────────────────────────────────────
    "0e4123": "0e3325",
    "32453a": "1a3a2d",
    "009900": "2bbf85",
    "209450": "2bbf85",
    "29a847": "2bbf85",
    "2f9f5e": "2bbf85",
    "34af67": "34d399",
    "3dc51d": "34d399",
    "59db8f": "34d399",  # primary ok green
    "00ff00": "34d399",
    # ── Status: red (err) / Zabbix brand ──────────────────────
    "433131": "3a1820",
    "4b0c0c": "3a1416",
    "52190b": "3a1416",
    "cc0000": "d92929",
    "d23d3d": "d92929",
    "d40000": "d92929",  # Zabbix logo red — kept on-brand
    "d64e4e": "d92929",
    "dc3838": "d92929",
    "e45959": "f25f5c",
    "e97659": "f25f5c",
    "f24f1d": "ff8a3a",
    "fc5a5a": "f25f5c",
    "ff3333": "f25f5c",
    "ff5555": "f25f5c",
    "ff9b9b": "ff9b9b",  # leave (light err tint)
    # ── Status: amber (warn) / PacketFence ────────────────────
    "2f280a": "3a2d0a",
    "733100": "5c3500",
    "734d00": "5c3500",
    "e79e0b": "f5b300",
    "e99003": "f5b300",
    "f1a50b": "f5b300",
    "f3a914": "f5b300",
    "f4af25": "f5b300",
    "ffa059": "ffb070",
    "ffc859": "ffd166",
    # ── Blues: info / accent / links ──────────────────────────
    "00268e": "1e3a8a",
    "0275b8": "4080d0",
    "4298ca": "5fa8d3",
    "429ae3": "5b8cff",
    "4796c4": "5fa8d3",  # main link color
    "7499ff": "8eb0ff",
    "aad7f0": "c0d8ee",
    # ── Teal (kept) ───────────────────────────────────────────
    "0f998b": "2bb8aa",
}


def repl(match: re.Match) -> str:
    hex_part = match.group(1).lower()
    # 3-digit shorthand: pass through unchanged.
    if len(hex_part) == 3:
        return "#" + hex_part
    # 8-digit (with alpha): only remap the leading 6.
    if len(hex_part) == 8:
        base, alpha = hex_part[:6], hex_part[6:]
        if base in MAP:
            return "#" + MAP[base] + alpha
        return "#" + hex_part
    # 6-digit
    if hex_part in MAP:
        return "#" + MAP[hex_part]
    return "#" + hex_part


HEX_RE = re.compile(r"#([0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b")


def remap_rgba(text: str) -> str:
    """Remap rgb/rgba(r, g, b[, a]) literals whose r,g,b match a known
    dark-theme color. We compare on (r,g,b) tuples derived from the MAP keys
    so e.g. rgba(217, 0, 0, .5) becomes rgba(217, 41, 41, .5)."""
    rgb_to_new = {}
    for k, v in MAP.items():
        r, g, b = int(k[0:2], 16), int(k[2:4], 16), int(k[4:6], 16)
        nr, ng, nb = int(v[0:2], 16), int(v[2:4], 16), int(v[4:6], 16)
        rgb_to_new[(r, g, b)] = (nr, ng, nb)

    def sub(m: re.Match) -> str:
        nums = [n.strip() for n in m.group(2).split(",")]
        if len(nums) < 3:
            return m.group(0)
        try:
            r, g, b = int(nums[0]), int(nums[1]), int(nums[2])
        except ValueError:
            return m.group(0)
        new = rgb_to_new.get((r, g, b))
        if not new:
            return m.group(0)
        rest = ("," + ",".join(nums[3:])) if len(nums) > 3 else ""
        fn = m.group(1)
        return f"{fn}({new[0]}, {new[1]}, {new[2]}{rest})"

    return re.sub(r"(rgba?)\(([^)]+)\)", sub, text)


def main() -> None:
    src = SRC.read_text(encoding="utf-8")
    out = HEX_RE.sub(repl, src)
    out = remap_rgba(out)

    # Header comment marking the theme and a couple of mockup-flavored tweaks
    # that aren't reachable via pure color remapping.
    header = (
        "@charset \"UTF-8\";\n"
        "/* TCS theme — derived from dark-theme.css, repalette to match the\n"
        "   Zabbix Dashboard.html mockup (Inter + JetBrains Mono, deep navy\n"
        "   surfaces, Zabbix-red accents). Generated by build-tcs-theme.py. */\n"
    )
    # Strip the original @charset to avoid duplicates.
    out = re.sub(r"^@charset[^;]+;\s*", "", out, count=1)

    # Append a small overrides block: pull in Inter + JetBrains Mono and lift
    # the body font to match the mockup, while keeping Zabbix's own metrics.
    overrides = """

/* ── TCS overrides (font + selection + scrollbar) ─────────────── */
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap");

body {
  font-family: "Inter", -apple-system, "Segoe UI", system-ui, Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  text-rendering: optimizeLegibility;
}
.monospace-font, pre, code, kbd, samp, tt {
  font-family: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, Consolas, "Courier New", monospace;
}
::selection { background: rgba(217, 41, 41, 0.35); color: #fff; }

/* Slimmer, mockup-style scrollbars */
::-webkit-scrollbar { width: 10px; height: 10px; }
::-webkit-scrollbar-track { background: #0d1117; }
::-webkit-scrollbar-thumb {
  background: #1f2738;
  border-radius: 5px;
  border: 2px solid #0d1117;
}
::-webkit-scrollbar-thumb:hover { background: #2c3650; }
"""

    DST.write_text(header + out + overrides, encoding="utf-8")
    print(f"Wrote {DST} ({len(out.splitlines())} lines).")


if __name__ == "__main__":
    main()
