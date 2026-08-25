# CLAUDE.md

Guidance for working in this repository.

## What this repo is

The `vpnhoodsignin` WHMCS addon. It replaces WHMCS's built-in Sign-In Integration so that
"Sign in with Google" **registers** new clients and **signs in** existing ones, instead of
dead-ending at *"Link Initiated! Please complete sign in to associate this service with
your existing account."*

## Read this first

**Before changing anything, read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).** It records
what WHMCS actually does underneath — including two behaviours that will otherwise cost you
an afternoon each (addon activation is three `tblconfiguration` lists, and a page has more
than one name). Keep it updated when you change the flow.

## Non-negotiable rules

- **Standalone.** This module depends on no other VpnHood module and must install on a bare
  WHMCS 9. Where code was adapted from `VpnHood.WHMCS.Iap` (the JWT/Google verification, the
  `AddClient` call) it was **copied deliberately** — never `require` across repos.
- **The ID token is the whole trust boundary.** `GoogleToken::verify()` is the only thing
  standing between a stranger and an auto-created or auto-linked WHMCS account. Never widen
  what it accepts, never skip the `aud` check, and keep the injectable certs fetcher — it is
  what makes the unit tests possible.
- **Never sign in a two-factor account.** Signing in mints a session directly, which would
  cancel a second factor the client deliberately enabled. `userHasTwoFactor()` returns
  `true` when it cannot read the column: unreadable state must not downgrade security.
- **Own no tables, duplicate no state.** Account links live in WHMCS's own
  `tblauthn_account_links`; the custom-field answer lives in `tblcustomfieldsvalues` and is
  written through `localAPI('UpdateClient')`, never directly.
- **Resolve the custom field by name, every time.** Ids differ per install (43 on our dev
  WHMCS, and a hook in the sibling hub repo still hardcodes 6). A hardcoded id silently
  disables the feature.
- **Hooks fail open; the endpoint fails closed.** A hook that cannot read its own state
  renders no button and bars no door. `api.php` answers 404 whenever the addon is inactive
  or disabled, and gives visitors generic messages while logging the real reason.
- **Folder naming:** lowercase letters/numbers only (no underscores/spaces).
- **Never hand-edit the module's version.** The root `VERSION` file is the single source of
  truth; `scripts/set-version.sh` stamps it.
- **Bump the version for ANY change to the module's files** — templates, hooks, a comment.
  WHMCS decides what to reinstall by comparing the stamped version, so an edit shipped under
  an unchanged number silently does not reach the install.

## Production vs. dev/test layout

Production code lives ONLY under `modules/`. Everything for development and testing stays in
its own folder — never mix test/dev files into the production tree:

- `VERSION` + `scripts/set-version.sh` — the repo version and the script that stamps it.
- `scripts/deploy-dev.sh` — publishes the module to the dev WHMCS (staged upload + md5
  verify + server-side `php -l` + endpoint smoke check). It deploys only `modules/`, so test
  files can never reach the server.
- `tests/unit/` — pure PHP, no WHMCS, no network: `php tests/unit/run.php`.
- `tests/integration/` — real-WHMCS tests, uploaded and run over SSH on the dev box.
- `docs/` — developer docs.

## Testing

There **is** a local PHP CLI here, and a real unit suite:

```bash
php tests/unit/run.php                 # token trust boundary, offline, ~20 cases
scripts/deploy-dev.sh                  # publish to the dev WHMCS, then:
tests/integration/signin.test.sh       # create / link / sign in against real WHMCS
```

The integration suite creates and then deletes real clients, and activates the addon on the
target install. Dev box only.

## Dev server & credentials

- Credentials live outside the repo at `<Vh root>/.user/whmcs/`: `ssh.openssh` (private
  key), `ssh.ppk`, `ssh.pub`, `secrets-dev.json`.
  > The sibling `VpnHood.WHMCS` repo's CLAUDE.md and `deploy-dev.sh` point at a
  > `.user/account-dev.vpnhood.com/` directory that does not exist. Use `.user/whmcs/`.
- Dev WHMCS: `ssh -i <Vh root>/.user/whmcs/ssh.openssh whmcsdev@webhost-ftps.vpnhood.com`,
  web root `/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html`, site
  `https://whmcs-dev.vpnhood.com`. Runs WHMCS **9.0.3**, theme `lagom2`.
- The WHMCS core is ionCube-encoded — you cannot read its logic. Templates, language files
  and JavaScript **are** plaintext and are where the real answers live.
- The real login page is `index.php?rp=/login`; `login.php` 302-redirects away.
- `cart.php?a=checkout` returns 500 on the dev box with this addon disabled too — that is
  pre-existing, not ours.

## Related repos (siblings, not dependencies)

`VpnHood.WHMCS` (hub), `VpnHood.WHMCS.Iap`, `VpnHood.WHMCS.Partner`,
`VpnHood.WHMCS.ClientTheme`. This module shares a dev WHMCS with them and nothing else.
