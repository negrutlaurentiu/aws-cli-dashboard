# Localhost setup (a runbook for Claude)

> This is the step-by-step setup guide referenced from `README.md` and `CLAUDE.md`.
> It is written so **Claude Code (or any agent) can bring the dashboard up on `localhost` from a
> fresh clone** with no prior context. Everything here is safe to run on the operator's machine.
>
> The repository intentionally ships **no secrets**. Every file that holds a credential
> (`config/accounts.json`, `config/mattermost.json`, `config/redmine.json`, `config/projects.json`,
> `config/state.json`, `config/settings.json`, `config/tasks.json`, plus all logs) is **gitignored**,
> so a clean checkout will not have them. The steps below create the ones you need locally from the
> committed `*.example.json` templates. **Never commit any of these files or any `*.log` / `*.zip`.**

---

## TL;DR

```bash
./start.sh          # auto-creates config/accounts.json + the CSRF token, then serves on 127.0.0.1:8010
open http://127.0.0.1:8010
```

That is enough for the **Credentials** and **S3** pages. The **Mattermost**, **@Claude intake**, and
**Redmine `/projects`** features are optional and each need one extra config file (steps 4–6).

---

## 0. Prerequisites

| Tool | Check | Install (macOS) |
|---|---|---|
| **PHP 8.1+** (8.5 is what the operator runs) | `php -v` | `brew install php` |
| **AWS CLI v2** (configured with your long-term profiles) | `aws --version` | [AWS install guide](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html) |
| **bash** + macOS/Linux | — | (Windows: use WSL, or run the `php -S …` line from `start.sh` directly) |

`start.sh` checks for both and warns rather than silently failing. There is **no Composer / npm / build
step** — it is PHP's built-in server + vanilla JS + one CSS file.

The dashboard *reads and writes* `~/.aws/credentials` (backed up to `.bak` before every write). For it
to do anything useful, the AWS CLI must already have your **long-term** profiles configured
(`aws configure --profile <name>` or hand-edited `~/.aws/credentials`).

---

## 1. Clone & enter

```bash
git clone <repo-url>          # the HyperSense-Software remote, or your personal one
cd <repo-dir>                 # e.g. laurentiu-local-dashboard
```

## 2. Start it (creates first-run config automatically)

```bash
./start.sh
```

On first run `start.sh`:
- copies `config/accounts.example.json` → **`config/accounts.json`** (`chmod 600`) if it is missing,
- pre-creates the CSRF app token in **`config/state.json`** (so multi-worker requests don't race),
- launches the **@Claude intake listener** in the background (it idles harmlessly while intake is off),
- serves the app on **`http://127.0.0.1:8010`** (binds `127.0.0.1` only) and opens it on macOS.

Stop with `Ctrl+C` — the server is **not a daemon**; it and the listener die when the terminal closes.

> Ad-hoc / headless launch (no browser pop, background): 
> ```bash
> PHP_CLI_SERVER_WORKERS=8 AWSDASH_OPEN_BROWSER=0 nohup ./start.sh >/tmp/awsdash.log 2>&1 &
> ```

## 3. Add an AWS account

Open <http://127.0.0.1:8010> and click **“+ Add account”**, or edit `config/accounts.json` directly.
Fields (see `README.md → Adding an account` for the full table):

- **Source profile** — the `~/.aws/credentials` profile holding your **long-term** keys.
- **Target profile** — where temp session creds get written (e.g. `mp-temp`).
- **MFA serial (ARN)** — `arn:aws:iam::<account-id>:mfa/<device>`.
- **Duration** — 900–129600 s (129600 = 36 h max).
- **MFA secret** — *optional* base32 seed for auto-generating the TOTP code. Storing it turns MFA into a
  single factor on this machine (the file is `0600` + gitignored); leave blank for manual mode.

That is all the Credentials + S3 pages need. The rest is optional.

---

## 4. (Optional) Mattermost check-in/out + `@Claude` task intake

Enables the Tasks page to post check-in/out digests and lets the operator's own `@Claude …` messages
become tasks (see `docs/mattermost-intake.md` for the deep dive).

```bash
cp config/mattermost.example.json config/mattermost.json
chmod 600 config/mattermost.json
```

Then edit `config/mattermost.json`:

| Key | What to put |
|---|---|
| `base_url` | Your Mattermost server, e.g. `https://mattermost.example.com` (HTTPS only). |
| `team` | The team slug. |
| `token` | A **personal or bot access token** (Mattermost → Account Settings → Security → Personal Access Tokens). Server-side only; never sent to the browser. |
| `checkin_channel` / `checkout_channel` | Channel names for the digests. |
| `intake_enabled` | `true` to turn on `@Claude` → task intake. |
| `intake_tag` | Trigger tag (default `@Claude`). |
| `intake_channel` | Optional: restrict intake to one channel. |
| `intake_llm` | `true` to interpret free-form text via the **local `claude` CLI** (see step 6); falls back to the built-in heuristic if the CLI is missing/slow. |

Only the operator's **own** posts trigger intake, and the listener starts/stops with `start.sh`.

## 5. (Optional) Redmine `/projects` hours reconciliation

Reconciles dashboard-tracked hours against hours logged in Redmine (read-only Redmine client).

```bash
cp config/redmine.example.json config/redmine.json    && chmod 600 config/redmine.json
cp config/projects.example.json config/projects.json  && chmod 600 config/projects.json
```

- `config/redmine.json` — one entry per Redmine host with its **API key** (Redmine → My account → API
  access key). Keys are secrets: sent only in the `X-Redmine-API-Key` header to that host, never logged,
  never returned to the browser.
- `config/projects.json` — the managed projects (id, name, `redmine_url`) shown on `/projects`.

## 6. (Optional) `@Claude` LLM intake + dashboard MCP

If `intake_llm` is `true`, the listener shells out to the operator's **locally logged-in `claude` CLI**
(Claude Code — the subscription, **no API key**) to interpret messages, and exposes the task board to it
via the stdio MCP server (`bin/dashboard-mcp` → `src/DashboardMcp.php`).

- Requires the `claude` binary on `PATH` (or set `CLAUDE_CLI_BIN=/path/to/claude`).
- No extra config file — it reuses `config/mattermost.json`.
- Everything degrades to the built-in heuristic if the CLI is absent, so this is always safe to leave off.

---

## 7. Verify it works

```bash
# 1. Lint (how every change in this repo is checked)
for f in $(git ls-files '*.php'); do php -l "$f" >/dev/null || echo "PHP FAIL: $f"; done
for f in $(git ls-files '*.js');  do node --check "$f"   || echo "JS FAIL:  $f"; done

# 2. Server is up + CSRF token is present
curl -s http://127.0.0.1:8010/ | grep -o 'csrf-token" content="[a-f0-9]*"'

# 3. Authenticated smoke test (GET needs no token; grab it for POSTs / file GETs)
TOKEN=$(curl -s http://127.0.0.1:8010/ | grep -o 'content="[a-f0-9]\{32,\}"' | grep -o '[a-f0-9]\{32,\}')
curl -s http://127.0.0.1:8010/api/accounts        # list accounts (GET, no token)
# POSTs need:  -H "X-CSRF-Token: $TOKEN"     file GETs need:  &token=$TOKEN
```

The `Host` header must be `127.0.0.1:8010` or `localhost:8010` (anti-DNS-rebinding); other hosts are
rejected. When testing against real AWS, keep calls **read-only** (`get-caller-identity`, `s3api list-*`)
and don't run `set-default` against the real credentials file.

---

## Security reminders (do not violate)

- **Localhost only.** The server binds `127.0.0.1:8010` and enforces the `Host` header. Never expose it.
- **Never commit secrets.** `config/*.json` (except the `*.example.json` templates), all `*.log`, and any
  `*.zip` backup are gitignored on purpose — a full project zip has leaked creds before. Before pushing,
  confirm: `git ls-files | grep -E 'accounts.json|state.json|tasks.json|mattermost.json|redmine.json|projects.json|^data/'`
  returns **nothing**.
- **Secret files are `0600`**, created exclusively (no world-readable window); `~/.aws/credentials` is
  backed up before each write.
- Tokens (Mattermost/Redmine) are read server-side and only ride TLS-verified requests to their validated
  host; they never reach the browser or the logs.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `PHP is required but not found` | `brew install php` (need 8.1+). |
| `aws CLI not found` | App still loads; install AWS CLI v2 before refreshing creds. |
| Port 8010 in use | `lsof -i :8010` then stop the other process, or edit `HOST/PORT` in `start.sh`. |
| Listener/toolbar dot red | Check `config/mm-listen.log` and `config/mm-listen.status`; ensure `intake_enabled` + a valid `token` in `config/mattermost.json`. |
| S3 calls fail “expired” | Refresh that account on the Credentials page — S3 uses its **target** profile. |
| Credentials not written | Confirm the **source** profile exists in `~/.aws/credentials` and the MFA code/secret is correct. |
