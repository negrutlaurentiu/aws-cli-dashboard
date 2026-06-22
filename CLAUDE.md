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
   and a weekly work summary.

It shells out to the real `aws` CLI for everything AWS. Runtime: **PHP 8.5**, **aws-cli v2**,
macOS. GitHub: `git@github.com:negrutlaurentiu/aws-cli-dashboard.git`.

## Run / test it

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
| `Store.php` | `accounts.json`, `state.json` (CSRF app token + last-session info), `settings.json` (global token lifetime). |
| `S3.php` | `aws s3 / s3api`: listBuckets, listObjects (paginated), headObject, streamObject, detached recursive download + status. Path-confinement helpers. |
| `Tasks.php` | Task store: CRUD, timer/sessions, status history, weekly summary, attachments; `flock` + atomic writes. |

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

## Data & files (all gitignored — NEVER commit real data)

| Path | Contents | Notes |
|---|---|---|
| `config/accounts.json` | accounts incl. optional TOTP seeds | 0600. Example: `accounts.example.json` (redacted placeholders only). |
| `config/state.json` | CSRF app token + last-session info | 0600 |
| `config/settings.json` | global token lifetime | 0600 |
| `config/tasks.json` | tasks | 0600, `flock` via `.lock` |
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
