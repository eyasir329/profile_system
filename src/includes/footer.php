<?php
declare(strict_types=1);

// Closes <main>, then renders the footer and the two page-wide overlays:
// the toast stack ($store.toasts) and the confirm dialog ($store.confirm).
// Both are driven from resources/js/ui.js.

if (!empty($hide_footer)) { return; }
?>
</main>

<footer class="site-footer">
  <div class="site-footer-inner">
    <span>Profile System</span>
    <span>Accounts are by invitation · <a href="<?= e(url_for('README.txt')) ?>">About</a></span>
  </div>
</footer>

<div class="toast-region" role="region" aria-label="Notifications" aria-live="polite">
  <template x-for="t in $store.toasts.items" :key="t.id">
    <div class="toast" :class="'is-' + t.type" :role="t.type === 'error' ? 'alert' : 'status'"
         @mouseenter="$store.toasts.hold(t.id)" @mouseleave="$store.toasts.arm(t.id)"
         @focusin="$store.toasts.hold(t.id)" @focusout="$store.toasts.arm(t.id)"
         x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="translate-y-2 opacity-0">
      <span class="toast-mark" aria-hidden="true"></span>
      <div class="toast-msg">
        <strong x-show="t.title" x-text="t.title"></strong>
        <span x-text="t.body"></span>
      </div>
      <template x-for="a in t.actions" :key="a.label">
        <button type="button" class="toast-action" @click="$store.toasts.run(t.id, a)" x-text="a.label"></button>
      </template>
      <button type="button" class="toast-close" @click="$store.toasts.dismiss(t.id)" aria-label="Dismiss">
        <?= pf_icon('close') ?>
      </button>
    </div>
  </template>
</div>

<dialog class="modal" x-data="confirmDialog" aria-labelledby="confirm-title" aria-describedby="confirm-text"
        @cancel.prevent="$store.confirm.answer(false)" @click.self="$store.confirm.answer(false)">
  <div class="modal-body">
    <h2 class="modal-title" id="confirm-title" x-text="$store.confirm.title"></h2>
    <p class="modal-text" id="confirm-text" x-text="$store.confirm.body"></p>
  </div>
  <div class="modal-actions">
    <button type="button" class="btn btn-secondary" data-cancel @click="$store.confirm.answer(false)" x-text="$store.confirm.cancelText">Cancel</button>
    <button type="button" class="btn" data-ok :class="$store.confirm.tone === 'danger' ? 'btn-danger' : 'btn-primary'"
            @click="$store.confirm.answer(true)" x-text="$store.confirm.okText">Confirm</button>
  </div>
</dialog>

</body>
</html>
