#!/usr/bin/env bash
#
# signin.test.sh — run the create / link / sign-in integration test on the dev box.
#
# Uploads signin.test.php and runs it with the server's PHP, against the
# DEPLOYED module — run scripts/deploy-dev.sh first, or you will be testing
# whatever was last published rather than your working tree.
#
# WARNING: creates and deletes real clients on the target WHMCS, and activates
# the addon on it if it is not active already. Dev box only.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/whmcs/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Running the sign-in integration test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/signin.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "php ~/tmp/signin.test.php; rc=\$?; rm -f ~/tmp/signin.test.php; exit \$rc"
