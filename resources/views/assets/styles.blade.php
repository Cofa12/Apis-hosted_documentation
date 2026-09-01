<style>
    :root {
        --bg: #f6f7f9;
        --surface: #ffffff;
        --surface-2: #f1f3f7;
        --surface-3: #e9ecf2;
        --border: #e2e5ec;
        --border-strong: #cfd4de;
        --text: #171a21;
        --text-soft: #444b58;
        --muted: #6b7385;
        --accent: #4f46e5;
        --accent-text: #ffffff;
        --accent-soft: #eef0ff;
        --code-bg: #0e1320;
        --code-text: #dfe4f0;
        --code-border: #1d2436;
        --tok-key: #7dd3fc;
        --tok-str: #a5e6a0;
        --tok-num: #fbbf8f;
        --tok-lit: #d8b4fe;
        --ok: #157347;
        --ok-soft: #e6f4ec;
        --warn: #9a6700;
        --warn-soft: #fdf3e2;
        --err: #b42318;
        --err-soft: #fdeceb;
        --radius: 10px;
        --radius-sm: 6px;
        --shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 1px 3px rgba(16, 24, 40, .04);
        --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
        --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        --sidebar-w: 292px;
    }

    [data-theme="dark"] {
        --bg: #0c0f16;
        --surface: #141822;
        --surface-2: #1a1f2b;
        --surface-3: #222836;
        --border: #262c3a;
        --border-strong: #333b4d;
        --text: #e8eaf0;
        --text-soft: #c2c7d4;
        --muted: #8b93a7;
        --accent: #8b87ff;
        --accent-text: #12121c;
        --accent-soft: #1e1f3d;
        --code-bg: #0a0d15;
        --code-border: #1c2231;
        --ok: #5ec38a;
        --ok-soft: #12241a;
        --warn: #e0b155;
        --warn-soft: #241d10;
        --err: #f08a80;
        --err-soft: #2a1615;
        --shadow: 0 1px 2px rgba(0, 0, 0, .4);
    }

    *, *::before, *::after { box-sizing: border-box; }

    html { scroll-behavior: smooth; scroll-padding-top: 88px; }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: var(--sans);
        font-size: 15px;
        line-height: 1.6;
        -webkit-font-smoothing: antialiased;
    }

    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    code, pre, kbd { font-family: var(--mono); font-size: 13px; }

    /* ---------- top bar ---------- */
    .topbar {
        position: sticky; top: 0; z-index: 40;
        display: flex; align-items: center; gap: 16px;
        height: 60px; padding: 0 20px;
        background: color-mix(in srgb, var(--surface) 88%, transparent);
        backdrop-filter: saturate(180%) blur(12px);
        border-bottom: 1px solid var(--border);
    }
    .brand { display: flex; align-items: center; gap: 10px; font-weight: 650; letter-spacing: -.01em; }
    .brand img { height: 26px; width: auto; border-radius: 6px; }
    .brand .pill { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px;
        background: var(--accent-soft); color: var(--accent); border: 1px solid color-mix(in srgb, var(--accent) 24%, transparent); }
    .topbar-spacer { flex: 1; }

    .search {
        position: relative; width: min(340px, 40vw);
    }
    .search input {
        width: 100%; height: 36px; padding: 0 34px 0 34px;
        border: 1px solid var(--border-strong); border-radius: 8px;
        background: var(--surface-2); color: var(--text); font: inherit; font-size: 14px;
    }
    .search input:focus { outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent); outline-offset: 1px; border-color: var(--accent); }
    .search svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--muted); }
    .search kbd {
        position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
        font-size: 11px; color: var(--muted); background: var(--surface-3);
        border: 1px solid var(--border); border-radius: 4px; padding: 1px 5px;
    }

    .icon-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        height: 34px; min-width: 34px; padding: 0 10px;
        border: 1px solid var(--border-strong); border-radius: 8px;
        background: var(--surface); color: var(--text-soft);
        cursor: pointer; font: inherit; font-size: 13px;
    }
    .icon-btn:hover { background: var(--surface-2); color: var(--text); }
    .icon-btn svg { width: 15px; height: 15px; }

    /* ---------- shell ---------- */
    .shell { display: flex; align-items: flex-start; }

    .sidebar {
        position: sticky; top: 60px;
        width: var(--sidebar-w); flex: 0 0 var(--sidebar-w);
        height: calc(100vh - 60px); overflow-y: auto;
        padding: 18px 12px 60px;
        border-right: 1px solid var(--border);
        background: var(--surface);
    }
    .sidebar h2 {
        margin: 18px 8px 6px; font-size: 11px; font-weight: 700;
        letter-spacing: .08em; text-transform: uppercase; color: var(--muted);
    }
    .nav-group { margin-bottom: 4px; }
    .nav-group > button {
        display: flex; align-items: center; gap: 6px; width: 100%;
        padding: 7px 8px; border: 0; border-radius: var(--radius-sm);
        background: transparent; color: var(--text); cursor: pointer;
        font: inherit; font-weight: 600; font-size: 13.5px; text-align: left;
    }
    .nav-group > button:hover { background: var(--surface-2); }
    .nav-group > button .chev { width: 14px; height: 14px; color: var(--muted); transition: transform .15s ease; }
    .nav-group.collapsed > button .chev { transform: rotate(-90deg); }
    .nav-group.collapsed .nav-items { display: none; }
    .nav-group .count { margin-left: auto; font-size: 11px; color: var(--muted); font-weight: 500; }
    .nav-items { list-style: none; margin: 2px 0 6px; padding: 0 0 0 6px; }
    .nav-items a {
        display: flex; align-items: center; gap: 8px;
        padding: 5px 8px; border-radius: var(--radius-sm);
        color: var(--text-soft); font-size: 13px; text-decoration: none;
        border-left: 2px solid transparent;
    }
    .nav-items a:hover { background: var(--surface-2); color: var(--text); text-decoration: none; }
    .nav-items a.active { background: var(--accent-soft); color: var(--accent); border-left-color: var(--accent); font-weight: 600; }
    .nav-items a .label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .content { flex: 1; min-width: 0; padding: 28px 32px 120px; max-width: 1180px; }

    /* ---------- method chips ---------- */
    .method {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 52px; padding: 2px 7px; border-radius: 5px;
        font-family: var(--mono); font-size: 10.5px; font-weight: 700; letter-spacing: .04em;
        border: 1px solid transparent; text-transform: uppercase;
    }
    .method.get { color: #0b6b8f; background: #e4f4fb; border-color: #b9e2f2; }
    .method.post { color: #14713d; background: #e6f6ec; border-color: #b7e4c8; }
    .method.put { color: #92610a; background: #fdf2df; border-color: #f2ddb2; }
    .method.patch { color: #6f42c1; background: #f3ecfe; border-color: #ddcdf7; }
    .method.delete { color: #b42318; background: #fdeceb; border-color: #f7cfcc; }
    .method.head, .method.options { color: var(--muted); background: var(--surface-3); border-color: var(--border); }
    [data-theme="dark"] .method.get { color: #7cd3f3; background: #10283a; border-color: #1c4258; }
    [data-theme="dark"] .method.post { color: #7fdba3; background: #102a1c; border-color: #1d4a30; }
    [data-theme="dark"] .method.put { color: #ecc37a; background: #2c2410; border-color: #4a3d1a; }
    [data-theme="dark"] .method.patch { color: #c4a8f7; background: #241a3a; border-color: #3b2b5c; }
    [data-theme="dark"] .method.delete { color: #f4a09a; background: #2f1614; border-color: #55231f; }

    /* ---------- intro ---------- */
    .intro { margin-bottom: 34px; }
    .intro h1 { margin: 0 0 8px; font-size: 30px; letter-spacing: -.02em; }
    .intro p { margin: 0 0 14px; color: var(--text-soft); max-width: 74ch; }
    .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-top: 18px; }
    .meta-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; box-shadow: var(--shadow); }
    .meta-card .k { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 700; }
    .meta-card .v { margin-top: 3px; font-family: var(--mono); font-size: 13px; word-break: break-all; }

    /* ---------- groups & operations ---------- */
    .group { margin-bottom: 46px; scroll-margin-top: 80px; }
    .group > h2 { margin: 0 0 4px; font-size: 21px; letter-spacing: -.01em; }
    .group > p.group-desc { margin: 0 0 18px; color: var(--muted); max-width: 74ch; }

    .op {
        margin-bottom: 20px; background: var(--surface);
        border: 1px solid var(--border); border-radius: 12px;
        box-shadow: var(--shadow); overflow: hidden; scroll-margin-top: 80px;
    }
    .op-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; cursor: pointer; }
    .op-head:hover { background: var(--surface-2); }
    .op-head .path { font-family: var(--mono); font-size: 13.5px; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
    .op-head .path .var { color: var(--accent); }
    .op-head .summary { color: var(--muted); font-size: 13px; margin-left: auto; text-align: right; }
    .op-head .chev { width: 16px; height: 16px; color: var(--muted); flex: 0 0 auto; transition: transform .15s ease; }
    .op.collapsed .op-head .chev { transform: rotate(-90deg); }
    .op.collapsed .op-body { display: none; }
    .badge {
        display: inline-flex; align-items: center; gap: 4px; flex: 0 0 auto; white-space: nowrap;
        padding: 1px 7px; border-radius: 999px; font-size: 11px; font-weight: 600;
        border: 1px solid var(--border-strong); color: var(--muted); background: var(--surface-2);
    }
    .badge.lock { color: var(--warn); background: var(--warn-soft); border-color: color-mix(in srgb, var(--warn) 30%, transparent); }
    .badge.dep { color: var(--err); background: var(--err-soft); border-color: color-mix(in srgb, var(--err) 30%, transparent); }
    .badge svg { width: 11px; height: 11px; }

    .op-body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 420px); gap: 0; border-top: 1px solid var(--border); }
    .op-main { padding: 18px 18px 22px; min-width: 0; }
    .op-side { padding: 18px; background: var(--surface-2); border-left: 1px solid var(--border); min-width: 0; }
    @media (max-width: 1100px) {
        .op-body { grid-template-columns: minmax(0, 1fr); }
        .op-side { border-left: 0; border-top: 1px solid var(--border); }
    }

    .op-desc { color: var(--text-soft); margin: 0 0 16px; }
    .op-desc p { margin: 0 0 8px; }

    section.block { margin-top: 22px; }
    section.block:first-of-type { margin-top: 0; }
    section.block > h3 {
        margin: 0 0 10px; font-size: 12px; font-weight: 700;
        letter-spacing: .07em; text-transform: uppercase; color: var(--muted);
    }

    /* ---------- parameter tables ---------- */
    .params { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .param {
        display: grid; grid-template-columns: minmax(0, 1fr); gap: 2px;
        padding: 10px 13px; border-bottom: 1px solid var(--border);
    }
    .param:last-child { border-bottom: 0; }
    .param:nth-child(even) { background: color-mix(in srgb, var(--surface-2) 55%, transparent); }
    .param-top { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
    .param-name { font-family: var(--mono); font-size: 13px; font-weight: 600; }
    .param-name .dim { color: var(--muted); font-weight: 400; }
    .param-type { font-family: var(--mono); font-size: 11.5px; color: var(--muted); }
    .req { font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--err); }
    .opt { font-size: 10.5px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
    .param-desc { color: var(--text-soft); font-size: 13.5px; }
    .param-desc code { background: var(--surface-3); padding: 1px 4px; border-radius: 4px; }
    .pills { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 3px; }
    .pill {
        font-family: var(--mono); font-size: 11px; padding: 1px 6px;
        border-radius: 5px; background: var(--surface-3); color: var(--muted); border: 1px solid var(--border);
    }
    .pill.enum { color: var(--accent); background: var(--accent-soft); border-color: color-mix(in srgb, var(--accent) 25%, transparent); }
    .depth-1 { padding-left: 30px; } .depth-2 { padding-left: 48px; }
    .depth-3 { padding-left: 66px; } .depth-4 { padding-left: 84px; }
    .depth-5, .depth-6 { padding-left: 100px; }
    .param[class*="depth-"] .param-name::before {
        content: "└"; color: var(--border-strong); margin-right: 6px; font-weight: 400;
    }

    .empty { color: var(--muted); font-size: 13.5px; font-style: italic; }

    /* ---------- code ---------- */
    .code {
        position: relative; background: var(--code-bg); color: var(--code-text);
        border: 1px solid var(--code-border); border-radius: var(--radius); overflow: hidden;
    }
    .code pre { margin: 0; padding: 13px 14px; overflow-x: auto; line-height: 1.55; }
    .code pre code { color: inherit; white-space: pre; }
    .tok-key { color: var(--tok-key); }
    .tok-str { color: var(--tok-str); }
    .tok-num { color: var(--tok-num); }
    .tok-lit { color: var(--tok-lit); }
    .copy {
        position: absolute; top: 7px; right: 7px; opacity: 0; transition: opacity .12s ease;
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 7px; border-radius: 5px; cursor: pointer; font: inherit; font-size: 11px;
        background: rgba(255, 255, 255, .1); color: #cfd6e6; border: 1px solid rgba(255, 255, 255, .16);
    }
    .code:hover .copy, .copy:focus { opacity: 1; }
    .copy:hover { background: rgba(255, 255, 255, .2); }

    /* ---------- tabs ---------- */
    .tabs { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 9px; }
    .tab {
        padding: 4px 10px; border-radius: 999px; cursor: pointer; font: inherit; font-size: 12px; font-weight: 600;
        background: transparent; color: var(--muted); border: 1px solid var(--border-strong);
    }
    .tab:hover { color: var(--text); background: var(--surface); }
    .tab[aria-selected="true"] { background: var(--accent); color: var(--accent-text); border-color: var(--accent); }
    .tab .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 5px; vertical-align: 1px; }
    .tab .dot.ok { background: var(--ok); } .tab .dot.err { background: var(--err); }
    .tab[aria-selected="true"] .dot { background: currentColor; }
    .tab-panel[hidden] { display: none; }
    .resp-desc { color: var(--muted); font-size: 13px; margin: 0 0 8px; }

    /* ---------- try it ---------- */
    .tryit { margin-top: 16px; border: 1px dashed var(--border-strong); border-radius: var(--radius); padding: 13px; }
    .tryit h4 { margin: 0 0 9px; font-size: 12px; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); }
    .tryit label { display: block; font-size: 11.5px; color: var(--muted); margin: 7px 0 3px; font-weight: 600; }
    .tryit input, .tryit textarea {
        width: 100%; padding: 6px 9px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm);
        background: var(--surface); color: var(--text); font-family: var(--mono); font-size: 12.5px;
    }
    .tryit textarea { min-height: 92px; resize: vertical; }
    .tryit .row { display: flex; gap: 8px; align-items: center; margin-top: 11px; }
    .btn {
        display: inline-flex; align-items: center; gap: 6px; padding: 6px 13px;
        border-radius: var(--radius-sm); border: 1px solid var(--accent);
        background: var(--accent); color: var(--accent-text); cursor: pointer; font: inherit; font-size: 13px; font-weight: 600;
    }
    .btn:hover { filter: brightness(1.07); }
    .btn.ghost { background: transparent; color: var(--muted); border-color: var(--border-strong); }
    .btn:disabled { opacity: .55; cursor: progress; }
    .tryit-out { margin-top: 11px; }
    .tryit-status { font-family: var(--mono); font-size: 12px; margin-bottom: 6px; }
    .tryit-status .ok { color: var(--ok); } .tryit-status .err { color: var(--err); }

    .handler { margin-top: 14px; font-size: 12px; color: var(--muted); }
    .handler code { background: var(--surface-3); padding: 1px 5px; border-radius: 4px; word-break: break-all; }
    .handler .mw { display: inline-flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }

    /* ---------- history ---------- */
    .timeline { list-style: none; margin: 0; padding: 0 0 0 16px; border-left: 2px solid var(--border); }
    .timeline > .rev { position: relative; padding: 0 0 16px 16px; }
    .timeline > .rev:last-child { padding-bottom: 0; }
    .timeline > .rev::before {
        content: ""; position: absolute; left: -23px; top: 7px;
        width: 9px; height: 9px; border-radius: 50%;
        background: var(--surface); border: 2px solid var(--border-strong);
    }
    .timeline > .rev:first-child::before { border-color: var(--accent); background: var(--accent); }
    .rev-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .rev-date { font-family: var(--mono); font-size: 12.5px; font-weight: 600; }
    .rev-headline { color: var(--muted); font-size: 13px; }

    .chg {
        font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
        padding: 1px 6px; border-radius: 4px; border: 1px solid transparent;
    }
    .chg-added { color: #14713d; background: #e6f6ec; border-color: #b7e4c8; }
    .chg-removed { color: #b42318; background: #fdeceb; border-color: #f7cfcc; }
    .chg-modified { color: #92610a; background: #fdf2df; border-color: #f2ddb2; }
    [data-theme="dark"] .chg-added { color: #7fdba3; background: #102a1c; border-color: #1d4a30; }
    [data-theme="dark"] .chg-removed { color: #f4a09a; background: #2f1614; border-color: #55231f; }
    [data-theme="dark"] .chg-modified { color: #ecc37a; background: #2c2410; border-color: #4a3d1a; }

    .rev-ops { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 5px; }
    .rev-ops li { display: flex; align-items: center; gap: 8px; font-size: 13px; }
    .rev-ops a { display: inline-flex; align-items: center; gap: 7px; color: var(--text-soft); text-decoration: none; min-width: 0; }
    .rev-ops a:hover { color: var(--accent); }
    .rev-ops code { font-size: 12.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rev-ops .more { color: var(--muted); font-size: 12.5px; }

    .rev-changes { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 4px; }
    .rev-changes .chg-item {
        position: relative; padding-left: 16px; font-size: 13.5px; color: var(--text-soft);
    }
    .rev-changes .chg-item::before {
        content: "+"; position: absolute; left: 0; font-family: var(--mono); font-weight: 700; color: var(--ok);
    }
    .rev-changes .chg-removed::before { content: "\2212"; color: var(--err); }
    .rev-changes .chg-modified::before { content: "~"; color: var(--warn); }
    .rev-changes code { background: var(--surface-3); padding: 1px 4px; border-radius: 4px; }
    .rev-changes details { margin-top: 4px; }
    .rev-changes summary { cursor: pointer; color: var(--muted); font-size: 12.5px; }
    .rev-changes details p { margin: 6px 0 0; padding: 8px 10px; background: var(--surface-2); border-radius: var(--radius-sm); }

    .changelog { margin-top: 26px; }
    .endpoint-history { padding-left: 12px; }

    .no-results { padding: 40px 0; text-align: center; color: var(--muted); }
    [hidden] { display: none !important; }

    @media (max-width: 900px) {
        /* Secondary on a narrow screen: the same date is in the History block. */
        .op-head .badge.updated { display: none; }
        .sidebar { display: none; }
        .content { padding: 20px 16px 80px; }
        .search { width: 180px; }
        .op-head .summary { display: none; }
    }

    @media print {
        .sidebar, .topbar, .tryit, .copy { display: none !important; }
        .op, .op-body { break-inside: avoid; }
        .op.collapsed .op-body { display: grid; }
    }
</style>
