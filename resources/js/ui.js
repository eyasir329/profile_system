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
 *   sheet            the full-screen mode of the directory's wide table
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
    },
    set(on) {
      this.full = on;
      try {
        on ? sessionStorage.setItem('ps_sheet_full', '1') : sessionStorage.removeItem('ps_sheet_full');
      } catch (e) {
        /* ignore */
      }
      this.sync();
      this.$nextTick(() => (on ? this.$refs.scroll : this.$refs.enter)?.focus({ preventScroll: true }));
    },
    sync() {
      document.documentElement.classList.toggle('has-sheet-full', this.full);
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
