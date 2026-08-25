# VpnHood! Sign-In

Makes **"Sign in with Google" actually sign people in** — including people who have never
had an account.

## Why this exists

WHMCS ships a Sign-In Integration for Google, but it never registers anybody. When someone
new signs in with Google it stops with:

> **Link Initiated!** Please complete sign in to associate this service with your existing
> account. You will only have to do this once.

…which asks a first-time visitor to sign in with a password they have never had. The only
way forward is to find the Register page, click Google *again*, and fill in the form
anyway — including any required custom fields. Most people leave instead.

The dead end is not a setting; it is the whole design. Both the login page and the register
page get the same `login_to_link` answer from WHMCS's endpoint, and only the client-side
JavaScript differs: the register page has an email field to pre-fill, the login page has
nothing, so it shows the message above.

This addon replaces that flow entirely:

| Google returns | WHMCS today | With this addon |
|---|---|---|
| An address WHMCS has never seen | "Link Initiated!" dead end | Account created, signed in |
| An address that already has an account | "Link Initiated!" dead end | Linked, owner notified, signed in |
| An already-linked Google account | Signed in | Signed in |
| An account with two-factor enabled | 2FA challenge | **Refused** — told to use their password |

## How it works

1. The addon renders its own Google button on the login, register and checkout pages,
   seeded with a nonce minted for that visitor's session.
2. Google hands the browser an **ID token** (a signed JWT) with that nonce inside it.
3. The browser posts it to `modules/addons/vpnhoodsignin/api.php`.
4. The addon verifies that token itself — RS256 signature against Google's published certs,
   pinned issuer, `exp`/`nbf`, `email_verified`, that the **audience is this site's own
   Client ID**, and that the **nonce is this session's**. A token signed by Google but minted
   for a different app, or for a different sign-in, is refused.
5. It creates or links the account, then mints the session with WHMCS's own
   `CreateSsoToken`.
6. The new client gets the normal **welcome** mail, but **not** the "Email Address
   Verification" one — Google already proved the mailbox, and the account is marked
   verified on the spot.

The browser is never trusted with anything except that token, and the token is only
believed once every check above passes.

## Install

1. Copy `modules/addons/vpnhoodsignin/` into your WHMCS `modules/addons/` directory.
2. **Activate it from the admin UI** — *Configuration → Addon Modules → VpnHood! Sign-In →
   Activate*, then grant your admin role access.
   > Activate through the UI, not by editing the database. WHMCS keeps a **separate,
   > cached list** (`tblconfiguration.AddonModulesHooks`) of addons whose `hooks.php` it
   > loads. Adding the module to `ActiveAddonModules` alone makes every WHMCS API report
   > the addon as active while its hooks are still never loaded — the button simply never
   > appears, and nothing anywhere says why.
3. Set up a Google OAuth Client ID (below) and paste it into the settings.
4. Set **Enable Google Sign-In** to *Yes*.
5. **Turn OFF the built-in integration** at *Configuration → System Settings → Sign-In
   Integrations → Google*. Leaving it on draws two Google buttons, one of which still dead-
   ends. The addon's admin page warns you when it detects this.

### Google Cloud Console

Create an **OAuth 2.0 Client ID** of type *Web application*, and add your WHMCS URL to
**Authorised JavaScript origins** (e.g. `https://whmcs.example.com`). Google Identity
Services checks the origin, so a missing entry makes the button render and then fail
silently on click.

You need the **Client ID only**. No client secret: this flow receives an ID token directly
and never exchanges an authorisation code.

## Settings

| Setting | Meaning |
|---|---|
| **Enable Google Sign-In** | Master switch. Off leaves the addon installed but inert — no button, and the endpoint answers 404. |
| **Google Client ID** | From Google Cloud Console. Also the expected token audience — the check that separates "Google signed this" from "this was meant for us". |
| **Button Placement** | One `position\|selector\|style` per line, tried in order. Defaults put the button exactly where the theme puts WHMCS's own provider block — after the login form, first inside the signup section — and draw the divider and label the theme takes away with that block. |
| **Button Language** | Language code for the button itself. Blank follows the client area. Worth setting: Google ignores the page and defaults to the visitor's *Google account* language, so an English page can render a button in another language. |
| **Show Button On** | Page identifiers that get the button. Matched against the page *filename* **and** its *template name*, because WHMCS routes some pages through `index.php` (the login page reports filename `index`, template `login`). |
| **Button Mount Points** | CSS selectors, one per line. The button is inserted before the first match. An escape hatch for a theme change that needs no deploy. |
| **When The Email Already Exists** | `Link and notify` (default), `Link silently`, or `Refuse`. |
| **Custom Field To Fill** | Name of a required client custom field the registration form would have asked for. Resolved by name at runtime, never by id. |
| **How To Fill It** | `Use default value`, `Ask once after signup`, or `Leave empty`. |
| **Default Value** | Used by *Use default value*. Must be one of the field's own dropdown options. |
| **Country Override** | Two-letter ISO code for new clients. Blank uses Cloudflare's visitor country, then the WHMCS default country. |
| **New-Client Cutoff** | Stamped at activation. Only clients created on or after it can be held by *Ask once after signup*. |

## The required custom field

Signing up with Google skips the registration form, which is where a required custom field
like *"How did you hear about us?"* would have been asked. WHMCS's `required` flag is
enforced only by the front-end forms — the `AddClient` API path ignores it — so the account
is created either way. This addon decides what happens to the field:

- **Use default value** — writes your chosen option at signup. Zero friction.
- **Ask once after signup** — the new client lands on a one-question page and answers it
  once. Keeps the data honest.
- **Leave empty** — neither.

The field id is looked up **by name on every run**. Ids differ between installs, and a
hardcoded one silently disables the feature on any install where it does not match.

## Security notes

- **Two-factor accounts are never signed in this way.** Signing in mints a session
  directly, which would quietly cancel a second factor the client deliberately enabled, so
  those accounts are told to use their password and WHMCS runs its own challenge.
- **Linking an existing address is safe** because the token carries a Google-*verified*
  email — the same proof of mailbox ownership a password reset requires. With
  *Link and notify* the owner is emailed when it happens.
- **A per-session nonce is bound into the token itself.** It is handed to Google when the
  button is initialised, so it comes back as a signed claim that whoever posts the token
  cannot choose. That is what makes a captured ID token useless to anyone else: without it,
  a token lifted from a visitor's browser is a bearer credential for its full hour of life,
  replayable by an attacker who just loads the login page to mint a nonce of their own.
  The same value is posted as a form field, checked first as a cheap gate before any
  cryptography, and it blocks login-CSRF (a stranger silently signing a visitor into an
  account of the stranger's choosing).
- **A Google signup is never asked to verify its email.** WHMCS sends that mail from
  inside `AddClient`, before it returns — so no amount of marking the address verified
  afterwards can call it back. An `EmailPreSend` hook drops it for the one signup this
  addon is in the middle of, and the account is marked verified instead. Normal
  registrations, admin resends and address changes are untouched, and the welcome mail
  still goes out. If you see the verification mail after a Google signup, check that the
  addon is **active** — WHMCS only loads an active addon's `hooks.php`.
- **Visitors get generic messages; admins get the real reason** in *Utilities → Logs →
  Module Log* under `vpnhoodsignin`. Someone probing the endpoint learns nothing about
  which addresses are registered.
- **New accounts get a random password they are never told.** This is what native WHMCS
  does too — its own flow hides the password fields and fills them with
  `WHMCS.utils.simpleRNG()` — except this addon uses a CSPRNG. The only ways into such an
  account remain Google and password-reset, and reset proves the same mailbox ownership
  Google just proved.

## If something goes wrong

1. **Set *Enable Google Sign-In* to No**, or **deactivate the addon** — that stops the
   hooks loading at all and restores the stock login page.
2. Check *Utilities → Logs → Module Log* for `vpnhoodsignin`; every refusal is recorded
   there with its real cause.
3. **No button at all?** Almost always one of: the addon was activated by database edit
   rather than through the admin UI (see Install), the Client ID is blank, the current page
   is not in *Show Button On*, or the theme matches none of the *Button Mount Points*.
4. **Button appears but nothing happens on click?** Usually the site's origin is missing
   from *Authorised JavaScript origins* in Google Cloud Console.

Everything fails open: a hook that cannot read its own state renders no button and bars no
door.

## Files

| File | Purpose |
|---|---|
| `vpnhoodsignin.php` | Settings, activation, admin status page, the one-question page |
| `hooks.php` | The button (`ClientAreaHeadOutput`) and the ask-once gate (`ClientAreaPage`) |
| `api.php` | The endpoint the button posts to; answers 404 while inactive |
| `lib/GoogleToken.php` | The trust boundary: what makes a token believable |
| `lib/Jwt.php` | RS256 verification primitives (no Composer) |
| `lib/AccountLinker.php` | Create / link / sign in |
| `lib/SignInGate.php` | Settings, custom-field resolution, country fallback, gate scope |
| `lib/Http.php` | Minimal cURL wrapper (one outbound call: Google's certs) |
| `templates/how-did-you-hear.tpl` | The one-question page |

It owns **no database tables**. Account links are stored in WHMCS's own
`tblauthn_account_links`, so enabling the built-in integration later finds them already in
place.
