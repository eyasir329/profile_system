/*
 * Shared UI behaviour, registered on Alpine.
 *
 * Stores
 *   $store.toasts    the toast stack            (rendered in footer.php)
 *   $store.confirm   the one confirm dialog     (rendered in footer.php)
 *
 * Directives (no expression; options come from data-* attributes)
 *   x-confirm        on a <form>: ask before submitting
 *   x-copy           on a <button data-copy="...">: copy to the clipboard
 *   x-autosubmit     on a filter <form>: submit when a <select> changes
 *
 * Components (x-data="name")
 *   dropdown         button + menu with keyboard support
 *   dirtyForm        "You have unsaved changes" + leave-page warning
 *   themePicker      Settings > Appearance
 *   passwordForm     Settings > Security: live length / match feedback
 *   sheet            the full-screen mode of a wide sheet, and its frozen
 *                    columns (.freeze-1..4: left offsets measured, not guessed)
 *   sheetFilter      a sheet's instant filter, "Only mine" and sortable headers
 *   emailComposer    Admin › Emails: people picker, recipient count, live preview
 *   tzPicker         Admin › Site settings: time zone preview, "use my computer's zone"
 *
 * Directive x-chart (on a .chart figure): hover / focus tooltip for data-tip marks
 */

let AlpineRef = null;
let nextToastId = 1;

/**
 * Show a toast.
 *   toast('Saved.', { type: 'success' })
 *   toast({ type: 'error', title: 'Upload failed', body: '...' })
 * Options: type 'success' | 'error' | 'warn' | 'info', duration in ms
 * (0 keeps it until dismissed), actions [{ label, onClick }].
 * Returns a function that dismisses the toast.
 */
export function toast(message, opts) {
  if (message && typeof message === 'object') {
    opts = message;
    message = opts.body || '';
  }
  opts = opts || {};
  const store = AlpineRef.store('toasts');
  const id = nextToastId++;
  store.items.push({
    id,
    type: opts.type || 'info',
    title: opts.title || '',
    body: message || '',
    actions: opts.actions || [],
    duration: typeof opts.duration === 'number' ? opts.duration : 4500,
  });
  // Keep the stack short: the oldest toast makes room.
  while (store.items.length > 4) store.dismiss(store.items[0].id);
  store.arm(id);
  return () => store.dismiss(id);
}

/**
 * Ask for confirmation in the shared dialog. Resolves true / false.
 *   confirm({ title, body, okText, cancelText, tone: 'danger' | 'primary' })
 */
export function confirm(opts) {
  return AlpineRef.store('confirm').ask(opts || {});
}

export function registerUi(Alpine) {
  AlpineRef = Alpine;

  // ------------------------------------------------------------- toasts
  const timers = new Map();
  Alpine.store('toasts', {
    items: [],
    arm(id) {
      const t = this.items.find((x) => x.id === id);
      if (!t || !t.duration) return;
      clearTimeout(timers.get(id));
      timers.set(id, setTimeout(() => this.dismiss(id), t.duration));
    },
    // Reading a toast (hover or focus) stops its clock.
    hold(id) {
      clearTimeout(timers.get(id));
    },
    dismiss(id) {
      clearTimeout(timers.get(id));
      timers.delete(id);
      this.items = this.items.filter((x) => x.id !== id);
    },
    run(id, action) {
      if (action && action.onClick) action.onClick();
      this.dismiss(id);
    },
  });

  // ------------------------------------------------------------ confirm
  let settle = null;
  Alpine.store('confirm', {
    open: false,
    title: '',
    body: '',
    okText: 'Confirm',
    cancelText: 'Cancel',
    tone: 'danger',
    ask(opts) {
      if (settle) settle(false);
      this.title = opts.title || 'Are you sure?';
      this.body = opts.body || '';
      this.okText = opts.okText || 'Confirm';
      this.cancelText = opts.cancelText || 'Cancel';
      this.tone = opts.tone || 'danger';
      this.open = true;
      return new Promise((resolve) => {
        settle = resolve;
      });
    },
    answer(value) {
      this.open = false;
      if (settle) {
        const resolve = settle;
        settle = null;
        resolve(value);
      }
    },
  });

  // The <dialog> itself. Native showModal() gives the focus trap, Esc and
  // focus restore for free.
  Alpine.data('confirmDialog', () => ({
    init() {
      this.$watch('$store.confirm.open', (open) => {
        if (open && !this.$el.open) {
          this.$el.showModal();
          // Destructive dialogs start on Cancel so Enter cannot delete by reflex.
          const first = this.$el.querySelector(this.$store.confirm.tone === 'danger' ? '[data-cancel]' : '[data-ok]');
          if (first) first.focus();
        } else if (!open && this.$el.open) {
          this.$el.close();
        }
      });
    },
  }));

  // ---------------------------------------------------------- directives
  Alpine.directive('confirm', (el, _, { cleanup }) => {
    let approved = false;
    const onSubmit = (e) => {
      if (approved) return;
      e.preventDefault();
      confirm({
        title: el.dataset.confirmTitle,
        body: el.dataset.confirmBody || 'This cannot be undone.',
        okText: el.dataset.confirmOk,
        cancelText: el.dataset.confirmCancel,
        tone: el.dataset.confirmTone,
      }).then((ok) => {
        if (!ok) return;
        approved = true;
        // requestSubmit keeps the submitter-less form valid and re-fires
        // this handler, which now lets it through.
        el.requestSubmit();
      });
    };
    el.addEventListener('submit', onSubmit);
    cleanup(() => el.removeEventListener('submit', onSubmit));
  });

  Alpine.directive('copy', (el, _, { cleanup }) => {
    const onClick = async () => {
      try {
        await navigator.clipboard.writeText(el.dataset.copy || '');
        toast('Copied to clipboard.', { type: 'success', duration: 2500 });
      } catch (e) {
        toast('Could not copy. Select the text and copy it by hand.', { type: 'error' });
      }
    };
    el.addEventListener('click', onClick);
    cleanup(() => el.removeEventListener('click', onClick));
  });

  Alpine.directive('autosubmit', (el, _, { cleanup }) => {
    const onChange = (e) => {
      if (e.target.tagName === 'SELECT') el.submit();
    };
    el.addEventListener('change', onChange);
    cleanup(() => el.removeEventListener('change', onChange));
  });

  // ---------------------------------------------------------- components
  Alpine.data('dropdown', () => ({
    open: false,
    items() {
      return Array.from(this.$refs.menu.querySelectorAll('[role^="menuitem"]'));
    },
    toggle() {
      this.open ? this.close() : this.show();
    },
    show() {
      this.open = true;
      this.$nextTick(() => {
        const items = this.items();
        (items.find((i) => i.getAttribute('aria-checked') === 'true') || items[0])?.focus();
      });
    },
    close(returnFocus) {
      if (!this.open) return;
      this.open = false;
      if (returnFocus) this.$refs.trigger.focus();
    },
    move(step) {
      const items = this.items();
      if (!items.length) return;
      const i = items.indexOf(document.activeElement);
      items[(i + step + items.length) % items.length].focus();
    },
    // Tabbing out of the menu closes it.
    focusLeft(e) {
      if (e.relatedTarget && !this.$el.contains(e.relatedTarget)) this.close();
    },
  }));

  Alpine.data('dirtyForm', () => ({
    dirty: false,
    init() {
      const mark = () => {
        this.dirty = true;
      };
      this.$el.addEventListener('input', mark);
      this.$el.addEventListener('change', mark);
      this.$el.addEventListener('submit', () => {
        this.dirty = false;
      });
      window.addEventListener('beforeunload', (e) => {
        if (this.dirty) {
          e.preventDefault();
          e.returnValue = '';
        }
      });
    },
  }));

  Alpine.data('themePicker', () => ({
    // Picking a theme applies it at once and saves it; no Save button needed.
    pick(e) {
      if (e.target.name !== 'theme') return;
      applyTheme(e.target.value);
      this.$el.submit();
    },
  }));

  Alpine.data('passwordForm', () => ({
    password: '',
    confirmation: '',
    get lengthHint() {
      const n = this.password.length;
      if (n === 0) return 'At least 8 characters.';
      if (n < 8) return 8 - n + ' more character' + (8 - n === 1 ? '' : 's') + ' needed.';
      return 'Long enough.';
    },
    get mismatch() {
      return this.confirmation !== '' && this.confirmation !== this.password;
    },
    get matchHint() {
      return this.mismatch ? 'The two passwords don’t match yet.' : 'Passwords match.';
    },
    init() {
      this.$watch('mismatch', (bad) => {
        // Looked up by attribute: the input sits inside its own password-field
        // component, so an x-ref would belong to that one, not to this form.
        const input = this.$root.querySelector('[data-confirm-input]');
        if (input) input.setCustomValidity(bad ? 'The two passwords don’t match.' : '');
      });
    },
  }));

  Alpine.data('sheet', () => ({
    full: false,
    init() {
      // Sorting and filtering reload the page; stay full-screen across that.
      try {
        this.full = sessionStorage.getItem('ps_sheet_full') === '1';
      } catch (e) {
        /* storage blocked: start windowed */
      }
      this.sync();
      // Frozen panes: each frozen column starts where the previous one ends.
      // Measured from the real header cells (table cells grow to fit their
      // content, so fixed offsets would drift).
      const measure = () => this.freeze();
      this.$nextTick(measure);
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(measure);
      window.addEventListener('resize', measure);
    },
    freeze() {
      this.$root.querySelectorAll('table.sheet').forEach((table) => {
        let left = 0;
        table.querySelectorAll('thead .freeze').forEach((th, i) => {
          // Rounded down: columns overlap by under a pixel instead of
          // leaving a sub-pixel gap that scrolled text shows through.
          table.style.setProperty('--fz' + (i + 1), Math.floor(left) + 'px');
          left += th.getBoundingClientRect().width;
        });
      });
    },
    set(on) {
      this.full = on;
      try {
        on ? sessionStorage.setItem('ps_sheet_full', '1') : sessionStorage.removeItem('ps_sheet_full');
      } catch (e) {
        /* ignore */
      }
      this.sync();
      this.$nextTick(() => {
        this.freeze();
        (on ? this.$refs.scroll : this.$refs.enter)?.focus({ preventScroll: true });
      });
    },
    sync() {
      document.documentElement.classList.toggle('has-sheet-full', this.full);
    },
  }));

  // A sheet's tools, in the browser only (no reload): filter rows by any
  // text, show only the viewer's rows (tr.is-mine), and sort by a column.
  // Markup: x-data="sheetFilter" around the toolbar and the table; header
  // buttons call sort(<column index>) and show arrow(<index>).
  Alpine.data('sheetFilter', () => ({
    q: '',
    mine: false,
    shown: 0,
    total: 0,
    sortCol: -1,
    sortDir: 1,
    init() {
      this.body = this.$root.querySelector('table.sheet tbody');
      this.rows = this.body ? Array.from(this.body.rows) : [];
      this.total = this.rows.length;
      // What a row says, without screen-reader-only "Not set" or button labels.
      this.texts = this.rows.map((r) => {
        const c = r.cloneNode(true);
        c.querySelectorAll('.sr-only, form, button, a.btn').forEach((x) => x.remove());
        return c.textContent.toLowerCase().replace(/\s+/g, ' ');
      });
      this.apply();
      this.$watch('q', () => this.apply());
      this.$watch('mine', () => this.apply());
    },
    apply() {
      const words = this.q.toLowerCase().split(/\s+/).filter(Boolean);
      let n = 0;
      this.rows.forEach((r, i) => {
        const ok = words.every((w) => this.texts[i].includes(w)) && (!this.mine || r.classList.contains('is-mine'));
        r.hidden = !ok;
        if (ok) n++;
      });
      this.shown = n;
    },
    cellValue(r, col) {
      const c = r.cells[col];
      if (!c) return '';
      const x = c.cloneNode(true);
      x.querySelectorAll('.sr-only, form, button').forEach((el) => el.remove());
      return x.textContent.trim().replace(/\s+/g, ' ');
    },
    sort(col) {
      this.sortDir = this.sortCol === col ? -this.sortDir : 1;
      this.sortCol = col;
      const isNum = (v) => /^-?\d+(\.\d+)?$/.test(v);
      const sorted = this.rows.slice().sort((ra, rb) => {
        const a = this.cellValue(ra, col);
        const b = this.cellValue(rb, col);
        if (a === '' || b === '') return a === b ? 0 : a === '' ? 1 : -1; // blanks always last
        const d = isNum(a) && isNum(b) ? parseFloat(a) - parseFloat(b) : a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
        return d * this.sortDir;
      });
      sorted.forEach((r) => this.body.appendChild(r));
    },
    arrow(col) {
      return this.sortCol === col ? (this.sortDir > 0 ? '↑' : '↓') : '↕';
    },
    ariaSort(col) {
      return this.sortCol === col ? (this.sortDir > 0 ? 'ascending' : 'descending') : 'none';
    },
  }));

  // x-chart on a .chart figure (src/includes/charts.php): a tooltip for any
  // mark with data-tip='{"title","total","rows":[[label, value, cssVar]]}',
  // on hover and on keyboard focus. Built with textContent, never innerHTML:
  // labels are data. The value leads; a short line keys each series.
  Alpine.directive('chart', (el, _, { cleanup }) => {
    let tip = document.querySelector('.chart-tip');
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'chart-tip';
      tip.setAttribute('role', 'tooltip');
      tip.hidden = true;
      document.body.appendChild(tip);
    }
    const show = (mark) => {
      let data;
      try {
        data = JSON.parse(mark.dataset.tip);
      } catch (e) {
        return;
      }
      tip.replaceChildren();
      const title = document.createElement('div');
      title.className = 'chart-tip-title';
      title.textContent = data.title;
      const total = document.createElement('div');
      total.className = 'chart-tip-total';
      total.textContent = data.total;
      tip.append(title, total);
      (data.rows || []).forEach(([label, value, cssVar]) => {
        const row = document.createElement('div');
        row.className = 'chart-tip-row';
        const key = document.createElement('span');
        key.className = 'chart-tip-key';
        key.style.background = 'var(' + cssVar + ')';
        const v = document.createElement('b');
        v.textContent = value;
        row.append(key, v, document.createTextNode(label));
        tip.append(row);
      });
      // The tooltip lives on <body>: copy the chart's colour tokens (light or dark).
      ['--series-1', '--series-2', '--series-3', '--series-alert', '--series-muted'].forEach((v) =>
        tip.style.setProperty(v, getComputedStyle(el).getPropertyValue(v)),
      );
      tip.hidden = false;
      const r = mark.getBoundingClientRect();
      const w = tip.offsetWidth;
      const h = tip.offsetHeight;
      let x = r.left + r.width / 2 - w / 2;
      x = Math.max(8, Math.min(x, window.innerWidth - w - 8));
      let y = r.top - h - 8;
      if (y < 8) y = r.bottom + 8;
      tip.style.left = x + 'px';
      tip.style.top = y + 'px';
    };
    const hide = () => {
      tip.hidden = true;
    };
    const over = (e) => {
      const mark = e.target.closest('[data-tip]');
      if (mark && el.contains(mark)) show(mark);
    };
    el.addEventListener('pointerover', over);
    el.addEventListener('focusin', over);
    el.addEventListener('pointerleave', hide);
    el.addEventListener('focusout', hide);
    window.addEventListener('scroll', hide, { passive: true });
    cleanup(() => {
      el.removeEventListener('pointerover', over);
      el.removeEventListener('focusin', over);
      el.removeEventListener('pointerleave', hide);
      el.removeEventListener('focusout', hide);
      window.removeEventListener('scroll', hide);
    });
  });

  // Admin › one user: "On this page" follows the section in view, and the
  // Lab details section shows while Student is ticked (data-student="1").
  Alpine.data('sectionNav', () => ({
    student: false,
    init() {
      this.student = this.$el.dataset.student === '1';
      const links = [...this.$el.querySelectorAll('.side-nav-item[data-nav]')];
      const mark = (key) => links.forEach((a) => a.setAttribute('aria-current', a.dataset.nav === key ? 'true' : 'false'));
      // The current section is the last one whose top has passed under the
      // top bar; at the very bottom of the page, the last one.
      const pick = () => {
        const secs = links.map((a) => [a.dataset.nav, document.getElementById('sec-' + a.dataset.nav)])
          .filter(([, el]) => el && el.offsetParent !== null);
        if (!secs.length) return;
        let cur = secs[0][0];
        secs.forEach(([key, el]) => {
          if (el.getBoundingClientRect().top <= 120) cur = key;
        });
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) cur = secs[secs.length - 1][0];
        mark(cur);
      };
      let queued = false;
      window.addEventListener('scroll', () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
          queued = false;
          pick();
        });
      }, { passive: true });
      links.forEach((a) => a.addEventListener('click', () => setTimeout(() => mark(a.dataset.nav), 50)));
      pick();
    },
  }));

  // Admin › Users: tick people, then act on all of them from the bulk bar.
  // data-compose = the Write an email URL; the selection is added as ?users=.
  Alpine.data('bulkSelect', () => ({
    ids: [],
    get all() {
      const s = this.allIds();
      return s.length > 0 && s.every((id) => this.ids.includes(id));
    },
    get some() {
      return this.ids.length > 0 && !this.all;
    },
    allIds() {
      return [...new Set([...this.$root.querySelectorAll('input.pick-one[value]')].map((b) => Number(b.value)))];
    },
    toggle(id) {
      this.ids = this.ids.includes(id) ? this.ids.filter((x) => x !== id) : [...this.ids, id];
    },
    toggleAll(on) {
      this.ids = on ? this.allIds() : [];
    },
    clear() {
      this.ids = [];
    },
    composeUrl() {
      return this.$root.dataset.compose + '&users=' + this.ids.join(',');
    },
  }));

  // The command palette in the admin top bar (Ctrl/⌘ + K): pages from
  // data-palette, people from its search URL. Built with x-text only.
  Alpine.data('palette', () => ({
    open: false,
    q: '',
    items: [],
    people: [],
    active: 0,
    busy: false,
    cfg: { pages: [], search: '' },
    seq: 0,
    init() {
      try {
        this.cfg = JSON.parse(this.$el.dataset.palette || '{}');
      } catch (e) {
        /* keep the empty default */
      }
      this.build();
    },
    openIt() {
      if (this.open) return;
      this.open = true;
      this.q = '';
      this.people = [];
      this.build();
      this.lockPage();
      // Touch screens: no automatic keyboard (it covers the list and shifts
      // the panel on iPad); the box is there to tap. Keyboards get focus.
      if (!window.matchMedia('(pointer: coarse)').matches) this.$nextTick(() => this.$refs.input.focus());
    },
    close() {
      if (!this.open) return;
      this.open = false;
      this.unlockPage();
    },
    // While the palette is open the page behind must not move. iPad Safari
    // ignores overflow:hidden on <body> for touch, so the page is pinned with
    // position:fixed and put back where it was on close. The panel is sized
    // to the visible area (minus the on-screen keyboard) via --palette-vh.
    lockPage() {
      this.scrollY = window.scrollY;
      const b = document.body.style;
      this.saved = { position: b.position, top: b.top, left: b.left, right: b.right, width: b.width, overflow: document.documentElement.style.overflow };
      Object.assign(b, { position: 'fixed', top: -this.scrollY + 'px', left: '0', right: '0', width: '100%' });
      document.documentElement.style.overflow = 'hidden';
      this.fit = () => {
        const vv = window.visualViewport;
        document.documentElement.style.setProperty('--palette-vh', (vv ? vv.height : window.innerHeight) + 'px');
      };
      this.fit();
      window.visualViewport?.addEventListener('resize', this.fit);
      // Belt and braces: a drag that starts outside the list never scrolls anything.
      this.blockTouch = (e) => {
        if (!e.target.closest('.palette-list')) e.preventDefault();
      };
      this.$root.querySelector('.palette-backdrop').addEventListener('touchmove', this.blockTouch, { passive: false });
    },
    unlockPage() {
      const s = this.saved || {};
      Object.assign(document.body.style, { position: s.position || '', top: s.top || '', left: s.left || '', right: s.right || '', width: s.width || '' });
      document.documentElement.style.overflow = s.overflow || '';
      window.scrollTo(0, this.scrollY || 0);
      window.visualViewport?.removeEventListener('resize', this.fit);
      this.$root.querySelector('.palette-backdrop').removeEventListener('touchmove', this.blockTouch);
    },
    build() {
      const words = this.q.toLowerCase().split(/\s+/).filter(Boolean);
      const pages = (this.cfg.pages || [])
        .filter(([title, , sub]) => words.every((w) => (title + ' ' + sub).toLowerCase().includes(w)))
        .map(([title, url, sub]) => ({ kind: 'Page', title, url, sub }));
      const people = this.people.map((u) => ({
        kind: u.active ? 'User' : 'User · off',
        title: u.name,
        url: u.url,
        sub: u.email + (u.roles ? ' · ' + u.roles.replaceAll(',', ', ') : ''),
      }));
      this.items = [...people, ...pages].slice(0, 12);
      this.active = 0;
    },
    async find() {
      this.build();
      const q = this.q.trim();
      if (q.length < 2 || !this.cfg.search) {
        this.people = [];
        this.build();
        return;
      }
      const mine = ++this.seq;
      this.busy = true;
      try {
        const r = await fetch(this.cfg.search + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
        const data = await r.json();
        if (mine === this.seq) {
          this.people = data.users || [];
          this.build();
        }
      } catch (e) {
        /* offline or signed out: pages still work */
      } finally {
        if (mine === this.seq) this.busy = false;
      }
    },
    move(d) {
      if (!this.items.length) return;
      this.active = (this.active + d + this.items.length) % this.items.length;
      this.$nextTick(() => document.getElementById('pal-' + this.active)?.scrollIntoView({ block: 'nearest' }));
    },
    go(i) {
      const item = this.items[i ?? this.active];
      if (item) window.location.href = item.url;
    },
    // A tap on a result: let a short scroll-drag through the list not count as a tap.
    tapStart(e) {
      this.tapY = e.touches ? e.touches[0].clientY : 0;
    },
    tapEnd(e, i) {
      const y = e.changedTouches ? e.changedTouches[0].clientY : 0;
      if (Math.abs(y - (this.tapY ?? y)) < 8) {
        e.preventDefault();
        this.go(i);
      }
    },
  }));

  // Admin › Site settings › Time zone: a live preview of how times will read
  // in the chosen zone, and "use my computer's zone". data-current = saved zone.
  Alpine.data('tzPicker', () => ({
    tz: '',
    current: '',
    browser: '',
    tick: 0,
    init() {
      this.current = this.$el.dataset.current || 'UTC';
      this.tz = this.$el.querySelector('select[name="timezone"]').value || this.current;
      try {
        this.browser = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
      } catch (e) {
        this.browser = '';
      }
      // Keep "Right now" current.
      setInterval(() => this.tick++, 30000);
    },
    useBrowser() {
      // $root, not $el: inside a click handler $el is the button.
      if (this.browser && this.$root.querySelector('option[value="' + CSS.escape(this.browser) + '"]')) this.tz = this.browser;
    },
    format(date, zone) {
      void this.tick;
      try {
        return new Intl.DateTimeFormat('en-US', {
          timeZone: zone || this.tz, weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
          hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).format(date);
      } catch (e) {
        return '';
      }
    },
    offset() {
      try {
        const part = new Intl.DateTimeFormat('en-US', { timeZone: this.tz, timeZoneName: 'longOffset' })
          .formatToParts(new Date()).find((p) => p.type === 'timeZoneName');
        return part ? part.value.replace('GMT', 'UTC') || 'UTC' : '';
      } catch (e) {
        return '';
      }
    },
  }));

  // Admin › Emails › Write an email. The form posts normally; this adds
  // the people search, picked-people pills, a live recipient count, the
  // preview, a "{name}" button and a confirm step before a real send.
  // Data comes from data-* attributes on the form (JSON, HTML-escaped):
  //   data-people  [{id, name, email}]   active accounts
  //   data-picked  [id]                  ticked when the page loads
  //   data-groups  {all, student, teacher, admin}  account counts
  Alpine.data('emailComposer', () => ({
    people: [],
    groups: {},
    picked: [],
    audience: 'selected',
    query: '',
    extra: '',
    subject: '',
    message: '',
    dirty: false,
    approved: false,
    init() {
      const d = this.$el.dataset;
      this.people = JSON.parse(d.people || '[]');
      this.groups = JSON.parse(d.groups || '{}');
      this.picked = JSON.parse(d.picked || '[]').map(Number);
      const f = this.$el.elements;
      this.audience = (this.$el.querySelector('input[name="audience"]:checked') || {}).value || 'selected';
      this.extra = f.extra.value;
      this.subject = f.subject.value;
      this.message = f.message.value;
      this.$el.addEventListener('input', () => (this.dirty = true));
      window.addEventListener('beforeunload', (e) => {
        if (this.dirty) {
          e.preventDefault();
          e.returnValue = '';
        }
      });
    },
    // Every typed word must appear in the name or the address.
    get shown() {
      const words = this.query.toLowerCase().split(/\s+/).filter(Boolean);
      return this.people.filter((p) => {
        const hay = (p.name + ' ' + p.email).toLowerCase();
        return words.every((w) => hay.includes(w));
      });
    },
    get pickedPeople() {
      return this.people.filter((p) => this.picked.includes(p.id));
    },
    isPicked(id) {
      return this.picked.includes(id);
    },
    toggle(id) {
      this.picked = this.isPicked(id) ? this.picked.filter((x) => x !== id) : [...this.picked, id];
      this.dirty = true;
    },
    pickShown() {
      const ids = this.shown.map((p) => p.id);
      this.picked = [...new Set([...this.picked, ...ids])];
    },
    clearPicked() {
      this.picked = [];
    },
    get extraList() {
      const parts = this.extra.split(/[\s,;]+/).filter(Boolean);
      const ok = (a) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a);
      return { valid: [...new Set(parts.filter(ok).map((a) => a.toLowerCase()))], invalid: parts.filter((a) => !ok(a)) };
    },
    // Approximate (the server removes duplicates between groups and typed addresses).
    get recipientCount() {
      const base = this.audience === 'selected' ? this.picked.length : this.groups[this.audience] || 0;
      return base + this.extraList.valid.length;
    },
    get audienceLabel() {
      return { all: 'everyone with an active account', student: 'all students', teacher: 'all teachers', admin: 'all admins' }[this.audience] || '';
    },
    get previewTo() {
      if (this.audience !== 'selected') return this.audienceLabel ? 'Each of ' + this.audienceLabel : '';
      const first = this.pickedPeople[0];
      const label = first ? first.name + ' <' + first.email + '>' : this.extraList.valid[0] || '';
      const more = this.recipientCount - (label ? 1 : 0);
      return label + (more > 0 ? ' and ' + more + ' more' : '');
    },
    get previewName() {
      const first = this.audience === 'selected' ? this.pickedPeople[0] : null;
      return first ? first.name : 'Aiko Tanaka';
    },
    get previewParas() {
      return this.message
        .replace(/\r\n/g, '\n')
        .split(/\n{2,}/)
        .map((p) => p.trim())
        .filter(Boolean)
        .map((p) => p.replaceAll('{name}', this.previewName));
    },
    insertName() {
      const ta = this.$refs.message;
      const at = ta.selectionStart ?? this.message.length;
      this.message = this.message.slice(0, at) + '{name}' + this.message.slice(ta.selectionEnd ?? at);
      this.dirty = true;
      this.$nextTick(() => {
        ta.focus();
        ta.setSelectionRange(at + 6, at + 6);
      });
    },
    // A real send asks first; "Send me a test" goes straight through.
    submit(e) {
      const btn = e.submitter;
      if (this.approved || !btn || btn.value !== 'send') {
        this.dirty = false;
        return;
      }
      e.preventDefault();
      const n = this.recipientCount;
      confirm({
        title: 'Send this email to ' + n + (n === 1 ? ' person?' : ' people?'),
        body: '“' + (this.subject || '(no subject)') + '” goes out now, one copy per person. It cannot be recalled.',
        okText: 'Send now',
        tone: 'primary',
      }).then((ok) => {
        if (!ok) return;
        this.approved = true;
        this.dirty = false;
        this.$el.requestSubmit(btn);
      });
    },
  }));

  followSystemTheme();
}

// ------------------------------------------------------------------ theme
// header.php puts data-theme="light|dark|auto" on <html> and, for "auto",
// an inline script sets the `dark` class before first paint. This keeps
// "auto" in step if the system setting changes while the page is open.
function applyTheme(theme) {
  const root = document.documentElement;
  root.dataset.theme = theme;
  const dark = theme === 'dark' || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  root.classList.toggle('dark', dark);
}
function followSystemTheme() {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (document.documentElement.dataset.theme === 'auto') applyTheme('auto');
  });
}
