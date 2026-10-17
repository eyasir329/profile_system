<?php
declare(strict_types=1);
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ui.php';

// Variables the calling page can set BEFORE including this:
//   $page_title   (string)    — used for <title>
//   $page_id      (string)    — used to mark the active nav link
//   $body_class   (string)    — extra classes for <body>
//   $flash        (array)     — array of ['type'=>'success','title'=>...,'body'=>...,'duration'=>...]
//   $hide_topbar  (bool)      — brand-only bar, for the sign-in flow
//   $hide_footer  (bool)
//
// Layout: a sticky top bar (brand, primary links, role switcher, account
// menu), then <main>. Below the md breakpoint the links, the role switcher
// and the account actions move into one drawer under the bar.
// Behaviour is Alpine (resources/js/ui.js); styles are in
// resources/css/components/navigation.css.

$page_title   = $page_title   ?? 'Profile System';
$page_id      = $page_id      ?? '';
$body_class   = $body_class   ?? '';
$hide_topbar  = $hide_topbar  ?? false;
$hide_footer  = $hide_footer  ?? false;
$flash        = $flash        ?? [];

$loggedIn = is_logged_in();
$role     = current_user_role();
$myName   = current_user_name();
$myId     = current_user_id();
$isTeacher = $loggedIn && (has_role('teacher') || has_role('admin'));

// If the user is logged in, ensure their active role is still in their
// current role set. Runs after is_logged_in() so it has a real user_id.
// (No-op for guests — see auth.php.)
if ($loggedIn) {
    ensure_active_role_valid();
    // Refresh local vars after the helper may have updated the session.
    $role      = current_user_role();
    $activeRole = active_user_role();
    $isTeacher = $loggedIn && (has_role('teacher') || has_role('admin'));
} else {
    $activeRole = '';
}

// Multi-role users get a switcher in the nav. Single-role users do not
// (no point showing a one-item dropdown).
$myRoles       = $loggedIn ? current_user_roles() : [];
$showSwitcher  = $loggedIn && count($myRoles) > 1;
$displayOrder  = ['admin', 'teacher', 'student'];

/**
 * Generalized navbar.
 *
 * Each role gets its own list of nav items. The navbar is rendered
 * from the ACTIVE role (not the highest-privilege role) so that a
 * user "acting as student" does NOT see the Admin link just because
 * they happen to also hold the admin role.
 *
 * Each role sees only what it works with:
 *   student  Dashboard, My profile (their lab details), Resources (read-only)
 *   teacher  Dashboard, Students (the sheet), Resources (edit), Invitations
 *   admin    Dashboard, Users, Resources (edit), Invitations
 * Settings and Log out live in the account menu at the right end of the
 * bar, along with "My profile": lab details while acting as a student,
 * a plain account page (no lab details) while acting as teacher or admin.
 */
$NAV_ITEMS_BY_ROLE = [
    'student' => [
        ['href' => 'dashboard',   'label' => 'Dashboard',   'page' => 'dashboard'],
        ['href' => 'profile',     'label' => 'My profile',  'page' => 'profile'],
        ['href' => 'resources',   'label' => 'Resources',   'page' => 'resources'],
    ],
    'teacher' => [
        ['href' => 'dashboard',   'label' => 'Dashboard',   'page' => 'dashboard'],
        ['href' => 'students',    'label' => 'Students',    'page' => 'students'],
        ['href' => 'resources',   'label' => 'Resources',   'page' => 'resources'],
        ['href' => 'invites',     'label' => 'Invitations', 'page' => 'invites'],
    ],
    'admin' => [
        ['href' => 'dashboard',   'label' => 'Dashboard',   'page' => 'dashboard'],
        ['href' => 'admin',       'label' => 'Users',       'page' => 'admin'],
        ['href' => 'resources',   'label' => 'Resources',   'page' => 'resources'],
        ['href' => 'invites',     'label' => 'Invitations', 'page' => 'invites'],
    ],
];

/**
 * Resolve a nav-item href key (e.g. 'dashboard') to a full URL via
 * the url_*() helpers. Kept as a small map so adding a new nav link
 * is a one-line change.
 */
$NAV_HREF_FOR = [
    'dashboard' => 'url_dashboard',
    'students'  => 'url_students',
    'profile'   => 'url_profile',
    'settings'  => 'url_settings',
    'admin'     => 'url_admin',
    'invites'   => 'url_invites',
    'resources' => 'url_resources',
];

/**
 * Decide which nav-item list to show for the current request.
 *  - Logged-out users: no nav items.
 *  - Logged-in users: use the active role's list. If the active role
 *    is somehow empty (shouldn't happen — ensure_active_role_valid
 *    guarantees a valid role), fall back to the highest-privilege
 *    role they hold so the page still works.
 */
function nav_items_for_active_role(?string $activeRole, array $roles, array $byRole): array {
    if (!$roles) return [];
    if ($activeRole !== '' && isset($byRole[$activeRole])) {
        return $byRole[$activeRole];
    }
    // Fall back to highest-privilege available.
    foreach (['admin', 'teacher', 'student'] as $r) {
        if (in_array($r, $roles, true) && isset($byRole[$r])) {
            return $byRole[$r];
        }
    }
    return [];
}
$navItems = $loggedIn ? nav_items_for_active_role($activeRole, $myRoles, $NAV_ITEMS_BY_ROLE) : [];

$assetBase = app_base() . '/assets';
// Cache-bust the built assets by modification time, so a new build is
// picked up without a hard refresh.
$assetVer = function (string $rel): string {
    $file = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/assets/' . $rel;
    if (!is_file($file)) { $file = __DIR__ . '/../../public/assets/' . $rel; }
    return is_file($file) ? '?v=' . filemtime($file) : '';
};

// Theme chosen in Settings → Appearance. settings.php mirrors it into this
// cookie; without the cookie the site stays on the light theme.
$themeAttr = in_array($_COOKIE['ps_theme'] ?? '', ['light', 'dark', 'auto'], true) ? (string)$_COOKIE['ps_theme'] : 'light';

// Resolve the nav list into [url, label, isCurrent] once; the bar and the
// small-screen drawer both render from it.
$navLinks = [];
foreach ($navItems as $item) {
    $fn = $NAV_HREF_FOR[$item['href']] ?? null;
    if ($fn === null) { continue; }
    $pid = (string)($item['page'] ?? '');
    $navLinks[] = [
        $fn(),
        (string)$item['label'],
        $pid !== '' && ($page_id === $pid || ($pid !== 'admin' && str_starts_with((string)$page_id, $pid))),
    ];
}
$sortedRoles = $myRoles;
usort($sortedRoles, function ($a, $b) use ($displayOrder) {
    $ia = array_search($a, $displayOrder, true);
    $ib = array_search($b, $displayOrder, true);
    return ($ia === false ? 999 : $ia) <=> ($ib === false ? 999 : $ib);
});
$myFirstName = explode(' ', trim($myName))[0] ?: 'Account';
$favicon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%23233a6b'/%3E%3Cpath d='M11 23V9h6.2a4.4 4.4 0 0 1 0 8.8H11' fill='none' stroke='%23fff' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E";
?>
<!doctype html>
<html lang="en" data-theme="<?= e($themeAttr) ?>"<?= $themeAttr === 'dark' ? ' class="dark"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#fbfaf8" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#161412" media="(prefers-color-scheme: dark)">
  <title><?= e($page_title) ?> · Profile System</title>
  <link rel="icon" href="<?= $favicon ?>">
<?php if ($themeAttr === 'auto'): ?>
  <script>if (matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.classList.add('dark');</script>
<?php endif; ?>
  <link rel="preload" href="<?= e($assetBase) ?>/fonts/ibm-plex-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= e($assetBase) ?>/css/app.css<?= $assetVer('css/app.css') ?>">
  <script defer src="<?= e($assetBase) ?>/js/app.js<?= $assetVer('js/app.js') ?>"></script>
</head>
<body class="<?= e($body_class) ?>" data-page="<?= e($page_id) ?>" x-data<?php if ($flash): ?> data-flash="<?= e(json_encode($flash)) ?>"<?php endif; ?>>
<a class="skip-link" href="#main">Skip to main content</a>

<?php if ($hide_topbar || !$loggedIn): ?>
<header class="navbar">
  <div class="navbar-inner">
    <a class="brand" href="<?= e(url_home()) ?>">
      <span class="brand-mark" aria-hidden="true">P</span>
      <span>Profile System</span>
    </a>
    <?php if (!$hide_topbar && $page_id !== 'login'): ?>
      <div class="nav-end">
        <a class="btn btn-secondary btn-sm" href="<?= e(url_login()) ?>">Log in</a>
      </div>
    <?php endif; ?>
  </div>
</header>
<?php else: ?>
<header class="navbar" x-data="{ drawer: false }" @keydown.escape="drawer = false">
  <div class="navbar-inner">
    <a class="brand" href="<?= e(url_home()) ?>">
      <span class="brand-mark" aria-hidden="true">P</span>
      <span class="max-sm:sr-only">Profile System</span>
    </a>

    <nav class="nav-links" aria-label="Primary">
      <?php foreach ($navLinks as [$url, $label, $isCurrent]): ?>
        <a class="nav-link" href="<?= e($url) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="nav-end">
      <?php if ($showSwitcher): ?>
        <!-- Role switcher: which of your roles this session is acting as. -->
        <div class="menu-wrap max-md:hidden" x-data="dropdown" @keydown.escape.stop="close(true)" @click.outside="close()" @focusout="focusLeft($event)">
          <button type="button" class="menu-trigger" x-ref="trigger" @click="toggle()" aria-haspopup="menu" :aria-expanded="open.toString()" aria-expanded="false"
                  aria-label="Acting as <?= e(ucfirst($activeRole)) ?>. Switch role">
            <?= ui_role($activeRole) ?>
            <?= pf_icon('caret', 'caret') ?>
          </button>
          <div class="menu" role="menu" aria-label="Switch role" x-ref="menu" x-show="open" x-cloak x-transition.opacity.duration.100ms
               @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)">
            <p class="menu-label">Act as</p>
            <?php foreach ($sortedRoles as $r): ?>
              <form method="post" action="<?= e(url_switch_role()) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="role" value="<?= e($r) ?>">
                <button type="submit" class="menu-item" role="menuitemradio" aria-checked="<?= $r === $activeRole ? 'true' : 'false' ?>">
                  <?= ui_role($r) ?>
                  <?php if ($r === $activeRole): ?><?= pf_icon('check', 'check-mark') ?><?php endif; ?>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Account menu -->
      <div class="menu-wrap max-md:hidden" x-data="dropdown" @keydown.escape.stop="close(true)" @click.outside="close()" @focusout="focusLeft($event)">
        <button type="button" class="menu-trigger" x-ref="trigger" @click="toggle()" aria-haspopup="menu" :aria-expanded="open.toString()" aria-expanded="false">
          <?= ui_avatar(null, $myName, 'avatar-sm') ?>
          <span class="max-w-32 truncate"><?= e($myFirstName) ?></span>
          <?= pf_icon('caret', 'caret') ?>
        </button>
        <div class="menu" role="menu" aria-label="Account" x-ref="menu" x-show="open" x-cloak x-transition.opacity.duration.100ms
             @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)">
          <p class="menu-label truncate"><?= e($myName) ?></p>
          <a class="menu-item" role="menuitem" href="<?= e(url_profile()) ?>"><?= pf_icon('user') ?> My profile</a>
          <a class="menu-item" role="menuitem" href="<?= e(url_settings()) ?>"><?= pf_icon('settings') ?> Settings</a>
          <div class="menu-sep" role="separator"></div>
          <form method="post" action="<?= e(url_logout()) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="menu-item" role="menuitem"><?= pf_icon('logout') ?> Log out</button>
          </form>
        </div>
      </div>

      <button type="button" class="btn btn-ghost btn-icon nav-toggle" @click="drawer = !drawer"
              aria-controls="nav-drawer" :aria-expanded="drawer.toString()" aria-expanded="false" aria-label="Menu">
        <span x-show="!drawer"><?= pf_icon('menu') ?></span>
        <span x-show="drawer" x-cloak><?= pf_icon('close') ?></span>
      </button>
    </div>
  </div>

  <!-- Small screens: everything in one drawer -->
  <div class="nav-drawer" id="nav-drawer" x-show="drawer" x-cloak x-transition.opacity.duration.100ms>
    <nav aria-label="Primary">
      <?php foreach ($navLinks as [$url, $label, $isCurrent]): ?>
        <a href="<?= e($url) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($activeRole !== 'student'): ?>
        <a href="<?= e(url_profile()) ?>"<?= $page_id === 'profile' ? ' aria-current="page"' : '' ?>>My profile</a>
      <?php endif; ?>
      <a href="<?= e(url_settings()) ?>"<?= $page_id === 'settings' ? ' aria-current="page"' : '' ?>>Settings</a>
    </nav>
    <?php if ($showSwitcher): ?>
      <p class="nav-drawer-label">Act as</p>
      <?php foreach ($sortedRoles as $r): ?>
        <form method="post" action="<?= e(url_switch_role()) ?>">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="role" value="<?= e($r) ?>">
          <button type="submit" class="nav-drawer-item"<?= $r === $activeRole ? ' aria-current="true"' : '' ?>>
            <?= ui_role($r) ?>
            <?php if ($r === $activeRole): ?><span class="faint ml-auto text-sm font-normal">Current</span><?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
    <p class="nav-drawer-label truncate"><?= e($myName) ?></p>
    <form method="post" action="<?= e(url_logout()) ?>">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="nav-drawer-item"><?= pf_icon('logout') ?> Log out</button>
    </form>
  </div>
</header>
<?php endif; ?>

<main id="main" tabindex="-1">
