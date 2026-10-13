/*
 * Inline editor for /profile.php: view first, edit in place.
 *
 * Every value saves on its own through POST /profile.php with `op=...`
 * (form data in, JSON out). There is no page-wide form and no save bar.
 *
 * Markup contract (see public/profile.php):
 *   [data-profile]                              the page root
 *   [data-value-card] [data-field=...]          one basics field
 *   [data-value-row] [data-section] [data-row-id]  one section entry
 *   [data-value-action=edit|duplicate|delete|add|edit-photo|clear-photo]
 *   [data-row-list] [data-section=...]          the entries of one section
 *   [data-section-empty=...]                    "add your first" button
 *   [data-section-nav] [data-section-link=...]  the section list
 *   [data-step=...]                             suggested next step
 *
 * Config: window.PS_VIEW_MODE = { csrf, sections, rows, basics, hasPhoto }.
 * Entry HTML always comes from the server (first paint and op responses),
 * so there is no second renderer to keep in sync here. The forms are built
 * from the field metadata with the same component classes the PHP
 * templates use (.field, .label, .input, ... see docs/COMPONENTS.md).
 *
 * This module is plain DOM code on purpose: it creates and destroys forms
 * on demand, which is a poor fit for Alpine's declarative templates.
 */
import { toast, confirm } from './ui.js';

// POST one op. Resolves with the JSON body when `ok`, rejects with the body
// otherwise. Unless `opts.quiet`, failures that are not field errors are
// announced in a toast.
const api = function (op, fields, opts) {
  opts = opts || {};
  const fd = new FormData();
  fd.append('op', op);
  fd.append('csrf_token', (window.PS_VIEW_MODE && window.PS_VIEW_MODE.csrf) || '');
  Object.keys(fields || {}).forEach(function (k) {
    const v = fields[k];
    if (v === null || v === undefined) return;
    if (typeof v === 'object' && !(v instanceof Blob)) {
      // One level of nesting, so the server sees $_POST[k][k2].
      Object.keys(v).forEach(function (k2) {
        if (v[k2] !== null && v[k2] !== undefined) fd.append(k + '[' + k2 + ']', String(v[k2]));
      });
    } else {
      fd.append(k, v);
    }
  });
  const fail = function (body) {
    if (!opts.quiet && !(body && body.field_errors)) {
      toast(body.error, { type: 'error', duration: 6000 });
    }
    return Promise.reject(body);
  };
  return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) {
      return r.text().then(function (txt) {
        let body = null;
        try { body = JSON.parse(txt); } catch (e) { /* not JSON */ }
        if (body && body.ok) return body;
        if (!body || typeof body !== 'object') {
          // An HTML answer means we were redirected to login or hit a
          // CSRF error page, not the op-router.
          body = { ok: false, error: 'Your session may have expired. Reload the page and try again.' };
        } else if (!body.field_errors && !body.error) {
          body.error = 'Something went wrong. Please try again.';
        }
        return fail(body);
      });
    }, function () {
      return fail({ ok: false, error: 'Could not reach the server. Check your connection and try again.' });
    });
};

export function initProfileEditor() {
  const cfg = window.PS_VIEW_MODE;
  const page = document.querySelector('[data-profile]');
  if (!cfg || !page) return;

  const $  = function (sel, el) { return (el || page).querySelector(sel); };
  const $$ = function (sel, el) { return Array.from((el || page).querySelectorAll(sel)); };
  const el = function (tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };
  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const reveal = function (node) {
    const r = node.getBoundingClientRect();
    if (r.top < 80 || r.bottom > window.innerHeight - 16) {
      node.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
    }
  };
  const focusQuietly = function (node) { if (node) node.focus({ preventScroll: true }); };
  let uid = 0;

  const sectionDefs = cfg.sections || {};
  const rowData     = cfg.rows || {};      // section -> id -> row
  const basicDefs   = (cfg.basics && cfg.basics.fields) || {};
  const basicValues = Object.assign({}, cfg.basics && cfg.basics.values);
  let hasPhoto = !!cfg.hasPhoto;

  const sectionEl = function (key) { return document.getElementById('sec-' + key); };
  const listEl    = function (key) { return document.getElementById('row-list-' + key); };
  const liveRows  = function (key) { return $$('[data-value-row]', listEl(key)); };

  // ---------------------------------------------------------------- forms
  // Shared behaviour for every inline form: dirty tracking, Esc to cancel
  // (with a confirm when there are edits), Ctrl/Cmd+Enter to save, a busy
  // state while saving, and per-field server errors.
  const openForms = new Set();
  window.addEventListener('beforeunload', function (e) {
    let dirty = false;
    openForms.forEach(function (f) { if (f.dirty) dirty = true; });
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  const clearErrors = function (form) {
    $$('[aria-invalid="true"]', form).forEach(function (i) { i.removeAttribute('aria-invalid'); });
    $$('[data-field-error]', form).forEach(function (n) { n.textContent = ''; n.hidden = true; });
    const fe = $('[data-form-error]', form);
    if (fe) { fe.textContent = ''; fe.hidden = true; }
  };

  const showErrors = function (form, body) {
    const errors = (body && body.field_errors) || {};
    const leftover = [];
    let first = null;
    Object.keys(errors).forEach(function (name) {
      const wrap = form.querySelector('[data-field-name="' + name + '"]');
      const slot = wrap && wrap.querySelector('[data-field-error]');
      const input = wrap && wrap.querySelector('input, textarea, select');
      if (!slot || !input) { leftover.push(errors[name]); return; }
      slot.textContent = errors[name];
      slot.hidden = false;
      input.setAttribute('aria-invalid', 'true');
      if (!first) first = input;
    });
    if (!Object.keys(errors).length) {
      leftover.push((body && body.error) || 'Could not save. Please try again.');
    }
    const fe = $('[data-form-error]', form);
    if (fe && leftover.length) { fe.textContent = leftover.join(' '); fe.hidden = false; }
    if (first) first.focus();
  };

  // opts: { save(addAnother) -> Promise, close() }
  const wireForm = function (form, opts) {
    const state = { dirty: false };
    let busy = false;
    let addAnother = false;
    openForms.add(state);
    const markDirty = function () { state.dirty = true; };
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);

    const close = function () { openForms.delete(state); opts.close(); };
    const cancel = function () {
      if (busy) return;
      if (!state.dirty) { close(); return; }
      confirm({
        title: 'Discard your changes?',
        body: 'What you typed here has not been saved.',
        okText: 'Discard',
        cancelText: 'Keep editing',
      }).then(function (ok) { if (ok) close(); });
    };

    form.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault(); e.stopPropagation(); cancel();
      } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault(); form.requestSubmit();
      }
    });
    $('[data-form-cancel]', form).addEventListener('click', cancel);
    // A plain button, so Enter in a field always means "Save".
    const another = $('[data-form-another]', form);
    if (another) {
      another.addEventListener('click', function () {
        addAnother = true;
        form.requestSubmit();
        addAnother = false;
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      const thenAnother = addAnother;
      clearErrors(form);
      const saveBtn = $('[data-form-save]', form);
      const label = saveBtn.textContent;
      busy = true;
      form.setAttribute('aria-busy', 'true');
      $$('button', form).forEach(function (b) { b.disabled = true; });
      saveBtn.textContent = 'Saving…';
      opts.save(thenAnother).then(function () {
        openForms.delete(state);
      }, function (body) {
        busy = false;
        form.removeAttribute('aria-busy');
        $$('button', form).forEach(function (b) { b.disabled = false; });
        saveBtn.textContent = label;
        showErrors(form, body);
      });
    });
  };

  const formFooter = function (opts) {
    const foot = el('div', 'inline-form-foot');
    const err = el('p', 'inline-form-error');
    err.setAttribute('data-form-error', '');
    err.setAttribute('role', 'alert');
    err.hidden = true;
    const actions = el('div', 'form-actions');
    const cancel = el('button', 'btn btn-ghost btn-sm', 'Cancel');
    cancel.type = 'button';
    cancel.setAttribute('data-form-cancel', '');
    actions.appendChild(cancel);
    if (opts && opts.another) {
      const another = el('button', 'btn btn-secondary btn-sm', 'Save and add another');
      another.type = 'button';
      another.setAttribute('data-form-another', '');
      actions.appendChild(another);
    }
    const save = el('button', 'btn btn-primary btn-sm', 'Save');
    save.type = 'submit';
    save.setAttribute('data-form-save', '');
    actions.appendChild(save);
    foot.appendChild(err);
    if (opts && opts.note) foot.appendChild(el('span', 'form-note', opts.note));
    foot.appendChild(actions);
    return foot;
  };

  const addCounter = function (wrap, input, max) {
    const counter = el('span', 'counter');
    const update = function () {
      const len = input.value.length;
      counter.textContent = len + ' / ' + max;
      counter.classList.toggle('is-warning', len >= max * 0.9);
    };
    input.addEventListener('input', update);
    update();
    wrap.appendChild(counter);
  };

  // ------------------------------------------------- completion + counts
  const stepDone = function (key) {
    if (key === 'photo') return hasPhoto;
    const parts = key.split(':');
    if (parts[0] === 'basics') return !!basicValues[parts[1]];
    return liveRows(parts[1]).length > 0;
  };

  const refreshProgress = function () {
    const steps = $$('[data-step]');
    let done = 0, shown = 0;
    steps.forEach(function (btn) {
      const isDone = stepDone(btn.getAttribute('data-step'));
      if (isDone) done++;
      // Offer at most three next steps at a time.
      const show = !isDone && shown < 3;
      if (show) shown++;
      btn.hidden = !show;
    });
    const pct = steps.length ? Math.round(done / steps.length * 100) : 0;
    const bar = $('[data-overall-bar]');
    if (bar) bar.setAttribute('aria-valuenow', String(pct));
    const fill = $('[data-overall-fill]');
    if (fill) fill.style.width = pct + '%';
    const text = $('[data-overall-text]');
    if (text) text.textContent = pct + '%';
    const detail = $('[data-overall-detail]');
    if (detail) {
      detail.textContent = done === steps.length
        ? 'Every section is filled in. Nice work.'
        : done + ' of ' + steps.length + ' done. Suggested next:';
    }

    const keys = Object.keys(basicDefs);
    const filled = keys.filter(function (k) { return !!basicValues[k]; }).length;
    const link = $('[data-section-nav] [data-section-link="basics"]');
    if (link) {
      link.classList.toggle('is-complete', filled === keys.length);
      $('[data-nav-count]', link).textContent = filled + '/' + keys.length;
    }
  };

  const refreshSection = function (key) {
    const sec = sectionEl(key);
    if (!sec) return;
    const n = liveRows(key).length;
    const count = $('[data-section-count]', sec);
    if (count) { count.textContent = n + (n === 1 ? ' entry' : ' entries'); count.hidden = n === 0; }
    const empty = $('[data-section-empty]', sec);
    if (empty) empty.hidden = n > 0 || !!$('[data-draft]', sec);
    const link = $('[data-section-nav] [data-section-link="' + key + '"]');
    if (link) {
      link.classList.toggle('is-complete', n > 0);
      const navCount = $('[data-nav-count]', link);
      navCount.textContent = String(n);
      navCount.hidden = n === 0;
    }
    refreshProgress();
  };

  // ------------------------------------------------------------- basics
  const renderBasics = function (card) {
    const field = card.getAttribute('data-field');
    const def = basicDefs[field];
    const value = basicValues[field] || '';
    const display = $('[data-value-display]', card);
    display.textContent = value || def.empty;
    display.classList.toggle('muted', !value);
    card.toggleAttribute('data-empty', !value);
    const btn = $('[data-value-action="edit"]', card);
    const verb = value ? 'Edit' : 'Add';
    $('[data-action-label]', btn).textContent = verb;
    btn.setAttribute('aria-label', verb + ' ' + def.label.toLowerCase());
    if (field === 'profession') {
      const headline = $('[data-hero-profession]');
      if (headline) { headline.textContent = value; headline.hidden = !value; }
    }
  };

  const editBasics = function (card) {
    const existing = $('form', card);
    if (existing) { focusQuietly($('input, textarea', existing)); return; }
    const field = card.getAttribute('data-field');
    const def = basicDefs[field];
    if (!def) return;
    const body = $('[data-value-body]', card);
    const actions = $('[data-value-actions]', card);

    const form = el('form', 'inline-form');
    const wrap = el('div', 'field');
    wrap.setAttribute('data-field-name', field);
    const input = def.type === 'textarea' ? el('textarea', 'textarea') : el('input', 'input');
    if (def.type === 'textarea') input.rows = def.rows || 4;
    else input.type = def.type;
    input.maxLength = def.max;
    input.placeholder = def.placeholder || '';
    input.value = basicValues[field] || '';
    input.setAttribute('aria-labelledby', 'lbl-' + field);
    const errId = 'pf-err-' + (++uid);
    input.setAttribute('aria-describedby', errId);
    wrap.appendChild(input);
    const under = el('div', 'field-under');
    if (def.hint) under.appendChild(el('span', 'hint', def.hint));
    if (def.type === 'textarea') addCounter(under, input, def.max);
    if (under.childNodes.length) wrap.appendChild(under);
    const err = el('p', 'field-error');
    err.id = errId;
    err.setAttribute('data-field-error', '');
    err.hidden = true;
    wrap.appendChild(err);
    form.appendChild(wrap);
    form.appendChild(formFooter());

    const close = function () {
      form.remove();
      body.hidden = false;
      actions.hidden = false;
      card.classList.remove('is-editing');
      focusQuietly($('[data-value-action="edit"]', card));
    };
    wireForm(form, {
      close: close,
      save: function () {
        return api('update_basics', { field: field, value: input.value }, { quiet: true }).then(function (res) {
          basicValues[field] = res.value || '';
          renderBasics(card);
          close();
          refreshProgress();
          toast(def.label + (res.value ? ' saved.' : ' cleared.'), { type: 'success' });
        });
      },
    });

    card.classList.add('is-editing');
    body.hidden = true;
    actions.hidden = true;
    card.appendChild(form);
    reveal(card);
    focusQuietly(input);
    if (input.type !== 'textarea' && input.select) input.select();
  };

  // --------------------------------------------------------- row forms
  // Build the form for one entry of `section` from the field metadata.
  // Returns { form, read() } where read() gives the fields to POST.
  const buildRowForm = function (section, row, isNew) {
    const sec = sectionDefs[section];
    const title = (isNew ? 'New ' : 'Edit ') + sec.singular.toLowerCase();
    const form = el('form', 'inline-form');
    form.setAttribute('aria-label', title);
    form.appendChild(el('p', 'inline-form-title', title));
    const grid = el('div', 'form-grid');
    const inputs = {};
    let anyRequired = false;

    sec.fields.forEach(function (f) {
      const id = 'pf-f-' + (++uid);
      const wrap = el('div', 'field');
      wrap.setAttribute('data-field-name', f.name);
      const wide = f.type === 'textarea' || f.type === 'check' || ((f.type === 'text' || f.type === 'url') && f.max >= 200);
      if (wide) wrap.classList.add('is-full');
      const value = row && row[f.name] !== null && row[f.name] !== undefined ? String(row[f.name]) : '';
      let input;

      if (f.type === 'check') {
        const lbl = el('label', 'check');
        input = el('input');
        input.type = 'checkbox';
        input.checked = !!row && !!Number(row[f.name]);
        lbl.appendChild(input);
        lbl.appendChild(el('span', '', f.label));
        wrap.appendChild(lbl);
      } else {
        const lbl = el('label', 'label', f.label);
        lbl.htmlFor = id;
        if (f.required) {
          anyRequired = true;
          const star = el('span', 'req', ' *');
          star.setAttribute('aria-hidden', 'true');
          lbl.appendChild(star);
        }
        wrap.appendChild(lbl);
        if (f.type === 'textarea') {
          input = el('textarea', 'textarea');
          input.rows = f.rows || 4;
        } else if (f.type === 'select') {
          input = el('select', 'select');
          let known = false;
          f.options.forEach(function (o) {
            const opt = el('option', '', o[1]);
            opt.value = o[0];
            if (o[0] === value) known = true;
            input.appendChild(opt);
          });
          if (!known && value) {
            // Keep a stored value the option list no longer offers.
            const opt = el('option', '', value);
            opt.value = value;
            input.appendChild(opt);
          }
        } else {
          input = el('input', 'input');
          if (f.type === 'year') {
            input.type = 'number';
            input.min = '1900'; input.max = '2100'; input.step = '1';
            input.inputMode = 'numeric';
            input.placeholder = 'YYYY';
          } else {
            input.type = ['date', 'url', 'email', 'tel', 'number'].indexOf(f.type) >= 0 ? f.type : 'text';
          }
        }
        input.value = value;
        if (f.placeholder) input.placeholder = f.placeholder;
        if (f.max > 0 && f.type !== 'year') input.maxLength = f.max;
        if (f.required) input.required = true;
        Object.keys(f.attrs || {}).forEach(function (k) {
          input.setAttribute(k, k === 'enterkeyhint' ? String(f.attrs[k]).toLowerCase() : f.attrs[k]);
        });
        wrap.appendChild(input);
      }
      input.id = id;
      input.name = f.name;
      inputs[f.name] = input;

      const describedBy = [];
      if (f.help || (f.type === 'textarea' && f.max > 0)) {
        const under = el('div', 'field-under');
        if (f.help) {
          const help = el('span', 'hint', f.help);
          help.id = id + '-help';
          describedBy.push(help.id);
          under.appendChild(help);
        }
        if (f.type === 'textarea' && f.max > 0) addCounter(under, input, f.max);
        wrap.appendChild(under);
      }
      const err = el('p', 'field-error');
      err.id = id + '-err';
      err.setAttribute('data-field-error', '');
      err.hidden = true;
      describedBy.push(err.id);
      wrap.appendChild(err);
      input.setAttribute('aria-describedby', describedBy.join(' '));
      grid.appendChild(wrap);
    });
    form.appendChild(grid);

    // "I currently work here" makes the end date meaningless.
    if (inputs.is_current && inputs.end_date) {
      const sync = function () {
        inputs.end_date.disabled = inputs.is_current.checked;
        if (inputs.is_current.checked) inputs.end_date.value = '';
      };
      inputs.is_current.addEventListener('change', sync);
      sync();
    }

    form.appendChild(formFooter({
      another: isNew && sec.tags,
      note: anyRequired ? '* Required' : '',
    }));

    const read = function () {
      const out = {};
      Object.keys(inputs).forEach(function (name) {
        const i = inputs[name];
        out[name] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value;
      });
      return out;
    };
    // Catch an end before its start here; the server only checks formats.
    const orderError = function () {
      const pairs = [['start_year', 'end_year', 'End year'], ['start_date', 'end_date', 'End date'], ['issue_date', 'expiry_date', 'Expiry date']];
      for (let i = 0; i < pairs.length; i++) {
        const a = inputs[pairs[i][0]], b = inputs[pairs[i][1]];
        if (a && b && a.value && b.value && b.value < a.value) {
          const out = {};
          out[pairs[i][1]] = pairs[i][2] + ' can’t be earlier than the start.';
          return { field_errors: out };
        }
      }
      return null;
    };
    return { form: form, read: read, orderError: orderError, first: inputs[sec.fields[0].name] };
  };

  const rowFromHtml = function (html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  };
  const flash = function (node) {
    node.classList.add('is-flash');
    setTimeout(function () { node.classList.remove('is-flash'); }, 1600);
  };
  const remember = function (section, row) {
    if (!rowData[section]) rowData[section] = {};
    rowData[section][String(row.id)] = row;
  };

  const editRow = function (row) {
    const existing = $('form', row);
    if (existing) { focusQuietly($('input, textarea, select', existing)); return; }
    const section = row.getAttribute('data-section');
    const id = row.getAttribute('data-row-id');
    const data = (rowData[section] || {})[id];
    if (!data || !sectionDefs[section]) {
      toast('This entry could not be opened. Reload the page and try again.', { type: 'error' });
      return;
    }
    const built = buildRowForm(section, data, false);
    const summary = $('[data-value-body]', row);
    const actions = $('[data-value-actions]', row);
    const close = function () {
      built.form.remove();
      summary.hidden = false;
      actions.hidden = false;
      row.classList.remove('is-editing');
      focusQuietly($('[data-value-action="edit"]', row));
    };
    wireForm(built.form, {
      close: close,
      save: function () {
        const bad = built.orderError();
        if (bad) return Promise.reject(bad);
        return api('update_row', { section: section, id: id, fields: built.read() }, { quiet: true }).then(function (res) {
          remember(section, res.row);
          const fresh = rowFromHtml(res.html);
          row.replaceWith(fresh);
          flash(fresh);
          focusQuietly($('[data-value-action="edit"]', fresh));
          toast('Saved.', { type: 'success' });
        });
      },
    });
    row.classList.add('is-editing');
    summary.hidden = true;
    actions.hidden = true;
    row.appendChild(built.form);
    reveal(row);
    focusQuietly(built.first);
  };

  // Adding opens a draft form. Nothing is written until Save, so
  // cancelling never leaves an empty entry behind.
  const addRow = function (section) {
    const list = listEl(section);
    if (!list || !sectionDefs[section]) return;
    const open = $('[data-draft]', list);
    if (open) { reveal(open); focusQuietly($('input, textarea, select', open)); return; }

    const built = buildRowForm(section, null, true);
    const draft = el('div', 'draft');
    draft.setAttribute('data-draft', '');
    draft.appendChild(built.form);
    const close = function () {
      draft.remove();
      refreshSection(section);
    };
    wireForm(built.form, {
      close: function () {
        close();
        focusQuietly($('[data-section-head] [data-value-action="add"]', sectionEl(section)));
      },
      save: function (thenAnother) {
        const bad = built.orderError();
        if (bad) return Promise.reject(bad);
        return api('add_row', { section: section, mode: 'fill', fields: built.read() }, { quiet: true }).then(function (res) {
          remember(section, res.row);
          const fresh = rowFromHtml(res.html);
          draft.replaceWith(fresh);
          refreshSection(section);
          flash(fresh);
          toast(sectionDefs[section].singular + ' added.', { type: 'success' });
          if (thenAnother) addRow(section);
          else focusQuietly($('[data-value-action="edit"]', fresh));
        });
      },
    });
    list.appendChild(draft);
    refreshSection(section);
    reveal(draft);
    focusQuietly(built.first);
  };

  const duplicateRow = function (row, btn) {
    const section = row.getAttribute('data-section');
    btn.disabled = true;
    api('duplicate_row', { section: section, id: row.getAttribute('data-row-id') }).then(function (res) {
      btn.disabled = false;
      remember(section, res.row);
      const fresh = rowFromHtml(res.html);
      row.after(fresh);
      refreshSection(section);
      toast('Copy created. Edit it to make it different.', { type: 'success' });
      editRow(fresh);
    }, function () { btn.disabled = false; });
  };

  // Delete right away, then offer Undo. Undo re-creates the entry in
  // the same place (it gets a new id).
  const deleteRow = function (row, btn) {
    const section = row.getAttribute('data-section');
    const id = row.getAttribute('data-row-id');
    const name = row.getAttribute('data-row-name') || 'Entry';
    btn.disabled = true;
    api('delete_row', { section: section, id: id }).then(function (res) {
      const next = row.nextElementSibling;
      const neighbour = next && next.matches('[data-value-row]') ? next : row.previousElementSibling;
      row.remove();
      delete (rowData[section] || {})[id];
      refreshSection(section);
      focusQuietly((neighbour && $('[data-value-action="edit"]', neighbour)) ||
                   $('[data-section-head] [data-value-action="add"]', sectionEl(section)));

      const old = res.row;
      const undo = function () {
        const fields = {};
        sectionDefs[section].fields.forEach(function (f) { fields[f.name] = old[f.name]; });
        api('add_row', { section: section, mode: 'fill', fields: fields, sort_order: old.sort_order }).then(function (r2) {
          remember(section, r2.row);
          const fresh = rowFromHtml(r2.html);
          const list = listEl(section);
          if (next && next.parentNode === list) list.insertBefore(fresh, next);
          else list.insertBefore(fresh, $('[data-draft]', list));
          refreshSection(section);
          flash(fresh);
          focusQuietly($('[data-value-action="edit"]', fresh));
        });
      };
      toast('Deleted “' + name + '”.', {
        duration: 8000,
        actions: old ? [{ label: 'Undo', onClick: undo }] : [],
      });
    }, function () { btn.disabled = false; });
  };

  // -------------------------------------------------------------- photo
  const zone = $('[data-photo-zone]');
  const photoInput = $('[data-photo-input]');
  const setPhoto = function (src) {
    hasPhoto = !!src;
    const img = $('[data-header-avatar]');
    const placeholder = $('[data-header-avatar-placeholder]');
    if (src) img.src = src; else img.removeAttribute('src');
    img.hidden = !src;
    placeholder.hidden = !!src;
    $('[data-value-action="clear-photo"]').hidden = !src;
    $('[data-photo-label]').textContent = src ? 'Change photo' : 'Upload photo';
    $('[data-photo-btn]').setAttribute('aria-label', src ? 'Change photo' : 'Upload a photo');
    refreshProgress();
  };
  const uploadPhoto = function (file) {
    if (!file) return;
    if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) {
      toast('That file type is not supported. Choose a JPG, PNG, WEBP or GIF image.', { type: 'error', duration: 6000 });
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      toast('That image is ' + (file.size / 1048576).toFixed(1) + ' MB. The limit is 2 MB.', { type: 'error', duration: 6000 });
      return;
    }
    zone.classList.add('is-busy');
    api('update_photo', { photo: file }, { quiet: true }).then(function (res) {
      setPhoto(res.value);
      toast('Photo updated.', { type: 'success' });
    }, function (body) {
      const msg = (body.field_errors && body.field_errors.photo) || body.error || 'The photo could not be uploaded.';
      toast(msg, { type: 'error', duration: 6000 });
    }).then(function () { zone.classList.remove('is-busy'); });
  };
  const clearPhoto = function () {
    confirm({
      title: 'Remove your photo?',
      body: 'Your initial will be shown instead. You can upload a new photo any time.',
      okText: 'Remove photo',
    }).then(function (ok) {
      if (!ok) return;
      api('clear_photo', {}).then(function () {
        setPhoto(null);
        toast('Photo removed.', { type: 'success' });
      }, function () {});
    });
  };
  if (photoInput) {
    photoInput.addEventListener('change', function () {
      uploadPhoto(photoInput.files && photoInput.files[0]);
      photoInput.value = '';
    });
  }
  if (zone) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-dragover'); });
    });
    zone.addEventListener('dragleave', function (e) {
      if (!zone.contains(e.relatedTarget)) zone.classList.remove('is-dragover');
    });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('is-dragover');
      uploadPhoto(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]);
    });
  }

  // ------------------------------------------------------------ clicks
  page.addEventListener('click', function (e) {
    const step = e.target.closest('[data-step]');
    if (step) {
      const parts = step.getAttribute('data-step').split(':');
      if (parts[0] === 'photo') photoInput.click();
      else if (parts[0] === 'basics') editBasics($('[data-value-card][data-field="' + parts[1] + '"]'));
      else addRow(parts[1]);
      return;
    }
    const btn = e.target.closest('[data-value-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-value-action');
    const card = btn.closest('[data-value-card]');
    const row = btn.closest('[data-value-row]');
    if (action === 'edit-photo') photoInput.click();
    else if (action === 'clear-photo') clearPhoto();
    else if (action === 'add') addRow(btn.getAttribute('data-section'));
    else if (action === 'edit' && card) editBasics(card);
    else if (action === 'edit' && row) editRow(row);
    else if (action === 'duplicate' && row) duplicateRow(row, btn);
    else if (action === 'delete' && row) deleteRow(row, btn);
  });

  // -------------------------------------------------- section navigation
  const links = $$('[data-section-link]');
  const navBar = $('[data-section-nav]');
  const setActive = function (key) {
    links.forEach(function (a) {
      const on = a.getAttribute('data-section-link') === key;
      a.setAttribute('aria-current', on ? 'true' : 'false');
      if (on && navBar && navBar.scrollWidth > navBar.clientWidth) {
        navBar.scrollTo({ left: a.offsetLeft - (navBar.clientWidth - a.offsetWidth) / 2, behavior: reducedMotion ? 'auto' : 'smooth' });
      }
    });
  };
  links.forEach(function (a) {
    a.addEventListener('click', function (e) {
      const target = sectionEl(a.getAttribute('data-section-link'));
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
    const io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) inBand.add(en.target); else inBand.delete(en.target);
      });
      const current = sectionEls.filter(function (s) { return inBand.has(s); })[0];
      if (current) setActive(current.getAttribute('data-section'));
    }, { rootMargin: '-15% 0% -70% 0%', threshold: 0 });
    sectionEls.forEach(function (s) { io.observe(s); });
  }

  // /profile.php?step=<key> (dashboard "next step" links) opens that editor.
  const wanted = new URLSearchParams(window.location.search).get('step');
  if (wanted) {
    history.replaceState(null, '', window.location.pathname);
    const parts = wanted.split(':');
    if (parts[0] === 'photo') {
      // A file picker cannot be opened without a click; point at the button.
      const btn = $('[data-photo-upload]');
      reveal(btn); focusQuietly(btn);
    } else if (parts[0] === 'basics' && basicDefs[parts[1]]) {
      editBasics($('[data-value-card][data-field="' + parts[1] + '"]'));
    } else if (parts[0] === 'section' && sectionDefs[parts[1]]) {
      addRow(parts[1]);
    }
  }
}
