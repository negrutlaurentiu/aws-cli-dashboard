"use strict";

const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const $ = (s, r = document) => r.querySelector(s);

const state = {
  projects: [],   // [{id, name, redmine_url, redmine_host, redmine_identifier}]
  instances: [],  // [{host, has_key}]
  weekOffset: -1, // reconciliation week (-1 = last week)
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

/* ---------- load + render ---------- */
async function load() {
  try {
    const d = await apiGet("/api/projects");
    state.projects = d.projects || [];
    state.instances = d.instances || [];
    renderTable();
    renderKeys();
  } catch (e) {
    toast("err", "Couldn't load projects", e.message);
  }
}

function renderTable() {
  const table = $("#proj-table");
  table.replaceChildren();

  const head = document.createElement("div");
  head.className = "proj-row proj-row-head";
  ["Name", "Redmine project URL", "Last week", ""].forEach((t) => {
    const c = document.createElement("span"); c.className = "proj-h"; c.textContent = t; head.appendChild(c);
  });
  table.appendChild(head);

  if (!state.projects.length) {
    const empty = document.createElement("p");
    empty.className = "proj-empty";
    empty.textContent = "No projects yet. Add one — name it exactly as you tag tasks, and paste its Redmine project URL.";
    table.appendChild(empty);
  }

  state.projects.forEach((p, i) => table.appendChild(buildRow(p, i)));
}

function buildRow(p, idx) {
  const row = document.createElement("div");
  row.className = "proj-row";
  row.dataset.idx = String(idx);

  const name = document.createElement("input");
  name.className = "proj-name"; name.placeholder = "Project name (matches tasks)";
  name.value = p.name || ""; name.setAttribute("aria-label", "Project name");

  const url = document.createElement("input");
  url.className = "proj-url"; url.placeholder = "https://redmine.example.com/projects/slug";
  url.value = p.redmine_url || ""; url.setAttribute("aria-label", "Redmine project URL");
  url.spellcheck = false;

  const status = document.createElement("span");
  status.className = "proj-status";
  // re-attach the most recent reconciliation result for this name, if any
  if (p.__status) applyStatus(status, p.__status);

  const del = document.createElement("button");
  del.type = "button"; del.className = "btn-mini danger proj-del"; del.textContent = "✕"; del.title = "Remove project";
  del.addEventListener("click", () => delRow(idx));

  row.append(name, url, status, del);
  return row;
}

// Read the (possibly hand-edited) table back into the model — preserving any reconciliation result.
function collectRows() {
  return [...document.querySelectorAll("#proj-table .proj-row:not(.proj-row-head)")].map((row) => {
    const i = Number(row.dataset.idx);
    const prev = state.projects[i] || {};
    return {
      id: prev.id || "",
      name: $(".proj-name", row).value.trim(),
      redmine_url: $(".proj-url", row).value.trim(),
      __status: prev.__status,
    };
  });
}

function addRow() {
  state.projects = collectRows();
  state.projects.push({ id: "", name: "", redmine_url: "" });
  renderTable();
  const rows = document.querySelectorAll("#proj-table .proj-row:not(.proj-row-head)");
  const last = rows[rows.length - 1];
  if (last) $(".proj-name", last).focus();
}

function delRow(idx) {
  state.projects = collectRows();
  state.projects.splice(idx, 1);
  renderTable();
}

async function saveProjects() {
  const btn = $("#proj-save");
  btn.disabled = true;
  try {
    const projects = collectRows().filter((p) => p.name !== "" || p.redmine_url !== "");
    const d = await api("/api/projects", { projects });
    state.projects = d.projects || [];
    state.instances = d.instances || [];
    renderTable();
    renderKeys();
    toast("ok", "Projects saved", `${state.projects.length} project${state.projects.length === 1 ? "" : "s"}`);
  } catch (e) {
    toast("err", "Couldn't save projects", e.message);
  } finally {
    btn.disabled = false;
  }
}

/* ---------- API keys ---------- */
function renderKeys() {
  const list = $("#key-list");
  list.replaceChildren();
  if (!state.instances.length) {
    const p = document.createElement("p");
    p.className = "proj-empty";
    p.textContent = "No Redmine instances yet — add a project with a Redmine URL and Save, then its host appears here for a key.";
    list.appendChild(p);
    return;
  }
  state.instances.forEach((inst) => {
    const row = document.createElement("div");
    row.className = "key-row"; row.dataset.host = inst.host;

    const host = document.createElement("span");
    host.className = "key-host"; host.textContent = inst.host;

    const stateLabel = document.createElement("span");
    stateLabel.className = "key-state " + (inst.has_key ? "set" : "unset");
    stateLabel.textContent = inst.has_key ? "● key stored" : "○ no key";

    const input = document.createElement("input");
    input.className = "key-input"; input.type = "password"; input.autocomplete = "new-password";
    input.placeholder = inst.has_key ? "leave blank to keep" : "paste API access key";
    input.setAttribute("aria-label", "API key for " + inst.host);

    const clear = document.createElement("button");
    clear.type = "button"; clear.className = "btn-mini key-clear"; clear.textContent = "Clear";
    clear.title = "Forget the stored key for " + inst.host;
    clear.disabled = !inst.has_key;
    clear.addEventListener("click", () => clearKey(inst.host));

    row.append(host, stateLabel, input, clear);
    list.appendChild(row);
  });
}

function collectKeys() {
  const keys = {};
  document.querySelectorAll("#key-list .key-row").forEach((row) => {
    const v = $(".key-input", row).value.trim();
    if (v !== "") keys[row.dataset.host] = v; // only send typed keys → server keeps the rest
  });
  return keys;
}

async function saveKeys() {
  const btn = $("#keys-save");
  const keys = collectKeys();
  if (!Object.keys(keys).length) { toast("info", "Nothing to save", "Type a key into an instance first."); return; }
  btn.disabled = true;
  try {
    const d = await api("/api/redmine/keys", { keys });
    state.instances = d.instances || [];
    renderKeys();
    toast("ok", "API keys saved");
  } catch (e) {
    toast("err", "Couldn't save keys", e.message);
  } finally {
    btn.disabled = false;
  }
}

async function clearKey(host) {
  if (!confirm(`Forget the stored Redmine API key for ${host}?`)) return;
  try {
    const d = await api("/api/redmine/keys", { clear: [host] });
    state.instances = d.instances || [];
    renderKeys();
    toast("ok", "Key cleared", host);
  } catch (e) {
    toast("err", "Couldn't clear key", e.message);
  }
}

/* ---------- reconciliation ---------- */
function weekLabel(off) {
  if (off === 0) return "this week";
  if (off === -1) return "last week";
  if (off < -1) return `${-off} weeks ago`;
  return `${off} week${off === 1 ? "" : "s"} ahead`;
}

function fmtH(h) {
  const n = Number(h) || 0;
  return n.toFixed(2).replace(/\.?0+$/, "") + "h";
}

function applyStatus(el, p) {
  el.className = "proj-status rm-" + p.status;
  el.textContent = ""; el.title = "";
  const dash = fmtH(p.dashboard_hours || 0);
  switch (p.status) {
    case "ok":
      el.textContent = `✓ ${fmtH(p.redmine_hours)} ≥ ${dash}`;
      el.title = `Logged ${fmtH(p.redmine_hours)} in Redmine, covering the ${dash} the dashboard tracked.`;
      break;
    case "short":
      el.textContent = `⚠ log ${fmtH(p.short_hours)} more`;
      el.title = `Redmine has ${fmtH(p.redmine_hours)} but the dashboard tracked ${dash} — ${fmtH(p.short_hours)} still to log.`;
      break;
    case "none":
      el.textContent = "· no tracked hours";
      el.title = "The dashboard tracked no time on this project that week — nothing to reconcile.";
      break;
    case "no_key":
      el.textContent = "🔑 add API key";
      el.title = `No Redmine API key for ${p.host || "this instance"} — add it below.`;
      break;
    case "no_url":
      el.textContent = "· no Redmine URL";
      el.title = "Add this project's Redmine URL to reconcile it.";
      break;
    case "error":
      el.textContent = "⚠ Redmine error";
      el.title = p.error || "Redmine request failed.";
      break;
    default:
      el.textContent = "";
  }
}

async function runRecon() {
  const btn = $("#recon-run");
  const note = $("#recon-note");
  btn.disabled = true;
  note.textContent = "Checking Redmine…";
  // sync any unsaved edits into the model so status cells match the displayed rows
  state.projects = collectRows();
  try {
    const d = await apiGet("/api/projects/redmine-status?week=" + state.weekOffset);
    $("#recon-label").textContent = d.week_label || weekLabel(state.weekOffset);
    const byName = {};
    (d.projects || []).forEach((p) => { byName[p.name] = p; });
    let pending = 0, ok = 0;
    state.projects.forEach((p) => {
      const r = byName[p.name];
      p.__status = r || null;
      if (r && r.status === "short") pending++;
      if (r && r.status === "ok") ok++;
    });
    // paint status cells onto the existing rows (without rebuilding inputs the user may be in)
    document.querySelectorAll("#proj-table .proj-row:not(.proj-row-head)").forEach((row) => {
      const i = Number(row.dataset.idx);
      const p = state.projects[i];
      const cell = $(".proj-status", row);
      if (p && p.__status) applyStatus(cell, p.__status); else if (cell) { cell.className = "proj-status"; cell.textContent = ""; }
    });
    note.textContent = `${d.from} → ${d.to} · ${ok} covered` + (pending ? `, ${pending} short` : "");
  } catch (e) {
    note.textContent = "";
    toast("err", "Redmine check failed", e.message);
  } finally {
    btn.disabled = false;
  }
}

function shiftWeek(delta) {
  state.weekOffset += delta;
  $("#recon-label").textContent = weekLabel(state.weekOffset);
  runRecon();
}

/* ---------- toasts ---------- */
function toast(kind, title, detail) {
  const box = document.createElement("div"); box.className = `toast ${kind}`;
  const b = document.createElement("b"); b.textContent = title; box.appendChild(b);
  if (detail) { const s = document.createElement("small"); s.textContent = detail; box.appendChild(s); }
  $("#toasts").appendChild(box);
  setTimeout(() => { box.style.opacity = "0"; setTimeout(() => box.remove(), 300); }, kind === "err" ? 7000 : 3500);
}

/* ---------- wire up ---------- */
$("#proj-add").addEventListener("click", addRow);
$("#proj-save").addEventListener("click", saveProjects);
$("#keys-save").addEventListener("click", saveKeys);
$("#recon-run").addEventListener("click", runRecon);
$("#recon-prev").addEventListener("click", () => shiftWeek(-1));
$("#recon-next").addEventListener("click", () => shiftWeek(1));

$("#recon-label").textContent = weekLabel(state.weekOffset);
load();
