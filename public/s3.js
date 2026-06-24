"use strict";

const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const $ = (s, r = document) => r.querySelector(s);

// Close a modal only when the click both starts and ends on the backdrop — so selecting text
// inside it and releasing the mouse out on the backdrop doesn't close it mid-selection.
function onBackdrop(el, close) {
  let downOnSelf = false;
  el.addEventListener("mousedown", (ev) => { downOnSelf = ev.target === el; });
  el.addEventListener("click", (ev) => { if (ev.target === el && downOnSelf) close(); });
}

const state = {
  accounts: [],
  profiles: [],            // every profile name from ~/.aws (credentials + config)
  profile: "",
  view: "buckets",          // "buckets" | "objects"
  buckets: [],
  bucketPage: 0,
  bucketsPerPage: 24,
  bucket: "",
  prefix: "",
  pageSize: 200,
  tokenStack: [""],         // starting token per object page; index 0 = first page ("")
  pageIndex: 0,
  nextToken: "",
  filter: "",
  dlTimer: null,
};

const els = {
  account: $("#account-select"),
  crumb: $("#breadcrumb"),
  list: $("#s3-list"),
  status: $("#s3-status"),
  pager: $("#s3-pager"),
  filter: $("#s3-filter"),
  download: $("#download-folder"),
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

/* ---------- accounts ---------- */

async function init() {
  try {
    const [aRes, pRes] = await Promise.all([
      fetch("/api/accounts", { headers: { "X-CSRF-Token": TOKEN } }),
      fetch("/api/profiles", { headers: { "X-CSRF-Token": TOKEN } }),
    ]);
    const aData = await aRes.json();
    const pData = await pRes.json();
    if (!aRes.ok || aData.ok === false) throw new Error(aData.error || `HTTP ${aRes.status}`);
    if (!pRes.ok || pData.ok === false) throw new Error(pData.error || `HTTP ${pRes.status}`);
    state.accounts = (aData.accounts || []).map((a) => ({
      id: a.id, label: a.label, source_profile: a.source_profile, target_profile: a.target_profile,
    }));
    state.profiles = pData.profiles || [];
  } catch (e) {
    setStatus(`Couldn't load profiles: ${e.message}`, "err");
    return;
  }
  buildProfileSelect();
  if (!els.account.options.length) {
    setStatus("No AWS profiles found. Add an account on the Credentials page, or set up ~/.aws/credentials.", "err");
    return;
  }
  const saved = localStorage.getItem("awsdash.profile");
  if (saved && [...els.account.options].some((o) => o.value === saved)) els.account.value = saved;
  await onProfileChange();
}

// Group the selector: dashboard accounts (with their label) first, then every other ~/.aws profile.
function buildProfileSelect() {
  const sel = els.account;
  sel.innerHTML = "";
  const accountTargets = new Set(state.accounts.map((a) => a.target_profile));
  if (state.accounts.length) {
    const og = document.createElement("optgroup");
    og.label = "Accounts";
    state.accounts.forEach((a) => {
      const o = document.createElement("option");
      o.value = a.target_profile;
      o.textContent = `${a.label}  (${a.target_profile})`;
      og.appendChild(o);
    });
    sel.appendChild(og);
  }
  const others = state.profiles.filter((p) => !accountTargets.has(p));
  if (others.length) {
    const og = document.createElement("optgroup");
    og.label = "Other profiles";
    others.forEach((p) => {
      const o = document.createElement("option");
      o.value = p;
      o.textContent = p;
      og.appendChild(o);
    });
    sel.appendChild(og);
  }
}

async function onProfileChange() {
  state.profile = els.account.value;
  localStorage.setItem("awsdash.profile", state.profile);
  await loadBuckets();
}

/* ---------- buckets view ---------- */

async function loadBuckets() {
  state.view = "buckets";
  state.bucket = "";
  state.prefix = "";
  state.filter = "";
  els.filter.value = "";
  els.download.classList.add("hidden");
  setStatus(`Loading buckets for profile ${state.profile}…`);
  els.list.innerHTML = "";
  els.pager.innerHTML = "";
  try {
    const data = await api("/api/s3/buckets", { profile: state.profile });
    state.buckets = data.buckets || [];
    state.bucketPage = 0;
    renderBuckets();
  } catch (e) {
    state.buckets = [];
    setStatus(s3Error(e), "err");
  }
}

function filteredBuckets() {
  const f = state.filter.trim().toLowerCase();
  return f ? state.buckets.filter((b) => b.name.toLowerCase().includes(f)) : state.buckets;
}

function renderBuckets() {
  state.view = "buckets";
  els.download.classList.add("hidden");
  renderBreadcrumb();
  els.list.innerHTML = "";

  const all = filteredBuckets();
  const per = state.bucketsPerPage;
  const total = all.length;
  const start = state.bucketPage * per;
  const page = all.slice(start, start + per);

  if (total === 0) {
    showEmpty(state.filter ? "No buckets match your filter." : "No buckets visible for this profile.");
    els.pager.innerHTML = "";
    setStatus(`${state.buckets.length} bucket(s)`, "muted");
    return;
  }

  page.forEach((b) => {
    const row = makeRow();
    row.querySelector(".s3-icon").textContent = "🪣";
    const name = row.querySelector(".s3-name");
    name.textContent = b.name;
    name.classList.add("is-folder");
    name.title = "Open bucket";
    name.addEventListener("click", () => openBucket(b.name));
    row.querySelector(".s3-date").textContent = fmtDate(b.created);
    const actions = row.querySelector(".s3-row-actions");
    const dl = document.createElement("button");
    dl.className = "btn-mini";
    dl.textContent = "Download";
    dl.title = "Download the whole bucket";
    dl.addEventListener("click", (ev) => { ev.stopPropagation(); downloadFolder(b.name, ""); });
    actions.appendChild(dl);
    els.list.appendChild(row);
  });

  renderPager({
    label: `Buckets ${start + 1}–${start + page.length} of ${total}`,
    canPrev: state.bucketPage > 0,
    canNext: start + per < total,
    onPrev: () => { state.bucketPage--; renderBuckets(); },
    onNext: () => { state.bucketPage++; renderBuckets(); },
  });
  setStatus(`${total} bucket(s)${state.filter ? " (filtered)" : ""}`, "muted");
}

function openBucket(name) {
  state.bucket = name;
  state.prefix = "";
  state.filter = "";
  els.filter.value = "";
  resetObjectPaging();
  loadObjects(0);
}

/* ---------- objects view ---------- */

function resetObjectPaging() {
  state.tokenStack = [""];
  state.pageIndex = 0;
  state.nextToken = "";
}

async function loadObjects(pageIndex) {
  state.view = "objects";
  els.download.classList.remove("hidden");
  renderBreadcrumb();
  setStatus("Loading…");
  const token = state.tokenStack[pageIndex] ?? "";
  try {
    const data = await api("/api/s3/list", {
      profile: state.profile, bucket: state.bucket, prefix: state.prefix, token, max: state.pageSize,
    });
    state.pageIndex = pageIndex;
    state.nextToken = data.next_token || "";
    if (state.nextToken && state.tokenStack[pageIndex + 1] === undefined) {
      state.tokenStack[pageIndex + 1] = state.nextToken;
    }
    renderObjects(data);
  } catch (e) {
    els.list.innerHTML = "";
    els.pager.innerHTML = "";
    setStatus(s3Error(e), "err");
  }
}

function renderObjects(data) {
  const prefixes = data.prefixes || [];
  const objects = data.objects || [];
  els.list.innerHTML = "";

  if (prefixes.length === 0 && objects.length === 0) {
    showEmpty("This folder is empty.");
  } else {
    prefixes.forEach((p) => {
      const name = p.slice(state.prefix.length).replace(/\/$/, "");
      const row = makeRow();
      row.dataset.name = name.toLowerCase();
      row.querySelector(".s3-icon").textContent = "📁";
      const nm = row.querySelector(".s3-name");
      nm.textContent = name + "/";
      nm.classList.add("is-folder");
      nm.addEventListener("click", () => { state.prefix = p; resetObjectPaging(); loadObjects(0); });
      els.list.appendChild(row);
    });
    objects.forEach((o) => {
      const kind = fileKind(o.name);
      const row = makeRow();
      row.dataset.name = o.name.toLowerCase();
      row.querySelector(".s3-icon").textContent = kindIcon(kind);
      const nm = row.querySelector(".s3-name");
      nm.textContent = o.name;
      nm.title = "Open";
      nm.addEventListener("click", () => openViewer(o));
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

  const n = prefixes.length + objects.length;
  renderPager({
    label: `Page ${state.pageIndex + 1}${state.nextToken ? "" : " (last)"} · ${n} item${n === 1 ? "" : "s"} on this page`,
    canPrev: state.pageIndex > 0,
    canNext: !!state.nextToken,
    onPrev: () => loadObjects(state.pageIndex - 1),
    onNext: () => loadObjects(state.pageIndex + 1),
  });
  applyObjectFilter();
  setStatus(`${state.bucket}/${state.prefix}`, "muted");
}

/* ---------- breadcrumb + pager + filter ---------- */

function renderBreadcrumb() {
  els.crumb.innerHTML = "";
  const root = document.createElement("button");
  root.className = "crumb";
  root.textContent = "All buckets";
  root.addEventListener("click", () => { state.filter = ""; els.filter.value = ""; loadBuckets(); });
  els.crumb.appendChild(root);
  if (state.view === "buckets") return;

  crumbSep();
  const bkt = document.createElement("button");
  bkt.className = "crumb";
  bkt.textContent = state.bucket;
  bkt.addEventListener("click", () => { state.prefix = ""; resetObjectPaging(); loadObjects(0); });
  els.crumb.appendChild(bkt);

  let acc = "";
  state.prefix.split("/").filter(Boolean).forEach((seg) => {
    acc += seg + "/";
    crumbSep();
    const b = document.createElement("button");
    b.className = "crumb";
    b.textContent = seg;
    const here = acc;
    b.addEventListener("click", () => { state.prefix = here; resetObjectPaging(); loadObjects(0); });
    els.crumb.appendChild(b);
  });
}

function crumbSep() {
  const s = document.createElement("span");
  s.className = "crumb-sep";
  s.textContent = "/";
  els.crumb.appendChild(s);
}

function renderPager({ label, canPrev, canNext, onPrev, onNext }) {
  els.pager.innerHTML = "";
  const prev = document.createElement("button");
  prev.className = "btn-mini";
  prev.textContent = "‹ Prev";
  prev.disabled = !canPrev;
  prev.addEventListener("click", onPrev);
  const info = document.createElement("span");
  info.className = "pager-info";
  info.textContent = label;
  const next = document.createElement("button");
  next.className = "btn-mini";
  next.textContent = "Next ›";
  next.disabled = !canNext;
  next.addEventListener("click", onNext);
  els.pager.appendChild(prev);
  els.pager.appendChild(info);
  els.pager.appendChild(next);
}

function onFilterInput() {
  state.filter = els.filter.value;
  if (state.view === "buckets") {
    state.bucketPage = 0;
    renderBuckets();
  } else {
    applyObjectFilter();
  }
}

function applyObjectFilter() {
  const f = state.filter.trim().toLowerCase();
  els.list.querySelectorAll(".s3-row").forEach((row) => {
    const name = row.dataset.name || "";
    row.style.display = !f || name.includes(f) ? "" : "none";
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

/* ---------- folder/bucket download ---------- */

async function downloadFolder(bucket, prefix) {
  const where = prefix ? `${bucket}/${prefix}` : `the whole bucket "${bucket}"`;
  if (!confirm(`Download ${where} to your local Downloads folder?`)) return;
  const panel = $("#dl-panel");
  panel.classList.remove("hidden");
  $("#dl-title").textContent = "Starting download…";
  $("#dl-info").textContent = "";
  $("#dl-log").textContent = "";
  try {
    const { job, dest } = await api("/api/s3/download", { profile: state.profile, bucket, prefix });
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

function makeRow() {
  return $("#row-tpl").content.firstElementChild.cloneNode(true);
}

function showEmpty(msg) {
  const empty = document.createElement("div");
  empty.className = "s3-empty";
  empty.textContent = msg;
  els.list.appendChild(empty);
}

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

els.account.addEventListener("change", onProfileChange);
els.filter.addEventListener("input", onFilterInput);
$("#reload-btn").addEventListener("click", () => (state.view === "buckets" ? loadBuckets() : loadObjects(state.pageIndex)));
els.download.addEventListener("click", () => downloadFolder(state.bucket, state.prefix));
$("#viewer-close").addEventListener("click", closeViewer);
onBackdrop(viewer, closeViewer);
$("#dl-close").addEventListener("click", () => $("#dl-panel").classList.add("hidden"));
document.addEventListener("keydown", (ev) => {
  if (ev.key === "Escape" && !viewer.classList.contains("hidden")) closeViewer();
});

init();
