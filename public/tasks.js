"use strict";

const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const $ = (s, r = document) => r.querySelector(s);

// Close a modal only when the click both starts and ends on the backdrop itself. Without the
// mousedown guard, selecting text inside the modal and releasing the mouse out on the backdrop
// fires a click whose target is the backdrop — which used to close the modal mid-selection.
function onBackdrop(el, close) {
  let downOnSelf = false;
  el.addEventListener("mousedown", (ev) => { downOnSelf = ev.target === el; });
  el.addEventListener("click", (ev) => { if (ev.target === el && downOnSelf) close(); });
}

// Deterministic per-project colour (same name → same hue everywhere), tuned for the dark theme.
function projectColor(name) {
  const s = String(name);
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (Math.imul(h, 31) + s.charCodeAt(i)) >>> 0;
  const hue = h % 360;
  return { fg: `hsl(${hue} 82% 78%)`, bg: `hsl(${hue} 60% 50% / 0.16)`, border: `hsl(${hue} 65% 62% / 0.45)` };
}
function applyProjectColor(el, name) {
  const c = projectColor(name);
  el.style.color = c.fg;
  el.style.background = c.bg;
  el.style.borderColor = c.border;
}
// A category label reuses the same deterministic hashing (same name → same hue everywhere), so a
// label's colour is stable across the board without storing it.
function applyLabelColor(el, name) { applyProjectColor(el, name); }

// All categories offered in the pickers/filter: the starter seeds plus any label already in use,
// de-duped and sorted (case-insensitive). This is how a freshly typed label becomes reusable.
function knownLabels() {
  const used = state.tasks.map((t) => (t.label || "").trim()).filter(Boolean);
  return [...new Set([...LABEL_SEEDS, ...used])]
    .sort((a, b) => a.localeCompare(b, undefined, { sensitivity: "base" }));
}

const STATUSES = [
  { key: "pending", name: "Pending" },
  { key: "in_progress", name: "In Progress" },
  { key: "review", name: "Review" },
  { key: "done", name: "Done" },
  { key: "archived", name: "Archived" },
];
const STATUS_NAME = Object.fromEntries(STATUSES.map((s) => [s.key, s.name]));

// Priority: low / medium / high (default medium). On a card we flag only HIGH (red) and LOW (muted)
// — medium is the implicit default, so showing nothing for it keeps the board calm. The editor and
// the filter always cover all three.
const PRIORITIES = [
  { key: "high", name: "High" },
  { key: "medium", name: "Medium" },
  { key: "low", name: "Low" },
];
const PRIORITY_NAME = Object.fromEntries(PRIORITIES.map((p) => [p.key, p.name]));
const PRIORITY_RANK = { high: 0, medium: 1, low: 2 }; // sort order, high first
// Starter categories — merged with whatever labels tasks already use, so a typed-once label sticks.
const LABEL_SEEDS = ["AWS Terraform", "backend", "frontend", "research"];

const state = {
  tasks: [],
  loadedAt: 0,        // Date.now() at last load, for live extrapolation
  detailId: null,
  weekOffset: 0,
  composeStatus: null, // which column's inline quick-add is open
  composeText: "",     // in-progress quick-add title, kept across board re-renders
  composeProject: "",  // quick-add project; persists across adds (set once, add many)
  screenTime: null,    // last /api/screentime payload {ok, seconds, needs_fda, error}
  selectMode: false,   // task multi-select (sum tracked time vs. computer time)
  selected: new Set(), // selected task ids while in select mode
  todayView: localStorage.getItem("tasks.todayView") === "1", // dim all but today's work (check-out)
  showAge: localStorage.getItem("tasks.showAge") === "1",      // show "added Nd ago" age line on cards
  registeredProjects: [], // names from the /projects registry, merged into the project datalist
  // Done/Archived columns show only THIS WEEK by default; these reveal the earlier tail. Persisted
  // (like todayView/showAge) so the choice survives the 30s re-sync rebuild and every drag.
  showDoneOlder: localStorage.getItem("tasks.showDoneOlder") === "1",
  showArchivedOlder: localStorage.getItem("tasks.showArchivedOlder") === "1",
  week: null, // cached weekly summary {worked, expected, short, fetchedAt} for the topbar chip
  filterLabel: "",    // board filter: only this category ("" = all). In-memory, resets on reload.
  filterPriority: "", // board filter: only this priority ("" = all). In-memory, resets on reload.
  sortByPriority: localStorage.getItem("tasks.sortByPriority") === "1", // order each column high→low
};

let dragHandled = false; // a card drag ended on a valid column (vs dropped nowhere → restore DOM)

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
    // Sync the project/category controls BEFORE rendering. refreshLabelControls() may normalize a
    // now-orphaned category filter (its only task was deleted/relabeled) back to "All"; renderBoard()
    // must then filter with the corrected value — otherwise it draws an empty board against a stale
    // filter that the control simultaneously reset. Both only read state.tasks, already set above.
    updateProjectDatalist();
    refreshLabelControls();
    renderBoard();
    loadWeekMeter(); // refresh the topbar week chip at the same post-mutation points (fire-and-forget)
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
  // Only re-grab focus for the composer if focus was already inside the board (i.e. the user is
  // adding tasks). Never yank focus away from another field — e.g. the detail modal's
  // description — which would also defeat its draft-carry guard in renderDetail.
  const composerWasFocused = !!(document.activeElement && board.contains(document.activeElement));
  board.innerHTML = "";
  let focusInput = null;
  STATUSES.forEach((st) => {
    const col = $("#column-tpl").content.firstElementChild.cloneNode(true);
    col.dataset.status = st.key;
    col.classList.add("col-" + st.key);
    $(".col-name", col).textContent = st.name;
    const allItems = applyFilters(state.tasks.filter((t) => t.status === st.key));
    const list = $(".col-list", col);

    // Done & Archived accumulate forever, so by default show only what entered the column THIS WEEK;
    // the earlier tail folds behind a reveal row (kept in storage, never deleted). Other columns are
    // active work and always render in full.
    let shownCount = allItems.length, hiddenCount = 0;
    if (st.key === "done" || st.key === "archived") {
      const ws = weekStart();
      const recent = [], older = [];
      allItems.forEach((t) => (enteredAt(t) >= ws ? recent : older).push(t));
      const showOlder = st.key === "done" ? state.showDoneOlder : state.showArchivedOlder;
      const shown = showOlder ? allItems : recent;
      shownCount = shown.length;
      hiddenCount = showOlder ? 0 : older.length;
      orderForColumn(shown).forEach((t) => list.appendChild(buildCard(t)));
      const fold = buildFoldRow(st.key, recent.length, older.length, showOlder);
      if (fold) list.appendChild(fold);
    } else {
      orderForColumn(allItems).forEach((t) => list.appendChild(buildCard(t)));
    }

    // Honest count: visible total, plus a faint "+N" when this week hides an earlier tail.
    const countEl = $(".col-count", col);
    countEl.replaceChildren(document.createTextNode(String(shownCount)));
    if (hiddenCount > 0) {
      const more = document.createElement("span");
      more.className = "col-count-more";
      more.textContent = " +" + hiddenCount;
      countEl.appendChild(more);
      countEl.title = "this week shown · " + hiddenCount + " earlier hidden";
    } else {
      countEl.removeAttribute("title");
    }

    // inline quick-add composer (per column)
    const compose = $(".col-compose", col);
    const projectInput = $(".col-compose-project", col);
    const input = $(".col-compose-input", col);
    $(".col-add", col).addEventListener("click", () => {
      state.composeStatus = state.composeStatus === st.key ? null : st.key;
      state.composeText = ""; // fresh title whenever a composer opens/closes/switches (project persists)
      renderBoard();
    });
    if (state.composeStatus === st.key) {
      compose.classList.remove("hidden");
      projectInput.value = state.composeProject; // persists across adds for fast repeated entry
      input.value = state.composeText; // restore draft: a re-render rebuilds this input from scratch
      focusInput = input;
    }
    // mirror keystrokes into state so the next board rebuild restores them instead of wiping
    projectInput.addEventListener("input", () => { state.composeProject = projectInput.value; });
    input.addEventListener("input", () => { state.composeText = input.value; });
    const submitQuickAdd = () => { const v = input.value.trim(); if (v) quickAdd(v, projectInput.value.trim(), st.key); };
    input.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") { ev.preventDefault(); submitQuickAdd(); }
      else if (ev.key === "Escape") { state.composeStatus = null; state.composeText = ""; renderBoard(); }
    });
    projectInput.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") { ev.preventDefault(); input.focus(); } // tab from project to title
      else if (ev.key === "Escape") { state.composeStatus = null; state.composeText = ""; renderBoard(); }
    });

    // drag-and-drop: reorder within a column AND move between columns, with live placement.
    // We move the dragged card in the DOM as the cursor passes each card so the drop point is
    // always visible; on drop we persist the new column + position (the card it landed before).
    col.addEventListener("dragover", (ev) => {
      const dragging = document.querySelector(".task-card.dragging");
      if (!dragging) return; // not a card drag (e.g. a file being dragged in) — leave it alone
      ev.preventDefault();
      ev.dataTransfer.dropEffect = "move";
      col.classList.add("drag-over");
      const after = dragAfterElement(list, ev.clientY);
      if (after == null) {
        // keep the live placeholder above a Done/Archived "+N earlier" reveal row, never below it
        const fold = list.querySelector(".col-fold");
        if (fold) list.insertBefore(dragging, fold); else list.appendChild(dragging);
      } else if (after !== dragging) list.insertBefore(dragging, after);
    });
    col.addEventListener("dragleave", (ev) => {
      if (!col.contains(ev.relatedTarget)) col.classList.remove("drag-over");
    });
    col.addEventListener("drop", (ev) => {
      const dragging = document.querySelector(".task-card.dragging");
      if (!dragging) return;
      ev.preventDefault();
      col.classList.remove("drag-over");
      dragHandled = true;
      // With the priority-sort lens on, a column's order is DERIVED from priority, so a same-column
      // drag is a visual no-op — persisting it would silently reshuffle stored order (only revealed
      // when the lens is later turned off). Skip it and just re-sync. A cross-column drag still
      // changes status, so it must persist (its position is re-derived by the sort on reload).
      const sameColumn = dragging.__task && dragging.__task.status === st.key;
      if (state.sortByPriority && sameColumn) { loadTasks(); return; }
      const next = dragging.nextElementSibling;
      const beforeId = next && next.classList.contains("task-card") ? next.dataset.id : "";
      moveTask(dragging.dataset.id, st.key, beforeId);
    });
    board.appendChild(col);
  });
  if (focusInput && composerWasFocused) {
    focusInput.focus();
    const n = focusInput.value.length; // caret to end of the restored draft
    focusInput.setSelectionRange(n, n);
  }
  tick();
}

// Board filters (category + priority). Both default to "" = no filter. A specific category hides
// tasks with no/other label; a specific priority treats a missing one as "medium" (the default).
function applyFilters(items) {
  return items.filter((t) =>
    (!state.filterLabel || (t.label || "") === state.filterLabel) &&
    (!state.filterPriority || (t.priority || "medium") === state.filterPriority));
}
// Optional per-column ordering by priority (high→low). Stable: equal priority keeps the underlying
// storage order (manual drag order), so toggling the lens off restores exactly what the user arranged.
function orderForColumn(items) {
  if (!state.sortByPriority) return items;
  return items
    .map((t, i) => [t, i])
    .sort((a, b) => ((PRIORITY_RANK[a[0].priority] ?? 1) - (PRIORITY_RANK[b[0].priority] ?? 1)) || (a[1] - b[1]))
    .map((pair) => pair[0]);
}

function buildCard(t) {
  const el = $("#card-tpl").content.firstElementChild.cloneNode(true);
  el.dataset.id = t.id;
  el.__task = t;
  if (t.running) el.classList.add("running");
  const projEl = $(".task-project", el);
  if (t.project) { projEl.textContent = t.project; applyProjectColor(projEl, t.project); projEl.classList.remove("hidden"); }
  $(".task-title", el).textContent = t.title;

  // category + priority chips. Priority shows only for high/low — medium is the quiet default.
  const tags = $(".task-tags", el);
  tags.replaceChildren();
  if (t.priority && t.priority !== "medium") {
    const p = document.createElement("span");
    p.className = "task-prio prio-" + t.priority;
    p.textContent = PRIORITY_NAME[t.priority] || t.priority;
    tags.appendChild(p);
  }
  if (t.label) {
    const lab = document.createElement("span");
    lab.className = "task-label";
    lab.textContent = t.label;
    applyLabelColor(lab, t.label);
    tags.appendChild(lab);
  }

  const meta = [];
  if (t.attachments && t.attachments.length) meta.push(`📎 ${t.attachments.length}`);
  if (t.notes && t.notes.length) meta.push(`📝 ${t.notes.length}`);
  $(".task-attach", el).textContent = meta.join(" · ");
  if (state.showAge) $(".task-since", el).textContent = "🕒 " + fmtAge(t.created_at); // off by default

  // Today view: fade everything except what was worked on (or finished) today. The per-card
  // "today" hours and the topbar total are filled live in tick().
  if (state.todayView) el.classList.add(isTodayTask(t) ? "today-hit" : "not-today");

  if (state.selectMode) {
    el.classList.add("selectable");
    el.draggable = false;
    if (state.selected.has(t.id)) el.classList.add("selected");
    const chk = document.createElement("span");
    chk.className = "task-check"; chk.setAttribute("aria-hidden", "true");
    el.appendChild(chk);
  }

  el.addEventListener("dragstart", (ev) => {
    ev.dataTransfer.setData("text/plain", t.id);
    ev.dataTransfer.effectAllowed = "move";
    el.classList.add("dragging");
    dragHandled = false;
  });
  el.addEventListener("dragend", () => {
    el.classList.remove("dragging");
    if (!dragHandled) loadTasks(); // dropped outside any column → restore the DOM we live-moved
  });
  el.addEventListener("click", (ev) => {
    if (ev.target.closest(".task-timer")) return;
    if (state.selectMode) toggleSelect(t.id);
    else openDetail(t.id);
  });

  const timerBtn = $(".task-timer", el);
  timerBtn.addEventListener("click", (ev) => { ev.stopPropagation(); toggleTimer(t); });
  return el;
}

// distinct project names — from tasks AND the /projects registry — for the <datalist> everywhere
function updateProjectDatalist() {
  const dl = document.getElementById("task-projects");
  if (!dl) return;
  const projects = [...new Set([
    ...state.tasks.map((t) => (t.project || "").trim()),
    ...(state.registeredProjects || []),
  ].filter(Boolean))].sort((a, b) => a.localeCompare(b));
  dl.replaceChildren();
  projects.forEach((p) => { const o = document.createElement("option"); o.value = p; dl.appendChild(o); });
}

// Sync the category controls to the labels currently known: the <datalist> behind the add-modal +
// detail inputs (pick existing or type new) and the toolbar filter <select> (preserving its choice;
// if the filtered label vanished, fall back to "all").
function refreshLabelControls() {
  const labels = knownLabels();
  const dl = document.getElementById("task-labels");
  if (dl) {
    dl.replaceChildren();
    labels.forEach((l) => { const o = document.createElement("option"); o.value = l; dl.appendChild(o); });
  }
  const sel = $("#filter-label");
  if (sel) {
    const cur = state.filterLabel;
    sel.replaceChildren();
    const all = document.createElement("option"); all.value = ""; all.textContent = "All categories"; sel.appendChild(all);
    labels.forEach((l) => { const o = document.createElement("option"); o.value = l; o.textContent = l; sel.appendChild(o); });
    sel.value = labels.includes(cur) ? cur : "";
    if (sel.value !== cur) state.filterLabel = sel.value; // the filtered label no longer exists
    sel.classList.toggle("is-active", !!sel.value);
  }
}

// Pull the managed project names once so they autocomplete even before any task uses them.
async function loadRegisteredProjects() {
  try {
    const d = await apiGet("/api/projects");
    state.registeredProjects = (d.projects || []).map((p) => p.name).filter(Boolean);
  } catch (_) { state.registeredProjects = []; }
  updateProjectDatalist();
}

/* ---------- weekly-time chip (topbar) ---------- */

// Pull THIS week's totals from the same endpoint the summary modal uses (one source of truth, so the
// chip and the modal can never disagree). Refreshed only at the post-mutation reload points via
// loadTasks() — never per-second; tick() extrapolates the live running portion locally.
async function loadWeekMeter() {
  try {
    const data = await apiGet("/api/tasks/summary?week=0");
    const s = data.summary || {};
    state.week = {
      worked: s.total_seconds || 0,
      expected: s.expected_seconds || 0,
      short: s.short_seconds || 0,
      fetchedAt: Date.now(),
    };
  } catch (_) {
    state.week = null;
  }
  renderWeekMeter();
}

// Render the chip. liveWorked (optional) lets tick() show the running timer's wall-clock without refetching.
function renderWeekMeter(liveWorked) {
  const el = $("#week-meter");
  if (!el) return;
  const w = state.week;
  if (!w) { el.replaceChildren(); el.style.removeProperty("--pct"); el.classList.remove("short"); return; }
  const worked = (liveWorked != null) ? liveWorked : w.worked;
  const pct = w.expected > 0 ? Math.min(100, Math.round((worked / w.expected) * 100)) : 0;
  el.style.setProperty("--pct", pct + "%");
  el.classList.toggle("short", w.short > 0);
  const cal = document.createElement("span"); cal.textContent = "📅"; cal.setAttribute("aria-hidden", "true");
  const val = document.createElement("b"); val.textContent = fmtDur(worked);
  el.replaceChildren(cal, val);
  if (w.expected > 0) {
    const exp = document.createElement("span"); exp.className = "wm-exp";
    exp.textContent = "/ " + fmtDur(w.expected).replace(/ 0m$/, "");
    el.appendChild(exp);
  }
  el.title = w.short > 0
    ? `Worked ${fmtDur(worked)} of ${fmtDur(w.expected)} this week — ${fmtDur(w.short)} under target. Click for the weekly summary (and past weeks).`
    : `Worked ${fmtDur(worked)} this week${w.expected ? " (target " + fmtDur(w.expected).replace(/ 0m$/, "") + ")" : ""}. Click for the weekly summary (and past weeks).`;
}

/* ---------- Done/Archived time-window (this week vs. earlier) ---------- */

// Monday 00:00 (local) of the current week — matches the Weekly Summary anchor and the week chip.
function weekStart() {
  const d = new Date();
  const dow = (d.getDay() + 6) % 7; // 0 = Mon … 6 = Sun
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() - dow);
  return d.getTime();
}

// When a task ENTERED its current column (status_since), so editing/attaching to an old Done card
// doesn't pull it back onto the board. Fail-open: a missing/unparseable time → Infinity → stays visible.
function enteredAt(t) {
  const hist = t.history || [];
  const src = t.status_since || (hist.length ? hist[hist.length - 1].at : t.created_at);
  const ms = Date.parse(src || "");
  return isNaN(ms) ? Infinity : ms;
}

// The reveal/collapse row appended to a Done/Archived column when an earlier tail exists.
function buildFoldRow(statusKey, recentCount, olderCount, showOlder) {
  if (olderCount === 0) return null;
  const verb = statusKey === "done" ? "finished" : "archived";
  const row = document.createElement("button");
  row.type = "button";
  row.className = "col-fold";
  if (showOlder) {
    row.classList.add("is-collapse");
    row.textContent = "↥ Show only this week";
  } else if (recentCount === 0) {
    row.textContent = `Nothing ${verb} this week · show ${olderCount} earlier`;
  } else {
    row.textContent = `+ ${olderCount} earlier · show`;
  }
  row.addEventListener("click", () => toggleOlder(statusKey));
  return row;
}

function toggleOlder(statusKey) {
  if (statusKey === "done") {
    state.showDoneOlder = !state.showDoneOlder;
    localStorage.setItem("tasks.showDoneOlder", state.showDoneOlder ? "1" : "0");
  } else {
    state.showArchivedOlder = !state.showArchivedOlder;
    localStorage.setItem("tasks.showArchivedOlder", state.showArchivedOlder ? "1" : "0");
  }
  renderBoard();
}

/* ---------- live tick ---------- */

function tick() {
  const elapsed = (Date.now() - state.loadedAt) / 1000;
  let runningTask = null;
  let todayTotal = 0, todayCount = 0;
  document.querySelectorAll(".task-card").forEach((el) => {
    const t = el.__task;
    if (!t) return;
    const worked = (t.worked_seconds || 0) + (t.running ? elapsed : 0);
    const wEl = $(".task-worked", el);
    wEl.textContent = (t.worked_seconds || t.running) ? "⌛ " + (t.running ? fmtClock(worked) : fmtDur(worked)) : "";
    // per-card today hours (shown only in Today view, on the highlighted cards)
    const todaySecs = (t.today_seconds || 0) + (t.running ? elapsed : 0);
    const tdEl = $(".task-today", el);
    if (state.todayView && isTodayTask(t)) {
      tdEl.textContent = todaySecs > 0 ? "today " + (t.running ? fmtClock(todaySecs) : fmtDur(todaySecs)) : "done today";
      todayTotal += todaySecs;
      todayCount++;
    } else {
      tdEl.textContent = "";
    }
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
  const tt = $("#today-total");
  tt.textContent = state.todayView ? `Today ${fmtDur(todayTotal)} · ${todayCount}t` : "";
  tt.title = state.todayView ? `Worked today across ${todayCount} task${todayCount === 1 ? "" : "s"}` : "";
  if (state.selectMode) updateSelectBar(); // keep the compare bar live (running tasks, re-renders)

  // Keep the week chip live ONLY while a timer runs — extrapolate the wall-clock since it was fetched
  // (the cached base already includes everything banked up to that fetch, so this never double-counts).
  // When idle, the chip already shows the fetched base, so there's nothing to re-render each second.
  if (state.week && runningTask) {
    renderWeekMeter(state.week.worked + (Date.now() - state.week.fetchedAt) / 1000);
  }

  // While its timer runs, the open detail modal's "Worked total" and today's row tick live, so
  // starting the timer visibly auto-populates today's log (banked to 15-min units on stop).
  if (state.detailId && !detailModal.classList.contains("hidden")) {
    const dt = state.tasks.find((x) => x.id === state.detailId);
    if (dt && dt.running) {
      const totalEl = document.querySelector(".detail-worked-total");
      if (totalEl) totalEl.textContent = fmtClock((dt.worked_seconds || 0) + elapsed);
      const todayVal = document.querySelector('.day-row[data-date="' + todayLocal() + '"]:not(.editing) .day-val');
      if (todayVal) {
        const base = (dt.days || []).find((d) => d.date === todayLocal());
        todayVal.textContent = fmtClock((base ? base.seconds : (dt.today_seconds || 0)) + elapsed);
      }
    }
  }
}

/* ---------- actions ---------- */

async function moveTask(id, status, beforeId = "") {
  try {
    await api("/api/tasks/move", { id, status, before_id: beforeId });
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't move task", e.message);
    loadTasks(); // re-sync the DOM we live-moved during the drag
  }
}

// The card the cursor sits above (insert the dragged card before it); null → drop at the end.
function dragAfterElement(listEl, y) {
  let closest = null, closestOffset = -Infinity;
  listEl.querySelectorAll(".task-card:not(.dragging)").forEach((card) => {
    const box = card.getBoundingClientRect();
    const offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closestOffset) { closestOffset = offset; closest = card; }
  });
  return closest;
}

async function quickAdd(title, project, status) {
  try {
    await api("/api/tasks", { title, project, status });
    state.composeStatus = status; // keep this column's composer open for rapid entry
    state.composeText = "";       // clear the title (project persists for the next task)
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
onBackdrop(addModal, () => addModal.classList.add("hidden"));
$("#add-form").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = ev.target;
  try {
    await api("/api/tasks", { title: f.title.value, description: f.description.value, project: f.project.value, label: f.label.value, priority: f.priority.value });
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

  // Carry an in-progress description edit across the rebuild below. Most rebuilds follow a blur
  // (which already saved via the change handler), but paste-to-attach and file-drop fire
  // loadTasks()->renderDetail() WITHOUT blurring the textarea, so without this the unsaved draft
  // reverts to the last saved value. The title is a persistent element guarded above; the
  // description is recreated each render, so preserve its live value + caret explicitly.
  const oldDesc = body.querySelector(".detail-desc");
  const descCarry = (oldDesc && document.activeElement === oldDesc)
    ? { value: oldDesc.value, start: oldDesc.selectionStart, end: oldDesc.selectionEnd }
    : null;
  // same draft-carry for the notes composer (e.g. paste-to-attach fires while typing a note)
  const oldNote = body.querySelector(".note-input");
  const noteCarry = (oldNote && document.activeElement === oldNote)
    ? { value: oldNote.value, start: oldNote.selectionStart, end: oldNote.selectionEnd }
    : null;
  const oldProj = body.querySelector('input[list="task-projects"]');
  const projCarry = (oldProj && document.activeElement === oldProj)
    ? { value: oldProj.value, start: oldProj.selectionStart, end: oldProj.selectionEnd }
    : null;
  const oldLabel = body.querySelector('input[list="task-labels"]');
  const labelCarry = (oldLabel && document.activeElement === oldLabel)
    ? { value: oldLabel.value, start: oldLabel.selectionStart, end: oldLabel.selectionEnd }
    : null;

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

  const prioSel = document.createElement("select");
  prioSel.className = "detail-status";
  PRIORITIES.forEach((p) => {
    const o = document.createElement("option");
    o.value = p.key; o.textContent = p.name;
    if (p.key === (t.priority || "medium")) o.selected = true;
    prioSel.appendChild(o);
  });
  prioSel.addEventListener("change", () => saveTask(t.id, { priority: prioSel.value }));
  const prioWrap = document.createElement("label");
  prioWrap.className = "detail-field-inline";
  prioWrap.append(labelSpan("Priority"), prioSel);

  const timerBtn = document.createElement("button");
  timerBtn.className = "btn-mini " + (t.running ? "is-running" : "");
  timerBtn.textContent = t.running ? "⏹ Stop timer" : "▶ Start timer";
  timerBtn.addEventListener("click", () => toggleTimer(t));
  row.append(statusWrap, prioWrap, timerBtn);
  body.appendChild(row);

  // project
  const projWrap = document.createElement("div");
  projWrap.className = "field";
  projWrap.append(labelSpan("Project"));
  const projInput = document.createElement("input");
  projInput.setAttribute("list", "task-projects");
  projInput.placeholder = "Reperks, Virtuosity, BTK…";
  projInput.value = projCarry ? projCarry.value : (t.project || "");
  projInput.addEventListener("change", () => saveTask(t.id, { project: projInput.value }));
  projWrap.appendChild(projInput);
  body.appendChild(projWrap);

  // category (a single free-form label; pick a known one or type a new one)
  const labelWrap = document.createElement("div");
  labelWrap.className = "field";
  labelWrap.append(labelSpan("Category"));
  const labelInput = document.createElement("input");
  labelInput.setAttribute("list", "task-labels");
  labelInput.placeholder = "AWS Terraform, frontend, research…";
  labelInput.value = labelCarry ? labelCarry.value : (t.label || "");
  labelInput.addEventListener("change", () => saveTask(t.id, { label: labelInput.value }));
  labelWrap.appendChild(labelInput);
  body.appendChild(labelWrap);

  // description
  const descWrap = document.createElement("div");
  descWrap.className = "field";
  descWrap.append(labelSpan("Description"));
  const desc = document.createElement("textarea");
  desc.className = "detail-desc";
  desc.rows = 4; desc.value = descCarry ? descCarry.value : (t.description || "");
  desc.placeholder = "Details, links, context…";
  desc.addEventListener("change", () => saveTask(t.id, { description: desc.value }));
  descWrap.appendChild(desc);
  body.appendChild(descWrap);

  // notes (running log)
  body.appendChild(sectionTitle("Notes"));
  body.appendChild(buildNotesComposer(t.id));
  const notesList = document.createElement("div");
  notesList.className = "notes-list";
  const notes = (t.notes || []).slice().reverse(); // newest first
  if (!notes.length) {
    const empty = document.createElement("p");
    empty.className = "detail-dim"; empty.textContent = "No notes yet — jot down findings, blockers, where you left off…";
    notesList.appendChild(empty);
  } else {
    notes.forEach((n) => notesList.appendChild(buildNote(t.id, n)));
  }
  body.appendChild(notesList);

  // time: one live "Worked total" (= sum of the per-day rows below, so they always reconcile),
  // the editable per-day log (today auto-fills from the running timer), then a compact status
  // history. (The old per-status "In Pending / In Done" stat boxes are now the History log.)
  body.appendChild(sectionTitle("Time"));
  const times = document.createElement("div");
  times.className = "time-grid";
  const totalShown = (t.days || []).reduce((a, d) => a + (d.seconds || 0), 0);
  const totalStat = timeStat("Worked total", t.running ? fmtClock(totalShown + sinceLoad()) : fmtDur(totalShown), t.running);
  totalStat.querySelector("strong").classList.add("detail-worked-total");
  times.appendChild(totalStat);
  body.appendChild(times);
  body.appendChild(buildDayList(t));    // editable hours per day (today + any past day)
  body.appendChild(buildHistoryLog(t)); // when added, how long it sat in each column, current status

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

  // restore focus + caret to the description if we carried an unsaved draft across the rebuild
  if (descCarry) { desc.focus(); desc.setSelectionRange(descCarry.start, descCarry.end); }
  if (noteCarry) {
    const ni = body.querySelector(".note-input");
    if (ni) { ni.value = noteCarry.value; ni.focus(); ni.setSelectionRange(noteCarry.start, noteCarry.end); }
  }
  if (projCarry) { projInput.focus(); projInput.setSelectionRange(projCarry.start, projCarry.end); }
  if (labelCarry) { labelInput.focus(); labelInput.setSelectionRange(labelCarry.start, labelCarry.end); }
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

function buildNotesComposer(taskId) {
  const wrap = document.createElement("div");
  wrap.className = "note-compose";
  const ta = document.createElement("textarea");
  ta.className = "note-input"; ta.rows = 2;
  ta.placeholder = "Add a note — what you found, a blocker, where you left off…  (⌘/Ctrl+Enter to save)";
  const btn = document.createElement("button");
  btn.type = "button"; btn.className = "btn-mini"; btn.textContent = "Add note";
  const submit = () => { const v = ta.value.trim(); if (v) addNote(taskId, v); };
  btn.addEventListener("click", submit);
  ta.addEventListener("keydown", (ev) => {
    if ((ev.metaKey || ev.ctrlKey) && ev.key === "Enter") { ev.preventDefault(); submit(); }
  });
  wrap.append(ta, btn);
  return wrap;
}
function buildNote(taskId, n) {
  const el = document.createElement("div");
  el.className = "note";
  const head = document.createElement("div"); head.className = "note-head";
  const when = document.createElement("span"); when.className = "note-when"; when.textContent = fmtDate(n.at);
  const del = document.createElement("button");
  del.type = "button"; del.className = "note-del"; del.textContent = "✕"; del.title = "Delete note";
  del.addEventListener("click", () => deleteNote(taskId, n.id));
  head.append(when, del);
  const text = document.createElement("div"); text.className = "note-text"; text.textContent = n.text;
  el.append(head, text);
  return el;
}
async function addNote(taskId, text) {
  try {
    await api("/api/tasks/note", { id: taskId, text });
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't add note", e.message);
  }
}
async function deleteNote(taskId, noteId) {
  if (!confirm("Delete this note?")) return;
  try {
    await api("/api/tasks/note-delete", { task_id: taskId, note_id: noteId });
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't delete note", e.message);
  }
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
onBackdrop(detailModal, closeDetail);
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
    decorateSummaryRedmine(state.weekOffset); // async, non-blocking — adds Redmine ✓/⚠ badges
  } catch (e) {
    body.textContent = "Error: " + e.message;
  }
}
function renderSummary(s) {
  $("#week-label").textContent = s.week_label + (state.weekOffset === 0 ? " (this week)" : "");
  const body = $("#summary-body");
  body.innerHTML = "";

  const days = s.days || [];
  const projectTotals = s.project_totals || [];
  const completed = s.completed || [];

  // Reflect the saved daily target in the control (don't clobber it while the user is typing).
  const tEl = $("#summary-target");
  if (tEl && document.activeElement !== tEl) tEl.value = (s.target_hours ?? 8);

  // Grand total — now includes BOTH timer sessions and manually-logged time.
  const total = document.createElement("div");
  total.className = "summary-total";
  const big = document.createElement("strong");
  big.textContent = fmtDur(s.total_seconds || 0);
  total.append(document.createTextNode("Worked "), big, document.createTextNode(` this week · ${completed.length} completed`));
  body.appendChild(total);

  // Completeness vs the daily target — the "did I log a full day?" check (for Redmine reconciliation).
  const expected = s.expected_seconds || 0;
  const shortSecs = s.short_seconds || 0;
  const shortDays = days.filter((d) => d.working_day && (d.short_seconds || 0) > 0).length;
  const dailyTarget = (s.days.find((d) => d.working_day)?.expected_seconds) || Math.round((s.target_hours || 0) * 3600);
  const comp = document.createElement("div");
  comp.className = "summary-subnote " + (expected > 0 && shortSecs > 0 ? "summary-short" : "summary-ok");
  comp.textContent = expected <= 0
    ? "Includes timer + manually logged hours."
    : (shortSecs > 0
        ? `⚠️ ${shortDays} day${shortDays === 1 ? "" : "s"} under the ${fmtDur(dailyTarget)}/day target — ${fmtDur(shortSecs)} missing (worked ${fmtDur(s.total_seconds || 0)} of ${fmtDur(expected)})`
        : `✅ Every working day met the ${fmtDur(dailyTarget)}/day target`);
  body.appendChild(comp);

  if (!days.length) {
    const none = document.createElement("p");
    none.className = "detail-dim"; none.textContent = "No tracked work this week.";
    body.appendChild(none);
    if (completed.length) renderCompletedSection(body, completed);
    return;
  }

  // By project — week totals with bars (quick "what did I work on" glance).
  if (projectTotals.length) {
  body.appendChild(sectionTitle("By project"));
  const maxP = Math.max(...projectTotals.map((p) => p.seconds), 1);
  projectTotals.forEach((p) => {
    const r = document.createElement("div");
    r.className = "sum-row sum-row-proj";
    r.dataset.project = p.project; // so decorateSummaryRedmine can match the Redmine badge to it
    const nm = document.createElement("span"); nm.className = "sum-name"; nm.textContent = p.project;
    if (p.project && p.project !== "Other") nm.style.color = projectColor(p.project).fg;
    const bar = document.createElement("div"); bar.className = "sum-bar";
    const fill = document.createElement("div"); fill.className = "sum-fill"; fill.style.width = (p.seconds / maxP * 100) + "%";
    bar.appendChild(fill);
    const val = document.createElement("span"); val.className = "sum-val"; val.textContent = fmtDur(p.seconds);
    r.append(nm, bar, val);
    body.appendChild(r);
  });
  }

  // By day — day → project → tasks, each with hours; weekdays flagged ✓ / ⚠ vs the target.
  body.appendChild(sectionTitle("By day"));
  days.forEach((day) => {
    const block = document.createElement("div");
    block.className = "sum-day" + (day.working_day && !day.complete ? " sum-day-incomplete" : "");

    const head = document.createElement("div"); head.className = "sum-day-head";
    const dl = document.createElement("span"); dl.className = "sum-day-label"; dl.textContent = day.label;
    const dv = document.createElement("span"); dv.className = "sum-day-val"; dv.textContent = fmtDur(day.seconds);
    head.append(dl);
    if (day.working_day && day.in_progress) {
      const fl = document.createElement("span"); fl.className = "sum-day-flag is-weekend"; fl.textContent = "today";
      head.append(fl);
    } else if (day.working_day) {
      const fl = document.createElement("span");
      fl.className = "sum-day-flag " + (day.complete ? "is-ok" : "is-short");
      fl.textContent = day.complete ? "✓"
        : (day.seconds > 0 ? "⚠ " + fmtDur(day.short_seconds) + " short" : "⚠ no time logged");
      head.append(fl);
    } else if (day.seconds > 0) {
      const fl = document.createElement("span"); fl.className = "sum-day-flag is-weekend"; fl.textContent = "weekend";
      head.append(fl);
    }
    head.append(dv);
    block.appendChild(head);

    (day.projects || []).forEach((p) => {
      const proj = document.createElement("div"); proj.className = "sum-proj-head";
      const pn = document.createElement("span"); pn.className = "sum-proj-name"; pn.textContent = p.project;
      if (p.project && p.project !== "Other") pn.style.color = projectColor(p.project).fg;
      const pv = document.createElement("span"); pv.className = "sum-proj-val"; pv.textContent = fmtDur(p.seconds);
      proj.append(pn, pv);
      block.appendChild(proj);

      (p.tasks || []).forEach((t) => {
        const tr = document.createElement("div"); tr.className = "sum-task";
        const tn = document.createElement("span"); tn.className = "sum-task-name"; tn.textContent = t.title;
        const tg = document.createElement("span"); tg.className = "sum-task-tag"; tg.textContent = STATUS_NAME[t.status] || t.status;
        const tv = document.createElement("span"); tv.className = "sum-task-val"; tv.textContent = fmtDur(t.seconds);
        tr.append(tn, tg, tv);
        block.appendChild(tr);
      });
    });
    body.appendChild(block);
  });

  if (completed.length) renderCompletedSection(body, completed);
}

function renderCompletedSection(body, completed) {
  body.appendChild(sectionTitle("Completed this week"));
  completed.forEach((c) => {
    const r = document.createElement("div"); r.className = "sum-line";
    r.textContent = "✓ " + (c.project ? c.project + " · " : "") + c.title + "  ·  " + fmtDate(c.at);
    body.appendChild(r);
  });
}

// Decorate the weekly summary's "By project" rows with the Redmine reconciliation badge for the
// viewed week (async, non-blocking — Redmine is an outbound call). Only ok/short/error show here;
// projects without a URL/key or with no tracked hours stay clean (the /projects page surfaces those).
async function decorateSummaryRedmine(offset) {
  let data;
  try { data = await apiGet("/api/projects/redmine-status?week=" + offset); }
  catch (_) { return; }
  if (offset !== state.weekOffset || summaryModal.classList.contains("hidden")) return; // week/view changed
  const byName = {};
  (data.projects || []).forEach((p) => { byName[p.name] = p; });
  document.querySelectorAll("#summary-body .sum-row-proj").forEach((row) => {
    if (row.querySelector(".rm-badge")) return; // already decorated
    const p = byName[row.dataset.project];
    if (!p || !["ok", "short", "error"].includes(p.status)) return;
    const b = document.createElement("span");
    b.className = "rm-badge rm-" + p.status;
    if (p.status === "ok") { b.textContent = "✓ Redmine"; b.title = `Logged ${fmtH(p.redmine_hours)} in Redmine ≥ ${fmtH(p.dashboard_hours)} tracked`; }
    else if (p.status === "short") { b.textContent = "⚠ " + fmtH(p.short_hours); b.title = `Redmine ${fmtH(p.redmine_hours)} < ${fmtH(p.dashboard_hours)} tracked — log ${fmtH(p.short_hours)} more`; }
    else { b.textContent = "⚠ Redmine"; b.title = p.error || "Redmine error"; }
    row.appendChild(b);
  });

  // Total hours logged to Redmine this week, summed across the reconciled (ok/short) projects.
  // Deduped by Redmine URL so a project mapped twice can't double-count; errored/unreachable
  // projects contribute nothing and are surfaced as a caveat rather than silently dropped.
  const seen = new Set();
  let totalLogged = 0, reconciled = 0, errors = 0;
  (data.projects || []).forEach((p) => {
    if (p.status === "error") { errors++; return; }
    if ((p.status === "ok" || p.status === "short") && typeof p.redmine_hours === "number") {
      const key = p.redmine_url || p.name;
      if (seen.has(key)) return;
      seen.add(key);
      totalLogged += p.redmine_hours;
      reconciled++;
    }
  });
  renderRedmineTotal(totalLogged, reconciled, errors);
}

// Insert/update the "logged to Redmine this week" headline just under the completeness subnote.
// Shown only once Redmine actually returned data for ≥1 project (reconciled or errored); otherwise
// there's nothing meaningful to total, so no line appears.
function renderRedmineTotal(hours, reconciled, errors) {
  const body = $("#summary-body");
  if (!body) return;
  let el = body.querySelector(".summary-redmine");
  if (reconciled === 0 && errors === 0) { if (el) el.remove(); return; }
  if (!el) {
    el = document.createElement("div");
    el.className = "summary-redmine";
    const anchor = body.querySelector(".summary-subnote") || body.querySelector(".summary-total");
    if (anchor) anchor.insertAdjacentElement("afterend", el); else body.insertBefore(el, body.firstChild);
  }
  el.replaceChildren();
  const icon = document.createElement("span"); icon.textContent = "📋 "; icon.setAttribute("aria-hidden", "true");
  const strong = document.createElement("strong"); strong.textContent = fmtH(hours);
  el.append(icon, strong, document.createTextNode(" logged to Redmine this week"));
  if (errors > 0) {
    const warn = document.createElement("span");
    warn.className = "summary-redmine-warn";
    warn.textContent = ` · ${errors} project${errors === 1 ? "" : "s"} couldn't be checked`;
    el.appendChild(warn);
  }
  el.title = `Total hours logged in Redmine this week across ${reconciled} reconciled project${reconciled === 1 ? "" : "s"}`
    + (errors > 0 ? `; ${errors} could not be checked and ${errors === 1 ? "is" : "are"} not included.` : ".");
}
function fmtH(h) { const n = Number(h) || 0; return n.toFixed(2).replace(/\.?0+$/, "") + "h"; }

// The summary opens from the topbar week chip (#week-meter) — wired further below.
$("#summary-close").addEventListener("click", () => summaryModal.classList.add("hidden"));
onBackdrop(summaryModal, () => summaryModal.classList.add("hidden"));
$("#week-prev").addEventListener("click", () => { state.weekOffset--; loadSummary(); });
$("#week-next").addEventListener("click", () => { state.weekOffset++; loadSummary(); });
// Post the currently-viewed week to Mattermost (reuses the check-in/out preview flow).
$("#summary-post").addEventListener("click", () => openCheckPreview("weekly"));
// Change the "full day" target → persist (merge-preserving) and re-evaluate the flags.
$("#summary-target").addEventListener("change", async (ev) => {
  const hours = parseFloat(ev.target.value);
  if (isNaN(hours) || hours < 0 || hours > 24) { loadSummary(); return; } // snap back on bad input
  try { await api("/api/settings", { daily_target_hours: hours }); toast("ok", "Daily target saved"); }
  catch (e) { toast("err", e.message); }
  loadSummary();
});

/* ---------- mattermost ---------- */

const mmModal = $("#mm-modal");
const mmForm = $("#mm-form");
const mmStatus = $("#mm-status");
const mmPreviewModal = $("#mm-preview-modal");
let mmPreviewSend = null; // bound to the current preview's send action

function setMmStatus(kind, text) {
  mmStatus.className = "mm-status" + (kind ? " " + kind : "");
  mmStatus.textContent = text || "";
}

// the editable fields, read into a settings payload (token omitted when left blank → server keeps it)
function mmFormBody() {
  const f = mmForm;
  const body = {
    base_url: f.base_url.value.trim(),
    team: f.team.value.trim(),
    checkin_channel: f.checkin_channel.value.trim(),
    checkout_channel: f.checkout_channel.value.trim(),
    checkout_show_hours: f.checkout_show_hours.checked,
    intake_enabled: f.intake_enabled.checked,
    intake_tag: f.intake_tag.value.trim(),
    intake_project: f.intake_project.value.trim(),
    intake_channel: f.intake_channel.value.trim(),
    intake_llm: f.intake_llm.checked,
    autoresponder_enabled: f.autoresponder_enabled.checked,
    autoresponder_week_allow: f.autoresponder_week_allow.value.trim(),
  };
  if (f.token.value.trim() !== "") body.token = f.token.value.trim();
  return body;
}

async function openMmSettings() {
  mmForm.reset();
  setMmStatus("", "Loading…");
  mmModal.classList.remove("hidden");
  try {
    const { settings } = await apiGet("/api/mattermost/settings");
    mmForm.base_url.value = settings.base_url || "";
    mmForm.team.value = settings.team || "";
    mmForm.checkin_channel.value = settings.checkin_channel || "";
    mmForm.checkout_channel.value = settings.checkout_channel || "";
    mmForm.checkout_show_hours.checked = settings.checkout_show_hours !== false; // default on
    mmForm.intake_enabled.checked = !!settings.intake_enabled;
    mmForm.intake_tag.value = settings.intake_tag || "@Claude";
    mmForm.intake_project.value = settings.intake_project || "";
    mmForm.intake_channel.value = settings.intake_channel || "";
    mmForm.intake_llm.checked = !!settings.intake_llm;
    mmForm.autoresponder_enabled.checked = !!settings.autoresponder_enabled;
    mmForm.autoresponder_week_allow.value = settings.autoresponder_week_allow || "";
    const cHint = $("#mm-claude-hint");
    if (cHint) cHint.textContent = settings.claude_available
      ? "Found your claude CLI" + (settings.claude_bin ? " (" + settings.claude_bin + ")" : "") + ". When on, Claude interprets each message; if it's ever unavailable, intake falls back to the built-in parsing."
      : "No claude CLI found on this machine — install Claude Code or check the PATH of the shell that runs ./start.sh. Until then, intake uses the built-in parsing.";
    refreshListenerStatus();
    if (settings.configured) {
      setMmStatus("ok", "Configured" + (settings.channels_resolved ? " · channels resolved" : " · run Test to resolve channels"));
    } else {
      setMmStatus("", settings.has_token ? "Token saved — set the server URL." : "Not configured yet.");
    }
    mmForm.base_url.focus();
  } catch (e) {
    setMmStatus("err", e.message);
  }
}

mmForm.addEventListener("submit", async (ev) => {
  ev.preventDefault();
  try {
    const body = mmFormBody();
    await api("/api/mattermost/settings", body);
    mmForm.token.value = "";
    setMmStatus("ok", "Saved.");
    toast("ok", "Mattermost settings saved");
    // If intake was just enabled, make sure the listener is up (it may not be if the dashboard
    // started before the feature, or it crashed). Idempotent — the daemon is a singleton.
    if (body.intake_enabled || body.autoresponder_enabled) { try { await api("/api/mattermost/listener/start", {}); } catch (_) {} }
    refreshListenerStatus();
  } catch (e) {
    setMmStatus("err", e.message);
    toast("err", "Couldn't save settings", e.message);
  }
});

/* ---------- @Claude intake listener status ---------- */

const mmDot = $("#mm-listener-dot");
const mmListenerLine = $("#mm-listener-line");

function describeListener(l) {
  if (!l.configured) return { cls: "is-off", label: "Mattermost: not configured", line: "Add a server URL and access token above, then Save." };
  const tag = l.intake_tag || "@Claude";
  // The listener serves @Claude intake AND/OR the colleague auto-responder; reflect whichever is on.
  const feats = [];
  if (l.intake_enabled) feats.push(tag + " intake");
  if (l.autoresponder_enabled) feats.push("auto-status");
  if (feats.length === 0) return { cls: "is-off", label: "Mattermost listener: off", line: "Nothing enabled — tick “Enable @Claude intake” or “Auto-reply to colleagues” above and Save." };
  const what = feats.join(" + ");
  switch (l.state) {
    case "connected": return { cls: "is-on", label: what + ": watching", line: "Connected — serving " + what + "." };
    case "connecting": return { cls: "is-warn", label: what + ": connecting…", line: "Connecting to Mattermost…" };
    case "disabled": return { cls: "is-off", label: "Mattermost listener: off", line: "Listener is idle." };
    case "error": return { cls: "is-err", label: "Mattermost: connection error", line: "Error: " + (l.error || "connection failed") + " — retrying." };
    default: return { cls: "is-err", label: "Listener: not running", line: "Listener isn’t running — click Restart, or restart the dashboard." };
  }
}

async function refreshListenerStatus() {
  try {
    const { listener } = await apiGet("/api/mattermost/listener");
    const d = describeListener(listener);
    if (mmDot) { mmDot.className = "mm-dot " + d.cls; mmDot.title = d.label; }
    if (mmListenerLine) mmListenerLine.textContent = d.line;
  } catch (_) { /* keep last shown state on a transient error */ }
}

if (mmDot) mmDot.addEventListener("click", () => openMmSettings());

$("#mm-listener-restart").addEventListener("click", async (ev) => {
  const btn = ev.currentTarget;
  btn.disabled = true;
  try {
    await api("/api/mattermost/listener/start", {});
    setMmStatus("ok", "Listener (re)started.");
  } catch (e) {
    setMmStatus("err", e.message);
  } finally {
    btn.disabled = false;
    setTimeout(refreshListenerStatus, 1500); // give it a moment to connect
  }
});

$("#mm-test").addEventListener("click", async () => {
  setMmStatus("", "Saving & testing…");
  try {
    await api("/api/mattermost/settings", mmFormBody()); // persist the typed token before testing it
    mmForm.token.value = "";
    const r = await api("/api/mattermost/test", {});
    setMmStatus("ok", `Connected as @${r.username} · both channels resolved`);
  } catch (e) {
    setMmStatus("err", e.message);
  }
});

/* ---------- topbar entry points (week chip → summary, gear → Mattermost settings) ---------- */
// The old 2-item "More" popover is dissolved: weekly time is now the always-on chip, and Mattermost
// settings is the icon-only gear — both one click, no overflow menu.
$("#week-meter").addEventListener("click", openSummary);
$("#mm-settings-gear").addEventListener("click", openMmSettings);

$("#mm-close").addEventListener("click", () => mmModal.classList.add("hidden"));
onBackdrop(mmModal, () => mmModal.classList.add("hidden"));

// Preview the digest the app would post, then send on confirm (the manual-trigger model).
// The check-out preview also exposes an "include worked hours" toggle: flipping it re-renders the
// message from the server (the source of truth for the format). Check-in has no hours, so the row
// stays hidden for it.
let mmPreviewWhich = null;

async function openCheckPreview(which) {
  mmPreviewWhich = which;
  $("#mm-preview-title").textContent = which === "checkin" ? "Check in" : which === "weekly" ? "Weekly summary" : "Check out";
  const hoursRow = $("#mm-hours-row");
  hoursRow.classList.toggle("hidden", which !== "checkout"); // hours toggle is check-out only
  const dayRow = $("#mm-day-row");
  dayRow.classList.toggle("hidden", which !== "checkout"); // day picker (Today/Yesterday) is check-out only
  if (which === "checkout") $("#mm-day").value = "0";       // default to today on each open
  $("#mm-preview-body").value = "";
  mmPreviewSend = null;
  mmPreviewModal.classList.remove("hidden");
  await refreshPreview(null); // null → server uses the saved default and tells us what it chose
}

// (Re)build the preview. includeHours: null = use saved default; true/false = explicit override.
async function refreshPreview(includeHours) {
  const which = mmPreviewWhich;
  const bodyEl = $("#mm-preview-body");
  const sendBtn = $("#mm-preview-send");
  $("#mm-preview-sub").textContent = "Building preview…";
  bodyEl.readOnly = true;
  sendBtn.disabled = true;
  try {
    const payload = { preview: true };
    if (which === "checkout" && includeHours !== null) payload.include_hours = includeHours;
    if (which === "checkout") payload.day_offset = parseInt($("#mm-day").value, 10) || 0;
    if (which === "weekly") payload.week_offset = state.weekOffset; // post the week the user is viewing
    const r = await api("/api/mattermost/" + which, payload);
    bodyEl.value = r.message;
    // Reflect the effective hours choice the server used (so the box matches the message shown).
    if (which === "checkout" && typeof r.include_hours === "boolean") $("#mm-include-hours").checked = r.include_hours;
    if (which === "checkout" && typeof r.day_offset === "number") $("#mm-day").value = String(r.day_offset);
    if (r.configured) {
      $("#mm-preview-sub").textContent = "Will post to #" + r.channel + " — edit below if needed, then Send.";
      bodyEl.readOnly = false; // the operator can tweak the message before it goes out
      sendBtn.disabled = false;
      mmPreviewSend = () => sendCheck(which, r.channel);
    } else {
      $("#mm-preview-sub").textContent = "⚠ Mattermost not configured — open ⚙ settings and add your token.";
    }
  } catch (e) {
    $("#mm-preview-sub").textContent = "";
    bodyEl.value = "Error: " + e.message;
  }
}

async function sendCheck(which, channel) {
  const sendBtn = $("#mm-preview-send");
  const message = $("#mm-preview-body").value;
  if (!message.trim()) { toast("err", "Nothing to post"); return; }
  sendBtn.disabled = true;
  sendBtn.textContent = "Sending…";
  try {
    await api("/api/mattermost/" + which, { message }); // post exactly what's in the (editable) preview
    mmPreviewModal.classList.add("hidden");
    toast("ok", "Posted to #" + channel);
  } catch (e) {
    toast("err", "Couldn't post", e.message);
    sendBtn.disabled = false;
  } finally {
    sendBtn.textContent = "Send";
  }
}

$("#mm-checkin").addEventListener("click", () => openCheckPreview("checkin"));
$("#mm-checkout").addEventListener("click", () => openCheckPreview("checkout"));

/* ---------- Today view + age toggles (board display only, persisted locally) ---------- */

function applyTodayView() {
  $("#board").classList.toggle("today-mode", state.todayView);
  $("#today-view").classList.toggle("active", state.todayView);
}
function applyShowAge() {
  $("#age-toggle").classList.toggle("active", state.showAge);
}
function applySortPriority() {
  $("#sort-priority").classList.toggle("active", state.sortByPriority);
}
$("#today-view").addEventListener("click", () => {
  state.todayView = !state.todayView;
  localStorage.setItem("tasks.todayView", state.todayView ? "1" : "0");
  applyTodayView();
  renderBoard(); // re-derive the dim/highlight classes from already-loaded tasks (no network)
});
$("#age-toggle").addEventListener("click", () => {
  state.showAge = !state.showAge;
  localStorage.setItem("tasks.showAge", state.showAge ? "1" : "0");
  applyShowAge();
  renderBoard(); // age text is only built into a card when the toggle is on (keeps .task-meta tidy)
});
$("#sort-priority").addEventListener("click", () => {
  state.sortByPriority = !state.sortByPriority;
  localStorage.setItem("tasks.sortByPriority", state.sortByPriority ? "1" : "0");
  applySortPriority();
  renderBoard(); // re-orders from already-loaded tasks (no network)
});
$("#filter-label").addEventListener("change", (ev) => {
  state.filterLabel = ev.target.value;
  ev.target.classList.toggle("is-active", !!state.filterLabel);
  renderBoard();
});
$("#filter-priority").addEventListener("change", (ev) => {
  state.filterPriority = ev.target.value;
  ev.target.classList.toggle("is-active", !!state.filterPriority);
  renderBoard();
});
// Toggling hours regenerates the message from the server (overwrites any hand-edits — expected).
$("#mm-include-hours").addEventListener("change", (ev) => refreshPreview(ev.target.checked));
// Switching the day rebuilds the preview for that day, keeping the current "include hours" choice.
$("#mm-day").addEventListener("change", () => refreshPreview($("#mm-include-hours").checked));
$("#mm-preview-send").addEventListener("click", () => { if (mmPreviewSend) mmPreviewSend(); });
$("#mm-preview-close").addEventListener("click", () => mmPreviewModal.classList.add("hidden"));
$("#mm-preview-cancel").addEventListener("click", () => mmPreviewModal.classList.add("hidden"));
onBackdrop(mmPreviewModal, () => mmPreviewModal.classList.add("hidden"));

/* ---------- screen time + task selection ---------- */

async function loadScreenTime() {
  try {
    const r = await apiGet("/api/screentime");
    state.screenTime = r.screentime;
  } catch (e) {
    state.screenTime = null;
  }
  renderScreenTime();
  if (state.selectMode) updateSelectBar();
  // keep an open breakdown modal current across the 3-min refresh
  if (!stModal.classList.contains("hidden") && state.screenTime && state.screenTime.ok) openScreenTimeModal();
}

function renderScreenTime() {
  const el = $("#screen-time");
  const st = state.screenTime;
  el.onclick = null;
  if (st && st.ok && st.seconds != null) {
    el.textContent = "🖥 " + fmtDur(st.seconds);
    el.className = "screen-time ok";
    if (st.seconds > 0) {
      el.title = "Computer-active time today — click for the breakdown";
      el.onclick = openScreenTimeModal;
    } else {
      el.title = "Computer-active time today";
    }
  } else if (st && st.needs_fda) {
    el.textContent = "🖥 Screen Time: setup";
    el.className = "screen-time warn";
    el.title = "Click for setup steps";
    el.onclick = () => toast("info", "Enable Screen Time reading",
      "System Settings → Privacy & Security → Full Disk Access → enable it for your terminal (the one running ./start.sh), then restart the server.");
  } else if (st && st.ok === false && st.error) {
    el.textContent = "🖥 Screen Time —"; // discoverable: hover shows why (e.g. Screen Time off)
    el.className = "screen-time";
    el.title = st.error;
  } else {
    el.textContent = "";
    el.className = "screen-time";
  }
}

const stModal = $("#st-modal");
function openScreenTimeModal() {
  const st = state.screenTime;
  if (!st || !st.ok) return;
  $("#st-title").textContent = "Computer time today · " + fmtDur(st.seconds || 0);
  const body = $("#st-body");
  body.innerHTML = "";
  body.appendChild(stSection("By category", st.categories,
    "Browser time is split by the sites you visited (read from your local browser history): work sites — AWS, Google Docs/Sheets, Redmine, GitHub, the company domain… — count as Productivity, social media as Social. Only sites we couldn't classify land in Browsing. (Apple's own Screen Time categories aren't available to other apps.)"));
  if (st.sites && st.sites.length) body.appendChild(stSection("Top sites", st.sites, null));
  body.appendChild(stSection("By app", st.apps, null));
  stModal.classList.remove("hidden");
}
function stSection(title, items, note) {
  const wrap = document.createElement("div");
  wrap.appendChild(sectionTitle(title));
  if (note) { const p = document.createElement("p"); p.className = "detail-dim"; p.textContent = note; wrap.appendChild(p); }
  if (!items || !items.length) {
    const p = document.createElement("p"); p.className = "detail-dim"; p.textContent = "No data."; wrap.appendChild(p);
    return wrap;
  }
  const max = Math.max(...items.map((i) => i.seconds), 1);
  items.forEach((i) => {
    const r = document.createElement("div"); r.className = "sum-row st-row";
    const nm = document.createElement("span"); nm.className = "sum-name"; nm.textContent = i.name;
    if (i.category) { // "Top sites" rows carry which bucket the site was counted in
      const tag = document.createElement("span"); tag.className = "st-cat"; tag.textContent = i.category;
      nm.append(" ", tag);
    }
    const bar = document.createElement("div"); bar.className = "sum-bar";
    const fill = document.createElement("div"); fill.className = "sum-fill"; fill.style.width = (i.seconds / max * 100) + "%";
    bar.appendChild(fill);
    const val = document.createElement("span"); val.className = "sum-val"; val.textContent = fmtDur(i.seconds);
    r.append(nm, bar, val);
    wrap.appendChild(r);
  });
  return wrap;
}
$("#st-close").addEventListener("click", () => stModal.classList.add("hidden"));
onBackdrop(stModal, () => stModal.classList.add("hidden"));

function setSelectMode(on) {
  state.selectMode = on;
  if (!on) state.selected.clear();
  $("#select-bar").classList.toggle("hidden", !on);
  $("#select-mode").classList.toggle("active", on);
  renderBoard();
  if (on) updateSelectBar();
}

function toggleSelect(id) {
  if (state.selected.has(id)) state.selected.delete(id); else state.selected.add(id);
  const card = document.querySelector('.task-card[data-id="' + id + '"]');
  if (card) card.classList.toggle("selected", state.selected.has(id));
  updateSelectBar();
}

function updateSelectBar() {
  const elapsed = (Date.now() - state.loadedAt) / 1000;
  let tracked = 0, count = 0;
  state.selected.forEach((id) => {
    const t = state.tasks.find((x) => x.id === id);
    // today's tracked time, to compare against today's computer time
    if (t) { count++; tracked += (t.today_seconds || 0) + (t.running ? elapsed : 0); }
  });
  $("#sb-count").textContent = count;
  $("#sb-tracked").textContent = fmtDur(tracked);
  const st = state.screenTime;
  const computer = (st && st.ok && st.seconds != null) ? st.seconds : null;
  $("#sb-computer").textContent = computer != null ? fmtDur(computer) : "—";
  const gapEl = $("#sb-gap");
  const wrap = $("#sb-gap-wrap");
  if (computer != null) {
    const gap = computer - tracked; // +ve = unallocated computer time still to distribute
    gapEl.textContent = (gap >= 0 ? "+" : "−") + fmtDur(Math.abs(gap));
    wrap.classList.toggle("over", gap < 0);
    wrap.title = gap >= 0 ? "Computer time not yet accounted for by the selected tasks" : "Selected tasks exceed your computer time";
  } else {
    gapEl.textContent = "—";
    wrap.classList.remove("over");
    wrap.title = "";
  }
}

$("#select-mode").addEventListener("click", () => setSelectMode(!state.selectMode));
$("#sb-done").addEventListener("click", () => setSelectMode(false));
$("#sb-clear").addEventListener("click", () => {
  state.selected.clear();
  document.querySelectorAll(".task-card.selected").forEach((c) => c.classList.remove("selected"));
  updateSelectBar();
});

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
onBackdrop(viewer, () => { viewer.classList.add("hidden"); $("#viewer-body").innerHTML = ""; });

/* ---------- helpers ---------- */

function labelSpan(text) { const s = document.createElement("span"); s.className = "inline-label"; s.textContent = text; return s; }
function sectionTitle(text) { const h = document.createElement("h3"); h.className = "section-title"; h.textContent = text; return h; }
function timeStat(label, value, hot) {
  const d = document.createElement("div"); d.className = "time-stat" + (hot ? " hot" : "");
  const v = document.createElement("strong"); v.textContent = value;
  const l = document.createElement("span"); l.textContent = label;
  d.append(v, l); return d;
}
// seconds elapsed since the last load — for live-extrapolating a running timer between ticks.
function sinceLoad() { return (Date.now() - state.loadedAt) / 1000; }

// ---- status history (a small log: when added, how long in each column, where it is now) ----
function buildHistoryLog(t) {
  const wrap = document.createElement("div");
  wrap.appendChild(sectionTitle("History"));
  const log = document.createElement("div");
  log.className = "history-log";
  wrap.appendChild(log);
  const hist = (t.history || []).filter((h) => h && h.at);
  if (!hist.length) {
    const p = document.createElement("p"); p.className = "detail-dim"; p.textContent = "No history yet.";
    log.appendChild(p); return wrap;
  }
  const name = (k) => STATUS_NAME[k] || k;
  // first entry = when the task was created, in its initial column
  log.appendChild(histRow("Added to " + name(hist[0].status), fmtWhen(hist[0].at), null));
  for (let i = 1; i < hist.length; i++) {
    const prev = hist[i - 1], cur = hist[i];
    const dwell = (new Date(cur.at) - new Date(prev.at)) / 1000;
    log.appendChild(histRow("Moved to " + name(cur.status), fmtWhen(cur.at),
      "after " + fmtDur(dwell) + " in " + name(prev.status)));
  }
  // where it sits now, and for how long (refreshes on each re-sync)
  const last = hist[hist.length - 1];
  log.appendChild(histRow("Now in " + name(last.status), null,
    fmtDur((Date.now() - new Date(last.at)) / 1000) + " so far"));
  return wrap;
}
function histRow(title, when, sub) {
  const r = document.createElement("div"); r.className = "hist-row";
  const main = document.createElement("div"); main.className = "hist-main";
  const tt = document.createElement("span"); tt.className = "hist-title"; tt.textContent = title;
  main.appendChild(tt);
  if (when) { const w = document.createElement("span"); w.className = "hist-when"; w.textContent = when; main.appendChild(w); }
  r.appendChild(main);
  if (sub) { const s = document.createElement("div"); s.className = "hist-sub"; s.textContent = sub; r.appendChild(s); }
  return r;
}

// ---- editable hours per day ----
// "Worked total" is read-only (the sum); time is logged/corrected per day here.
function buildDayList(t) {
  const wrap = document.createElement("div");
  wrap.appendChild(sectionTitle("Hours per day"));
  const list = document.createElement("div");
  list.className = "day-list";
  wrap.appendChild(list);

  const today = todayLocal();
  const days = t.days || [];
  const todayEntry = days.find((d) => d.date === today);
  const todaySecs = todayEntry ? todayEntry.seconds : (t.today_seconds || 0);

  list.appendChild(dayRow(t.id, today, todaySecs, true));                 // today, always editable
  days.filter((d) => d.date && d.date !== today)
    .forEach((d) => list.appendChild(dayRow(t.id, d.date, d.seconds, true)));

  // log another (past) day
  const log = document.createElement("div");
  log.className = "day-log";
  const cap = document.createElement("span"); cap.className = "day-log-cap"; cap.textContent = "Log another day:";
  const di = document.createElement("input");
  di.type = "date"; di.max = today; di.className = "day-date"; di.setAttribute("aria-label", "Pick a day to log");
  di.addEventListener("change", () => {
    const date = di.value;
    if (!date) return;
    let row = [...list.querySelectorAll(".day-row")].find((r) => r.dataset.date === date);
    const secs = date === today ? todaySecs : ((days.find((d) => d.date === date) || {}).seconds || 0);
    if (!row) { row = dayRow(t.id, date, secs, true); list.appendChild(row); }
    openDayEditor(row, t.id, date, secs);
    di.value = "";
  });
  log.append(cap, di);
  wrap.appendChild(log);
  return wrap;
}

function dayRow(taskId, date, seconds, editable) {
  const row = document.createElement("div");
  row.className = "day-row";
  row.dataset.date = date;
  renderDayView(row, taskId, date, seconds, editable);
  return row;
}
function renderDayView(row, taskId, date, seconds, editable) {
  row.__day = { taskId, date, seconds, editable }; // so a sibling revert keeps the right value
  row.replaceChildren();
  row.classList.remove("editing");
  const lab = document.createElement("span"); lab.className = "day-label"; lab.textContent = dayLabel(date);
  const val = document.createElement("span"); val.className = "day-val";
  val.textContent = (seconds < 0 ? "−" : "") + fmtDur(Math.abs(seconds || 0)); // signed (rare legacy negative)
  row.append(lab, val);
  if (editable) {
    const edit = document.createElement("button");
    edit.type = "button"; edit.className = "stat-edit day-edit"; edit.textContent = "✎";
    edit.title = "Set hours for " + dayLabel(date);
    edit.addEventListener("click", () => openDayEditor(row, taskId, date, seconds));
    row.appendChild(edit);
  }
}
function openDayEditor(row, taskId, date, seconds) {
  // one day editor open at a time — revert any other open editor to its view
  const list = row.closest(".day-list");
  if (list) list.querySelectorAll(".day-row.editing").forEach((r) => {
    if (r !== row && r.__day) renderDayView(r, r.__day.taskId, r.__day.date, r.__day.seconds, r.__day.editable);
  });
  row.replaceChildren();
  row.classList.add("editing");
  const lab = document.createElement("span"); lab.className = "day-label"; lab.textContent = dayLabel(date);
  const hi = document.createElement("input");
  hi.type = "number"; hi.min = "0"; hi.className = "we-num"; hi.value = String(Math.floor((seconds || 0) / 3600));
  const hl = document.createElement("span"); hl.className = "we-unit"; hl.textContent = "h";
  // minutes in 15-min units (the logging granularity) — snap any stored odd value to the nearest
  const mi = document.createElement("select");
  mi.className = "we-num we-min";
  const curMin = Math.floor(((seconds || 0) % 3600) / 60);
  [0, 15, 30, 45].forEach((v) => {
    const o = document.createElement("option"); o.value = String(v); o.textContent = String(v).padStart(2, "0"); mi.appendChild(o);
  });
  mi.value = String([0, 15, 30, 45].reduce((a, b) => (Math.abs(b - curMin) < Math.abs(a - curMin) ? b : a), 0));
  const ml = document.createElement("span"); ml.className = "we-unit"; ml.textContent = "m";
  const ok = document.createElement("button"); ok.type = "button"; ok.className = "btn-mini"; ok.textContent = "Save";
  const cancel = document.createElement("button"); cancel.type = "button"; cancel.className = "btn-mini"; cancel.textContent = "✕";
  const commit = () => saveWorkedDay(taskId, date, Math.max(0, (parseInt(hi.value, 10) || 0) * 3600 + (parseInt(mi.value, 10) || 0) * 60));
  ok.addEventListener("click", commit);
  cancel.addEventListener("click", () => renderDayView(row, taskId, date, seconds, true));
  const onKey = (ev) => {
    if (ev.key === "Enter") { ev.preventDefault(); ev.stopPropagation(); commit(); }
    else if (ev.key === "Escape") { ev.preventDefault(); ev.stopPropagation(); renderDayView(row, taskId, date, seconds, true); }
  };
  hi.addEventListener("keydown", onKey);
  mi.addEventListener("keydown", onKey);
  row.append(lab, hi, hl, mi, ml, ok, cancel);
  hi.focus(); hi.select();
}
async function saveWorkedDay(taskId, date, seconds) {
  try {
    await api("/api/tasks/worked", { id: taskId, date, seconds, scope: "day" });
    toast("ok", "Hours updated");
    loadTasks();
  } catch (e) {
    toast("err", "Couldn't update time", e.message);
  }
}

function todayLocal() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
function dayLabel(date) {
  if (!date) return "Earlier (unassigned)";
  const today = new Date();
  const y = new Date(); y.setDate(y.getDate() - 1);
  const fmt = (dt) => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, "0")}-${String(dt.getDate()).padStart(2, "0")}`;
  const short = (() => { const dt = new Date(date + "T00:00:00"); return isNaN(dt) ? date : dt.toLocaleDateString(undefined, { weekday: "short", month: "short", day: "numeric" }); })();
  if (date === fmt(today)) return "Today · " + short;
  if (date === fmt(y)) return "Yesterday · " + short;
  return short;
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
// compact "Jun 22, 10:46" for the history timeline
function fmtWhen(s) {
  if (!s) return ""; const d = new Date(s); if (isNaN(d)) return "";
  return d.toLocaleString(undefined, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}
// "Worked or finished today" — matches the Mattermost check-out digest (done_today is server-computed
// against local midnight; today_seconds covers in-progress/timer work, running covers a fresh start).
function isTodayTask(t) { return !!t.done_today || !!t.running || (t.today_seconds || 0) > 0; }
function fmtAge(createdAt) {
  if (!createdAt) return "added —";
  const d = new Date(createdAt);
  if (isNaN(d)) return "added —";
  const days = Math.floor((Date.now() - d.getTime()) / 86400000);
  if (days <= 0) return "added today";
  if (days === 1) return "added 1d ago";
  if (days < 7) return `added ${days}d ago`;
  return `added ${Math.floor(days / 7)}w ago`;
}
function toast(kind, title, detail) {
  const box = document.createElement("div"); box.className = `toast ${kind}`;
  const b = document.createElement("b"); b.textContent = title; box.appendChild(b);
  if (detail) { const s = document.createElement("small"); s.textContent = detail; box.appendChild(s); }
  $("#toasts").appendChild(box);
  setTimeout(() => { box.style.opacity = "0"; setTimeout(() => box.remove(), 300); }, kind === "err" ? 7000 : 3500);
}

document.addEventListener("keydown", (ev) => {
  if (ev.key !== "Escape") return;
  [viewer, detailModal, summaryModal, addModal, mmModal, mmPreviewModal, stModal].forEach((m) => {
    if (m === detailModal && !m.classList.contains("hidden")) closeDetail();
    else m.classList.add("hidden");
  });
});

// The periodic re-sync rebuilds the whole board (and the detail body) from scratch, which would
// discard any unsaved text in a focused field — the column quick-add input, the detail
// title/description, or the add-task modal. Skip the cycle while any text field is focused;
// tick() keeps timers live every second anyway and the next cycle catches up.
function isEditingText() {
  // Hold the re-sync during a card drag too: a rebuild mid-drag (innerHTML wipe in renderBoard)
  // would detach the .dragging node, so the drop finds nothing and the move is silently lost.
  if (document.querySelector(".task-card.dragging")) return true;
  const el = document.activeElement;
  if (!el) return false;
  if (el.tagName === "INPUT" || el.tagName === "TEXTAREA" || el.isContentEditable) return true;
  // also hold the re-sync while a per-day editor is open (focus may be on its Save/✕ buttons)
  return !!(el.closest && el.closest(".day-row.editing"));
}

applyTodayView(); // honor persisted Today/age/sort toggles before the first render
applyShowAge();
applySortPriority();
loadTasks();
loadRegisteredProjects(); // merge managed project names into the autocomplete
loadScreenTime();
refreshListenerStatus();
setInterval(tick, 1000);
setInterval(() => { if (!isEditingText()) loadTasks(); }, 30000); // periodic re-sync (timers, multi-tab)
setInterval(loadScreenTime, 180000); // refresh Screen Time every ~3 min
setInterval(refreshListenerStatus, 10000); // @Claude listener health (toolbar dot)
