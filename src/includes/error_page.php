<?php
declare(strict_types=1);

/**
 * Error pages: one renderer for every HTTP error the app or Apache returns.
 *
 *   render_error_page(404);
 *   render_error_page(403, 'The Students sheet is for teachers.');
 *   render_error_page(503, '', minimal: true);   // when the DB is down
 *
 * Normal mode uses the site header and footer, so a signed-in user keeps
 * the top bar. Minimal mode is a standalone page (brand bar only, no
 * session or database), for failures that happen before those exist.
 *
 * Apache sends its own errors (unknown URL, denied path) to
 * public/error.php, which calls this. See docker/apache/app.conf.
 */

/**
 * Show the 500 page for an uncaught exception or a fatal error, instead of
 * a blank page. The cause goes to the error log. Called from auth.php,
 * which every page includes. If the page had already started printing,
 * nothing more is added (the log still has the cause).
 */
function install_error_page_handlers(): void
{
    set_exception_handler(function (Throwable $e): void {
        error_log('Uncaught ' . $e);
        if (!headers_sent()) { render_error_page(500, '', minimal: true); }
    });
    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
            render_error_page(500, '', minimal: true);
        }
    });
}

/** Title, message and which actions fit, per status code. */
function error_page_info(int $code): array
{
    return match ($code) {
        400 => ['Bad request', 'The request could not be understood. Check the address, or go back and try again.'],
        401 => ['Sign-in needed', 'You need to sign in to see this page.'],
        403 => ['No access', 'Your account can’t open this page.'],
        404 => ['Page not found', 'There is nothing at this address. It may have been moved or removed, or the link may be mistyped.'],
        405 => ['Not allowed', 'This page can’t be used that way. Go back and use the buttons on the page.'],
        410 => ['No longer available', 'This link has expired or has already been used.'],
        419 => ['Page expired', 'This form was open too long, or you signed in somewhere else in the meantime. Go back, reload the page and try again. Nothing was saved.'],
        429 => ['Too many requests', 'Too many attempts in a short time. Wait a few minutes and try again.'],
        503 => ['Temporarily unavailable', 'The system is down for a moment, for maintenance or because the database is not answering. Try again in a few minutes.'],
        default => ['Something went wrong', 'An unexpected error stopped this page. Nothing you did caused it. Try again; if it keeps happening, tell an admin of your lab.'],
    };
}

/**
 * Send the status code and render the error page, then stop.
 *
 * @param string $detail  Optional extra sentence for this case (plain text).
 * @param bool   $minimal Standalone page without session, header or DB.
 */
function render_error_page(int $code, string $detail = '', bool $minimal = false): never
{
    if ($code < 400 || $code > 599) { $code = 500; }
    if (!headers_sent()) {
        // Apache does not know 419 and would turn it into a 500, so an
        // expired form is sent as 403 while the page still says "expired".
        http_response_code($code === 419 ? 403 : $code);
        header('Cache-Control: no-store');
        if ($code === 503) { header('Retry-After: 120'); }
    }
    [$title, $message] = error_page_info($code);

    if ($minimal) {
        error_page_minimal($code, $title, $message, $detail);
        exit;
    }

    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/url.php';
    require_once __DIR__ . '/ui.php';

    $loggedIn  = is_logged_in();
    $manyRoles = $loggedIn && count(current_user_roles()) > 1;
    // Where "home" is for this visitor.
    [$homeUrl, $homeLabel] = $loggedIn ? [url_dashboard(), 'Go to dashboard'] : [url_home(), 'Go to home page'];

    $page_title = $title;
    $page_id    = 'error';
    require __DIR__ . '/header.php';
    ?>
    <div class="page-narrow">
      <section class="card error-card" aria-labelledby="error-title">
        <p class="error-code">Error <?= $code ?></p>
        <h1 class="page-title" id="error-title"><?= e($title) ?></h1>
        <p class="page-desc"><?= e($message) ?></p>
        <?php if ($detail !== ''): ?><p class="mt-3 text-sm text-muted-foreground-2"><?= e($detail) ?></p><?php endif; ?>
        <?php if ($code === 403 && $manyRoles): ?>
          <p class="mt-3 text-sm text-muted-foreground-2">You hold more than one role. What you can open depends on the role you are acting as: switch it from the menu at the top right.</p>
        <?php endif; ?>

        <div class="error-actions">
          <?php if ($code === 401 || ($code === 419 && !$loggedIn)): ?>
            <a class="btn btn-primary" href="<?= e(url_login()) ?>">Log in</a>
          <?php elseif (in_array($code, [400, 405, 419], true)): ?>
            <button type="button" class="btn btn-primary" onclick="history.back()"><?= pf_icon('back') ?> Go back</button>
          <?php else: ?>
            <a class="btn btn-primary" href="<?= e($homeUrl) ?>"><?= e($homeLabel) ?></a>
          <?php endif; ?>
          <?php if (in_array($code, [500, 503, 429], true)): ?>
            <button type="button" class="btn btn-secondary" onclick="location.reload()">Try again</button>
          <?php endif; ?>
        </div>
        <p class="error-help">Stuck? See <a href="<?= e(url_help()) ?>">Help</a>.</p>
      </section>
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

/** Standalone page: works without a session, the header or the database. */
function error_page_minimal(int $code, string $title, string $message, string $detail): void
{
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $css = '/assets/css/app.css';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . $h($title) . ' · Profile System</title>'
        . '<link rel="stylesheet" href="' . $css . '"></head><body>'
        . '<header class="navbar"><div class="navbar-inner"><a class="brand" href="/">'
        . '<span class="brand-mark" aria-hidden="true">P</span><span>Profile System</span></a></div></header>'
        . '<main id="main"><div class="page-narrow"><section class="card error-card" aria-labelledby="error-title">'
        . '<p class="error-code">Error ' . $code . '</p>'
        . '<h1 class="page-title" id="error-title">' . $h($title) . '</h1>'
        . '<p class="page-desc">' . $h($message) . '</p>'
        . ($detail !== '' ? '<p class="mt-3 text-sm text-muted-foreground-2">' . $h($detail) . '</p>' : '')
        . '<div class="error-actions"><button type="button" class="btn btn-primary" onclick="location.reload()">Try again</button></div>'
        . '</section></div></main></body></html>';
}
