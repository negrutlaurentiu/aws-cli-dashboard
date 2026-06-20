"use strict";

const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const $ = (s, r = document) => r.querySelector(s);

const STATUSES = [
  { key: "pending", name: "Pending" },
  { key: "in_progress", name: "In Progress" },
  { key: "review", name: "Review" },
  { key: "done", name: "Done" },
  { key: "archived", name: "Archived" },
];
const STATUS_NAME = Object.fromEntries(STATUSES.map((s) => [s.key, s.name]));

const state = {
  tasks: [],
  loadedAt: 0,        // Date.now() at last load, for live extrapolation
  detailId: null,
  weekOffset: 0,
  composeStatus: null, // which column's inline quick-add is open
};

/* ---------- api ---------- */

async function api(path, body) {
  const res = await fetch(path, {
    method: "POST",
    headers: { "X-CSRF-Token": TOKEN, "Content-Type": "application/json" },
    body: JSON.stringify(body || {}),
  });
  let data = {};
  try { data = await res.json(); } catch (_) { /* */ }
  if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}
async function apiGet(path) {
  const res = await fetch(path, { headers: { "X-CSRF-Token": TOKEN } });
  let data = {};
  try { data = await res.json(); } catch (_) { /* */ }
  if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}
function fileUrl(taskId, fileId, dl) {
  const q = new URLSearchParams({ task_id: taskId, file_id: fileId, token: TOKEN });
  if (dl) q.set("dl", "1");
  return "/api/tasks/file?" + q.toString();
}

/* ---------- load + render board ---------- */

async function loadTasks() {
  try {
    const data = await apiGet("/api/tasks");
    state.tasks = data.tasks || [];
    state.loadedAt = Date.now();
    renderBoard();
    if (state.detailId) {
      const t = state.tasks.find((x) => x.id === state.detailId);
      if (t) renderDetail(t); else closeDetail();
    }
  } catch (e) {
    toast("err", "Couldn't load tasks", e.message);
  }
}

function renderBoard() {
  const board = $("#board");
  board.innerHTML = "";
  let focusInput = null;
  STATUSES.forEach((st) => {
    const col = $("#column-tpl").content.firstElementChild.cloneNode(true);
    col.dataset.status = st.key;
    col.classList.add("col-" + st.key);
    $(".col-name", col).textContent = st.name;
    const items = state.tasks.filter((t) => t.status === st.key);
    $(".col-count", col).textContent = items.length;
    const list = $(".col-list", col);
    items.forEach((t) => list.appendChild(buildCard(t)));

    // inline quick-add composer (per column)
    const compose = $(".col-compose", col);
    const input = $(".col-compose-input", col);
    $(".col-add", col).addEventListener("click", () => {
      state.composeStatus = state.composeStatus === st.key ? null : st.key;
      renderBoard();
    });
    if (state.composeStatus === st.key) { compose.classList.remove("hidden"); focusInput = input; }
    input.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") { ev.preventDefault(); const v = input.value.trim(); if (v) quickAdd(v, st.key); }
      else if (ev.key === "Escape") { state.composeStatus = null; renderBoard(); }
    });

    // drag-and-drop target
    col.addEventListener("dragover", (ev) => {
      ev.preventDefault();
      ev.dataTransfer.dropEffect = "move";
      col.classList.add("drag-over");
    });
    col.addEventListener("dragleave", (ev) => {
      if (!col.contains(ev.relatedTarget)) col.classList.remove("drag-over");
    });
    col.addEventListener("drop", (ev) => {
      ev.preventDefault();
      col.classList.remove("drag-over");
      const id = ev.dataTransfer.getData("text/plain");
      if (id) moveTask(id, st.key);
    });
    board.appendChild(col);
  });
  if (focusInput) focusInput.focus();
  tick();
}

function buildCard(t) {
  const el = $("#card-tpl").content.firstElementChild.cloneNode(true);
  el.dataset.id = t.id;
  el.__task = t;
  if (t.running) el.classList.add("running");
  $(".task-title", el).textContent = t.title;
  $(".task-attach", el).textContent = (t.attachments && t.attachments.length) ? `📎 ${t.attachments.length}` : "";

  el.addEventListener("dragstart", (ev) => {
    ev.dataTransfer.setData("text/plain", t.id);
    ev.dataTransfer.effectAllowed = "move";
    el.classList.add("dragging");
  });
  el.addEventListener("dragend", () => el.classList.remove("dragging"));
  el.addEventListener("click", (ev) => { if (!ev.target.closest(".task-timer")) openDetail(t.id); });

  const timerBtn = $(".task-timer", el);
  timerBtn.addEventListener("click", (ev) => { ev.stopPropagation(); toggleTimer(t); });
  return el;
}

/* ---------- live tick ---------- */

function tick() {
  const elapsed = (Date.now() - state.loadedAt) / 1000;
  let runningTask = null;
  document.querySelectorAll(".task-card").forEach((el) => {
    const t = el.__task;
    if (!t) return;
    const sinceSecs = (t.status_seconds || 0) + elapsed;
    const worked = (t.worked_seconds || 0) + (t.running ? elapsed : 0);
    $(".task-since", el).textContent = "🕒 " + fmtDur(sinceSecs);
    const wEl = $(".task-worked", el);
    wEl.textContent = (t.worked_seconds || t.running) ? "⌛ " + (t.running ? fmtClock(worked) : fmtDur(worked)) : "";
    const btn = $(".task-timer", el);
    btn.textContent = t.running ? "⏹ Stop" : "▶ Start";
    btn.classList.toggle("is-running", !!t.running);
    if (t.running) runningTask = { t, worked };
  });
  const nt = $("#now-timer");
  if (runningTask) {
    nt.textContent = `🕒 ${fmtClock(runningTask.worked)} · ${runningTask.t.title}`;
    nt.classList.add("active");
  } else {
    nt.textContent = "";
    nt.classList.remove("active");
  }
}

/* ---------- actions ---------- */

async function moveTask(id, status) {
  try {
    await api("/api/tasks/update", { id, status });
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't move task", e.message);
  }
}

async function quickAdd(title, status) {
  try {
    await api("/api/tasks", { title, status });
    state.composeStatus = status; // keep this column's composer open for rapid entry
    await loadTasks();            // re-renders and refocuses the composer input
  } catch (e) {
    toast("err", "Couldn't add task", e.message);
  }
}

async function toggleTimer(t) {
  try {
    await api("/api/tasks/timer", { id: t.id, action: t.running ? "stop" : "start" });
    loadTasks();
  } catch (e) {
    toast("err", "Timer failed", e.message);
  }
}

/* ---------- add task ---------- */

const addModal = $("#add-modal");
$("#add-task").addEventListener("click", () => { $("#add-form").reset(); addModal.classList.remove("hidden"); $("#add-form").title.focus(); });
$("#add-close").addEventListener("click", () => addModal.classList.add("hidden"));
$("#add-cancel").addEventListener("click", () => addModal.classList.add("hidden"));
addModal.addEventListener("click", (ev) => { if (ev.target === addModal) addModal.classList.add("hidden"); });
$("#add-form").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = ev.target;
  try {
    await api("/api/tasks", { title: f.title.value, description: f.description.value });
    addModal.classList.add("hidden");
    toast("ok", "Task created", f.title.value);
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't create task", e.message);
  }
});

/* ---------- detail ---------- */

const detailModal = $("#detail-modal");

function openDetail(id) {
  const t = state.tasks.find((x) => x.id === id);
  if (!t) return;
  state.detailId = id;
  renderDetail(t);
  detailModal.classList.remove("hidden");
}

function closeDetail() {
  state.detailId = null;
  detailModal.classList.add("hidden");
}

function renderDetail(t) {
  const titleEl = $("#detail-title");
  if (document.activeElement !== titleEl) titleEl.value = t.title;
  titleEl.onchange = () => saveTask(t.id, { title: titleEl.value });

  const body = $("#detail-body");
  body.innerHTML = "";

  // status + meta
  const row = document.createElement("div");
  row.className = "detail-row";
  const statusSel = document.createElement("select");
  statusSel.className = "detail-status";
  STATUSES.forEach((s) => {
    const o = document.createElement("option");
    o.value = s.key; o.textContent = s.name;
    if (s.key === t.status) o.selected = true;
    statusSel.appendChild(o);
  });
  statusSel.addEventListener("change", () => saveTask(t.id, { status: statusSel.value }));
  const statusWrap = document.createElement("label");
  statusWrap.className = "detail-field-inline";
  statusWrap.append(labelSpan("Status"), statusSel);

  const timerBtn = document.createElement("button");
  timerBtn.className = "btn-mini " + (t.running ? "is-running" : "");
  timerBtn.textContent = t.running ? "⏹ Stop timer" : "▶ Start timer";
  timerBtn.addEventListener("click", () => toggleTimer(t));
  row.append(statusWrap, timerBtn);
  body.appendChild(row);

  // description
  const descWrap = document.createElement("div");
  descWrap.className = "field";
  descWrap.append(labelSpan("Description"));
  const desc = document.createElement("textarea");
  desc.rows = 4; desc.value = t.description || "";
  desc.placeholder = "Details, links, context…";
  desc.addEventListener("change", () => saveTask(t.id, { description: desc.value }));
  descWrap.appendChild(desc);
  body.appendChild(descWrap);

  // time breakdown
  const times = document.createElement("div");
  times.className = "time-grid";
  times.appendChild(timeStat("Worked total", fmtDur(t.worked_seconds || 0), t.running));
  STATUSES.forEach((s) => {
    const secs = (t.status_totals && t.status_totals[s.key]) || 0;
    if (secs > 0 || s.key === t.status) times.appendChild(timeStat("In " + s.name, fmtDur(secs), s.key === t.status));
  });
  body.appendChild(sectionTitle("Time"));
  body.appendChild(times);

  // attachments
  body.appendChild(sectionTitle("Attachments"));
  const drop = document.createElement("div");
  drop.className = "drop-zone";
  drop.innerHTML = '<span>Drop files here, paste a screenshot, or </span>';
  const pick = document.createElement("button");
  pick.className = "btn-mini"; pick.textContent = "choose files";
  const input = document.createElement("input");
  input.type = "file"; input.multiple = true; input.style.display = "none";
  pick.addEventListener("click", () => input.click());
  input.addEventListener("change", () => { if (input.files.length) uploadFiles(t.id, input.files); input.value = ""; });
  drop.append(pick, input);
  drop.addEventListener("dragover", (ev) => { ev.preventDefault(); drop.classList.add("over"); });
  drop.addEventListener("dragleave", () => drop.classList.remove("over"));
  drop.addEventListener("drop", (ev) => { ev.preventDefault(); drop.classList.remove("over"); if (ev.dataTransfer.files.length) uploadFiles(t.id, ev.dataTransfer.files); });
  body.appendChild(drop);

  const grid = document.createElement("div");
  grid.className = "attach-grid";
  (t.attachments || []).forEach((a) => grid.appendChild(buildAttachment(t.id, a)));
  body.appendChild(grid);

  // delete task
  const foot = document.createElement("div");
  foot.className = "detail-foot";
  const created = document.createElement("span");
  created.className = "detail-dim";
  created.textContent = "created " + fmtDate(t.created_at);
  const del = document.createElement("button");
  del.className = "btn-mini danger";
  del.textContent = "Delete task";
  del.addEventListener("click", () => deleteTask(t.id));
  foot.append(created, del);
  body.appendChild(foot);
}

function buildAttachment(taskId, a) {
  const kind = fileKind(a.filename);
  const card = document.createElement("div");
  card.className = "attach";
  const thumb = document.createElement("button");
  thumb.className = "attach-thumb";
  thumb.title = "Preview";
  if (kind === "image") {
    const img = document.createElement("img");
    img.loading = "lazy"; img.alt = a.filename; img.src = fileUrl(taskId, a.id, false);
    thumb.appendChild(img);
  } else {
    thumb.textContent = kindIcon(kind);
  }
  thumb.addEventListener("click", () => openViewer(taskId, a));
  const name = document.createElement("div");
  name.className = "attach-name"; name.textContent = a.filename; name.title = a.filename;
  const meta = document.createElement("div");
  meta.className = "attach-meta"; meta.textContent = fmtSize(a.size || 0);
  const actions = document.createElement("div");
  actions.className = "attach-actions";
  const dl = document.createElement("a");
  dl.className = "btn-mini"; dl.textContent = "↓"; dl.title = "Download";
  dl.href = fileUrl(taskId, a.id, true); dl.setAttribute("download", a.filename);
  const rm = document.createElement("button");
  rm.className = "btn-mini danger"; rm.textContent = "✕"; rm.title = "Delete";
  rm.addEventListener("click", () => deleteAttachment(taskId, a.id));
  actions.append(dl, rm);
  card.append(thumb, name, meta, actions);
  return card;
}

async function saveTask(id, fields) {
  try {
    await api("/api/tasks/update", { id, ...fields });
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't save", e.message);
  }
}

async function deleteTask(id) {
  if (!confirm("Delete this task and its attachments? This can't be undone.")) return;
  try {
    await api("/api/tasks/delete", { id });
    closeDetail();
    toast("ok", "Task deleted");
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't delete", e.message);
  }
}

async function uploadFiles(taskId, fileList) {
  for (const file of fileList) {
    const fd = new FormData();
    fd.append("task_id", taskId);
    fd.append("file", file, file.name || "pasted");
    try {
      const res = await fetch("/api/tasks/upload", { method: "POST", headers: { "X-CSRF-Token": TOKEN }, body: fd });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
    } catch (e) {
      toast("err", "Upload failed", `${file.name}: ${e.message}`);
    }
  }
  toast("ok", "Attachment saved");
  loadTasks();
}

async function deleteAttachment(taskId, fileId) {
  try {
    await api("/api/tasks/file-delete", { task_id: taskId, file_id: fileId });
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't delete file", e.message);
  }
}

$("#detail-close").addEventListener("click", closeDetail);
detailModal.addEventListener("click", (ev) => { if (ev.target === detailModal) closeDetail(); });
// paste-to-attach while the detail modal is open
document.addEventListener("paste", (ev) => {
  if (!state.detailId || detailModal.classList.contains("hidden")) return;
  const files = [...(ev.clipboardData?.files || [])];
  if (files.length) { ev.preventDefault(); uploadFiles(state.detailId, files); }
});

/* ---------- weekly summary ---------- */

const summaryModal = $("#summary-modal");

async function openSummary() {
  state.weekOffset = 0;
  summaryModal.classList.remove("hidden");
  loadSummary();
}
async function loadSummary() {
  const body = $("#summary-body");
  body.textContent = "Loading…";
  try {
    const data = await apiGet("/api/tasks/summary?week=" + state.weekOffset);
    renderSummary(data.summary);
  } catch (e) {
    body.textContent = "Error: " + e.message;
  }
}
function renderSummary(s) {
  $("#week-label").textContent = s.week_label + (state.weekOffset === 0 ? " (this week)" : "");
  const body = $("#summary-body");
  body.innerHTML = "";

  const total = document.createElement("div");
  total.className = "summary-total";
  total.innerHTML = "";
  const big = document.createElement("strong");
  big.textContent = fmtDur(s.total_seconds || 0);
  total.append(document.createTextNode("Worked "), big,
    document.createTextNode(` across ${s.per_task.length} task(s) · ${s.completed.length} completed · ${s.created.length} created`));
  body.appendChild(total);

  if (s.per_task.length) {
    body.appendChild(sectionTitle("Time per task"));
    const max = Math.max(...s.per_task.map((p) => p.seconds), 1);
    s.per_task.forEach((p) => {
      const r = document.createElement("div");
      r.className = "sum-row";
      const nm = document.createElement("span"); nm.className = "sum-name"; nm.textContent = p.title;
      const bar = document.createElement("div"); bar.className = "sum-bar";
      const fill = document.createElement("div"); fill.className = "sum-fill"; fill.style.width = (p.seconds / max * 100) + "%";
      bar.appendChild(fill);
      const val = document.createElement("span"); val.className = "sum-val"; val.textContent = fmtDur(p.seconds);
      const tag = document.createElement("span"); tag.className = "sum-tag"; tag.textContent = STATUS_NAME[p.status] || p.status;
      r.append(nm, bar, val, tag);
      body.appendChild(r);
    });
  } else {
    const none = document.createElement("p");
    none.className = "detail-dim"; none.textContent = "No tracked work this week.";
    body.appendChild(none);
  }

  if (s.completed.length) {
    body.appendChild(sectionTitle("Completed this week"));
    s.completed.forEach((c) => {
      const r = document.createElement("div"); r.className = "sum-line";
      r.textContent = "✓ " + c.title + "  ·  " + fmtDate(c.at);
      body.appendChild(r);
    });
  }
}

$("#open-summary").addEventListener("click", openSummary);
$("#summary-close").addEventListener("click", () => summaryModal.classList.add("hidden"));
summaryModal.addEventListener("click", (ev) => { if (ev.target === summaryModal) summaryModal.classList.add("hidden"); });
$("#week-prev").addEventListener("click", () => { state.weekOffset--; loadSummary(); });
$("#week-next").addEventListener("click", () => { state.weekOffset++; loadSummary(); });

/* ---------- attachment viewer ---------- */

const viewer = $("#viewer");

async function openViewer(taskId, a) {
  const kind = fileKind(a.filename);
  $("#viewer-title").textContent = a.filename;
  const dl = $("#viewer-download");
  dl.href = fileUrl(taskId, a.id, true); dl.setAttribute("download", a.filename);
  const body = $("#viewer-body");
  body.innerHTML = ""; body.className = "viewer-body";
  viewer.classList.remove("hidden");
  if (kind === "image") {
    const img = document.createElement("img"); img.className = "viewer-img"; img.alt = a.filename;
    img.src = fileUrl(taskId, a.id, false); body.appendChild(img);
  } else if (kind === "pdf") {
    const fr = document.createElement("iframe"); fr.className = "viewer-frame"; fr.src = fileUrl(taskId, a.id, false); body.appendChild(fr);
  } else if (kind === "text") {
    body.textContent = "Loading…";
    try {
      const res = await fetch(fileUrl(taskId, a.id, false));
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      let text = await res.text();
      const big = text.length > 500000; if (big) text = text.slice(0, 500000);
      const pre = document.createElement("pre"); pre.className = "viewer-text";
      pre.textContent = text + (big ? "\n\n… truncated" : "");
      body.innerHTML = ""; body.appendChild(pre);
    } catch (e) { body.textContent = "Couldn't load: " + e.message; }
  } else {
    const wrap = document.createElement("div"); wrap.className = "viewer-nopreview";
    const p = document.createElement("p"); p.textContent = "No inline preview for this file type.";
    const a2 = document.createElement("a"); a2.className = "btn btn-primary"; a2.textContent = "Download " + a.filename;
    a2.href = fileUrl(taskId, a.id, true); a2.setAttribute("download", a.filename);
    wrap.append(p, a2); body.appendChild(wrap);
  }
}
$("#viewer-close").addEventListener("click", () => { viewer.classList.add("hidden"); $("#viewer-body").innerHTML = ""; });
viewer.addEventListener("click", (ev) => { if (ev.target === viewer) { viewer.classList.add("hidden"); $("#viewer-body").innerHTML = ""; } });

/* ---------- helpers ---------- */

function labelSpan(text) { const s = document.createElement("span"); s.className = "inline-label"; s.textContent = text; return s; }
function sectionTitle(text) { const h = document.createElement("h3"); h.className = "section-title"; h.textContent = text; return h; }
function timeStat(label, value, hot) {
  const d = document.createElement("div"); d.className = "time-stat" + (hot ? " hot" : "");
  const v = document.createElement("strong"); v.textContent = value;
  const l = document.createElement("span"); l.textContent = label;
  d.append(v, l); return d;
}
function fileKind(name) {
  const ext = (name.split(".").pop() || "").toLowerCase();
  if (["png", "jpg", "jpeg", "gif", "webp", "bmp", "svg", "ico", "avif", "tif", "tiff"].includes(ext)) return "image";
  if (ext === "pdf") return "pdf";
  if (["txt", "log", "md", "markdown", "csv", "tsv", "json", "xml", "yml", "yaml", "ini", "conf", "cfg",
       "env", "sh", "sql", "js", "ts", "css", "html", "htm", "php", "py", "rb", "go", "java", "c", "h",
       "cpp", "toml", "properties"].includes(ext)) return "text";
  return "other";
}
function kindIcon(kind) { return { image: "🖼️", pdf: "📄", text: "📄", other: "📎" }[kind] || "📎"; }
function fmtSize(n) {
  if (n < 1024) return n + " B";
  const u = ["KB", "MB", "GB"]; let i = -1;
  do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
  return n.toFixed(n < 10 ? 1 : 0) + " " + u[i];
}
function fmtDur(s) {
  s = Math.max(0, Math.floor(s));
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return `${m}m ${sec}s`;
  return `${sec}s`;
}
function fmtClock(s) {
  s = Math.max(0, Math.floor(s));
  const p = (n) => String(n).padStart(2, "0");
  return `${p(Math.floor(s / 3600))}:${p(Math.floor((s % 3600) / 60))}:${p(s % 60)}`;
}
function fmtDate(s) { if (!s) return ""; const d = new Date(s); return isNaN(d) ? "" : d.toLocaleString(); }
function toast(kind, title, detail) {
  const box = document.createElement("div"); box.className = `toast ${kind}`;
  const b = document.createElement("b"); b.textContent = title; box.appendChild(b);
  if (detail) { const s = document.createElement("small"); s.textContent = detail; box.appendChild(s); }
  $("#toasts").appendChild(box);
  setTimeout(() => { box.style.opacity = "0"; setTimeout(() => box.remove(), 300); }, kind === "err" ? 7000 : 3500);
}

document.addEventListener("keydown", (ev) => {
  if (ev.key !== "Escape") return;
  [viewer, detailModal, summaryModal, addModal].forEach((m) => {
    if (m === detailModal && !m.classList.contains("hidden")) closeDetail();
    else m.classList.add("hidden");
  });
});

loadTasks();
setInterval(tick, 1000);
setInterval(loadTasks, 30000); // periodic re-sync (timers, multi-tab)
