"use strict";

const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const RING_CIRC = 2 * Math.PI * 19; // matches r=19 in the SVG

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
  tick();
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
  $(".serial", el).textContent = acc.mfa_serial;

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

async function refreshAllStored() {
  const cards = [...grid.querySelectorAll(".card")].filter((el) => el.__acc?.has_secret);
  if (cards.length === 0) {
    toast("info", "Nothing to refresh", "No accounts have a stored MFA secret.");
    return;
  }
  toast("info", `Refreshing ${cards.length} account(s)…`, "");
  for (const el of cards) {
    // sequential: avoids hammering STS and keeps credential-file writes serialized
    await doRefresh(el);
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
    form.duration_seconds.value = acc.duration_seconds;
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
    duration_seconds: Number(form.duration_seconds.value),
    region: form.region.value,
    totp_secret: form.totp_secret.value,
    clear_secret: form.clear_secret.checked,
  };
  try {
    await api("/api/accounts", { method: "POST", body: payload });
    toast("ok", "Account saved", payload.label);
    closeModal();
    loadAccounts();
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
$("#refresh-all").addEventListener("click", refreshAllStored);
$("#modal-close").addEventListener("click", closeModal);
$("#modal-cancel").addEventListener("click", closeModal);
modal.addEventListener("click", (ev) => { if (ev.target === modal) closeModal(); });
document.addEventListener("keydown", (ev) => {
  if (ev.key === "Escape" && !modal.classList.contains("hidden")) closeModal();
});

loadAccounts();
setInterval(tick, 1000);
setInterval(loadAccounts, 60000); // safety re-sync
