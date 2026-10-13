/*
 * Profile System front-end entry point.
 * Bundled by esbuild into public/assets/js/app.js (npm run build).
 *
 *   ui.js              Alpine stores, directives and small components that
 *                      every page can use (toasts, confirm dialog, menus...)
 *   profile-editor.js  the inline editor on /profile.php
 *
 * See docs/FRONTEND.md for how the pieces fit together.
 */
import Alpine from 'alpinejs';
import { registerUi, toast, confirm } from './ui.js';
import { initProfileEditor } from './profile-editor.js';

registerUi(Alpine);

// Small public API for inline scripts and for debugging in the console.
window.PS = { toast, confirm };
window.Alpine = Alpine;

Alpine.start();

function ready() {
  initProfileEditor();

  // Server-side validation failed: put the cursor in the first bad field.
  const firstInvalid = document.querySelector('main [aria-invalid="true"]');
  if (firstInvalid) {
    firstInvalid.focus({ preventScroll: true });
    firstInvalid.scrollIntoView({ block: 'center' });
  }

  // Flash messages rendered by header.php as JSON on <body data-flash>.
  const flash = document.body.getAttribute('data-flash');
  if (flash) {
    try {
      JSON.parse(flash).forEach((f) => toast(f));
    } catch (e) {
      /* malformed flash: nothing to show */
    }
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
else ready();
