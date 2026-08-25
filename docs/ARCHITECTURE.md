# VpnHood.WHMCS.SignIn — Architecture & Developer Guide

Developer-facing documentation. Admin/install steps live in the module's
[README.md](../modules/addons/vpnhoodsignin/README.md); this explains **how the pieces fit
together, what WHMCS actually does underneath, and how to extend it safely**.

## The problem, precisely

WHMCS's Sign-In Integration is a **client-side** flow. Google Identity Services hands the
browser an ID token; the page posts it to WHMCS:

```
POST /index.php?rp=/auth/provider/google_signin/finalize
     id_token=<JWT>&fail_if_exists=<0|1>&token=<csrf>&cartCheckout=0
```

WHMCS answers JSON with a `result`, and the theme's JavaScript decides what to show:

| `result` | Meaning |
|---|---|
| `logged_in` / `2fa_needed` | Known link → follow `redirect_url` |
| `linking_complete` | A signed-in user linked an account |
| `login_to_link` | **The dead end.** Not linked yet |
| `other_user_exists` | Address belongs to somebody else, or the Google account is linked elsewhere |
| `already_linked` | Already linked to you |

The critical detail: **the login page and the register page both receive `login_to_link`.**
Only the client-side handling differs — the register page pre-fills its email/name inputs,
the login page has none, so it prints *"Link Initiated! …"*. There is no server-side
difference to configure and **no hook anywhere in that path** (WHMCS ships no `SignIn*`,
`OAuth*`, `RemoteAuth*` or `PreRegistration` hook), so the flow cannot be corrected from
inside it.

That is why this module replaces it rather than extending it.

## How this module works instead

```
                    (button seeded with a per-session nonce)
GSI button (ours)  ──▶  Google  ──▶  id_token in the browser
                                            │
                                            ▼
                         POST modules/addons/vpnhoodsignin/api.php
                                            │
                            GoogleToken::verify  (the trust boundary)
                                            │
                              AccountLinker::signIn
                    ┌───────────────────────┼───────────────────────┐
              already linked          email known             email unknown
                    │                       │                       │
                    │                  storeLink +             AddClient +
                    │                  notify owner            storeLink
                    └───────────────────────┼───────────────────────┘
                                            ▼
                              2FA enrolled?  ──yes──▶  refuse, use password
                                            │no
                                   localAPI CreateSsoToken
                                            ▼
                                       client area
```

### The trust boundary — `lib/GoogleToken.php`

The token arrives from the browser, so it is hostile until all of this passes:

1. RS256 signature against Google's published X.509 certs, with `alg` **pinned before any
   cryptography** so `none`/HS256 confusion dies on the first check
2. `exp`/`nbf`/`iat` within clock-skew leeway
3. `iss` is one of Google's two spellings
4. **`aud` is this installation's Client ID** — the check that separates "Google signed it"
   from "it was meant for us". Without it, a token minted for *any* Google app is accepted
5. **`nonce` is the one this session gave the button** — OpenID Connect §3.2.2.11. `aud`
   proves the token was minted for this *site*; `nonce` proves it was minted for *this
   sign-in*. Without it an ID token is a bearer credential for its whole hour: anyone who
   captured one from a visitor's browser could load the login page, mint a session nonce of
   their own, post the stolen token, and be signed in as that visitor
6. non-empty `sub`, syntactically valid `email`
7. `email_verified` — every decision downstream keys off the address

The nonce reaches Google through `google.accounts.id.initialize({nonce: …})` in `hooks.php`,
which is what makes it a *signed* claim rather than another field the poster controls. The
same value is also posted as a plain form field and checked in `api.php` before `verify()`
runs — not for the binding (the signed claim does that) but as a cheap gate, so a stranger's
probe cannot cost us a JWT verification and an outbound fetch of Google's certs.

`$expectedNonce` is a required argument, not an optional one: a future caller that forgets
it must fail loudly rather than quietly run one check fewer.

The certs fetcher is injected through the constructor, which is what lets
`tests/unit/google-token.test.php` drive the whole pipeline against a generated key with no
network. Keep that seam.

### The verification mail — `EmailPreSend`

With `EnableEmailVerification` on, WHMCS mails "Email Address Verification" **from inside
`AddClient`**, before that call has returned the client id. There is therefore no moment at
which the addon could mark the address verified early enough to stop it:
`setEmailVerificationCompleted()` runs immediately afterwards and leaves the account
correctly verified, but the mail has already gone — asking the client to confirm what
Google confirmed seconds earlier, with a link that is spent before they read it.

So `AccountLinker` opens a process-wide window around that single `AddClient` call
(`SignInGate::suppressVerificationEmail()`, cleared in a `finally`), and an `EmailPreSend`
hook returns `['abortsend' => true]` for `messagename === 'Email Address Verification'`
while it is open.

**The messagename test is load-bearing.** `AddClient` sends the welcome mail from inside
the same window, and that one is wanted — aborting on the window alone would swallow it.
Verified on the dev box, where WHMCS records both outcomes in `tblactivitylog`:

```
Email Sending Aborted by Hook (Subject: Email Address Verification)
Email Verification Message Sending Aborted by Hook - UserID: 478
Email Sent to Google Signup (Welcome) - Client ID: 480
```

That log is the only record of either — the verification mail is a user-type message and
never reaches `tblemails`, which is why the integration test asserts against
`tblactivitylog` rather than the mail log.

### Sessions — `CreateSsoToken`

Sign-in is `localAPI('CreateSsoToken', ['client_id' => …, 'destination' =>
'sso:custom_redirect', 'sso_redirect_path' => '/clientarea.php'])`, which returns a
`redirect_url` the browser follows. Verified working with **no admin username**.

`destination` is picky: `sso:custom_redirect` and omitting it both work; the
`clientarea:*` values that appear in older documentation return *"Invalid destination"* on
WHMCS 9.0.

Because this bypasses WHMCS's own login form, **an account with `tblusers.second_factor`
set is refused** and sent to the password form, where WHMCS runs its own challenge. If
`userHasTwoFactor()` cannot read the column it returns `true` — unreadable state must never
silently downgrade someone's security.

### Storage — none of our own

| What | Where | Why |
|---|---|---|
| Account link | `tblauthn_account_links` | WHMCS's own table. Its `(provider, remote_user_id)` unique key is exactly the constraint needed, and keeping links there means enabling the built-in integration later finds them already in place |
| Custom-field answer | `tblcustomfieldsvalues` | via `localAPI('UpdateClient')`, never a direct write |
| Settings | `tbladdonmodules` | standard addon settings |

The module owns no tables and keeps no duplicate state, the same discipline `vpnhoodverify`
applies to `email_verified_at`.

Writing the link uses WHMCS's `AccountLink` model with **per-attribute assignment**, not
`::create()` — the core model declares no `$fillable`, so mass assignment throws
*"Add [provider] to fillable property"*. A Capsule insert is the fallback, and a duplicate
key is treated as success (a concurrent request wrote the row we wanted).

## Where the button goes

The theme puts WHMCS's own provider block in two different places, with different
markup around each, and **both are inside `{if $linkableProviders}`** — so they exist only
while the built-in Sign-In Integration is enabled. This addon replaces that integration,
which means it has to reproduce the surroundings, not just the button.

| Page | Where the theme puts it | What the theme wraps it in |
|---|---|---|
| Login (`includes/login/login.tpl`) | between `form.login-form` and `.login-footer` | a `.login-divider` reading “or” **above** |
| Register (`includes/login/register-form.tpl`) | first child of `#containerNewUserSignup` | a caption **above**, a `.login-divider` reading “or fill the form below” **below** |

Hence the `ButtonPlacements` setting: one `position|selector|style` per line, first match
wins. `position` is one of before/after/prepend/append — “insert before X” alone cannot
express either of the rows above. `style` (`login`, `register`, `plain`) selects which
surrounding markup to draw, using **the theme's own class names** so the divider inherits
the theme's CSS instead of an inline approximation that drifts on the next restyle.

A line with no pipes is read as a bare selector at `before`, which is the format the old
`ButtonSelectors` setting used; that key is still read when `ButtonPlacements` is empty.

Beware `#login` in lagom2: it is the **submit button**, not a container. It was in the old
default list, and matching it put the panel inside the form.

### Button language — `hl`

Google Identity Services does not follow the page or the browser. With no `locale` passed
to `renderButton()`, it labels the button in the language of **whichever Google account
the visitor is signed into** — so an English client area can render a button in another
language entirely. WHMCS's built-in integration avoids this by always sending `hl=en`.

`SignInGate::buttonLocale()` follows the client area instead: the language part of
`$_LANG['locale']` (`en_001` → `en`), overridable with the `ButtonLanguage` setting, and
falling back to `en` for anything unrecognisable — an invalid `hl` makes Google ignore the
parameter, which puts you back to the account-language default.

## Two WHMCS behaviours that will waste your day

### 1. Activation is three lists, not one

WHMCS loads `modules/addons/<name>/hooks.php` only for addons named in the **cached**
`tblconfiguration.AddonModulesHooks` list, which it writes when an admin activates the
module *through the admin UI*.

Adding the module to `ActiveAddonModules` alone is **not enough**, and the failure is
invisible: `Addon::getActiveModules()`, `isActive()` and `isModuleEnabled()` all report the
module as active while its hooks are never loaded, so the button simply never renders.

Full activation touches:

- `tblconfiguration.ActiveAddonModules` — the module is active
- `tblconfiguration.AddonModulesHooks` — **its `hooks.php` is loaded**
- `tblconfiguration.AddonModulesPerms` — it appears in the admin menu for a role
- `tbladdonmodules` — the settings rows, plus `access` and `version`

`tests/integration/signin.test.php` does all four so it is self-sufficient. Humans should
use the admin UI.

### 2. A page has more than one name

`$vars['filename']` is not a reliable page identifier. WHMCS routes some client-area pages
through `index.php`:

| Page | `filename` | `templatefile` |
|---|---|---|
| Login | `index` | `login` |
| Register | `register` | `clientregister` |
| Checkout | `cart` | `viewcart` |

Matching on `filename` alone renders nothing on the **login page** — the one place the
button matters most. `vpnhoodsignin_pageIdentifiers()` collects filename, templatefile and
the script basename, and the configured list is matched against any of them.

Note also that `login.php` **302-redirects** to `clientarea.php`; the real login page is
`index.php?rp=/login`. Test against that URL.

### Bonus: which output hook a theme actually prints

`ClientAreaFooterOutput` only appears where a theme prints `{$footeroutput}`. In `lagom2`
that is solely its default footer layout, which the login page does not use — so footer
output never reaches the login page. `{$headoutput}` is in the global `header.tpl`, so
**`ClientAreaHeadOutput` is the reliable one**. Everything is therefore emitted as a single
script that builds its DOM at runtime, since nothing may be visible markup inside `<head>`.

## Extending

- **Another identity provider (Apple, Facebook):** add a `lib/<Provider>Token.php` with the
  same `verify($token, $audience, $nonce): array` shape, and branch in `api.php`. `AccountLinker` is
  provider-agnostic apart from the `GoogleToken::PROVIDER` constant used as the
  `tblauthn_account_links.provider` value — parameterise that first.
- **A new setting:** add it to `_config()['fields']`, read it in `SignInGate::settings()`,
  and surface its failure mode on the admin page. Every check on that page exists because it
  silently produces "the button does nothing", which an admin cannot diagnose from the front
  end.
- **Never trust the browser** beyond the ID token, and never widen what `verify()` accepts.
- **Fail open** everywhere a hook runs; fail closed everywhere the endpoint runs.

## Testing

| Suite | Needs | Covers |
|---|---|---|
| `tests/unit/run.php` | a `php` binary | The whole token trust boundary, every negative case, against a generated key. No WHMCS, no network |
| `tests/integration/signin.test.sh` | SSH to the dev WHMCS | Create / link / returning sign-in / 2FA refusal / custom field, through real `localAPI`, then deletes what it made |

The unit runner resolves an `openssl.cnf` itself — Windows PHP ships one without pointing
OpenSSL at it, and `putenv('OPENSSL_CONF=…')` is too late by the time the extension loads,
so key generation is threaded through the documented per-call `config` option instead.

There is no linter or build step. `scripts/deploy-dev.sh` runs `php -l` over every deployed
file using the server's PHP and smoke-checks the endpoint, which is the closest thing to CI
this repo has.

## Versioning & releases

`VERSION` at the repo root is the single source of truth; `scripts/set-version.sh` stamps it
into the `'version'` key of `vpnhoodsignin_config()`, and `--check` verifies agreement.
`.github/workflows/release.yml` runs on demand: verify, lint, tag, publish.

**Bump the version for any change to the module's files** — templates, hooks, even a
comment. WHMCS keys its install/upgrade path on the stamped version, so an edit that ships
under an unchanged number is an edit that silently does not reach the install.
