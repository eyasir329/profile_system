/*
 * Inline editor for /profile.php: view first, edit one field in place.
 *
 * Each lab field saves on its own through POST /profile.php with
 * op=update_lab (form data in, JSON out). There is no page-wide form.
 *
 * Markup contract (see public/profile.php):
 *   [data-profile]                               the page root
 *   [data-value-card][data-field=...]            one editable field
 *     [data-value-body] [data-value-display]     its current value
 *     [data-value-actions] [data-value-action=edit] [data-action-label]
 *   [data-step=lab:<field>] [data-step-locked]   suggested next step
 *   [data-overall-bar|fill|text|detail]          the meter
 *   [data-section-nav] [data-section-link=...]   the section list, with
 *     [data-group-count=...] [data-nav-course-count]   its counts
 *   [data-profile-section][data-section=...]     one section of the page
 *   [data-readonly-field=...]                    a field the user cannot edit
 *   [data-entry-add=<section>] [data-entry-list=<section>]   a repeating
 *     section; rows are [data-entry-row=<id>][data-entry] with
 *     [data-entry-edit] / [data-entry-delete]; counts in
 *     [data-section-count] [data-entry-count] [data-section-empty];
 *     [data-entry-legacy] is the old text, removed once entries exist
 *   [data-tabs] [data-tab=<section>] [data-tab-panel=<section>]   the
 *     tabbed publications/awards section; [data-entry-total] sums its tabs
 *   [data-copy-bibtex]                           copy a row's BibTeX
 *   [data-course-add] [data-course-form] [data-course-list]
 *   [data-course-remove=<id>]                    the Courses history
 *   [data-course-complete=1|0]                   mark the current course
 *                                                completed, or undo it
 *
 * Config: window.PS_VIEW_MODE = { csrf, lab: { fields, values, all }, groups, entries, courses }.
 *   fields  definitions of the fields this user may edit
 *   values  their current values
 *   all     every field's value (including admin-only ones), for the meter
 *   groups  section key => its field keys ($GROUPS in profile.php)
 *   entries repeating sections (lab_entry_defs() in profile.php)
 *
 * Plain DOM code on purpose: it builds and removes a form on demand, which
 * is a poor fit for Alpine's declarative templates. The form uses the same
 * component classes as the PHP templates (see docs/COMPONENTS.md).
 */
import { toast, confirm } from './ui.js';

// POST one op. Resolves with the JSON body when `ok`, rejects with it otherwise.
function api(op, fields) {
  const fd = new FormData();
  fd.append('op', op);
  fd.append('csrf_token', (window.PS_VIEW_MODE && window.PS_VIEW_MODE.csrf) || '');
  Object.keys(fields).forEach((k) => fd.append(k, fields[k]));
  return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(
    (r) =>
      r.text().then((txt) => {
        let body = null;
        try {
          body = JSON.parse(txt);
        } catch (e) {
          /* not JSON */
        }
        if (body && body.ok) return body;
        if (!body || typeof body !== 'object') {
          // An HTML answer means we were redirected to login or hit a
          // CSRF error page, not the op handler.
          body = { ok: false, error: 'Your session may have expired. Reload the page and try again.' };
        } else if (!body.field_errors && !body.error) {
          body.error = 'Something went wrong. Please try again.';
        }
        return Promise.reject(body);
      }),
    () => Promise.reject({ ok: false, error: 'Could not reach the server. Check your connection and try again.' })
  );
}

const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

// Read one BibTeX entry and build an IEEE-style citation from it:
//   A. Author, B. Author, and C. Author, "Title," Journal, vol. 1, no. 2,
//   pp. 3-4, Mon. 2024.
// Returns { citation, month: 'YYYY-MM' | '' } or null when nothing parses.
function parseBibtex(src) {
  const text = String(src || '');
  const type = (text.match(/@(\w+)\s*\{/) || [])[1];
  if (!type) return null;
  const f = {};
  // field = {value} (one level of nested braces) | "value" | bare
  const re = /(\w+)\s*=\s*(?:\{((?:[^{}]|\{[^{}]*\})*)\}|"([^"]*)"|([\w.-]+))/g;
  let m;
  while ((m = re.exec(text))) f[m[1].toLowerCase()] = (m[2] ?? m[3] ?? m[4] ?? '').replace(/[{}]/g, '').replace(/\s+/g, ' ').trim();
  if (!f.title) return null;
  const names = (f.author || '').split(/\s+and\s+/i).filter(Boolean).map((a) => {
    const [last, first] = a.includes(',') ? a.split(',').map((x) => x.trim()) : [a.split(' ').pop(), a.split(' ').slice(0, -1).join(' ')];
    const initials = (first || '').split(/[\s-]+/).filter(Boolean).map((p) => p[0].toUpperCase() + '.').join(' ');
    return (initials ? initials + ' ' : '') + last;
  });
  const authors = names.length > 2 ? names.slice(0, -1).join(', ') + ', and ' + names[names.length - 1] : names.join(' and ');
  const mi = f.month ? (/^\d+$/.test(f.month) ? Number(f.month) - 1 : MONTHS.indexOf(f.month.slice(0, 3).toLowerCase())) : -1;
  const venue = f.journal || (f.booktitle ? 'in ' + f.booktitle : '');
  const parts = [
    venue,
    f.volume && 'vol. ' + f.volume,
    f.number && 'no. ' + f.number,
    f.pages && (/[-–]/.test(f.pages) ? 'pp. ' : 'p. ') + f.pages.replace(/-+/g, '–'),
    // IEEE abbreviates every month but May: "Mar.", "May", "Sep.".
    [mi >= 0 ? MONTHS[mi][0].toUpperCase() + MONTHS[mi].slice(1) + (mi === 4 ? '' : '.') : '', f.year].filter(Boolean).join(' '),
  ].filter(Boolean);
  const citation = (authors ? authors + ', ' : '') + '"' + f.title + '," ' + parts.join(', ') + '.';
  const month = f.year && /^\d{4}$/.test(f.year) ? f.year + '-' + String(mi >= 0 ? mi + 1 : 1).padStart(2, '0') : '';
  return { citation, month: mi >= 0 ? month : '' };
}

export function initProfileEditor() {
  const cfg = window.PS_VIEW_MODE;
  const page = document.querySelector('[data-profile]');
  if (!cfg || !cfg.lab || !page) return;

  const $ = (sel, root) => (root || page).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || page).querySelectorAll(sel));
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const reveal = (node) => {
    const r = node.getBoundingClientRect();
    if (r.top < 80 || r.bottom > window.innerHeight - 16) {
      node.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
    }
  };
  const focusQuietly = (node) => node && node.focus({ preventScroll: true });

  const defs = cfg.lab.fields || {};
  const values = Object.assign({}, cfg.lab.values);
  const all = Object.assign({}, cfg.lab.all);
  const cardOf = (field) => $('[data-value-card][data-field="' + field + '"]');
  let uid = 0;

  // Warn before leaving while a form has unsaved edits.
  const openForms = new Set();
  window.addEventListener('beforeunload', (e) => {
    if (Array.from(openForms).some((f) => f.dirty)) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // ------------------------------------------------------------ meter
  const refreshProgress = () => {
    const steps = $$('[data-step]');
    let done = 0;
    let shown = 0;
    steps.forEach((btn) => {
      const isDone = !!all[btn.getAttribute('data-step').split(':')[1]];
      if (isDone) done++;
      // Offer at most three next steps, and never one only an admin can do.
      const show = !isDone && !btn.hasAttribute('data-step-locked') && shown < 3;
      if (show) shown++;
      btn.hidden = !show;
    });
    const pct = steps.length ? Math.round((done / steps.length) * 100) : 0;
    const bar = $('[data-overall-bar]');
    if (bar) bar.setAttribute('aria-valuenow', String(pct));
    const fill = $('[data-overall-fill]');
    if (fill) fill.style.width = pct + '%';
    const text = $('[data-overall-text]');
    if (text) text.textContent = pct + '%';
    const detail = $('[data-overall-detail]');
    if (detail) {
      detail.textContent =
        done === steps.length
          ? 'Every field is filled in. Nice work.'
          : done + ' of ' + steps.length + ' done.' + (shown ? ' Suggested next:' : '');
    }
    Object.keys(cfg.groups || {}).forEach((g) => {
      const fields = cfg.groups[g];
      const n = fields.filter((f) => !!all[f]).length;
      const c = $('[data-group-count="' + g + '"]');
      if (c) {
        c.textContent = n + '/' + fields.length;
        c.closest('[data-section-link]').classList.toggle('is-complete', n === fields.length);
      }
    });
    const courses = $('[data-nav-course-count]');
    if (courses) courses.textContent = String($$('[data-course-row]').length);
  };

  // Redraw everything a reply can change: the current course, its
  // enrolment date (both shown read-only in places) and the Courses list.
  const applyState = (state) => {
    if (!state) return;
    Object.keys(state.values).forEach((k) => {
      all[k] = state.values[k];
      if (k in values) values[k] = state.values[k];
      const card = cardOf(k);
      if (card && !$('form', card)) render(card);
      const ro = $('[data-readonly-field="' + k + '"] [data-value-display]');
      if (ro) {
        ro.textContent = state.values[k] || 'Not set';
        ro.classList.toggle('muted', !state.values[k]);
      }
    });
    const list = $('[data-course-list]');
    if (list) list.innerHTML = state.history; // server-rendered and escaped
    // One course at a time: "Add course" opens up once the current one is completed.
    const addBtn = $('[data-course-add]');
    if (addBtn && 'can_add_course' in state) {
      addBtn.disabled = !state.can_add_course;
      if (state.can_add_course) addBtn.removeAttribute('aria-describedby');
      else addBtn.setAttribute('aria-describedby', 'course-add-why');
      const why = $('[data-course-add-why]');
      if (why) why.hidden = state.can_add_course;
      if (!state.can_add_course) { const slot = $('[data-course-form]'); if (slot) slot.innerHTML = ''; }
    }
    refreshProgress();
  };

  // ------------------------------------------------------------- view
  const render = (card) => {
    const field = card.getAttribute('data-field');
    const value = values[field] || '';
    const display = $('[data-value-display]', card);
    display.textContent = value || defs[field].empty;
    // The header repeats course, student ID and email.
    const echo = $('[data-echo="' + field + '"]');
    if (echo) { echo.textContent = (field === 'student_id' && value ? 'ID ' : '') + value; echo.hidden = !value; }
    display.classList.toggle('muted', !value);
    const btn = $('[data-value-action="edit"]', card);
    const verb = value ? 'Edit' : 'Add';
    $('[data-action-label]', btn).textContent = verb;
    btn.setAttribute('aria-label', verb + ' ' + defs[field].label.toLowerCase());
  };

  // ------------------------------------------------------------- edit
  const edit = (card) => {
    if (!card) return;
    const existing = $('form', card);
    if (existing) {
      focusQuietly($('input, textarea', existing));
      return;
    }
    const field = card.getAttribute('data-field');
    const def = defs[field];
    if (!def) return;
    const body = $('[data-value-body]', card);
    const actions = $('[data-value-actions]', card);
    const state = { dirty: false };
    let busy = false;

    const form = el('form', 'inline-form');
    const wrap = el('div', 'field');
    const multiline = def.type === 'textarea';
    const choice = def.type === 'select';
    const input = multiline ? el('textarea', 'textarea') : choice ? el('select', 'select') : el('input', 'input');
    if (choice) {
      const current = values[field] || '';
      if (!current) {
        const blank = el('option', '', 'Choose…');
        blank.value = '';
        blank.disabled = true;
        blank.selected = true;
        input.appendChild(blank);
      }
      // Also offer the stored value when it is not an option (e.g. an
      // enrolment month the system set, such as "July").
      const options = (def.options || []).slice();
      if (current && options.indexOf(current) < 0) options.push(current);
      options.forEach((o) => {
        const opt = el('option', '', o);
        opt.value = o;
        input.appendChild(opt);
      });
      if (current) input.value = current;
    } else {
      if (multiline) input.rows = def.rows || 3;
      else input.type = def.type;
      input.maxLength = def.max;
      input.placeholder = def.placeholder || '';
      input.value = values[field] || '';
    }
    if (def.required) input.required = true;
    input.setAttribute('aria-labelledby', 'lbl-' + field);
    const errId = 'pf-err-' + ++uid;
    input.setAttribute('aria-describedby', errId);
    wrap.appendChild(input);

    const under = el('div', 'field-under');
    if (def.hint) under.appendChild(el('span', 'hint', def.hint));
    if (multiline) {
      const counter = el('span', 'counter');
      const count = () => {
        counter.textContent = input.value.length + ' / ' + def.max;
        counter.classList.toggle('is-warning', input.value.length >= def.max * 0.9);
      };
      input.addEventListener('input', count);
      count();
      under.appendChild(counter);
    }
    if (under.childNodes.length) wrap.appendChild(under);

    const err = el('p', 'field-error');
    err.id = errId;
    err.setAttribute('role', 'alert');
    err.hidden = true;
    wrap.appendChild(err);
    form.appendChild(wrap);

    const foot = el('div', 'form-actions');
    const cancelBtn = el('button', 'btn btn-ghost btn-sm', 'Cancel');
    cancelBtn.type = 'button';
    const saveBtn = el('button', 'btn btn-primary btn-sm', 'Save');
    saveBtn.type = 'submit';
    foot.appendChild(cancelBtn);
    foot.appendChild(saveBtn);
    form.appendChild(foot);

    const close = () => {
      openForms.delete(state);
      form.remove();
      body.hidden = false;
      actions.hidden = false;
      card.classList.remove('is-editing');
      focusQuietly($('[data-value-action="edit"]', card));
    };
    const cancel = () => {
      if (busy) return;
      if (!state.dirty) return close();
      confirm({
        title: 'Discard your changes?',
        body: 'What you typed here has not been saved.',
        okText: 'Discard',
        cancelText: 'Keep editing',
      }).then((ok) => ok && close());
    };

    form.addEventListener('input', () => (state.dirty = true));
    form.addEventListener('change', () => (state.dirty = true));
    cancelBtn.addEventListener('click', cancel);
    form.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        cancel();
      } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        form.requestSubmit();
      }
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (busy) return;
      busy = true;
      err.hidden = true;
      input.removeAttribute('aria-invalid');
      saveBtn.disabled = cancelBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      api('update_lab', { field, value: input.value }).then(
        (res) => {
          values[field] = all[field] = res.value || '';
          render(card);
          close();
          applyState(res.state);
          toast(def.label + (res.value ? ' saved.' : ' cleared.'), { type: 'success' });
        },
        (res) => {
          busy = false;
          saveBtn.disabled = cancelBtn.disabled = false;
          saveBtn.textContent = 'Save';
          err.textContent = (res.field_errors && res.field_errors[field]) || res.error || 'Could not save. Please try again.';
          err.hidden = false;
          input.setAttribute('aria-invalid', 'true');
          input.focus();
        }
      );
    });

    openForms.add(state);
    card.classList.add('is-editing');
    body.hidden = true;
    actions.hidden = true;
    card.appendChild(form);
    reveal(card);
    focusQuietly(input);
    if (!multiline && !choice) input.select();
  };

  // ---------------------------------------------------------- entries
  // Theses and papers: a list of entries per section. Each entry is saved
  // on its own; every reply carries the section's rows, rendered by PHP.
  const entryDefs = cfg.entries || {};

  const setEntries = (section, res) => {
    const list = $('[data-entry-list="' + section + '"]');
    if (list) list.innerHTML = res.list; // server-rendered and escaped
    const n = res.count;
    const label = $('[data-section-count="' + section + '"]');
    if (label) { label.textContent = n + (n === 1 ? ' entry' : ' entries'); label.hidden = !n; }
    $$('[data-entry-count="' + section + '"]').forEach((c) => {
      c.textContent = String(n);
      const link = c.closest('[data-section-link]');
      if (link) link.classList.toggle('is-complete', n > 0);
    });
    $$('[data-entry-total]').forEach((c) => {
      const total = c.getAttribute('data-entry-total').split(' ')
        .reduce((sum, k) => sum + Number(($('[data-entry-count="' + k + '"]') || {}).textContent || 0), 0);
      c.textContent = String(total);
      c.closest('[data-section-link]').classList.toggle('is-complete', total > 0);
    });
    const empty = $('[data-section-empty="' + section + '"]');
    if (empty) empty.hidden = n > 0;
    const legacy = $('[data-entry-legacy="' + section + '"]');
    if (legacy && n > 0) legacy.remove();
    applyState(res.state);
  };

  // Build the form for one entry. `current` holds its values (empty when adding).
  const entryForm = (section, current, onSave, onClose) => {
    const def = entryDefs[section];
    const form = el('form', 'inline-form entry-form');
    const state = { dirty: false };
    let busy = false;
    const inputs = {};
    const errs = {};
    (def.form || Object.keys(def.fields)).forEach((name) => {
      const f = def.fields[name];
      const wrap = el('div', 'field');
      // Width out of three columns: long fields take the whole row.
      const span = f.span || (f.type === 'text' || f.type === 'textarea' ? 3 : 1);
      wrap.style.setProperty('--span', String(span));
      const id = 'pf-entry-' + ++uid;
      const label = el('label', 'label', f.label);
      label.htmlFor = id;
      let input;
      if (f.type === 'select') {
        input = el('select', 'select');
        const blank = el('option', '', 'Choose…');
        blank.value = '';
        blank.disabled = true;
        input.appendChild(blank);
        Object.keys(f.options).forEach((v) => {
          const opt = el('option', '', f.options[v]);
          opt.value = v;
          input.appendChild(opt);
        });
        input.value = current[name] || f.default || '';
        if (!input.value) blank.selected = true;
      } else if (f.type === 'textarea') {
        input = el('textarea', 'textarea');
        input.rows = name === 'bibtex' ? 5 : 3;
        input.maxLength = f.max;
        input.placeholder = f.placeholder || '';
        input.value = current[name] || '';
      } else {
        input = el('input', 'input');
        // month → "2024-05", date → "2024-05-01"; both stored as typed.
        input.type = f.type === 'month' || f.type === 'date' ? f.type : 'text';
        if (f.type === 'number') input.inputMode = 'decimal';
        if (f.type === 'month') input.placeholder = 'YYYY-MM';
        input.maxLength = f.max;
        if (f.placeholder) input.placeholder = f.placeholder;
        input.value = current[name] || '';
      }
      input.id = id;
      input.name = name;
      if (f.required) input.required = true;
      const err = el('p', 'field-error');
      err.id = id + '-err';
      err.setAttribute('role', 'alert');
      err.hidden = true;
      input.setAttribute('aria-describedby', err.id);
      wrap.append(label, input, err);
      form.appendChild(wrap);
      inputs[name] = input;
      errs[name] = err;
    });
    // Pasted BibTeX can fill in the citation and the month.
    if (inputs.bibtex && inputs.citation) {
      const fill = el('button', 'btn btn-ghost btn-sm mt-1.5', 'Fill citation and month from BibTeX');
      fill.type = 'button';
      fill.addEventListener('click', () => {
        const got = parseBibtex(inputs.bibtex.value);
        if (!got) return toast('Paste a BibTeX entry first.', { type: 'error' });
        const filled = [];
        if (got.citation && !inputs.citation.value.trim()) {
          inputs.citation.value = got.citation;
          filled.push('citation');
        }
        if (got.month && inputs.pub_month && !inputs.pub_month.value) {
          inputs.pub_month.value = got.month;
          filled.push('month');
        }
        state.dirty = true;
        toast(filled.length ? 'Filled the ' + filled.join(' and ') + ' from BibTeX.' : 'Citation and month are already filled in. Clear them to fill from BibTeX.', { type: filled.length ? 'success' : 'info' });
      });
      inputs.bibtex.parentNode.insertBefore(fill, inputs.bibtex.nextSibling);
    }
    const foot = el('div', 'form-actions');
    const cancelBtn = el('button', 'btn btn-ghost btn-sm', 'Cancel');
    cancelBtn.type = 'button';
    const saveBtn = el('button', 'btn btn-primary btn-sm', 'Save');
    saveBtn.type = 'submit';
    foot.append(cancelBtn, saveBtn);
    form.appendChild(foot);

    const close = () => {
      openForms.delete(state);
      onClose();
    };
    const cancel = () => {
      if (busy) return;
      if (!state.dirty) return close();
      confirm({
        title: 'Discard your changes?',
        body: 'What you typed here has not been saved.',
        okText: 'Discard',
        cancelText: 'Keep editing',
      }).then((ok) => ok && close());
    };
    form.addEventListener('input', () => (state.dirty = true));
    form.addEventListener('change', () => (state.dirty = true));
    cancelBtn.addEventListener('click', cancel);
    form.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        cancel();
      }
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (busy) return;
      busy = true;
      Object.keys(errs).forEach((k) => { errs[k].hidden = true; inputs[k].removeAttribute('aria-invalid'); });
      saveBtn.disabled = cancelBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      const data = {};
      Object.keys(inputs).forEach((k) => (data[k] = inputs[k].value));
      onSave(data).then(
        (res) => {
          openForms.delete(state);
          setEntries(section, res);
        },
        (res) => {
          busy = false;
          saveBtn.disabled = cancelBtn.disabled = false;
          saveBtn.textContent = 'Save';
          const fe = res.field_errors || {};
          const first = Object.keys(fe).find((k) => errs[k]);
          Object.keys(fe).forEach((k) => {
            if (!errs[k]) return;
            errs[k].textContent = fe[k];
            errs[k].hidden = false;
            inputs[k].setAttribute('aria-invalid', 'true');
          });
          if (first) inputs[first].focus();
          else toast(res.error || 'Could not save. Please try again.', { type: 'error' });
        }
      );
    });
    openForms.add(state);
    return { form, first: inputs[Object.keys(inputs)[0]] };
  };

  // Tabs (publications/awards): click, or arrow keys between tabs.
  const showTab = (tab, focus) => {
    const list = tab.closest('[data-tabs]');
    $$('[data-tab]', list).forEach((t) => {
      const on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      const panel = $('[data-tab-panel="' + t.getAttribute('data-tab') + '"]');
      if (panel) panel.hidden = !on;
    });
    if (focus) tab.focus();
  };
  $$('[data-tabs]').forEach((list) => {
    list.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'Home' && e.key !== 'End') return;
      const tabs = $$('[data-tab]', list);
      const i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      e.preventDefault();
      const next = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      showTab(tabs[next], true);
    });
  });

  // Adding opens a draft below the list. Nothing is written until Save.
  const addEntry = (section) => {
    const list = $('[data-entry-list="' + section + '"]');
    if (!list || !entryDefs[section]) return;
    const open = $('[data-entry-draft]', list.parentNode);
    if (open) return focusQuietly($('input, select', open));
    const draft = el('div', 'mt-4');
    draft.setAttribute('data-entry-draft', '');
    const empty = $('[data-section-empty="' + section + '"]');
    const built = entryForm(
      section,
      {},
      (data) => api('add_entry', Object.assign({ section }, data)).then((res) => {
        draft.remove();
        toast('Entry added.', { type: 'success' });
        return res;
      }),
      () => {
        draft.remove();
        if (empty) empty.hidden = list.children.length > 0;
      }
    );
    draft.appendChild(built.form);
    if (empty) empty.hidden = true;
    list.after(draft);
    reveal(draft);
    focusQuietly(built.first);
  };

  const editEntry = (row) => {
    if ($('form', row)) return;
    const section = row.getAttribute('data-entry-section');
    let current = {};
    try { current = JSON.parse(row.getAttribute('data-entry')) || {}; } catch (e) { /* keep empty */ }
    const body = $('[data-entry-body]', row);
    const actions = $('[data-entry-actions]', row);
    const built = entryForm(
      section,
      current,
      (data) => api('update_entry', Object.assign({ section, id: row.getAttribute('data-entry-row') }, data)).then((res) => {
        toast('Entry saved.', { type: 'success' });
        return res;
      }),
      () => {
        built.form.remove();
        body.hidden = actions.hidden = false;
        row.classList.remove('is-editing');
        focusQuietly($('[data-entry-edit]', row));
      }
    );
    row.classList.add('is-editing');
    body.hidden = actions.hidden = true;
    row.appendChild(built.form);
    reveal(row);
    focusQuietly(built.first);
  };

  const deleteEntry = (row) => {
    const section = row.getAttribute('data-entry-section');
    confirm({
      title: 'Delete this entry?',
      body: 'It is removed from your profile and the Students sheet.',
      okText: 'Delete',
      cancelText: 'Keep it',
    }).then((ok) => {
      if (!ok) return;
      api('delete_entry', { section, id: row.getAttribute('data-entry-row') }).then(
        (res) => {
          setEntries(section, res);
          toast('Entry deleted.', { type: 'success' });
        },
        (res) => toast(res.error || 'Could not delete the entry.', { type: 'error' })
      );
    });
  };

  // ---------------------------------------------------------- courses
  const addCourse = () => {
    const slot = $('[data-course-form]');
    const btn = $('[data-course-add]');
    if (!slot || (btn && btn.disabled)) return;
    if (slot.firstChild) return focusQuietly($('select', slot));
    const form = el('form', 'inline-form my-4');
    const wrap = el('div', 'field');
    const label = el('label', 'label', 'New course');
    const select = el('select', 'select');
    select.id = label.htmlFor = 'pf-course-new';
    select.required = true;
    const blank = el('option', '', 'Choose…');
    blank.value = '';
    blank.disabled = blank.selected = true;
    select.appendChild(blank);
    (cfg.courses || []).forEach((c) => {
      if (c === all.course) return; // already the current course
      const opt = el('option', '', c);
      opt.value = c;
      select.appendChild(opt);
    });
    const hint = el('p', 'hint', 'It becomes your current course, enrolled today.');
    const err = el('p', 'field-error');
    err.setAttribute('role', 'alert');
    err.hidden = true;
    wrap.append(label, select, hint, err);
    const foot = el('div', 'form-actions');
    const cancelBtn = el('button', 'btn btn-ghost btn-sm', 'Cancel');
    cancelBtn.type = 'button';
    const saveBtn = el('button', 'btn btn-primary btn-sm', 'Add course');
    saveBtn.type = 'submit';
    foot.append(cancelBtn, saveBtn);
    form.append(wrap, foot);
    const close = () => {
      form.remove();
      focusQuietly($('[data-course-add]'));
    };
    cancelBtn.addEventListener('click', close);
    form.addEventListener('keydown', (e) => e.key === 'Escape' && close());
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      saveBtn.disabled = true;
      api('add_course', { course: select.value }).then(
        (res) => {
          close();
          applyState(res.state);
          toast('You are now in ' + res.value + '.', { type: 'success' });
        },
        (res) => {
          saveBtn.disabled = false;
          err.textContent = (res.field_errors && res.field_errors.course) || res.error || 'Could not add the course.';
          err.hidden = false;
        }
      );
    });
    slot.appendChild(form);
    reveal(form);
    focusQuietly(select);
  };

  const removeCourse = (btn) => {
    confirm({
      title: 'Remove this course from your history?',
      body: 'Only the record of this past course is removed. Your current course is not affected.',
      okText: 'Remove',
    }).then((ok) => {
      if (!ok) return;
      api('remove_course', { id: btn.getAttribute('data-course-remove') }).then(
        (res) => {
          applyState(res.state);
          toast('Course removed.', { type: 'success' });
        },
        (res) => toast(res.error || 'Could not remove the course.', { type: 'error' })
      );
    });
  };

  // Mark the current course completed, or undo it.
  const completeCourse = (btn) => {
    const done = btn.getAttribute('data-course-complete') === '1';
    const go = () =>
      api('complete_course', { done: done ? '1' : '0' }).then(
        (res) => {
          applyState(res.state);
          toast(done ? 'Course marked as completed.' : 'Course marked as not completed.', { type: 'success' });
        },
        (res) => toast(res.error || 'Could not update the course.', { type: 'error' })
      );
    if (!done) return go();
    confirm({
      title: 'Mark your course as completed?',
      body: 'Your teachers will see you under Completed students. You can undo this.',
      okText: 'Mark as completed',
      cancelText: 'Not yet',
    }).then((ok) => ok && go());
  };

  // A completion step: theses and papers open a new entry, others the field.
  const openField = (field) => (entryDefs[field] ? addEntry(field) : defs[field] && edit(cardOf(field)));

  // ----------------------------------------------------------- clicks
  page.addEventListener('click', (e) => {
    if (e.target.closest('[data-course-add]')) return addCourse();
    const rm = e.target.closest('[data-course-remove]');
    if (rm) return removeCourse(rm);
    const cc = e.target.closest('[data-course-complete]');
    if (cc) return completeCourse(cc);
    const tab = e.target.closest('[data-tab]');
    if (tab) return showTab(tab, false);
    const copy = e.target.closest('[data-copy-bibtex]');
    if (copy) {
      const text = copy.parentNode.querySelector('pre').textContent;
      return navigator.clipboard.writeText(text).then(
        () => toast('BibTeX copied.', { type: 'success' }),
        () => toast('Could not copy. Select the text and copy it instead.', { type: 'error' })
      );
    }
    const add = e.target.closest('[data-entry-add]');
    if (add) return addEntry(add.getAttribute('data-entry-add'));
    const editE = e.target.closest('[data-entry-edit]');
    if (editE) return editEntry(editE.closest('[data-entry-row]'));
    const delE = e.target.closest('[data-entry-delete]');
    if (delE) return deleteEntry(delE.closest('[data-entry-row]'));
    const step = e.target.closest('[data-step]');
    if (step) return openField(step.getAttribute('data-step').split(':')[1]);
    const btn = e.target.closest('[data-value-action="edit"]');
    if (btn) edit(btn.closest('[data-value-card]'));
  });

  // -------------------------------------------------- section navigation
  const links = $$('[data-section-link]');
  const navBar = $('[data-section-nav]');
  const setActive = (key) => {
    links.forEach((a) => {
      const on = a.getAttribute('data-section-link') === key;
      a.setAttribute('aria-current', on ? 'true' : 'false');
      if (on && navBar && navBar.scrollWidth > navBar.clientWidth) {
        navBar.scrollTo({ left: a.offsetLeft - (navBar.clientWidth - a.offsetWidth) / 2, behavior: reducedMotion ? 'auto' : 'smooth' });
      }
    });
  };
  links.forEach((a) => {
    a.addEventListener('click', (e) => {
      const target = $('[data-profile-section][data-section="' + a.getAttribute('data-section-link') + '"]');
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
      history.replaceState(null, '', '#' + target.id);
    });
  });
  const sectionEls = $$('[data-profile-section]');
  if (sectionEls.length && 'IntersectionObserver' in window) {
    // The current section is the first one crossing a band near the top.
    const inBand = new Set();
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) inBand.add(en.target); else inBand.delete(en.target);
      });
      const current = sectionEls.filter((s) => inBand.has(s))[0];
      if (current) setActive(current.getAttribute('data-section'));
    }, { rootMargin: '-15% 0% -70% 0%', threshold: 0 });
    sectionEls.forEach((s) => io.observe(s));
  }

  // /profile.php?step=lab:<field> (dashboard "next step" links) opens that field.
  const wanted = new URLSearchParams(window.location.search).get('step');
  if (wanted) {
    history.replaceState(null, '', window.location.pathname);
    const parts = wanted.split(':');
    if (parts[0] === 'lab') openField(parts[1]);
  }
}
