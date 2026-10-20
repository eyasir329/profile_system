// Responsive audit: opens every page the app links to, for each role, at
// nine screen sizes (320 px phone to 1920 px desktop, plus a phone in
// landscape; phones as real touch devices), and reports:
//   - pages that scroll sideways, and the elements past the screen edge
//   - text cut off without an ellipsis
//   - tap targets smaller than 24 x 24 px on phones (WCAG 2.5.8)
//   - JavaScript errors
// Only GET requests: it follows links, never submits a form, and skips
// log out, exports and the role switch.
//
// Needs Node and Playwright (npm i -g playwright && npx playwright install
// chromium). Accounts come from the environment, never from this file;
// leave a role's variable unset to skip it:
//
//   BASE_URL=http://127.0.0.1:8080 AUDIT_PASSWORD='...' \
//   AUDIT_STUDENT=student@example.test AUDIT_TEACHER=teacher@example.test \
//   AUDIT_ADMIN=admin@example.test \
//   node tools/responsive-audit.js /tmp/rwd [shots]
//
// Writes <outdir>/report.json (and screenshots with "shots") and prints a
// summary. Exits 1 if any page scrolls sideways.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = process.argv[2] || './out';
const SHOTS = process.argv[3] === 'shots';
const PW = process.env.AUDIT_PASSWORD || '';
const VIEWPORTS = [
  [320, 568], [360, 740], [390, 844], [430, 932], [667, 375],
  [768, 1024], [1024, 768], [1280, 800], [1920, 1080],
];
const SHOT_WIDTHS = new Set([320, 390, 768, 1024, 1280]);
const ROLES = { anon: null };
for (const r of ['student', 'teacher', 'admin']) {
  const email = process.env['AUDIT_' + r.toUpperCase()];
  if (email && PW) ROLES[r] = email;
}
const SEED = {
  anon: ['/', '/login.php', '/forgot_password.php', '/reset_password.php?token=bad', '/register.php',
         '/register.php?invite=bad', '/about.php', '/help.php', '/privacy.php', '/verify_email.php?token=bad',
         '/restore_account.php', '/no-such-page'],
  student: ['/dashboard.php', '/profile.php', '/settings.php', '/resources.php', '/about.php', '/help.php', '/privacy.php', '/no-such-page'],
  teacher: ['/dashboard.php', '/students.php', '/invites.php', '/resources.php', '/settings.php', '/profile.php'],
  admin: ['/dashboard.php', '/admin/', '/admin/activity.php', '/admin/emails.php', '/admin/settings.php', '/invites.php', '/resources.php', '/settings.php'],
};
const SKIP = /logout|export=|switch_role|download|\.csv|mailto:|tel:|\/assets\//i;
const MAX_PER_ROLE = 45;

// Signature: path + query keys, with the values of keys that pick a view.
function sig(u) {
  const url = new URL(u, BASE);
  const keep = ['view', 'tab', 'status', 'role', 'group', 'area'];
  const parts = [...url.searchParams.keys()].sort().map(k => keep.includes(k) ? `${k}=${url.searchParams.get(k)}` : k);
  return url.pathname + (parts.length ? '?' + parts.join('&') : '');
}

async function checks(page, w) {
  return page.evaluate((w) => {
    const vw = document.documentElement.clientWidth;
    const res = { docOverflow: document.documentElement.scrollWidth - vw, offenders: [], smallTargets: [], clipped: [] };
    const label = (el) => {
      let s = el.tagName.toLowerCase();
      if (el.id) s += '#' + el.id;
      if (el.className && typeof el.className === 'string') s += '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.');
      const t = (el.innerText || el.value || el.getAttribute('aria-label') || '').trim().slice(0, 30);
      return t ? `${s} "${t}"` : s;
    };
    const visible = (el) => {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    };
    const clippedByAncestor = (el) => {
      for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        const cs = getComputedStyle(p);
        if (/(auto|scroll|hidden|clip)/.test(cs.overflowX)) return true;
        if (cs.position === 'fixed') return true;
      }
      return false;
    };
    const all = document.body.querySelectorAll('*');
    for (const el of all) {
      if (!visible(el)) continue;
      if (el.closest('dialog:not([open]), [aria-hidden="true"], .sr-only, .skip-link, template')) continue;
      // Visually hidden on purpose (e.g. max-sm:sr-only): 1 px boxes, not content.
      if (el.getBoundingClientRect().width <= 1 && getComputedStyle(el).position === 'absolute') continue;
      const r = el.getBoundingClientRect();
      if ((r.right > vw + 1 || r.left < -1) && !clippedByAncestor(el) && getComputedStyle(el).position !== 'fixed') {
        res.offenders.push(`${label(el)} [${Math.round(r.left)}..${Math.round(r.right)}]`);
      }
      // Text that is cut off (no ellipsis) inside its own box.
      const cs = getComputedStyle(el);
      if (/(hidden|clip)/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 2 && cs.textOverflow !== 'ellipsis'
          && el.children.length === 0 && (el.innerText || '').trim()) {
        res.clipped.push(label(el));
      }
    }
    if (w < 768) {
      for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button], summary')) {
        if (!visible(el) || el.closest('.sr-only, .skip-link, dialog:not([open])')) continue;
        const r = el.getBoundingClientRect();
        // Inline links inside running text are exempt (WCAG 2.5.8).
        const inline = el.tagName === 'A' && getComputedStyle(el).display === 'inline' && el.closest('p, li, dd, td, figcaption');
        if (!inline && (r.height < 24 || r.width < 24)) res.smallTargets.push(`${label(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
      }
    }
    res.offenders = [...new Set(res.offenders)].slice(0, 8);
    res.smallTargets = [...new Set(res.smallTargets)].slice(0, 8);
    res.clipped = [...new Set(res.clipped)].slice(0, 6);
    return res;
  }, w);
}

async function login(page, email) {
  await page.goto(BASE + '/login.php');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', PW);
  await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
  if (!/dashboard/.test(page.url())) throw new Error('login failed for ' + email + ' -> ' + page.url());
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
  const report = [];
  const errors = [];
  for (const [role, email] of Object.entries(ROLES)) {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await ctx.newPage();
    // Phones and small tablets: a real touch device (coarse pointer, mobile viewport).
    const mctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const mpage = await mctx.newPage();
    mpage.on('pageerror', (e) => errors.push(`[${role}/m] ${mpage.url()} JS: ${e.message}`));
    page.on('pageerror', (e) => errors.push(`[${role}] ${page.url()} JS: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(`[${role}] ${page.url()} console: ${m.text()}`); });
    if (email) { await login(page, email); await login(mpage, email); }

    // Crawl.
    const queue = [...SEED[role]];
    const seen = new Map();
    while (queue.length && seen.size < MAX_PER_ROLE) {
      const u = queue.shift();
      const s = sig(u);
      if (seen.has(s)) continue;
      const resp = await page.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => null);
      if (!resp) continue;
      const final = new URL(page.url());
      if (final.origin !== BASE) continue;
      seen.set(s, u);
      const links = await page.$$eval('a[href]', as => as.map(a => a.getAttribute('href')));
      for (const h of links) {
        if (!h || h.startsWith('#') || SKIP.test(h)) continue;
        let abs; try { abs = new URL(h, page.url()); } catch { continue; }
        if (abs.origin !== BASE) continue;
        const rel = abs.pathname + abs.search;
        if (!seen.has(sig(rel))) queue.push(rel);
      }
    }

    for (const [s, u] of seen) {
      for (const [w, h] of VIEWPORTS) {
        const pg = w < 768 ? mpage : page;
        await pg.setViewportSize({ width: w, height: h });
        const resp = await pg.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => null);
        const status = resp ? resp.status() : 0;
        await pg.waitForTimeout(150);
        const c = await checks(pg, w);
        report.push({ role, url: u, sig: s, w, h, status, ...c });
        if (SHOTS && SHOT_WIDTHS.has(w)) {
          const name = `${role}_${s.replace(/[^a-z0-9]+/gi, '_')}_${w}.png`;
          await pg.screenshot({ path: path.join(OUT, name), fullPage: true });
        }
      }
    }
    await ctx.close(); await mctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify({ report, errors }, null, 1));

  // Summary.
  const bad = report.filter(r => r.docOverflow > 0 || r.offenders.length || r.clipped.length);
  console.log(`checked ${report.length} page-views; ${bad.length} with overflow/clipping; ${errors.length} JS errors`);
  const bySig = {};
  for (const r of report) {
    const k = `${r.role} ${r.sig}`;
    bySig[k] = bySig[k] || { overflowW: [], small: new Set(), off: new Set(), clip: new Set(), status: r.status };
    if (r.docOverflow > 0 || r.offenders.length) bySig[k].overflowW.push(`${r.w}(+${r.docOverflow})`);
    r.offenders.forEach(o => bySig[k].off.add(o.replace(/\[.*\]/, '')));
    r.smallTargets.forEach(o => bySig[k].small.add(o.replace(/ \d+x\d+$/, '')));
    r.clipped.forEach(o => bySig[k].clip.add(o));
  }
  for (const [k, v] of Object.entries(bySig)) {
    if (!v.overflowW.length && !v.small.size && !v.clip.size) continue;
    console.log(`\n## ${k} [${v.status}]`);
    if (v.overflowW.length) console.log('  overflow at: ' + v.overflowW.join(' ') + '\n    ' + [...v.off].slice(0, 6).join('\n    '));
    if (v.clip.size) console.log('  clipped: ' + [...v.clip].slice(0, 4).join(' | '));
    if (v.small.size) console.log('  small targets: ' + [...v.small].slice(0, 6).join(' | '));
  }
  if (errors.length) console.log('\nJS errors:\n' + [...new Set(errors)].slice(0, 20).join('\n'));
  if (report.some(r => r.docOverflow > 0)) process.exitCode = 1;
})().catch(e => { console.error(e); process.exit(1); });
