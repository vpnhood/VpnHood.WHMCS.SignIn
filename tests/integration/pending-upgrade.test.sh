#!/usr/bin/env bash
#
# pending-upgrade.test.sh — run the cron's catch-up of an update (hooks.php) on the dev box.
#
# Uploads pending-upgrade.test.php and runs it with the server's PHP, against the DEPLOYED
# module — run scripts/deploy-dev.sh first. Writes only the module's own version row, and leaves
# it at the files' version. Dev box only.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Running the pending-upgrade test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/pending-upgrade.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "php ~/tmp/pending-upgrade.test.php; rc=\$?; rm -f ~/tmp/pending-upgrade.test.php; exit \$rc"
