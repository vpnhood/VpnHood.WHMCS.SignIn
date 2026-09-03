#!/usr/bin/env bash
#
# deploy-dev.sh — publish the vpnhoodsignin module to the dev WHMCS
# (https://whmcs-dev.vpnhood.com), so testing always runs against the current
# working tree.
#
# Usage:
#   scripts/deploy-dev.sh
#
# The module directory is uploaded to a staging dir on the server and swapped
# into place, so the live site never serves a half-copied module. After the swap
# it verifies an md5 manifest (local vs remote), lints every deployed .php with
# the server's PHP, and smoke-checks the endpoint.
#
# This repo deploys ONE directory and adds nothing to includes/hooks — the hook
# lives inside the addon, which is both the kill switch and a sidestep of the
# shared-hooks "two files declaring the same function fatals the site" hazard.
#
# Config (env vars, all optional):
#   WHMCS_DEV_SSH_KEY   default <Vh root>/.user/ssh/ssh.openssh
#   WHMCS_DEV_SSH_HOST  default whmcsdev@webhost-ftps.vpnhood.com
#   WHMCS_DEV_WEBROOT   default /home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html
#   WHMCS_DEV_URL       default https://whmcs-dev.vpnhood.com

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
WEBROOT="${WHMCS_DEV_WEBROOT:-/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"

MODULE_REL="modules/addons/vpnhoodsignin"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

FAIL=0

# Replace $WEBROOT/$rel with $REPO_ROOT/$rel (exact sync, staged then swapped).
deploy_dir() {
  local rel="$1" stage="$1.deploying"
  [ -d "$REPO_ROOT/$rel" ] || { echo "!! source missing: $REPO_ROOT/$rel" >&2; exit 1; }
  echo "-> $rel"
  "${SSH[@]}" "rm -rf '$WEBROOT/$stage' && mkdir -p '$WEBROOT/$stage'"
  tar -C "$REPO_ROOT/$rel" -cf - --exclude='._*' --exclude='.DS_Store' . \
    | "${SSH[@]}" "tar -C '$WEBROOT/$stage' -xf - \
        && rm -rf '$WEBROOT/$rel' && mv '$WEBROOT/$stage' '$WEBROOT/$rel'"
}

# Copy files under $REPO_ROOT/$rel into $WEBROOT/$rel WITHOUT deleting anything
# already there. Used for modules/widgets, which holds WHMCS's own dashboard
# widgets and the widgets of every other VpnHood package — replacing that
# directory would delete them.
overlay_dir() {
  local rel="$1"
  [ -d "$REPO_ROOT/$rel" ] || { echo "!! source missing: $REPO_ROOT/$rel" >&2; exit 1; }
  echo "-> $rel (overlay)"
  tar -C "$REPO_ROOT" -cf - --exclude='._*' --exclude='.DS_Store' "$rel"     | "${SSH[@]}" "tar -C '$WEBROOT' -xf -"
}

# Compare an md5 manifest of local vs deployed files. (`sed 's/ \*/  /'`
# normalizes the binary-mode marker Git Bash's md5sum emits but Linux's doesn't.)
verify_dir() {
  local rel="$1" local_sum remote_sum
  local_sum="$(cd "$REPO_ROOT/$rel" && find . -type f | LC_ALL=C sort | xargs md5sum | sed 's/ \*/  /' | md5sum | cut -d' ' -f1)"
  remote_sum="$("${SSH[@]}" "cd '$WEBROOT/$rel' && find . -type f | LC_ALL=C sort | xargs md5sum | sed 's/ \*/  /' | md5sum" | cut -d' ' -f1)"
  if [ "$local_sum" = "$remote_sum" ]; then
    echo "   verified: $rel"
  else
    echo "!! MANIFEST MISMATCH: $rel" >&2
    FAIL=1
  fi
}

# php -l every .php in the deployed directory, using the server's PHP.
lint_dir() {
  local rel="$1" out
  out="$("${SSH[@]}" "cd '$WEBROOT/$rel' && find . -name '*.php' -print0 \
        | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors' || true")"
  if [ -n "$out" ]; then
    echo "!! PHP LINT ERRORS in $rel:" >&2
    echo "$out" >&2
    FAIL=1
  else
    echo "   php -l ok: $rel"
  fi
}

# Smoke check: the endpoint must answer with its OWN JSON envelope. An inactive
# or disabled addon fails closed with 404 + {"status":"refused"}; an active one
# rejects the missing nonce with 403 + the same envelope. Matching a bare
# "error" substring is not enough — a PHP fatal renders an HTML page too, which
# is exactly how a site-wide 500 can pass a lazier check.
smoke_check() {
  local resp code body
  resp="$("${SSH[@]}" "curl -sk -m 30 -w '\n%{http_code}' -X POST '$SITE_URL/$MODULE_REL/api.php' -d 'id_token=probe'")"
  code="$(printf '%s' "$resp" | tail -n1)"
  body="$(printf '%s' "$resp" | sed '$d')"

  if [ "$code" -ge 500 ] 2>/dev/null; then
    echo "!! SMOKE CHECK FAILED (HTTP $code) — the site is erroring:" >&2
    echo "$body" | head -c 400 >&2; echo >&2
    FAIL=1
  elif printf '%s' "$body" | grep -q '"status"[[:space:]]*:[[:space:]]*"refused"'; then
    echo "   api answers (HTTP $code): $body"
  else
    echo "!! SMOKE CHECK FAILED — not the module's JSON envelope (HTTP $code):" >&2
    echo "$body" | head -c 400 >&2; echo >&2
    FAIL=1
  fi
}

echo "Deploying vpnhoodsignin to $SSH_HOST:$WEBROOT"
deploy_dir "$MODULE_REL"
verify_dir "$MODULE_REL"
lint_dir   "$MODULE_REL"
# the shared VpnHood update-check widget ships with every package, same path
overlay_dir "modules/widgets"
lint_dir    "modules/widgets"
smoke_check

if [ "$FAIL" -ne 0 ]; then
  echo "DEPLOY FINISHED WITH ERRORS" >&2
  exit 1
fi
echo "Deploy OK"
