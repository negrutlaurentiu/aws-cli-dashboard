"use strict";

const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const $ = (s, r = document) => r.querySelector(s);

const state = {
  accounts: [],   // [{id,label,target_profile}]
  profile: "",    // active profile (target_profile of selected account)
  bucket: "",
  prefix: "",
  dlTimer: null,
};

const els = {
  account: $("#account-select"),
  bucket: $("#bucket-select"),
  crumb: $("#breadcrumb"),
  list: $("#s3-list"),
  status: $("#s3-status"),
};

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

function objectUrl(key, dl) {
  const q = new URLSearchParams({ profile: state.profile, bucket: state.bucket, key, token: TOKEN });
  if (dl) q.set("dl", "1");
  return "/api/s3/object?" + q.toString();
}

/* ---------- accounts + buckets ---------- */

async function init() {
  try {
    // /api/accounts is a GET endpoint (the POST route creates an account), so don't use api()
    const res = await fetch("/api/accounts", { headers: { "X-CSRF-Token": TOKEN } });
    const data = await res.json();
    if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
    state.accounts = (data.accounts || []).map((a) => ({ id: a.id, label: a.label, target_profile: a.target_profile }));
  } catch (e) {
    setStatus(`Couldn't load accounts: ${e.message}`, "err");
    return;
  }
  if (state.accounts.length === 0) {
    setStatus("No accounts configured yet. Add one on the Credentials page first.", "err");
    return;
  }

  els.account.innerHTML = "";
  state.accounts.forEach((a) => {
    const o = document.createElement("option");
    o.value = a.id;
    o.textContent = `${a.label}  (${a.target_profile})`;
    els.account.appendChild(o);
  });
  const saved = localStorage.getItem("awsdash.account");
  if (saved && state.accounts.some((a) => a.id === saved)) els.account.value = saved;

  await onAccountChange();
}

async function onAccountChange() {
  const acc = state.accounts.find((a) => a.id === els.account.value) || state.accounts[0];
  localStorage.setItem("awsdash.account", acc.id);
  state.profile = acc.target_profile;
  state.bucket = "";
  state.prefix = "";
  els.bucket.innerHTML = `<option>loading…</option>`;
  setStatus(`Loading buckets for profile ${state.profile}…`);
  try {
    const data = await api("/api/s3/buckets", { profile: state.profile });
    const buckets = data.buckets || [];
    els.bucket.innerHTML = "";
    if (buckets.length === 0) {
      els.bucket.innerHTML = `<option value="">(no buckets)</option>`;
      els.list.innerHTML = "";
      setStatus("No buckets visible for this profile.", "muted");
      return;
    }
    buckets.forEach((b) => {
      const o = document.createElement("option");
      o.value = b.name;
      o.textContent = b.name;
      els.bucket.appendChild(o);
    });
    state.bucket = buckets[0].name;
    await loadList();
  } catch (e) {
    els.bucket.innerHTML = `<option value="">(error)</option>`;
    els.list.innerHTML = "";
    setStatus(s3Error(e), "err");
  }
}

async function onBucketChange() {
  state.bucket = els.bucket.value;
  state.prefix = "";
  await loadList();
}

/* ---------- listing ---------- */

async function loadList() {
  if (!state.bucket) return;
  setStatus("Loading…");
  renderBreadcrumb();
  try {
    const data = await api("/api/s3/list", { profile: state.profile, bucket: state.bucket, prefix: state.prefix });
    renderList(data);
    const n = (data.prefixes || []).length + (data.objects || []).length;
    setStatus(`${n} item${n === 1 ? "" : "s"}${data.truncated ? " (first 2000 shown — narrow with a subfolder)" : ""}`, "muted");
  } catch (e) {
    els.list.innerHTML = "";
    setStatus(s3Error(e), "err");
  }
}

function renderList(data) {
  els.list.innerHTML = "";
  const prefixes = data.prefixes || [];
  const objects = data.objects || [];

  if (prefixes.length === 0 && objects.length === 0) {
    const empty = document.createElement("div");
    empty.className = "s3-empty";
    empty.textContent = "This folder is empty.";
    els.list.appendChild(empty);
    return;
  }

  prefixes.forEach((p) => {
    const name = p.slice(state.prefix.length).replace(/\/$/, "");
    const row = makeRow();
    row.querySelector(".s3-icon").textContent = "📁";
    const nameBtn = row.querySelector(".s3-name");
    nameBtn.textContent = name + "/";
    nameBtn.classList.add("is-folder");
    nameBtn.addEventListener("click", () => { state.prefix = p; loadList(); });
    els.list.appendChild(row);
  });

  objects.forEach((o) => {
    const kind = fileKind(o.name);
    const row = makeRow();
    row.querySelector(".s3-icon").textContent = kindIcon(kind);
    const nameBtn = row.querySelector(".s3-name");
    nameBtn.textContent = o.name;
    nameBtn.title = "Open";
    nameBtn.addEventListener("click", () => openViewer(o));
    row.querySelector(".s3-size").textContent = fmtSize(o.size);
    row.querySelector(".s3-date").textContent = fmtDate(o.modified);

    const actions = row.querySelector(".s3-row-actions");
    const view = document.createElement("button");
    view.className = "btn-mini";
    view.textContent = "View";
    view.addEventListener("click", () => openViewer(o));
    const dl = document.createElement("a");
    dl.className = "btn-mini";
    dl.textContent = "Download";
    dl.href = objectUrl(o.key, true);
    dl.setAttribute("download", o.name);
    actions.appendChild(view);
    actions.appendChild(dl);
    els.list.appendChild(row);
  });
}

function makeRow() {
  return $("#row-tpl").content.firstElementChild.cloneNode(true);
}

function renderBreadcrumb() {
  els.crumb.innerHTML = "";
  const root = document.createElement("button");
  root.className = "crumb";
  root.textContent = state.bucket || "(bucket)";
  root.addEventListener("click", () => { state.prefix = ""; loadList(); });
  els.crumb.appendChild(root);

  let acc = "";
  state.prefix.split("/").filter(Boolean).forEach((seg) => {
    acc += seg + "/";
    const sep = document.createElement("span");
    sep.className = "crumb-sep";
    sep.textContent = "/";
    els.crumb.appendChild(sep);
    const b = document.createElement("button");
    b.className = "crumb";
    b.textContent = seg;
    const here = acc;
    b.addEventListener("click", () => { state.prefix = here; loadList(); });
    els.crumb.appendChild(b);
  });
}

/* ---------- viewer ---------- */

const viewer = $("#viewer");

async function openViewer(o) {
  const kind = fileKind(o.name);
  $("#viewer-title").textContent = o.name;
  const dl = $("#viewer-download");
  dl.href = objectUrl(o.key, true);
  dl.setAttribute("download", o.name);
  const body = $("#viewer-body");
  body.innerHTML = "";
  body.className = "viewer-body";
  viewer.classList.remove("hidden");

  if (kind === "image") {
    const img = document.createElement("img");
    img.className = "viewer-img";
    img.alt = o.name;
    img.src = objectUrl(o.key, false);
    body.appendChild(img);
  } else if (kind === "pdf") {
    const frame = document.createElement("iframe");
    frame.className = "viewer-frame";
    frame.src = objectUrl(o.key, false);
    body.appendChild(frame);
  } else if (kind === "text") {
    body.textContent = "Loading…";
    try {
      const res = await fetch(objectUrl(o.key, false));
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      let text = await res.text();
      const big = text.length > 500000;
      if (big) text = text.slice(0, 500000);
      const pre = document.createElement("pre");
      pre.className = "viewer-text";
      pre.textContent = text + (big ? "\n\n… truncated (download to see the rest)" : "");
      body.innerHTML = "";
      body.appendChild(pre);
    } catch (e) {
      body.innerHTML = "";
      body.appendChild(noPreview(o, `Couldn't load text: ${e.message}`));
    }
  } else {
    body.appendChild(noPreview(o, "No inline preview for this file type."));
  }
}

function noPreview(o, msg) {
  const wrap = document.createElement("div");
  wrap.className = "viewer-nopreview";
  const p = document.createElement("p");
  p.textContent = msg;
  const a = document.createElement("a");
  a.className = "btn btn-primary";
  a.textContent = "Download " + o.name;
  a.href = objectUrl(o.key, true);
  a.setAttribute("download", o.name);
  wrap.appendChild(p);
  wrap.appendChild(a);
  return wrap;
}

function closeViewer() {
  viewer.classList.add("hidden");
  $("#viewer-body").innerHTML = "";
}

/* ---------- folder download ---------- */

async function downloadFolder() {
  if (!state.bucket) return;
  const where = state.prefix ? `${state.bucket}/${state.prefix}` : `the whole bucket "${state.bucket}"`;
  if (!confirm(`Download ${where} to your local Downloads folder?`)) return;
  const panel = $("#dl-panel");
  panel.classList.remove("hidden");
  $("#dl-title").textContent = "Starting download…";
  $("#dl-info").textContent = "";
  $("#dl-log").textContent = "";
  try {
    const { job, dest } = await api("/api/s3/download", { profile: state.profile, bucket: state.bucket, prefix: state.prefix });
    $("#dl-title").textContent = "Downloading…";
    $("#dl-info").textContent = "→ " + dest;
    pollDownload(job, dest);
  } catch (e) {
    $("#dl-title").textContent = "Download failed";
    $("#dl-info").textContent = e.message;
  }
}

function pollDownload(job, dest) {
  if (state.dlTimer) clearInterval(state.dlTimer);
  const tick = async () => {
    try {
      const s = await api("/api/s3/download-status", { job });
      $("#dl-log").textContent = s.tail || "";
      $("#dl-title").textContent = s.done ? "" : `Downloading… ${s.files} file(s)`;
      if (s.done) {
        clearInterval(state.dlTimer);
        state.dlTimer = null;
        if (s.exit === 0) {
          $("#dl-title").textContent = `✓ Done — ${s.files} file(s)`;
          toast("ok", "Download complete", `${s.files} file(s) → ${dest}`);
        } else {
          $("#dl-title").textContent = `✖ Failed (exit ${s.exit})`;
          toast("err", "Download failed", s.tail || `exit ${s.exit}`);
        }
      }
    } catch (e) {
      clearInterval(state.dlTimer);
      state.dlTimer = null;
      $("#dl-title").textContent = "Status error";
      $("#dl-info").textContent = e.message;
    }
  };
  state.dlTimer = setInterval(tick, 1500);
  tick();
}

/* ---------- helpers ---------- */

function fileKind(name) {
  const ext = (name.split(".").pop() || "").toLowerCase();
  if (["png", "jpg", "jpeg", "gif", "webp", "bmp", "svg", "ico", "avif", "tif", "tiff"].includes(ext)) return "image";
  if (ext === "pdf") return "pdf";
  if (["txt", "log", "md", "markdown", "csv", "tsv", "json", "xml", "yml", "yaml", "ini", "conf", "cfg",
       "env", "sh", "sql", "js", "ts", "css", "html", "htm", "php", "py", "rb", "go", "java", "c", "h",
       "cpp", "toml", "properties", "gitignore"].includes(ext)) return "text";
  return "other";
}

function kindIcon(kind) {
  return { image: "🖼️", pdf: "📄", text: "📄", other: "📦" }[kind] || "📦";
}

function fmtSize(n) {
  if (n < 1024) return n + " B";
  const u = ["KB", "MB", "GB", "TB"];
  let i = -1;
  do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
  return n.toFixed(n < 10 ? 1 : 0) + " " + u[i];
}

function fmtDate(s) {
  if (!s) return "";
  const d = new Date(s);
  return isNaN(d) ? "" : d.toLocaleString();
}

function s3Error(e) {
  let m = e.message || String(e);
  if (/expired/i.test(m)) m = "Credentials expired — refresh this account on the Credentials page. " + m;
  else if (/AccessDenied|not authorized/i.test(m)) m = "Access denied for this profile. " + m;
  return m;
}

function setStatus(msg, cls) {
  els.status.className = "s3-status" + (cls ? " " + cls : "");
  els.status.textContent = msg;
}

function toast(kind, title, detail) {
  const box = document.createElement("div");
  box.className = `toast ${kind}`;
  const b = document.createElement("b"); b.textContent = title; box.appendChild(b);
  if (detail) { const s = document.createElement("small"); s.textContent = detail; box.appendChild(s); }
  $("#toasts").appendChild(box);
  setTimeout(() => { box.style.opacity = "0"; setTimeout(() => box.remove(), 300); }, kind === "err" ? 7000 : 4000);
}

/* ---------- wire up ---------- */

els.account.addEventListener("change", onAccountChange);
els.bucket.addEventListener("change", onBucketChange);
$("#reload-btn").addEventListener("click", loadList);
$("#download-folder").addEventListener("click", downloadFolder);
$("#viewer-close").addEventListener("click", closeViewer);
viewer.addEventListener("click", (ev) => { if (ev.target === viewer) closeViewer(); });
$("#dl-close").addEventListener("click", () => $("#dl-panel").classList.add("hidden"));
document.addEventListener("keydown", (ev) => {
  if (ev.key === "Escape" && !viewer.classList.contains("hidden")) closeViewer();
});

init();
