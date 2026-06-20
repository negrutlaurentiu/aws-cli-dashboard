#!/usr/bin/env bash
#
# Launch the AWS CLI Dashboard on http://127.0.0.1:8010 (localhost only).
#
set -euo pipefail

HOST="127.0.0.1"
PORT="8010"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- prerequisites ----------------------------------------------------------
if ! command -v php >/dev/null 2>&1; then
  echo "✖ PHP is required but not found. Install it (e.g. 'brew install php') and retry." >&2
  exit 1
fi
if ! command -v aws >/dev/null 2>&1; then
  echo "⚠ aws CLI not found on PATH. The dashboard loads, but refreshing credentials will fail" >&2
  echo "  until the AWS CLI v2 is installed: https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html" >&2
fi

# --- first-run config -------------------------------------------------------
if [ ! -f "$DIR/config/accounts.json" ]; then
  cp "$DIR/config/accounts.example.json" "$DIR/config/accounts.json"
  chmod 600 "$DIR/config/accounts.json"
  echo "→ Created config/accounts.json (from the example). Add accounts from the UI."
fi

URL="http://$HOST:$PORT"
echo ""
echo "  AWS CLI Dashboard"
echo "  ─────────────────"
echo "  ▸ $URL"
echo "  ▸ credentials file: ${AWS_SHARED_CREDENTIALS_FILE:-$HOME/.aws/credentials}"
echo "  ▸ press Ctrl+C to stop"
echo ""

# Open the browser on macOS (ignore failures / other platforms).
if command -v open >/dev/null 2>&1; then
  ( sleep 1; open "$URL" >/dev/null 2>&1 || true ) &
fi

exec php -S "$HOST:$PORT" -t "$DIR/public" "$DIR/public/router.php"
