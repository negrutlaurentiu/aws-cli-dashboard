"use strict";

const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const RING_CIRC = 2 * Math.PI * 19; // matches r=19 in the SVG
let globalDuration = 129600; // token lifetime in seconds, shared by all accounts

// Close a modal only when the click both starts and ends on the backdrop — so selecting text
// inside it and releasing the mouse out on the backdrop doesn't close it mid-selection.
function onBackdrop(el, close) {
  let downOnSelf = false;
  el.addEventListener("mousedown", (ev) => { downOnSelf = ev.target === el; });
  el.addEventListener("click", (ev) => { if (ev.target === el && downOnSelf) close(); });
}

const state = {
  serverOffset: 0, // serverUnixMs - clientUnixMs
  fetching: false,
  ids: [],         // current account ids rendered, in order
};

const $ = (sel, root = document) => root.querySelector(sel);
const grid = $("#grid");
const emptyState = $("#empty-state");

function serverNow() {
  return (Date.now() + state.serverOffset) / 1000;
}

async function api(path, { method = "GET", body } = {}) {
  const opts = { method, headers: { "X-CSRF-Token": TOKEN } };
  if (body !== undefined) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(path, opts);
  let data = {};
  try { data = await res.json(); } catch (_) { /* empty */ }
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `HTTP ${res.status}`);
  }
  return data;
}

/* ---------- data + rendering ---------- */

async function loadAccounts() {
  if (state.fetching) return;
  state.fetching = true;
  try {
    const data = await api("/api/accounts");
    state.serverOffset = data.now * 1000 - Date.now();
    if (data.duration_seconds) {
      globalDuration = data.duration_seconds;
      const ds = $("#duration-select");
      if (ds && !ds.matches(":focus")) ds.value = String(globalDuration);
    }
    reconcile(data.accounts || []);
  } catch (e) {
    toast("err", "Couldn't load accounts", e.message);
  } finally {
    state.fetching = false;
  }
}

function reconcile(accounts) {
  emptyState.classList.toggle("hidden", accounts.length > 0);

  const incomingIds = accounts.map((a) => a.id);
  const sameSet =
    incomingIds.length === state.ids.length &&
    incomingIds.every((id, i) => id === state.ids[i]);

  if (!sameSet) {
    grid.innerHTML = "";
    accounts.forEach((acc) => grid.appendChild(buildCard(acc)));
    state.ids = incomingIds;
  } else {
    accounts.forEach((acc) => {
      const el = grid.querySelector(`[data-id="${cssEscape(acc.id)}"]`);
      if (el) updateCard(el, acc);
    });
  }
  populateAccountFilter(accounts);
  applyAccountFilter();
  tick();
}

/* ---------- account filter (client-side show/hide for client demos) ---------- */

const acctFilter = $("#account-filter");

function populateAccountFilter(accounts) {
  const cur = acctFilter.value || localStorage.getItem("awsdash.account") || "all";
  acctFilter.innerHTML = "";
  const all = document.createElement("option");
  all.value = "all";
  all.textContent = "All accounts";
  acctFilter.appendChild(all);
  accounts.forEach((a) => {
    const o = document.createElement("option");
    o.value = a.id;
    o.textContent = a.label;
    acctFilter.appendChild(o);
  });
  acctFilter.value = [...acctFilter.options].some((o) => o.value === cur) ? cur : "all";
}

function applyAccountFilter() {
  const sel = acctFilter.value || "all";
  localStorage.setItem("awsdash.account", sel);
  grid.querySelectorAll(".card").forEach((el) => {
    el.style.display = sel === "all" || el.dataset.id === sel ? "" : "none";
  });
}

/* ---------- global token-lifetime setting ---------- */

async function saveDuration() {
  const val = Number($("#duration-select").value);
  try {
    await api("/api/settings", { method: "POST", body: { duration_seconds: val } });
    globalDuration = val;
    toast("ok", "Token lifetime updated", durationLabel(val) + " — applies to all accounts");
    loadAccounts(); // refresh the command snippets with the new --duration-seconds
  } catch (e) {
    toast("err", "Couldn't update token lifetime", e.message);
  }
}

function durationLabel(s) {
  const opt = [...$("#duration-select").options].find((o) => Number(o.value) === Number(s));
  return opt ? opt.textContent : s + "s";
}

function buildCard(acc) {
  const el = $("#card-tpl").content.firstElementChild.cloneNode(true);
  el.dataset.id = acc.id;

  $(".edit", el).addEventListener("click", () => openModal(el.__acc));
  $(".del", el).addEventListener("click", () => removeAccount(el.__acc));
  $(".btn-refresh", el).addEventListener("click", () => doRefresh(el));
  $(".totp-code", el).addEventListener("click", () => {
    const code = el.__acc?.totp?.code;
    if (code) copy(code);
  });
  $(".mfa-id", el).addEventListener("click", () => { if (el.__arn) copy(el.__arn); });
  $(".test", el).addEventListener("click", () => doTest(el));
  $(".setdef", el).addEventListener("click", () => doSetDefault(el));
  $(".cmds-toggle", el).addEventListener("click", () => {
    const cmds = $(".cmds", el);
    cmds.classList.toggle("hidden");
    $(".cmds-toggle", el).setAttribute("aria-expanded", String(!cmds.classList.contains("hidden")));
  });
  $(".cmds", el).addEventListener("click", (ev) => {
    const row = ev.target.closest(".cmd-row");
    if (!row || !row._cmd) return;
    copy(row._cmd);
    const btn = row.querySelector(".cmd-copy");
    if (btn) {
      const o = btn.textContent;
      btn.textContent = "Copied!";
      setTimeout(() => { btn.textContent = o; }, 1200);
    }
  });
  const manual = $(".manual-code", el);
  manual.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter") doRefresh(el);
  });

  updateCard(el, acc);
  return el;
}

function updateCard(el, acc) {
  el.__acc = acc;

  $(".card-label", el).textContent = acc.label;
  $(".chip.src", el).textContent = acc.source_profile;
  $(".chip.dst", el).textContent = acc.target_profile;

  const arn = acc.mfa_serial || "";
  el.__arn = arn;
  const device = arn.includes("/") ? arn.slice(arn.indexOf("/") + 1) : arn;
  $(".mfa-device", el).textContent = device || "(no ARN set)";
  $(".mfa-arn", el).textContent = arn;

  renderCommands(el, acc);

  const totpArea = $(".totp-area", el);
  const manualArea = $(".manual-area", el);
  if (acc.has_secret && acc.totp) {
    totpArea.classList.remove("hidden");
    manualArea.classList.add("hidden");
    $(".totp-digits", el).textContent = formatCode(acc.totp.code);
  } else {
    totpArea.classList.add("hidden");
    manualArea.classList.remove("hidden");
    if (acc.totp_error) {
      $(".manual-code", el).placeholder = "secret invalid — type code";
    }
  }
}

/* ---------- per-second ticker ---------- */

function tick() {
  const now = serverNow();
  let needRefetch = false;

  grid.querySelectorAll(".card").forEach((el) => {
    const acc = el.__acc;
    if (!acc) return;

    // TOTP ring
    if (acc.has_secret && acc.totp) {
      const period = acc.totp.period || 30;
      const left = Math.max(0, acc.totp.valid_until - now);
      const fg = $(".ring-fg", el);
      const offset = RING_CIRC * (1 - left / period);
      fg.style.strokeDashoffset = offset.toFixed(2);
      fg.style.stroke = left <= 4 ? "var(--red)" : left <= 8 ? "var(--amber)" : "var(--accent)";
      $(".ring-secs", el).textContent = Math.ceil(left);
      if (acc.totp.valid_until - now <= 0) needRefetch = true;
    }

    // session status
    applyStatus(el, acc, now);
  });

  if (needRefetch) loadAccounts();
}

function applyStatus(el, acc, now) {
  el.classList.remove("is-active", "is-soon", "is-expired");
  const text = $(".status-text", el);
  const s = acc.session;

  if (!s || s.expires_unix == null) {
    text.innerHTML = "No active session";
    return;
  }
  const left = s.expires_unix - now;
  if (left <= 0) {
    el.classList.add("is-expired");
    text.innerHTML = `<b>Expired</b> ${fmtDur(-left)} ago`;
  } else if (left <= 1800) {
    el.classList.add("is-soon");
    text.innerHTML = `Expires in <b>${fmtDur(left)}</b>`;
  } else {
    el.classList.add("is-active");
    text.innerHTML = `Active &middot; expires in <b>${fmtDur(left)}</b>`;
  }
}

/* ---------- actions ---------- */

async function doRefresh(el) {
  const acc = el.__acc;
  const btn = $(".btn-refresh", el);
  const result = $(".card-result", el);
  const body = { id: acc.id };

  if (!acc.has_secret) {
    const code = $(".manual-code", el).value.trim();
    if (!/^\d{6,8}$/.test(code)) {
      result.className = "card-result show";
      result.innerHTML = `<div class="err">Enter a 6-digit MFA code first.</div>`;
      $(".manual-code", el).focus();
      return;
    }
    body.code = code;
  }

  btn.disabled = true;
  btn.classList.add("busy");
  const original = btn.textContent;
  btn.textContent = "Requesting session token…";
  result.className = "card-result";
  result.innerHTML = "";

  try {
    const data = await api("/api/refresh", { method: "POST", body });
    const when = new Date(data.expiration);
    result.className = "card-result show";
    result.innerHTML =
      `<div class="ok">✓ Wrote <b>${escapeHtml(data.target_profile)}</b>` +
      ` &middot; valid until ${when.toLocaleString()}` +
      `<small>${escapeHtml(data.access_key_id)} &middot; backup: ~/.aws/credentials.bak</small></div>`;
    toast("ok", `${acc.label} refreshed`, `Profile ${data.target_profile} · expires ${when.toLocaleTimeString()}`);
    if (!acc.has_secret) $(".manual-code", el).value = "";
    loadAccounts();
    loadDefaultSelect();
  } catch (e) {
    result.className = "card-result show";
    result.innerHTML = `<div class="err">${escapeHtml(e.message)}</div>`;
    toast("err", `${acc.label} failed`, e.message);
  } finally {
    btn.disabled = false;
    btn.classList.remove("busy");
    btn.textContent = original;
  }
}

async function removeAccount(acc) {
  if (!confirm(`Remove "${acc.label}" from the dashboard?\n\nThis only edits the dashboard config — it does NOT touch ~/.aws/credentials.`)) {
    return;
  }
  try {
    await api("/api/accounts/delete", { method: "POST", body: { id: acc.id } });
    toast("ok", "Account removed", acc.label);
    loadAccounts();
  } catch (e) {
    toast("err", "Delete failed", e.message);
  }
}

async function doTest(el) {
  const acc = el.__acc;
  const result = $(".card-result", el);
  const btn = $(".test", el);
  btn.disabled = true;
  const orig = btn.textContent;
  btn.textContent = "Checking…";
  result.className = "card-result";
  result.innerHTML = "";
  try {
    const d = await api("/api/whoami", { method: "POST", body: { profile: acc.target_profile } });
    result.className = "card-result show";
    if (d.valid) {
      result.innerHTML =
        `<div class="ok">✓ <b>${escapeHtml(acc.target_profile)}</b> is valid` +
        `<small>${escapeHtml(d.arn)} · account ${escapeHtml(d.account)}</small></div>`;
    } else {
      result.innerHTML =
        `<div class="err">${d.expired ? "⏱ Expired" : "✖ Invalid"} — ${escapeHtml(acc.target_profile)}\n` +
        `${escapeHtml(d.error)}</div>`;
    }
  } catch (e) {
    result.className = "card-result show";
    result.innerHTML = `<div class="err">${escapeHtml(e.message)}</div>`;
  } finally {
    btn.disabled = false;
    btn.textContent = orig;
  }
}

async function doSetDefault(el) {
  const acc = el.__acc;
  if (!confirm(`Copy the credentials from profile "${acc.target_profile}" into [default]?\n\n` +
      `Unscoped aws commands (no --profile) will then use them.`)) {
    return;
  }
  const btn = $(".setdef", el);
  btn.disabled = true;
  const orig = btn.textContent;
  btn.textContent = "Setting…";
  try {
    await api("/api/set-default", { method: "POST", body: { profile: acc.target_profile } });
    toast("ok", "Default profile updated", `[default] now mirrors ${acc.target_profile}`);
    loadDefaultPanel();
  } catch (e) {
    toast("err", "Couldn't set default", e.message);
  } finally {
    btn.disabled = false;
    btn.textContent = orig;
  }
}

/* Build the copy-paste command list for a card. Rows are built with textContent so a
   profile name or ARN can never inject markup. */
function renderCommands(el, acc) {
  const cmds = $(".cmds", el);
  if (!cmds) return;
  const T = acc.target_profile;
  const S = acc.source_profile;
  const arn = acc.mfa_serial;
  const dur = globalDuration;

  const items = [
    ["Verify identity / check the creds", `aws sts get-caller-identity --profile ${T}`],
    ["List S3 buckets", `aws s3 ls --profile ${T}`],
    ["Show what's configured", `aws configure list --profile ${T}`],
    ["Use this profile for the whole shell", `export AWS_PROFILE=${T}`],
    ["Manually refresh the MFA session (replace CODE)",
      `aws sts get-session-token --serial-number ${arn} --duration-seconds ${dur} --profile ${S} --token-code CODE`],
  ];

  cmds.textContent = "";
  for (const [label, cmd] of items) {
    const item = document.createElement("div");
    item.className = "cmd-item";

    const lab = document.createElement("span");
    lab.className = "cmd-label";
    lab.textContent = label;

    const row = document.createElement("div");
    row.className = "cmd-row";
    row.title = "Click to copy";
    row._cmd = cmd;

    const code = document.createElement("code");
    code.className = "cmd-text";
    code.textContent = cmd;

    const btn = document.createElement("button");
    btn.className = "cmd-copy";
    btn.type = "button";
    btn.textContent = "Copy";

    row.appendChild(code);
    row.appendChild(btn);
    item.appendChild(lab);
    item.appendChild(row);
    cmds.appendChild(item);
  }
}

/* ---------- default-profile panel ---------- */

const dpIdentity = $("#dp-identity");
const dpSelect = $("#dp-select");

async function loadDefaultPanel() {
  dpIdentity.className = "dp-identity";
  dpIdentity.innerHTML = `<span class="dot"></span><span class="muted">checking…</span>`;
  try {
    const d = await api("/api/whoami", { method: "POST", body: { profile: "default" } });
    if (d.valid) {
      dpIdentity.className = "dp-identity valid";
      dpIdentity.innerHTML =
        `<span class="dot"></span><span class="who">${escapeHtml(d.arn)}</span>` +
        `<span class="muted">· account ${escapeHtml(d.account)}</span>`;
    } else {
      dpIdentity.className = "dp-identity invalid";
      dpIdentity.innerHTML =
        `<span class="dot"></span><span class="muted">${d.expired ? "expired / " : ""}invalid — ` +
        `${escapeHtml(d.error)}</span>`;
    }
  } catch (e) {
    dpIdentity.className = "dp-identity invalid";
    dpIdentity.innerHTML = `<span class="dot"></span><span class="muted">${escapeHtml(e.message)}</span>`;
  }
}

async function loadDefaultSelect() {
  try {
    const data = await api("/api/profiles");
    const current = dpSelect.value;
    dpSelect.innerHTML = "";
    (data.profiles || []).filter((p) => p !== "default").forEach((p) => {
      const o = document.createElement("option");
      o.value = p;
      o.textContent = p;
      dpSelect.appendChild(o);
    });
    if (current) dpSelect.value = current;
  } catch (_) { /* non-fatal */ }
}

async function applyDefault() {
  const profile = dpSelect.value;
  if (!profile) return;
  if (!confirm(`Copy credentials from "${profile}" into [default]?`)) return;
  const btn = $("#dp-apply");
  btn.disabled = true;
  try {
    await api("/api/set-default", { method: "POST", body: { profile } });
    toast("ok", "Default profile updated", `[default] now mirrors ${profile}`);
    loadDefaultPanel();
  } catch (e) {
    toast("err", "Couldn't set default", e.message);
  } finally {
    btn.disabled = false;
  }
}

/* ---------- modal ---------- */

const modal = $("#modal");
const form = $("#account-form");

async function openModal(acc) {
  form.reset();
  await loadProfiles();
  const editing = !!acc;
  $("#modal-title").textContent = editing ? "Edit account" : "Add account";
  form.id.value = editing ? acc.id : "";
  const canForget = editing && acc.has_secret;
  $("#clear-secret-row").hidden = !canForget;
  form.clear_secret.checked = false;
  if (editing) {
    form.label.value = acc.label;
    form.source_profile.value = acc.source_profile;
    form.target_profile.value = acc.target_profile;
    form.mfa_serial.value = acc.mfa_serial;
    form.region.value = acc.region || "";
    form.totp_secret.placeholder = acc.has_secret
      ? "•••••• stored — leave blank to keep"
      : "JBSWY3DPEHPK3PXP… leave blank to type codes manually";
  } else {
    form.totp_secret.placeholder = "JBSWY3DPEHPK3PXP… leave blank to type codes manually";
  }
  modal.classList.remove("hidden");
  form.label.focus();
}

function closeModal() {
  modal.classList.add("hidden");
}

form.addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const btn = $("#modal-save");
  btn.disabled = true;
  const payload = {
    id: form.id.value,
    label: form.label.value,
    source_profile: form.source_profile.value,
    target_profile: form.target_profile.value,
    mfa_serial: form.mfa_serial.value,
    region: form.region.value,
    totp_secret: form.totp_secret.value,
    clear_secret: form.clear_secret.checked,
  };
  try {
    await api("/api/accounts", { method: "POST", body: payload });
    toast("ok", "Account saved", payload.label);
    closeModal();
    loadAccounts();
    loadDefaultSelect();
  } catch (e) {
    toast("err", "Couldn't save", e.message);
  } finally {
    btn.disabled = false;
  }
});

async function loadProfiles() {
  try {
    const data = await api("/api/profiles");
    const dl = $("#profiles");
    dl.innerHTML = "";
    (data.profiles || []).forEach((p) => {
      const opt = document.createElement("option");
      opt.value = p;
      dl.appendChild(opt);
    });
  } catch (_) { /* non-fatal */ }
}

/* ---------- confirmation popup (reusable) ---------- */

const confirmModal = $("#confirm-modal");
let confirmResolver = null;

function confirmPopup({ title, body, okLabel = "Confirm", danger = false }) {
  if (confirmResolver) { confirmResolver(false); confirmResolver = null; } // never leave a prior one pending
  $("#confirm-title").textContent = title;
  $("#confirm-body").textContent = body;
  const ok = $("#confirm-ok");
  ok.textContent = okLabel;
  ok.classList.toggle("danger", !!danger);
  confirmModal.classList.remove("hidden");
  ok.focus();
  return new Promise((resolve) => { confirmResolver = resolve; });
}

function closeConfirm(result) {
  confirmModal.classList.add("hidden");
  const r = confirmResolver;
  confirmResolver = null;
  if (r) r(result);
}

$("#confirm-ok").addEventListener("click", () => closeConfirm(true));
$("#confirm-cancel").addEventListener("click", () => closeConfirm(false));
$("#confirm-close").addEventListener("click", () => closeConfirm(false));
onBackdrop(confirmModal, () => closeConfirm(false));

/* ---------- manage AWS profiles ---------- */

const profilesModal = $("#profiles-modal");

async function openProfilesModal() {
  const list = $("#profiles-list");
  list.textContent = "";
  const loading = document.createElement("p");
  loading.className = "muted"; loading.textContent = "Loading…";
  list.appendChild(loading);
  profilesModal.classList.remove("hidden");
  try {
    const data = await api("/api/profiles");
    const accounts = [...grid.querySelectorAll(".card")].map((el) => el.__acc).filter(Boolean);
    renderProfilesList(data.profiles || [], accounts);
  } catch (e) {
    list.textContent = "";
    const p = document.createElement("p");
    p.className = "err-text"; p.textContent = "Couldn't load profiles: " + e.message;
    list.appendChild(p);
  }
}

function renderProfilesList(profiles, accounts) {
  const list = $("#profiles-list");
  list.textContent = "";
  if (!profiles.length) {
    const p = document.createElement("p");
    p.className = "muted"; p.textContent = "No profiles found in ~/.aws.";
    list.appendChild(p);
    return;
  }
  profiles.forEach((name) => {
    const row = document.createElement("div");
    row.className = "profile-row";
    const left = document.createElement("div");
    left.className = "profile-name";
    const nm = document.createElement("span");
    nm.className = "pr-name"; nm.textContent = name;
    left.appendChild(nm);
    const usedBy = accounts
      .filter((a) => a.source_profile === name || a.target_profile === name)
      .map((a) => a.label);
    if (name === "default") {
      const tag = document.createElement("span"); tag.className = "pr-tag"; tag.textContent = "default";
      left.appendChild(tag);
    } else if (usedBy.length) {
      const tag = document.createElement("span");
      tag.className = "pr-tag account"; tag.textContent = "account: " + usedBy.join(", ");
      left.appendChild(tag);
    }
    const del = document.createElement("button");
    del.className = "btn-mini danger"; del.textContent = "Delete";
    if (name === "default") {
      del.disabled = true; del.title = "The [default] profile can't be deleted here";
    } else {
      del.addEventListener("click", () => deleteProfile(name, usedBy));
    }
    row.appendChild(left);
    row.appendChild(del);
    list.appendChild(row);
  });
}

async function deleteProfile(name, usedBy) {
  let body = `Delete the AWS profile "${name}"?\n\nThis removes it from ~/.aws/credentials and ~/.aws/config (each backed up to .bak first). It does NOT touch any AWS data.`;
  if (usedBy && usedBy.length) {
    body += `\n\n⚠ Used by the dashboard account(s): ${usedBy.join(", ")} — those will stop working until you refresh/recreate them.`;
  }
  const ok = await confirmPopup({ title: `Delete profile "${name}"`, body, okLabel: "Delete profile", danger: true });
  if (!ok) return;
  try {
    const r = await api("/api/profiles/delete", { method: "POST", body: { profile: name } });
    const where = Object.entries(r.removed || {}).map(([f, n]) => `${f} (${n})`).join(", ");
    toast("ok", `Deleted profile "${name}"`, where || "removed");
    await openProfilesModal();  // refresh the list in place
    loadAccounts();
    loadDefaultSelect();
  } catch (e) {
    toast("err", "Couldn't delete profile", e.message);
  }
}

$("#manage-profiles").addEventListener("click", openProfilesModal);
$("#profiles-close").addEventListener("click", () => profilesModal.classList.add("hidden"));
onBackdrop(profilesModal, () => profilesModal.classList.add("hidden"));

/* ---------- toasts + utils ---------- */

function toast(kind, title, detail) {
  const box = document.createElement("div");
  box.className = `toast ${kind}`;
  box.innerHTML = `<b>${escapeHtml(title)}</b>${detail ? `<small>${escapeHtml(detail)}</small>` : ""}`;
  $("#toasts").appendChild(box);
  setTimeout(() => {
    box.style.transition = "opacity .3s";
    box.style.opacity = "0";
    setTimeout(() => box.remove(), 300);
  }, kind === "err" ? 7000 : 3500);
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast("ok", "Copied", text);
  } catch (_) {
    toast("info", "Copy failed", text);
  }
}

function formatCode(code) {
  return code && code.length === 6 ? `${code.slice(0, 3)} ${code.slice(3)}` : code;
}

function fmtDur(secs) {
  secs = Math.max(0, Math.floor(secs));
  const h = Math.floor(secs / 3600);
  const m = Math.floor((secs % 3600) / 60);
  const s = secs % 60;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return `${m}m ${s}s`;
  return `${s}s`;
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

function cssEscape(str) {
  return (window.CSS && CSS.escape) ? CSS.escape(str) : String(str).replace(/"/g, '\\"');
}

/* ---------- wire up ---------- */

$("#add-account").addEventListener("click", () => openModal());
$("#add-account-empty").addEventListener("click", () => openModal());
acctFilter.addEventListener("change", applyAccountFilter);
$("#duration-select").addEventListener("change", saveDuration);
$("#modal-close").addEventListener("click", closeModal);
$("#modal-cancel").addEventListener("click", closeModal);
$("#dp-recheck").addEventListener("click", loadDefaultPanel);
$("#dp-apply").addEventListener("click", applyDefault);
onBackdrop(modal, closeModal);
document.addEventListener("keydown", (ev) => {
  if (ev.key !== "Escape") return;
  if (!confirmModal.classList.contains("hidden")) closeConfirm(false);
  else if (!profilesModal.classList.contains("hidden")) profilesModal.classList.add("hidden");
  else if (!modal.classList.contains("hidden")) closeModal();
});

loadAccounts();
loadDefaultPanel();
loadDefaultSelect();
setInterval(tick, 1000);
setInterval(loadAccounts, 60000); // safety re-sync
