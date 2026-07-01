# CLAUDE.md

Guidance for working in this repo. Read this first — it captures architecture, conventions,
and the security patterns that every change must follow.

## What this is

A **single-user, localhost developer dashboard** (PHP, no framework, no dependencies) that:

1. **Credentials** (`/`) — manages AWS MFA session tokens: per-account cards, generates the
   TOTP code from a stored seed (optional) or takes a typed code, runs
   `aws sts get-session-token`, and writes temp creds into `~/.aws/credentials`. Also: test a
   profile (`get-caller-identity`), switch the `[default]` profile, a global token-lifetime
   setting, and per-account terminal-command snippets.
2. **S3 Browser** (`/s3`) — browse buckets (paginated) → objects (paginated via continuation
   tokens) → preview (image/PDF/text) or download a file, or download a whole bucket/prefix to
   `~/Downloads/aws-cli-dashboard` (detached job + progress).
3. **Tasks** (`/tasks`) — Kanban board (pending / in_progress / review / done / archived) with
   drag-and-drop, per-task work timer, time-in-status tracking, file attachments with preview,
   a weekly work summary, Mattermost check-in/out posting, and **@Claude intake**: a background
   listener (`bin/mm-listen`) holds a Mattermost WebSocket open and turns the operator's own
   `@Claude …` messages into tasks (see below).

It shells out to the real `aws` CLI for everything AWS. Runtime: **PHP 8.5**, **aws-cli v2**,
macOS. GitHub: `git@github.com:negrutlaurentiu/aws-cli-dashboard.git`.

### The @Claude intake listener (`bin/mm-listen`)

> **Maintainer deep-dive:** [`docs/mattermost-intake.md`](docs/mattermost-intake.md) — data flow,
> config keys, lifecycle, "how to change X" recipes, and troubleshooting. Read it before editing the
> intake pipeline.


Mattermost's native triggers (outgoing webhooks / slash commands) POST a callback **to** the
integration, which can't work here — the dashboard binds `127.0.0.1` only and the Mattermost server
is remote. Instead `bin/mm-listen` opens an **outbound** WebSocket to Mattermost (RFC 6455 by hand,
via `MattermostSocket`), authenticates with the stored bearer token, and reads `posted` events. For
the operator's **own** non-system messages that start with the trigger tag (default `@Claude`,
optionally restricted to one channel), it turns the text into a task and calls `Tasks::create()`
directly, then reacts ✅ on the source post. Parsing is either the **built-in heuristic** (`Intake`:
`proj:`/`#`/`!status` tokens) or, when `intake_llm` is on, an **optional Claude pass**
(`ClaudeCli::extractTask`, which shells out to the operator's local `claude` CLI — no API key) that
interprets the free-form text — plus the replied-to message fetched via `Mattermost::getPost` — into
a structured task; the LLM path degrades to the heuristic if the CLI is missing/slow/errors, so
intake never wedges. It's a `flock`
**singleton**, idles when `intake_enabled`
is off (re-checking every ~15s), reconnects with backoff, and writes a `mm-listen.status` heartbeat
the UI polls for a toolbar status dot. `start.sh` launches it in the background and a `trap` kills it
on exit, so it and the dashboard live and die together (it is NOT a daemon that survives the
terminal). WebSocket delivers each post once and never replays history on reconnect, so there is no
backfill and no dedup bookkeeping.

## Run / test it

> **Setting up from a fresh clone?** Read [`docs/claude-setup.md`](docs/claude-setup.md) first — it is
> the localhost setup runbook (prerequisites, the gitignored config files you must create from the
> `*.example.json` templates, optional Mattermost/Redmine features, and how to verify).

```bash
./start.sh            # http://127.0.0.1:8010  (binds 127.0.0.1 ONLY)
```

`start.sh` pre-creates the CSRF token, runs the server with `PHP_CLI_SERVER_WORKERS=8`
(so streaming/downloads don't block the UI) and `-d upload_max_filesize=64M -d post_max_size=66M`
(task attachments). The server is **not a daemon** — the user runs `./start.sh` in a terminal;
it dies when that closes. For ad-hoc testing, launch it in the background:

```bash
PHP_CLI_SERVER_WORKERS=8 nohup php -d upload_max_filesize=64M -d post_max_size=66M \
  -S 127.0.0.1:8010 -t public public/router.php >/tmp/awsdash.log 2>&1 &
```

How changes have been verified (do the same):
- `php -l <file>` lint every PHP file; `node --check public/*.js` for JS.
- PHP "unit" checks via `php -r '...'` against the `src/` classes (TOTP vectors, credentials
  round-trip, etc.).
- `curl` smoke tests against the running server (extract the CSRF token from the page HTML:
  `grep -o 'csrf-token" content="[a-f0-9]*"'`). GET endpoints need no token; POSTs need
  `-H "X-CSRF-Token: $TOKEN"`; the file-serving GETs need `&token=$TOKEN` in the query.
- Browser checks via the `chrome-devtools` MCP (navigate, `evaluate_script`, `take_screenshot`).
- **Always clean up test data** you create (tasks, etc.) and reset any global settings you change.

## Architecture

- **No framework, no Composer, no npm.** Just PHP's built-in server + vanilla JS + one CSS file.
- **`public/router.php`** is the built-in-server router: real files under `public/` (CSS, JS)
  are served directly (`return false`); everything else is dispatched to `public/index.php`.
- **`public/index.php`** is the front controller: `new App()` → `assertTrustedHost()` → a big
  `switch (true)` matching `$method` + `$path`. Each page route renders an HTML template; each
  `/api/*` route calls a handler function (defined lower in the same file).
- **`src/bootstrap.php`** defines `App` (paths, the `Store`/`Tasks` instances, aws-bin
  resolution) and the security middleware (`assertTrustedHost`, `assertCsrf`,
  `assertCsrfQuery`, `withCredentialsLock`) and response helpers (`json`, `fail`, `jsonBody`).
- **Each page is a separate template + JS file**, all sharing `public/styles.css`:
  - `src/page.html` + `public/app.js` — Credentials (`/`)
  - `src/s3.html` + `public/s3.js` — S3 Browser (`/s3`)
  - `src/tasks.html` + `public/tasks.js` — Tasks (`/tasks`)
  - Templates live in `src/` (NOT under `public/`) so they're never served raw; the front
    controller reads them and substitutes `__APP_TOKEN__`, `__HOST__`, `__PORT__`.

### `src/` classes (each self-contained, namespace `AwsDash`)

| File | Responsibility |
|---|---|
| `Totp.php` | RFC 6238 TOTP (base32 + HMAC-SHA1). Passes the standard test vectors. |
| `CredentialsFile.php` | Line-preserving editor for `~/.aws/credentials`: load / setProfile (last-wins on dup names) / getProfile / removeKey / render / atomic save with backup. |
| `Sts.php` | Wraps `aws sts get-session-token` and `get-caller-identity` (proc_open + escapeshellarg); `listProfiles`. |
| `Store.php` | `accounts.json`, `state.json` (CSRF app token + last-session info), `settings.json` (global token lifetime), `mattermost.json` (connection + `intake_*` config), and the listener heartbeat (`mm-listen.status`). |
| `S3.php` | `aws s3 / s3api`: listBuckets, listObjects (paginated), headObject, streamObject, detached recursive download + status. Path-confinement helpers. |
| `Tasks.php` | Task store: CRUD, timer/sessions, status history, weekly summary, attachments; `flock` + atomic writes. |
| `Mattermost.php` | Mattermost REST v4 client (bearer token, HTTPS + TLS-verify, no redirects): `me`, `resolveChannelId`, `post`, `addReaction`. Posts check-in/out digests; the only outbound-HTTP surface. |
| `MattermostSocket.php` | Hand-rolled RFC 6455 WebSocket **client** (stock PHP, no deps): TLS connect, `authentication_challenge`, masked client frames, ping/pong, frame parser. Powers the @Claude listener. |
| `Intake.php` | Pure logic for the @Claude listener: `matchPostedEvent` (own/non-system/tag filter), the built-in `parse` (`proj:`/`#`/`!status` heuristic), and title/description sanitisation. No I/O — unit-testable. |
| `ClaudeCli.php` | Optional task interpretation via the operator's LOCAL `claude` CLI (Claude Code — the subscription they're logged into, NO API key): `extractTask()` runs `claude -p … --output-format json` (proc_open, argv array — no shell) in a neutral cwd with a timeout, and reads `{title,project,description,status}` out of the result. The listener falls back to `Intake::parse` if it's missing/slow/errors. |

## Routing / how to add things

- **New API endpoint**: add a `case $method === 'POST' && $path === '/api/...':` to the switch
  in `index.php`; call `$app->assertCsrf()` for mutations; write a handler function below.
- **New GET that loads in `<img>`/`<iframe>`** (can't send headers): authenticate with
  `$app->assertCsrfQuery()` (token in the `?token=` query param) instead of `assertCsrf()`.
- **New page**: add a `renderX()` (calls `renderTemplate($app, 'x.html', $csp)`), a `src/x.html`
  template, a `public/x.js`, and a nav link (`/`, `/s3`, `/tasks` nav appears in all 3 templates).

## Security model (NON-NEGOTIABLE — every change must hold these)

Trust model: a **single trusted local operator**. There is deliberately no login. Protections
exist to stop *other web pages in the browser* and *other local users/processes* from driving
the tool or reading its files. A "real" bug is one exploitable by someone **other** than that
operator, or that corrupts the credentials file / leaks a secret.

- **Bind 127.0.0.1 only.** `assertTrustedHost()` also requires the `Host` header to be
  `127.0.0.1:8010` / `localhost:8010` (anti DNS-rebinding) on every request.
- **CSRF**: all mutating (POST) routes call `assertCsrf()` (constant-time `hash_equals` of the
  per-install app token sent as `X-CSRF-Token`). The token is a 256-bit random in `state.json`,
  embedded in each page as `<meta name="csrf-token">`. `<img>`/`<iframe>` GETs use
  `assertCsrfQuery()` (token in query); pages set `Referrer-Policy: no-referrer`.
- **Shell**: NEVER interpolate user input into a command. Build args as an array and join with
  `escapeshellarg` (see `S3::cmd`, `Sts`). The one nested `sh -c` (S3 download) double-escapes.
- **Serving file bytes** (S3 objects, task attachments): go through `serveLocalFile` /
  `s3Object`, which set `X-Content-Type-Options: nosniff` + a script-free
  `Content-Security-Policy: default-src 'none'` and use the `s3Mime()` allow-list — only
  images/PDF/text are inline; `.html`/`.svg`/unknown are `text/plain` or forced download. This
  is what stops an attacker-controlled bucket/upload from running script in our origin. Reuse it
  for any new file-serving.
- **Writing secret-bearing files** (credentials, accounts.json, state.json, settings.json,
  task files): wrap in `umask(0077)`, create the temp via `fopen($p,'xb')` (O_EXCL), `chmod`
  0600 / dirs 0700, then atomic `rename`. Never leave a 0644 window. Back up `~/.aws/credentials`
  before writing.
- **Concurrency**: the server is multi-worker, so any read-modify-write of a shared JSON/INI
  file must hold a lock spanning read→write (`App::withCredentialsLock` for credentials,
  `Tasks::acquireLock()` for tasks). Atomic rename alone prevents torn files but not lost updates.
- **Path traversal**: never build a filesystem path from raw client input. Task attachment paths
  are only assembled after `find($taskId)` / attachment-lookup matches a server-generated
  `t-`/`f-` id; S3 download dest is confined under `~/Downloads/...` via `safeSegment`/`safeRelPath`.
- **No secret to the browser**: the API returns `has_secret: true/false`, never the TOTP seed or
  written keys (access keys are masked via `maskKey`).
- **@Claude listener**: the bearer token is read server-side only and rides the **TLS-verified**
  socket (`verify_peer`/`verify_peer_name` always on) / `Authorization` header to the validated host
  — never logged, never in the `mm-listen.status` heartbeat (state + timestamp only), never returned
  by `/api/mattermost/listener`. **Only the operator's own posts** (`user_id === me.id`) trigger
  intake, so a coworker can't create tasks. Inbound chat is untrusted: the task **title is sanitised**
  (control chars stripped, length capped) before `Tasks::create`. The cursor-free WebSocket model
  plus the `flock` singleton mean overlapping reads can't double-create. The `start`/`stop` routes
  are `assertCsrf`-guarded and only ever spawn a **fixed binary** / `kill` an int pid — no client
  input reaches the shell.
- **Local `claude` CLI (optional LLM intake)**: there is NO API key — `ClaudeCli` shells out to the
  operator's logged-in `claude` binary via `proc_open` with an **argv array** (the untrusted message
  text is a single argument, never interpolated into a shell command — no injection surface), in a
  neutral cwd (so it doesn't load this project's CLAUDE.md/MCP), with a hard timeout. The binary path
  is fixed/resolved (`CLAUDE_CLI_BIN` env → known locations → PATH), never client input. Worst case
  of a prompt-injected referenced message is a mis-parsed task on the operator's OWN board — no
  escalation (model output is never eval'd/shelled/used as a path), and any failure falls back to the
  heuristic.

## Data & files (all gitignored — NEVER commit real data)

| Path | Contents | Notes |
|---|---|---|
| `config/accounts.json` | accounts incl. optional TOTP seeds | 0600. Example: `accounts.example.json` (redacted placeholders only). |
| `config/state.json` | CSRF app token + last-session info | 0600 |
| `config/settings.json` | global token lifetime | 0600 |
| `config/tasks.json` | tasks | 0600, `flock` via `.lock` |
| `config/mattermost.json` | Mattermost URL/token/channels + `intake_*` config | 0600. Example: `mattermost.example.json` (redacted token only). |
| `config/mm-listen.{lock,pid,status,log}` | @Claude listener runtime (singleton lock, pid, heartbeat, log) | 0600; transient, recreated on launch. No secrets. |
| `data/task-files/<taskId>/<fileId>` | attachment blobs | 0700/0600, outside the doc root |
| `~/.aws/credentials` | the real AWS creds we read/write | backed up to `.bak` before writes |

`.gitignore` covers all of the above. The committed `accounts.example.json` uses fake account
IDs — **real AWS account IDs / MFA ARNs must never land in git** (history was once scrubbed for
this; keep it clean).

## Conventions

- **PHP**: `declare(strict_types=1)`, namespace `AwsDash`, typed signatures, small focused
  classes, comments explain *why* (esp. security choices). PSR-12-ish.
- **JS**: vanilla, no build. Build DOM with `textContent` / `createElement` (NEVER `innerHTML`
  with user data — XSS). `escapeHtml` only where a template literal is unavoidable. Each page's
  JS reads the CSRF token from the meta tag and has its own `api()` helper.
- **CSS**: one file `public/styles.css`, CSS variables at `:root`, dark "ops console" aesthetic
  (the user cares about UI/UX — keep it polished and intentional, not templated).
- **Git**: branch is `main`. Commit only when work is verified. Commit messages: a concise
  subject + a body explaining what/why; end with
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`. Use SSH remote `origin`.
  Confirm `git ls-files | grep -E 'accounts.json|state.json|tasks.json|^data/'` returns nothing
  before pushing.
- **Reviews**: substantial / security-sensitive changes have been validated with an adversarial
  multi-agent review (the `Workflow` tool) before committing — keep doing this for new
  credential/file-upload/exec surface.

## Gotchas

- `php -S` is single-threaded *per worker*; long requests (file streaming, downloads) are why
  it runs multi-worker. Downloads are detached (`nohup sh -c ... &`) and polled, not held open.
- Templates must stay in `src/` (not `public/`) or they'd be served as raw text by the router.
- AWS `--max-items` paginates **objects** (Contents); folder-only levels (CommonPrefixes) can
  return all at once — that's expected.
- The user's profiles follow `source` → `<short>-temp` (e.g. `MobilityPlus` → `mp-temp`); S3 uses
  the account's `target_profile`, which must be non-expired (refresh it on `/` first).
- When testing, the app makes **real AWS calls** under the user's profiles — keep them read-only
  (`get-caller-identity`, `s3api list-*`) unless explicitly exercising a write, and never call
  `set-default` against the real file during automated tests.
