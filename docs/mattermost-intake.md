# Mattermost integration & `@Claude` task intake

Maintainer guide for the Mattermost features on **/tasks**: check-in/out posting and, the focus of
this doc, **`@Claude` task intake** — type `@Claude …` in Mattermost and a task appears on the board.
Read this before changing the feature. For the repo-wide architecture/security rules see
[`../CLAUDE.md`](../CLAUDE.md); this doc is the deep-dive for the intake pipeline.

---

## 1. What it does

While the dashboard is running, a background **listener** holds an authenticated Mattermost
**WebSocket** open. When *you* post a message that starts with the trigger tag (default `@Claude`),
it turns the message into a task and reacts ✅ on it. Turning the message into a task is done one of
two ways:

- **Built-in heuristic** (default, no Claude): first line → title, with optional inline tokens
  `proj:Name` / `#Name` (project) and `!progress` / `!review` / `!done` / `!pending` (status).
- **Claude interpretation** (opt-in, `intake_llm`): the message — plus the message you're replying
  to — is handed to your **local `claude` CLI** (the subscription you're logged into, **no API
  key**), which returns a structured `{title, project, description, status}`. Falls back to the
  heuristic on any failure.

### Why a WebSocket (not a webhook / slash command / polling)
The dashboard binds `127.0.0.1` only, so Mattermost (a remote server) can't call *in* to it —
outgoing webhooks and slash commands are out. The WebSocket is opened **outbound** from the laptop,
so no inbound reachability is needed. It delivers each post once and never replays history, so there
is no backfill flood and no cursor/dedup bookkeeping.

---

## 2. Files

| File | Role |
|---|---|
| `src/Mattermost.php` | REST v4 client (bearer token, HTTPS + TLS-verify, no redirects): `me()`, `resolveChannelId()`, `post()`, `addReaction()`, `getPost()`, `baseUrl()`, `token()`. Also powers check-in/out posting. |
| `src/MattermostSocket.php` | Hand-rolled RFC 6455 WebSocket **client** (stock PHP, no Composer): `connect()`, `authenticate()`, `read()`, `ping()`, `sendText()`, `close()`. |
| `src/Intake.php` | Pure (no I/O) matching + heuristic parsing: `matchTag()`, `matchPostedEvent()`, `fromPostedEvent()`, `parse()`, `sanitizeTitle()`, `sanitizeDescription()`. Unit-testable. |
| `src/ClaudeCli.php` | Optional interpretation via the local `claude` CLI: `extractTask()`, `resolveBin()`, `isAvailable()`, `bin()`. |
| `bin/mm-listen` | The listener daemon: singleton, supervisor/connect loop, `mmHandleEvent()`, `mmLlmTask()`. |
| `src/Store.php` | Config in `config/mattermost.json`: `mattermost()`, `saveMattermost()`, `saveMattermostChannelIds()`; listener heartbeat: `listenerStatus()`, `writeListenerStatus()`. |
| `public/index.php` | Routes: settings (`GET/POST /api/mattermost/settings`), `test`, `checkin`, `checkout`, and the listener (`GET /api/mattermost/listener`, `POST …/listener/start`, `…/listener/stop`). Handlers: `mattermostSettingsView()`, `mattermostListenerView/Start/Stop()`. |
| `src/tasks.html` + `public/tasks.js` | Settings modal (`#mm-form`), the toolbar status dot, and the JS that loads/saves settings + polls listener health (`mmFormBody()`, `openMmSettings()`, `refreshListenerStatus()`). |
| `start.sh` | Launches `bin/mm-listen` in the background and `trap`s to kill it on exit. |

---

## 3. Data flow (one `@Claude` message → one task)

```
You type "@Claude proj:S3 fix pagination" (or reply "@Claude create task" to a colleague)
      │
      ▼  Mattermost broadcasts a `posted` event to your sessions
MattermostSocket::read()                          (bin/mm-listen inner loop)
      │  decoded event
      ▼
Intake::matchPostedEvent($evt, $myUserId, $tag, $channel)
      │  → null (ignore)  if: not `posted` / not YOUR post (user_id ≠ me) /
      │                       system message (type ≠ "") / wrong channel / tag doesn't lead
      │  → { post_id, root_id, rest }   (rest = text after the tag)
      ▼
  intake_llm ON ──► mmLlmTask()
      │                 │  if root_id: Mattermost::getPost(root_id) → referenced message = context
      │                 ▼
      │            ClaudeCli::extractTask(rest, context, heartbeat)
      │                 │  runs:  claude -p '<prompt>' --output-format json   (proc_open, argv array)
      │                 ▼  parse envelope.result → {title, project, description, status}
      │            sanitize (Intake::sanitizeTitle / sanitizeDescription); apply default project
      │                 │  on ANY error → return null  ┐
      ▼                 ▼                              │ fall back
  intake_llm OFF ─► Intake::parse(rest, defaultProject)◄┘
      │
      ▼
App->tasks->create(title, description, status, project)      (src/Tasks.php, its own flock)
      │
      ▼
Mattermost::addReaction(post_id, me.id, "white_check_mark")  ✅  (best-effort, non-fatal)
```

`mmHandleEvent()` (bin/mm-listen) orchestrates the above; `mmLlmTask()` is the Claude branch.

---

## 4. Config (`config/mattermost.json`, 0600, gitignored)

Set via the ⚙ Mattermost settings modal (→ `Store::saveMattermost`). Intake keys:

| Key | Default | Meaning |
|---|---|---|
| `intake_enabled` | `false` | Master switch — the listener only connects/watches when true. |
| `intake_tag` | `@Claude` | Leading trigger token (case-insensitive, must be a single word). |
| `intake_project` | `""` | Default project when none is inferred/specified. |
| `intake_channel` | `""` | Restrict to one channel **slug**; blank = any channel you're in. |
| `intake_llm` | `false` | Use the local `claude` CLI to interpret (vs the built-in heuristic). |

(`base_url`, `team`, `token`, `checkin_channel`, `checkout_channel`, cached `*_channel_id`s, and
`checkout_show_hours` are the check-in/out posting config.) Example with placeholders:
`config/mattermost.example.json`.

**Runtime files** (all gitignored, recreated on launch): `config/mm-listen.lock` (flock singleton),
`config/mm-listen.pid`, `config/mm-listen.status` (heartbeat the UI polls), `config/mm-listen.log`.

The listener re-reads config every ~10s, so changing `intake_tag` / `intake_channel` /
`intake_project` / `intake_llm` in settings takes effect within ~15s **without a restart**. Changing
`base_url` / `token` triggers a reconnect.

---

## 5. Lifecycle

- `start.sh` launches `bin/mm-listen` in the background and `trap`s `kill` on `EXIT INT TERM`, so the
  listener and the web server **live and die together** (it is NOT a daemon that survives the
  terminal). The web server runs in the foreground (note: not `exec`, so the trap can fire).
- The listener is a **`flock` singleton** (`config/mm-listen.lock`) — a second launch exits harmlessly.
- It **idles** when `intake_enabled` is false (writes a `disabled` heartbeat, re-checks every 15s).
- It **reconnects with exponential backoff** (1→60s) on a dropped socket.
- It writes a heartbeat each loop → `config/mm-listen.status` → `GET /api/mattermost/listener` → the
  toolbar **status dot** (green connected / grey off / amber connecting / red error or not-running).
- Manual control from /tasks: **Restart listener** button → `POST /api/mattermost/listener/start`
  (idempotent, flock-guarded). `…/listener/stop` kills it by its recorded pid.

The Claude CLI call is **synchronous** inside the read loop (a few seconds); `ClaudeCli::extractTask`
calls a heartbeat callback every ~5s during the wait so the status dot doesn't go stale, and has a
hard timeout (90s) + output cap (2 MB) so a runaway CLI can't hang/OOM the listener.

---

## 6. How to make common changes

| Change | Where |
|---|---|
| Trigger tag, watched channel, default project, LLM on/off | **No code** — ⚙ Mattermost settings (persisted to `config/mattermost.json`). |
| The extraction prompt or the output fields | `ClaudeCli::extractTask()` — `$prompt` and the JSON keys/`STATUSES`. Keep `--output-format json` and read `envelope.result`. |
| Force a specific Claude model for intake | `ClaudeCli::extractTask()` — add `'--model', 'claude-haiku-4-5'` to the args array passed to `run([...])`. (Default uses your `claude` config's model.) |
| Heuristic parsing (`proj:` / `#` / `!status` tokens) | `Intake::parse()` (+ `STATUS_ALIASES`). |
| Tag-matching rule (e.g. allow the tag mid-message) | `Intake::matchTag()` / `matchPostedEvent()`. |
| What counts as a trigger (e.g. also others' posts) | `Intake::matchPostedEvent()` — the `user_id === $myUserId` / `type === ""` / channel checks. ⚠ relaxing "own posts only" widens the trust boundary. |
| Confirmation (different emoji, or reply in-thread) | `bin/mm-listen` `mmHandleEvent()` — the `addReaction(...)` call. For a thread reply you'd extend `Mattermost::post()` to accept a `root_id` and post with it (it currently takes `($channelId, $message)`). A reply that contains the tag would re-trigger intake unless filtered — prefer a reaction. |
| Include more context than the replied-to message | `mmLlmTask()` — it fetches only `getPost(root_id)`; add e.g. a thread fetch. |
| CLI timeout / output cap / binary location | `ClaudeCli` constructor `$timeout`, `MAX_OUTPUT`, `resolveBin()` (or the `CLAUDE_CLI_BIN` env). |
| WebSocket framing / keepalive | `src/MattermostSocket.php` (`read()` control-frame budget, `ping()`); rare. |

After any change: re-run the checks in §8 and, for new credential/exec surface, an adversarial
review (per `CLAUDE.md`).

---

## 7. Security invariants (don't break these)

- **Token stays server-side** — never returned to the browser (`mattermostSettingsView` exposes only
  `has_token` / `intake_*` / `claude_available`), never logged, never in the heartbeat. TLS verify is
  always on for both the REST client and the WebSocket.
- **No API key** for the Claude path — `ClaudeCli` shells out via `proc_open` with an **argv array**;
  the untrusted message text is a single argument, never interpolated into a shell command (no
  injection). The binary path is resolved, never client input; it runs in a neutral cwd.
- **Only your own posts trigger** (`user_id === me.id`); the ✅ reaction can't loop (reactions aren't
  `posted` events).
- **Untrusted inbound text** → the task title is sanitised (control chars stripped, length capped)
  before `Tasks::create`; the board renders via `textContent` (no `innerHTML`).
- **No wedge** — every Claude/CLI failure is caught and falls back to the heuristic; the listener is a
  flock singleton with reconnect/backoff.

---

## 8. Testing / verifying a change

- **Lint:** `php -l <file>` for each PHP file; `node --check public/tasks.js`; `bash -n start.sh`.
- **Unit (no network), via `php -r`:** `Intake::matchTag/matchPostedEvent/parse` cases; `ClaudeCli`
  `decodeTaskJson` (fences/prose) and `isAvailable(false)`; `Store::saveMattermost` round-trip.
- **CLI smoke (uses your subscription, ~5s):**
  ```bash
  php -r 'require "src/ClaudeCli.php"; echo json_encode((new AwsDash\ClaudeCli())
    ->extractTask("- Create task - reperks project, 2FA Login"));'
  ```
- **End-to-end (real listener):** with `intake_enabled` + `intake_llm` on, post a uniquely-marked
  `@Claude …` to your **self-DM** (private), confirm the task appears + ✅ reaction, then **clean up**
  the task and the test post. Keep all Mattermost calls to your own DM/test channel.
- **Watch it live:** `tail -f config/mm-listen.log` (look for `connected`, `created a task …`,
  `Claude interpretation unavailable …`, `Claude CLI error …`).

---

## 9. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Task has the **literal message** as its title (e.g. `- Create task - …`) | `intake_llm` is off → built-in heuristic ran. Enable "Interpret messages with Claude" in settings (it's saved as `intake_llm`). |
| **No task at all** | Listener not connected, or `intake_enabled` off, or the token expired. Check the status dot, `GET /api/mattermost/listener`, and `config/mm-listen.log`. |
| Dot shows **not running / stale** | Listener died — click **Restart listener**, or re-run `./start.sh`. |
| **"No claude CLI found"** in settings | `claude` isn't on the PATH of the shell that ran `./start.sh`. Fix PATH, or set `CLAUDE_CLI_BIN=/full/path/to/claude` before `./start.sh`, or add a path to `ClaudeCli::resolveBin()`. |
| LLM **silently falls back** to the heuristic | The CLI errored/timed out — check `config/mm-listen.log` for `Claude CLI error` / `timed out`, then run the §8 CLI smoke test in the same shell environment. |
| Address already in use on `./start.sh` | Leftover server: `lsof -tiTCP:8010 -sTCP:LISTEN \| xargs kill -9`. |

> Note: there is **no MCP server** involved. The app calls the `claude` CLI directly; nothing needs
> to connect *into* the dashboard.
