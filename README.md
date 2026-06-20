# AWS CLI Dashboard

A tiny **localhost** dashboard for juggling multiple AWS accounts that require MFA.
It turns this repetitive dance…

```bash
aws sts get-session-token \
  --serial-number arn:aws:iam::111122223333:mfa/your-device-name \
  --duration-seconds 129600 \
  --profile MobilityPlus \
  --token-code 123456
# …then copy AccessKeyId / SecretAccessKey / SessionToken into ~/.aws/credentials by hand
```

…into a **one-click refresh**. Optionally store each account's MFA secret and the dashboard
generates the 6-digit code for you, calls STS, and writes the temporary credentials into the
right profile of `~/.aws/credentials` automatically.

No frameworks, no build step, no `node_modules` — just PHP's built-in server and the AWS CLI.

![runs on 127.0.0.1:8010](https://img.shields.io/badge/binds-127.0.0.1%3A8010-5eead4) ![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4) ![AWS CLI v2](https://img.shields.io/badge/AWS%20CLI-v2-f5b544)

---

## Features

- **Account cards** — one per AWS profile, showing the live session status and a countdown
  to expiry.
- **Auto 2FA (optional)** — store the MFA base32 secret and a live TOTP code is shown with a
  30-second countdown ring; refresh becomes a single click.
- **Manual mode** — prefer to keep MFA as a true second factor? Leave the secret blank and
  type the 6-digit code each time.
- **Test / check expiry** — one click per card runs `aws sts get-caller-identity` on the
  target profile and tells you whether its credentials are still valid or expired.
- **Default-profile panel** — see who `[default]` currently resolves to (live identity), spot
  when it's expired, and switch `[default]` to mirror any profile in one click.
- **Account filter** — a dropdown to show just one account's card (handy when screen-sharing
  with a client). It's a visual filter, shared with the S3 page via your browser.
- **S3 browser** (`/s3`) — pick an account, browse buckets and folders, **preview** images,
  PDFs and text inline, **download** a single file, or **download a whole bucket/prefix** to
  `~/Downloads/aws-cli-dashboard`.
- **Task board** (`/tasks`) — a Kanban board (pending / in progress / review / done / archived)
  with drag-and-drop, a per-task **work timer**, **time-in-status** tracking, file
  **attachments** (drop / paste / pick, with inline preview), and a **weekly summary** of what
  you worked on.
- **Safe credential writes** — only the target profile's keys are rewritten; every other
  profile, comment and blank line in `~/.aws/credentials` is preserved, and a `.bak` backup
  is made before each write.
- **Profile suggestions** — source/target fields autocomplete from your existing
  `~/.aws/credentials` and `~/.aws/config` profiles.
- **Terminal companion** — `bin/aws-mfa` does the same thing without a browser.

---

## Requirements

- PHP 8.1+ (`php -v`)
- AWS CLI v2 (`aws --version`) configured with your long-term profiles
- macOS / Linux (Windows works via WSL or `php -S` directly)

## Quick start

```bash
git clone git@github.com:negrutlaurentiu/aws-cli-dashboard.git
cd aws-cli-dashboard
./start.sh
```

Then open <http://127.0.0.1:8010> (the script tries to open it for you on macOS) and click
**“+ Add account.”**

To stop, press `Ctrl+C`.

## Adding an account

| Field | Meaning |
|---|---|
| **Label** | Friendly name, e.g. *Mobility Plus*. |
| **Source profile** | The profile in `~/.aws/credentials` that holds your **long-term** access keys. |
| **Target profile** | Where the temporary session credentials are written (e.g. `mp-temp`). Defaults to the source profile. |
| **MFA serial (ARN)** | `arn:aws:iam::<account-id>:mfa/<device>` — shown in IAM → Security credentials. |
| **Duration** | Session length in seconds (900–129600; 129600 = 36 h, the IAM-user max). |
| **Region** | Optional; written into the target profile. |
| **MFA secret** | Optional base32 seed for auto-generating codes (see below). |

The classic pattern (and the one your existing `~/.aws/credentials` already uses) is:
keep long-term keys in `MobilityPlus`, write temp creds to `mp-temp`, and point your tools at
`--profile mp-temp`.

## Where do I get the MFA secret?

It's the **base32 “secret key”** AWS shows you (under *“Show secret key”*) when you first set
up a **virtual MFA device** — the same seed your authenticator app stores. If you saved it,
paste it into the *MFA secret* field for fully automatic refreshes.

If you only have the device/QR already configured and never saved the seed, that's fine —
leave the field blank and use **manual mode** (type the 6-digit code at refresh time). You can
also delete and recreate the virtual MFA device in IAM to get a fresh seed.

## Checking expiry & switching the default profile

- **Is a profile still valid?** Click **Test / check expiry** on a card. It runs
  `aws sts get-caller-identity` against that card's *target* profile and reports the identity
  (account + ARN) if valid, or **Expired** / **Invalid** otherwise — the authoritative answer.
- **What is `[default]` right now?** The panel at the top runs the same check on `[default]`
  on load and shows the current identity (or that it's expired).
- **Switch the default.** Pick a profile in the panel's dropdown (or hit **Set as default** on
  a card) and the dashboard copies that profile's credentials into `[default]`. Copying a
  long-term profile clears any stale session token; copying a temporary profile carries its
  session token across. Unscoped `aws` commands (no `--profile`) then use those credentials.

## S3 browser

Open **S3 Browser** from the top nav (or go to <http://127.0.0.1:8010/s3>). Pick an account —
the browser uses that account's **target** profile (the one holding the session credentials) —
then:

- **Browse**: choose a bucket, click into folders, use the breadcrumb to go back up.
- **Preview** (click a file or **View**): images and PDFs render inline; text/CSV/JSON/logs
  show as text. Other types (zip, xlsx, binaries) offer a **Download** instead.
- **Download a file**: the **Download** button on a row.
- **Download a whole folder/bucket**: **⇩ Download this folder** copies everything under the
  current path to `~/Downloads/aws-cli-dashboard/<bucket>/<prefix>` and shows live progress.

If a profile's session has expired, S3 calls fail with a clear message — refresh that account
on the Credentials page first.

### Viewer safety

Bucket contents are untrusted, so the file proxy never lets an object run script in the
dashboard's origin: HTML/SVG/unknown types are served as `text/plain` or forced to download,
every object response carries `X-Content-Type-Options: nosniff` and a script-free
`Content-Security-Policy: default-src 'none'`, and only an allow-list of image/PDF/text types
is ever shown inline. The viewer endpoint is authenticated with the same per-install token
(passed as a query param so `<img>`/`<iframe>` can load it).

## Task board

Open **Tasks** from the top nav (or <http://127.0.0.1:8010/tasks>).

- **Board**: five columns — Pending, In Progress, Review, Done, Archived. **Drag** a card
  between columns to change its status; the change is timestamped.
- **Add task**: title + description. Click a card to open its detail (edit title/description,
  change status, attach files, delete).
- **Work timer**: each card has a **Start / Stop** timer. Only one timer runs at a time —
  starting another banks the first one's elapsed time. The top bar shows the live running
  timer. So if you start work on a task, switch to another, then come back to review one, each
  task accumulates exactly the time you spent on it.
- **Timeframes**: every card shows how long it's been in its current status; the detail view
  breaks down total worked time and time spent in each status (pending / in progress / review …).
- **Attachments**: drop files onto a task, **paste** a screenshot, or pick files. Images show a
  thumbnail; click to preview images / PDFs / text inline (others download).
- **Weekly summary**: the calendar button shows, for any week (prev / next), total time worked,
  a per-task breakdown, and what you completed and created that week.

Tasks live in `config/tasks.json` and attachments under `data/task-files/` — both local, `0600`,
and gitignored. Attachments are served with the same script-free hardening as the S3 viewer.

## Terminal usage

```bash
bin/aws-mfa                 # list configured accounts + session status
bin/aws-mfa mobilityplus    # refresh (uses stored secret, or prompts for a code)
bin/aws-mfa mobilityplus 123456   # refresh with an explicit code
bin/aws-mfa --all           # refresh every account that has a stored secret
```

(Run `chmod +x bin/aws-mfa` once if needed, or call it as `php bin/aws-mfa …`.)

---

## Security model

This is a **single-user local tool**. The protections exist to stop *other web pages* or
*other machines* from driving it — not to authenticate you to yourself.

- **Localhost only.** The server binds `127.0.0.1:8010`. It is never exposed on your network.
- **Anti DNS-rebinding.** Every request's `Host` header must be exactly `127.0.0.1:8010` or
  `localhost:8010`, so a malicious site cannot trick your browser into driving the dashboard.
- **CSRF token.** All mutating requests must echo a per-install token embedded in the page;
  a cross-origin page can't read it.
- **No shell injection.** Every value passed to the AWS CLI goes through `escapeshellarg`.
- **Atomic, backed-up, serialized writes.** `~/.aws/credentials` is copied to
  `~/.aws/credentials.bak`, then replaced via a temp-file `rename`. An advisory `flock` guards
  the read-modify-write so two concurrent refreshes (two tabs, or the UI plus the CLI) can't
  lose each other's updates. Other profiles, comments and `[headers]` are preserved
  byte-for-byte; duplicate profile names are updated last-wins to match the AWS CLI.
- **No world-readable window.** The temp and `.bak` files are created `0600` from the very
  first byte (via `umask` + exclusive `O_EXCL` create), so secrets are never momentarily
  readable by another local user on a shared host — and a value containing a newline is
  refused, so nothing can inject extra lines into the credentials file.
- **Secrets at rest.** `config/accounts.json` and `config/state.json` are written `0600` and
  are **gitignored**. They never leave your machine.

> ⚠️ **Storing an MFA secret turns MFA into a single factor on this machine.** Anyone with
> read access to `config/accounts.json` can mint sessions. For the highest security, leave the
> secret blank and use manual mode. Storing it is a convenience trade-off — your call,
> per account.

### What is *not* committed

`config/accounts.json`, `config/state.json`, and anything named `credentials*` are gitignored.
Only `config/accounts.example.json` (no secrets) is tracked.

---

## How it works

```
public/router.php   → PHP built-in-server router (static passthrough + front controller)
public/index.php    → routes: GET / , GET/POST /api/*
public/app.js       → vanilla-JS dashboard (live TOTP, countdowns, refresh)
public/styles.css   → styling
src/Totp.php        → RFC 6238 TOTP (base32 + HMAC-SHA1)
src/Sts.php         → wraps `aws sts get-session-token`
src/CredentialsFile.php → line-preserving ~/.aws/credentials editor + backup
src/Store.php       → accounts.json / state.json + CSRF token
src/bootstrap.php   → config + Host/CSRF guards
bin/aws-mfa         → terminal companion
```

## License

MIT — see [LICENSE](LICENSE).
