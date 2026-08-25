# VpnHood.WHMCS.SignIn

The **`vpnhoodsignin`** WHMCS addon: makes "Sign in with Google" register new clients and
sign in existing ones, instead of dead-ending at *"Link Initiated! Please complete sign in
to associate this service with your existing account."*

Admin and install documentation lives in
**[modules/addons/vpnhoodsignin/README.md](modules/addons/vpnhoodsignin/README.md)**.
Developer documentation is in **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**.

## Standalone

This module depends on no other VpnHood module and works on any WHMCS 9 install. It owns no
database tables, keeps no duplicate state, and everything it does stops the moment the addon
is deactivated.

## Layout

```
modules/addons/vpnhoodsignin/   the module (this is all that ships)
scripts/set-version.sh          stamp VERSION into the module
scripts/deploy-dev.sh           publish to the dev WHMCS (staged, verified, linted)
tests/unit/                     pure-PHP tests; no WHMCS, no network
tests/integration/              real-WHMCS tests, run over SSH on the dev box
docs/ARCHITECTURE.md            developer guide
```

Production code lives **only** under `modules/`. Everything for development and testing
stays in `scripts/`, `tests/` and `docs/`, and never ships.

## Tests

```bash
php tests/unit/run.php          # the token trust boundary, offline
scripts/deploy-dev.sh           # publish to the dev WHMCS first, then:
tests/integration/signin.test.sh  # create / link / sign in against a real WHMCS
```

The unit suite generates its own RSA key and injects it as "Google's certs", so the whole
verification pipeline runs with no network. The integration suite creates and then deletes
real clients — point it at the dev box only.

## Versioning

`VERSION` at the repo root is the single source of truth; `scripts/set-version.sh` stamps it
into the module. **Bump it for any change to the module's files** — WHMCS decides whether to
re-run a module's install path by comparing the stamped version, so an edit shipped under an
unchanged number silently never reaches the install.
