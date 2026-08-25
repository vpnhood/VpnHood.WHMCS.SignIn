// capture-screenshots.js — regenerate the README's WHMCS admin screenshots.
//
// Run it through scripts/capture-screenshots.ps1, which supplies the credentials.
// Output goes to docs/images/.
//
// Two rules this script exists to enforce, because doing them by hand is where
// screenshots leak things:
//
//   1. Nothing sensitive is ever rasterised. The WHMCS "Configure" screen is a single
//      shared form holding EVERY addon's settings, so a naive capture puts unrelated
//      modules' live API keys in frame. Values are overwritten in the DOM *before* the
//      screenshot, not covered by a box afterwards — a box can be cropped back off.
//   2. Every shot is an element screenshot scoped to this addon, never a full page, so
//      whatever else the source install happens to have does not appear.
//
// This script is READ ONLY against the WHMCS it captures: it never activates, disables
// or reconfigures anything. It documents the install as it finds it.
//
// After redacting, a leak check scans what is actually VISIBLE in the target element
// for credential-shaped text and reports anything suspicious. Review the images before
// committing them regardless: the check is a safety net, not a substitute for looking.

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = (process.env.WHMCS_URL || 'https://whmcs-dev.vpnhood.com').replace(/\/+$/, '');
const OUT = process.env.SHOT_DIR || path.join(__dirname, '..', 'docs', 'images');
const USER = process.env.WHMCS_USER;
const PASS = process.env.WHMCS_PASS;

const MODULE = 'vpnhoodsignin';
// The addon's display name, as vpnhoodsignin_config() returns it — this is what the
// Addon Modules list row is found by.
const DISPLAY_NAME = 'VpnHood! Sign-In';

// A Google Client ID is public (it ships inside every page that draws the button), but
// the capture source's own ID still has no business documenting somebody else's
// install: a reader who copies it gets a button that fails on their own origin. Show a
// shaped placeholder instead, so the field reads as "this is where yours goes".
const CLIENT_ID_PLACEHOLDER = '123456789012-a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6.apps.googleusercontent.com';
const CLIENT_ID_SOURCE = '\\b\\d{6,}-[A-Za-z0-9_-]{8,}\\.apps\\.googleusercontent\\.com\\b';

async function redact(page) {
  await page.evaluate(({ mod, placeholder, clientIdSource }) => {
    // 1. Anything belonging to ANOTHER module on the shared Configure form must not be
    //    legible at all. Allow-list by module rather than naming each foreign field: a
    //    module added to the install later would otherwise walk straight into frame.
    document.querySelectorAll('input,textarea').forEach((el) => {
      const name = el.name || '';
      if (!name.includes('fields[')) return;
      if (name.includes('[' + mod + ']')) return;
      el.value = 'REDACTED';
      el.setAttribute('value', 'REDACTED');
      if (el.type === 'password') el.type = 'text'; // show the placeholder, not dots
    });

    // 2. Our own Client ID -> the placeholder, both in the field and anywhere the
    //    admin page prints it back.
    document.querySelectorAll('input,textarea').forEach((el) => {
      if ((el.name || '').includes('[GoogleClientId]')) {
        el.value = placeholder;
        el.setAttribute('value', placeholder);
      }
    });
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const re = new RegExp(clientIdSource, 'g');
      if (re.test(n.nodeValue)) {
        n.nodeValue = n.nodeValue.replace(new RegExp(clientIdSource, 'g'), placeholder);
      }
    }

    // 3. Chrome that would confuse a reader or date the image.
    document.querySelectorAll('.global-admin-warning, .alert-warning').forEach((el) => {
      if (/Hooks Debug Mode/i.test(el.textContent || '')) el.remove();
    });
  }, { mod: MODULE, placeholder: CLIENT_ID_PLACEHOLDER, clientIdSource: CLIENT_ID_SOURCE });
}

async function assertClean(page, selector, label) {
  const leaked = await page.evaluate(({ sel, placeholder }) => {
    const el = document.querySelector(sel);
    if (!el) return ['(element missing)'];
    // Only what is VISIBLE can reach the image. Hidden inputs (CSRF tokens and such)
    // are in the DOM but not in frame, and flagging them buries the real hits.
    const visible = n => n.offsetParent !== null || getComputedStyle(n).position === 'fixed';
    const values = [...el.querySelectorAll('input,textarea')]
      .filter(i => i.type !== 'hidden' && visible(i)).map(i => i.value).join(' ');
    const hay = (el.innerText || '') + ' ' + values;

    const bad = [];
    // Long opaque strings are what a secret looks like. The placeholder's own random
    // half is the one legitimate exception.
    (hay.match(/\b[A-Za-z0-9_+/=-]{24,}\b/g) || [])
      .filter(t => !placeholder.includes(t))
      .filter(t => !/^REDACTED$/i.test(t))
      .forEach(t => bad.push('token:' + t.slice(0, 12) + '…'));
    // Real people. This addon's whole subject is client accounts, so an address in
    // frame is the mistake most worth catching.
    (hay.match(/\b[\w.+-]+@[\w.-]+\.\w{2,}\b/g) || [])
      .filter(a => !/@example\.(com|org|net)$/i.test(a))
      .forEach(a => bad.push('email:' + a));
    if (/whmcs-dev|localhost|127\.0\.0\.1/i.test(hay)) bad.push('non-public host in frame');
    return bad;
  }, { sel: selector, placeholder: CLIENT_ID_PLACEHOLDER });

  if (leaked.length) {
    console.log(`    !! ${label}: REVIEW BEFORE COMMITTING -> ${leaked.join(', ')}`);
    return false;
  }
  console.log(`    ok  ${label}`);
  return true;
}

async function shoot(page, selector, file, label) {
  const el = await page.$(selector);
  if (!el) { console.log(`    -- skipped ${file} (no ${selector})`); return false; }
  const clean = await assertClean(page, selector, label);
  await el.screenshot({ path: path.join(OUT, file) });
  console.log(`    +   ${file} (${Math.round(fs.statSync(path.join(OUT, file)).size / 1024)} KB)`);
  return clean;
}

// WHMCS puts a second password prompt in front of some admin areas. Until it is
// cleared, those pages render only the prompt.
async function passSudo(page) {
  const gated = await page.evaluate(() => /Confirm password to continue/i.test(document.body.innerText));
  if (!gated) return false;
  console.log('    (clearing WHMCS password confirmation)');
  // Submit the form the password field belongs to — the page also carries two search
  // forms, and a generic submit click lands on one of those instead.
  const ok = await page.evaluate((pw) => {
    const box = [...document.querySelectorAll('input[type=password]')].find(i => i.offsetParent !== null);
    if (!box || !box.form) return false;
    box.value = pw;
    box.dispatchEvent(new Event('input', { bubbles: true }));
    const btn = box.form.querySelector('button[type=submit], input[type=submit]');
    if (btn) btn.click(); else box.form.submit();
    return true;
  }, PASS);
  if (!ok) return false;
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
  return true;
}

(async () => {
  if (!USER || !PASS) {
    console.error('WHMCS_USER and WHMCS_PASS must be set — run scripts/capture-screenshots.ps1');
    process.exit(1);
  }
  fs.mkdirSync(OUT, { recursive: true });
  let allClean = true;

  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const ctx = await browser.newContext({
    viewport: { width: 1400, height: 1200 },
    deviceScaleFactor: 2, // crisp on high-DPI screens
  });
  const page = await ctx.newPage();

  await page.goto(`${BASE}/admin/login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="username"]', USER);
  await page.fill('input[name="password"]', PASS);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  if (/login\.php/.test(page.url())) { console.error('login failed'); process.exit(1); }
  console.log(`logged in to ${BASE}\n`);

  // ---- 1. Addon Modules: the addon's row
  console.log('1. Addon Modules list');
  await page.goto(`${BASE}/admin/configaddonmods.php`, { waitUntil: 'networkidle' });
  if (await passSudo(page)) {
    await page.goto(`${BASE}/admin/configaddonmods.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
  }
  await redact(page);
  const rowFound = await page.evaluate((name) => {
    const tr = [...document.querySelectorAll('tr')].find(t => (t.innerText || '').includes(name));
    if (!tr) return false;
    tr.id = 'shot-row';
    tr.scrollIntoView({ block: 'center' });
    return true;
  }, DISPLAY_NAME);
  if (!rowFound) console.log(`    -- ${DISPLAY_NAME} is not installed on this WHMCS`);
  await page.waitForTimeout(400);
  allClean &= await shoot(page, '#shot-row', '01-addon-modules-row.png', 'addon row');

  // ---- 2. The addon's settings
  //
  // Scoped to the panel holding this addon's own fields. The Configure screen is one
  // form for every module on the install, so an unscoped shot would publish the rest.
  console.log('2. Configure form');
  await page.click(`#${MODULE}_configure`);
  await page.waitForTimeout(2500);
  await redact(page);
  await page.evaluate((mod) => {
    const ours = [...document.querySelectorAll('input,textarea,select')]
      .filter(el => (el.name || '').includes('[' + mod + ']'));
    if (!ours.length) return;
    // Climb until the container holds ALL of this module's fields, then keep climbing
    // for as long as the frame stays ours alone — that picks up the panel heading and
    // the Save button, and stops the moment another module's field would enter frame.
    const foreign = el => [...el.querySelectorAll('input,textarea,select')]
      .some(f => (f.name || '').includes('fields[') && !(f.name || '').includes('[' + mod + ']'));
    let el = ours[0];
    while (el.parentElement && !ours.every(f => el.contains(f))) el = el.parentElement;
    while (el.parentElement && el.parentElement !== document.body && !foreign(el.parentElement)) {
      el = el.parentElement;
    }
    el.id = 'shot-config';
    el.scrollIntoView({ block: 'center' });
  }, MODULE);
  await page.waitForTimeout(400);
  allClean &= await shoot(page, '#shot-config', '02-configure-settings.png', 'configure form');

  // ---- 3. The addon's own admin page
  //
  // Narrower than the rest: the status tables are width:auto, so a 1400px frame is
  // mostly empty page. The prose paragraphs reflow, nothing is cut off.
  console.log('3. Admin status page');
  await page.setViewportSize({ width: 1100, height: 1200 });
  await page.goto(`${BASE}/admin/addonmodules.php?module=${MODULE}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  if (await passSudo(page)) {
    await page.goto(`${BASE}/admin/addonmodules.php?module=${MODULE}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
  }
  await redact(page);
  await page.evaluate(() => {
    const h = [...document.querySelectorAll('h3')].find(x => /^\s*Status\s*$/i.test(x.textContent || ''));
    const el = (h && h.parentElement) || document.querySelector('#contentarea') || document.body;
    el.id = 'shot-status';
    el.scrollIntoView({ block: 'start' });
  });
  await page.waitForTimeout(400);
  allClean &= await shoot(page, '#shot-status', '03-admin-status-page.png', 'status page');

  await browser.close();
  console.log(`\nwrote to ${OUT}`);
  console.log(allClean
    ? 'No credential-shaped text found in any frame — still eyeball them before committing.'
    : 'SOMETHING WAS FLAGGED ABOVE. Do not commit until you have looked at those images.');
})().catch(e => { console.error('ERROR', e.message); process.exit(1); });
