:root {
  --bg: #0b0d17;
  --bg-soft: #10132370;
  --surface: #141728;
  --surface-2: #191d33;
  --border: #262b47;
  --border-soft: #1e2238;
  --text: #eef0fb;
  --text-dim: #9497b8;
  --text-faint: #6c6f8f;
  --accent-1: #8b7bf7;
  --accent-2: #4fc3f7;
  --accent-grad: linear-gradient(120deg, var(--accent-1), var(--accent-2));
  --good: #4ade80;
  --good-bg: #123322;
  --good-border: #235a3e;
  --bad: #fb7185;
  --bad-bg: #2c1620;
  --bad-border: #7a2e3d;
  --radius: 14px;
  --radius-sm: 9px;
  --shadow: 0 8px 28px -8px rgba(0, 0, 0, 0.55);
  --mono: "SF Mono", "Cascadia Code", Consolas, monospace;
}

* { box-sizing: border-box; }
html { color-scheme: dark; }
body {
  font-family: -apple-system, "Segoe UI", Roboto, Vazirmatn, Tahoma, sans-serif;
  background: radial-gradient(circle at top left, #171b30 0%, var(--bg) 55%);
  color: var(--text);
  margin: 0;
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: none; }
code {
  background: #0a0c16; padding: 2px 7px; border-radius: 6px; font-size: 12px;
  color: #b3c2ff; font-family: var(--mono); word-break: break-all;
}
pre.log-box {
  background: #0a0c16; padding: 14px; border-radius: var(--radius-sm); font-size: 12px;
  overflow-x: auto; color: var(--text-dim); font-family: var(--mono); line-height: 1.6;
  border: 1px solid var(--border-soft); max-width: 100%;
}
::selection { background: #4a3f9a; }
::-webkit-scrollbar { height: 8px; width: 8px; }
::-webkit-scrollbar-thumb { background: #2a2f4a; border-radius: 8px; }
::-webkit-scrollbar-track { background: transparent; }

/* ===== Setup / Login ===== */
.setup-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
.setup-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 32px; max-width: 460px; width: 100%; box-shadow: var(--shadow);
  animation: nrv-rise 0.35s ease;
}
.setup-card h1 {
  text-align: center; font-size: 21px; margin: 0 0 4px; font-weight: 700;
  background: var(--accent-grad); -webkit-background-clip: text; background-clip: text; color: transparent;
}
.setup-card .subtitle { text-align: center; color: var(--text-dim); font-size: 13px; margin-bottom: 24px; }
.setup-card h2 { font-size: 15px; color: var(--text); margin: 0 0 16px; font-weight: 600; }

label { display: block; font-size: 12.5px; color: var(--text-dim); margin: 14px 0 6px; font-weight: 500; }
label:first-child { margin-top: 0; }
input[type=text], input[type=password] {
  width: 100%; padding: 11px 13px; border-radius: var(--radius-sm); border: 1px solid var(--border);
  background: #0c0e1a; color: var(--text); font-size: 13.5px; font-family: inherit;
  transition: border-color 0.15s, box-shadow 0.15s;
}
input[type=text]:focus, input[type=password]:focus {
  outline: none; border-color: var(--accent-1); box-shadow: 0 0 0 3px rgba(139, 123, 247, 0.18);
}
input[type=checkbox] { accent-color: var(--accent-1); width: 16px; height: 16px; cursor: pointer; }
hr { border: none; border-top: 1px solid var(--border); margin: 20px 0; }

/* ===== Buttons ===== */
.btn {
  margin-top: 20px; width: 100%; padding: 12px; border: none; border-radius: var(--radius-sm);
  background: var(--accent-grad); color: #10121e; font-weight: 700;
  font-size: 14px; cursor: pointer; transition: transform 0.12s, opacity 0.12s, box-shadow 0.12s;
  font-family: inherit;
}
.btn:hover { opacity: 0.92; box-shadow: 0 4px 18px -4px rgba(139, 123, 247, 0.45); }
.btn:active { transform: scale(0.98); }
.btn:disabled { opacity: 0.35; cursor: not-allowed; box-shadow: none; transform: none; }
.btn.small { width: auto; margin-top: 0; padding: 7px 15px; font-size: 12.5px; border-radius: 8px; }
.btn.danger { background: linear-gradient(120deg, #fb7185, #ef4444); }
.btn.ghost {
  background: transparent; border: 1px solid var(--border); color: var(--text-dim);
}
.btn.ghost:hover { border-color: var(--accent-1); color: var(--text); box-shadow: none; }

/* ===== Alerts ===== */
.alert {
  padding: 13px 16px; border-radius: var(--radius-sm); font-size: 13px; margin-bottom: 14px;
  line-height: 1.55; border: 1px solid transparent;
}
.alert.bad { background: var(--bad-bg); border-color: var(--bad-border); color: #fca5a5; }
.alert.good { background: var(--good-bg); border-color: var(--good-border); color: #86efac; }

/* ===== Env test rows ===== */
.test-list .row {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
  padding: 10px 0; border-bottom: 1px dashed var(--border-soft); font-size: 13px;
}
.test-list .row:last-child { border-bottom: none; }
.test-list .row .name { flex: 1; font-weight: 500; }
.test-list .row .detail { font-size: 11.5px; color: var(--text-faint); margin-top: 3px; font-weight: 400; }
.badge {
  font-size: 11px; padding: 4px 11px; border-radius: 20px; white-space: nowrap; font-weight: 600;
  letter-spacing: 0.2px;
}
.badge.ok { background: var(--good-bg); color: var(--good); }
.badge.fail { background: var(--bad-bg); color: var(--bad); }

/* ===== App shell ===== */
.app { display: flex; min-height: 100vh; }
.sidebar {
  width: 220px; background: var(--surface); border-left: 1px solid var(--border);
  padding: 22px 0; flex-shrink: 0; display: flex; flex-direction: column;
}
.sidebar .brand {
  font-weight: 700; font-size: 17px; padding: 0 22px 12px; letter-spacing: 0.2px;
  background: var(--accent-grad); -webkit-background-clip: text; background-clip: text; color: transparent;
  display: flex; align-items: center; gap: 8px;
}
.sidebar .version-row { padding: 0 22px 22px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.version-tag { font-size: 11px; color: var(--text-faint); font-weight: 500; }
.update-badge {
  font-size: 10.5px; font-weight: 700; color: #fff; background: linear-gradient(120deg, #ef4444, #f87171);
  padding: 3px 9px; border-radius: 20px; letter-spacing: 0.2px; box-shadow: 0 2px 10px -2px rgba(239, 68, 68, 0.55);
  transition: opacity 0.12s;
}
.update-badge:hover { opacity: 0.88; }
.sidebar nav { display: flex; flex-direction: column; gap: 2px; }
.sidebar nav a {
  display: flex; align-items: center; gap: 10px; padding: 11px 22px; font-size: 13.5px;
  color: var(--text-dim); border-right: 3px solid transparent; font-weight: 500;
  transition: background 0.12s, color 0.12s;
}
.sidebar nav a:hover { color: var(--text); background: var(--surface-2); }
.sidebar nav a.active { color: var(--text); border-right-color: var(--accent-1); background: var(--surface-2); }
.sidebar nav a.logout { margin-top: auto; color: var(--bad); }
.sidebar .icon { width: 16px; text-align: center; opacity: 0.85; font-size: 13px; }
.sidebar nav form.logout-form { margin-top: auto; }
.sidebar nav form.logout-form button.logout,
.mobile-topbar nav form.logout-form button.logout {
  background: none; border: none; cursor: pointer; font: inherit; text-align: inherit;
  width: 100%; padding: 11px 22px; display: flex; align-items: center; gap: 10px;
  color: var(--bad);
}
.mobile-topbar nav form.logout-form { display: inline; }
.mobile-topbar nav form.logout-form button.logout { width: auto; padding: 0; }

.content { flex: 1; padding: 30px 34px; max-width: 960px; width: 100%; margin: 0 auto; }
.content h1 { font-size: 23px; margin: 0 0 22px; font-weight: 700; letter-spacing: -0.2px; }

/* mobile top bar, hidden on desktop */
.mobile-topbar { display: none; }

/* ===== Dashboard stat tiles ===== */
.stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 24px; }
.stat-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 18px; transition: border-color 0.15s;
}
.stat-card:hover { border-color: #33396030; }
.stat-label { font-size: 12px; color: var(--text-dim); font-weight: 500; }
.stat-value { font-size: 25px; font-weight: 700; margin: 8px 0; letter-spacing: -0.3px; }
.stat-bar { height: 6px; background: #0c0e1a; border-radius: 4px; overflow: hidden; }
.stat-bar-fill { height: 100%; background: var(--accent-grad); border-radius: 4px; transition: width 0.4s ease; }

/* ===== Panel boxes ===== */
.panel-box {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 20px 22px; margin-bottom: 18px; animation: nrv-rise 0.3s ease;
}
.panel-box h2 {
  font-size: 14.5px; color: var(--text); margin: 0 0 15px; padding-bottom: 10px;
  border-bottom: 1px solid var(--border); font-weight: 600; display: flex; align-items: center; gap: 8px;
}
.muted { color: var(--text-faint); font-size: 13px; line-height: 1.65; }

/* ===== Tables ===== */
.table-wrap { overflow-x: auto; margin: 0 -2px; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 480px; }
.table th {
  text-align: right; color: var(--text-dim); font-weight: 500; font-size: 12px;
  padding: 9px 8px; border-bottom: 1px solid var(--border);
}
.table td { padding: 11px 8px; border-bottom: 1px dashed var(--border-soft); vertical-align: middle; }
.table tr:last-child td { border-bottom: none; }
.table .actions { display: flex; gap: 6px; flex-wrap: wrap; }

/* ===== Modal ===== */
.modal {
  position: fixed; inset: 0; background: rgba(6, 7, 14, 0.72); display: flex;
  align-items: center; justify-content: center; padding: 18px; z-index: 50;
  backdrop-filter: blur(3px); animation: nrv-fade 0.15s ease;
}
.modal.hidden { display: none; }
.modal-box {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 22px; max-width: 540px; width: 100%; max-height: 88vh; overflow-y: auto;
  box-shadow: var(--shadow); animation: nrv-rise 0.2s ease;
}
.modal-box h2 { margin-top: 0; font-size: 16px; font-weight: 600; }
.modal-box textarea {
  width: 100%; background: #0a0c16; color: #b3c2ff; border: 1px solid var(--border);
  border-radius: var(--radius-sm); font-size: 11.5px; padding: 10px; margin-bottom: 10px;
  font-family: var(--mono); resize: vertical;
}
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 6px; }

/* ===== Misc ===== */
.field-row { display: flex; align-items: center; gap: 10px; }
.divider-label {
  font-size: 11px; color: var(--text-faint); text-transform: uppercase; letter-spacing: 0.6px;
  margin: 18px 0 8px; font-weight: 600;
}

@keyframes nrv-rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
@keyframes nrv-fade { from { opacity: 0; } to { opacity: 1; } }

/* ===== Responsive ===== */
@media (max-width: 860px) {
  .content { padding: 20px; max-width: 100%; }
  .stat-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .stat-card { padding: 13px; }
  .stat-value { font-size: 20px; }
}

@media (max-width: 680px) {
  .app { flex-direction: column; }
  .sidebar { display: none; }
  .mobile-topbar {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 14px 18px; position: sticky; top: 0; z-index: 40;
  }
  .mobile-topbar .brand {
    font-weight: 700; font-size: 15px;
    background: var(--accent-grad); -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .mobile-topbar nav { display: flex; gap: 14px; font-size: 12.5px; }
  .mobile-topbar nav a { color: var(--text-dim); font-weight: 500; }
  .mobile-topbar nav a.active { color: var(--text); }
  .mobile-topbar nav a.logout { color: var(--bad); }
  .content { padding: 18px 16px; }
  .stat-grid { grid-template-columns: 1fr 1fr; }
  .stat-grid .stat-card:last-child { grid-column: 1 / -1; }
  .panel-box { padding: 16px; }
  .table .actions { flex-direction: column; align-items: stretch; }
  .table .actions .btn.small { width: 100%; text-align: center; }
  .setup-card { padding: 22px; }
}

@media (max-width: 420px) {
  .stat-grid { grid-template-columns: 1fr; }
  .stat-grid .stat-card:last-child { grid-column: auto; }
}
