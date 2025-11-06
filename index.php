<?php
// index.php — Event selection + Login/Browse, styled like the old index
declare(strict_types=1);

require_once __DIR__ . '/config.php'; // pulls in master DB constants (MASTER_SERVER, MASTER_DB, etc.)
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logging.php';

csrf_start_session_if_needed();

$errors = [];
$messages = [];

// $event_explicit indicates a chosen rather than defaulted event
$event_explicit = !empty($_SESSION['event_explicit']);

// Pull events list (name + description), and find the selected description
$events_list = list_events_from_master_with_status(); // [event_name, event_description, status]
$selected_event = ($event_explicit && defined('EVENT_NAME')) ? EVENT_NAME : '';
$selected_desc = '';
foreach ($events_list as $ev) {
    if ($ev['event_name'] === $selected_event) {
        $selected_desc = $ev['event_description'] ?? '';
        break;
    }
}

// Feature flags (only valid once config.php has loaded event constants)
$hasSched  = defined('EVENT_SCHEDULER_URL') && EVENT_SCHEDULER_URL !== '';
$hasLogger = defined('EVENT_LOGGER_URL')    && EVENT_LOGGER_URL    !== '';
$hasAdmin  = defined('EVENT_ADMIN_URL')     && EVENT_ADMIN_URL     !== '';
if (!$event_explicit) { $hasSched = $hasLogger = $hasAdmin = false; }

// --- POST handler ------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check_same_origin(/* optionally: ['admin.n7dz.net'] */)) {
        http_response_code(403);
        $msg = 'Blocked due to cross-origin request.';
        $errors[] = $msg;
        // Don’t mutate state; render page with error.
        exit($msg);
    }

    $action = $_POST['action'] ?? '';
    log_msg(DEBUG_INFO, "action is $action");

    if (in_array($action, ['identify','login','browse'], true)) {
        if (!csrf_validate($_POST['_csrf_key'] ?? null, $_POST['_csrf'] ?? null)) {
            http_response_code(403);
            exit('Invalid or expired form. Please try again.');
        }
    }

    if ($action === 'set_event') {
        $new_event = trim($_POST['event'] ?? '');
        if ($new_event === '') {
            // User intentionally selected the blank option: clear selection
            auth_initialize();
            $_SESSION['event_explicit'] = false;
        } else {
            auth_set_event($new_event);
            $_SESSION['event_explicit'] = true;
        }
        header('Location: index.php'); // PRG so config.php can re-define constants
        exit;
    }

    if ($action === 'browse') {
        if (!$selected_event || !$hasSched) {
            log_msg(DEBUG_INFO, "Scheduler not configured for $selected_event");
            $errors[] = 'Scheduler not configured for this event.';
        } else {
            // callsign/name optional for browse; keep if provided:
            $callsign = strtoupper(trim($_POST['callsign'] ?? (auth_get_callsign())));
            $name     = trim($_POST['name'] ?? (auth_get_name()));
            if ($callsign === '') $callsign = 'BROWSE';
            if ($name === '') $name = 'Browse';

            log_msg(DEBUG_INFO, "Launching browse-schedule for $selected_event to URL " . EVENT_SCHEDULER_URL);

            // refresh identify cache
            auth_set_browse($selected_event, $callsign, $name);
            header('Location: ' . EVENT_SCHEDULER_URL);
            exit;
        }
    }

    if ($action === 'identify') {
        $event    = trim($_POST['event'] ?? '');
        $callsign = strtoupper(trim($_POST['callsign'] ?? ''));
        $name     = trim($_POST['name'] ?? '');

        if ($event === '' || $callsign === '' || $name === '') {
            $errors[] = 'Please enter event, callsign, and name.';
        } else {
            $status = auth_status_for_callsign($event, $callsign); // 'exists' | 'new'
            $auth_set_event($event);
            $auth_set_callsign($callsign);
            $auth_set_name($name);
            if ($status === 'exists') {
                $messages[] = 'Password exists for this callsign. Enter it to continue.';
            } else {
                $messages[] = 'No password yet for this callsign. Enter and confirm a new password to create it now.';
            }
        }
    } 

    if ($action === 'login') {
        $callsign   = strtoupper(trim($_POST['callsign'] ?? ''));
        $name       = trim($_POST['name'] ?? '');
        $password   = (string)($_POST['password'] ?? '');
        $password2  = (string)($_POST['password2'] ?? '');
        $redirect_to = $_POST['redirect_to'] ?? 'scheduler'; // 'scheduler' | 'admin'

        if (!$selected_event) {
            $errors[] = 'Select an event first.';
        } elseif ($callsign === '' || $name === '') {
            $errors[] = 'Please enter callsign and name.';
        } elseif ($password === '') {
            $errors[] = 'Please enter a password.';
        } else {
            $status = auth_status_for_callsign($selected_event, $callsign);
            if ($status === 'new' && $password !== $password2) {
                $errors[] = 'Passwords do not match.';
            } else {
                if (auth_login_or_create($selected_event, $callsign, $name, $password)) {
                    $dest = null;
                    if ($redirect_to === 'admin') {
                        $dest = $hasAdmin ? EVENT_ADMIN_URL : null;
                    } else {
                        $dest = $hasSched ? EVENT_SCHEDULER_URL : null;
                    }
                    if ($dest) {
                        header('Location: ' . $dest);
                        exit;
                    }
                    $errors[] = 'Destination not configured for this event.';
                } else {
                    $errors[] = 'Incorrect password.';
                }
            }
        }
        // on error, re-render same page with messages
    }
}

$events   = list_events_from_master_with_status();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo htmlspecialchars(auth_get_event()); ?> Radio Society of Tucson Event Selection</title>
  <link rel="icon" type="image/png" sizes="32x32" href="img/cropped-RST-Logo-1-32x32.png">
  <link rel="apple-touch-icon" sizes="180x180" href="img/cropped-RST-Logo-1-180x180.png">
  <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">

  <link rel="stylesheet" href="scheduler.css"> <!-- keep your site stylesheet -->

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    /* Borrowed from the old index layout */
    .wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
    .banner{ display:block; height:144px; /* fixed visual height */ width:auto; /* don’t stretch full width */ margin:8px 0 16px 0;  /* no auto-centering */ }
    .card { border: 1px solid #ddd; border-radius: 10px; padding: 16px; }
    .row { display: grid; grid-template-columns: 1fr; gap: 12px; }
    @media (min-width: 720px) { .row { grid-template-columns: 1.2fr .8fr; } }
    .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top: 12px; }
    .btn { display:inline-block; padding:10px 14px; border-radius:10px; text-decoration:none; border:1px solid #999; cursor:pointer; }
    .btn.primary { background:#0069d9; color:#fff; border-color:#0060c7; }
    .btn.secondary { background:#1aa385; color:#fff; border-color:#179579; }
    .btn.ghost { background:#f2f2f2; color:#888; border-color:#ddd; cursor:not-allowed; }
    .btn.linklike { background:transparent; border:none; color:#06c; padding:0; }
    .muted { color:#666; }
    .err { background:#fee; color:#900; padding:8px; border-radius:6px; margin:8px 0; }
    .msg { background:#eef; color:#123; padding:8px; border-radius:6px; margin:8px 0; }
    label { font-weight:600; }
    input, select { padding:8px; font-size:16px; width:100%; box-sizing:border-box; }
  </style>
</head>
<body>
<div class="wrap">

  <img class="banner" src="img/RST-header-768x144.jpg" alt="Radio Society of Tucson">

  <div class="card">
    <?php foreach (($errors ?? []) as $e): ?>
      <div class="err"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php foreach (($messages ?? []) as $m): ?>
      <div class="msg"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <strong>Event selection and log-in<a href="how-do-i-use-this.php" target="_blank">&nbsp;&nbsp;&nbsp;(How do I use this?)</a></strong>
    <p class="muted" style="margin-top:4px;">Choose an event. Once selected, browse/log in options will appear below.</p>

    <?php
      // Event/description from config/session
      $selected_event = defined('EVENT_NAME') ? EVENT_NAME : ($auth_get_event());

      $events_list = list_events_from_master_with_status();
      $selected_desc = '';
      foreach ($events_list as $ev) {
          if ($ev['event_name'] === $selected_event) { $selected_desc = $ev['event_description'] ?? ''; break; }
      }

    ?>

    <!-- Event picker (names only) + separate description -->
    <form method="post" class="row" style="grid-template-columns: 1fr 1fr;">
      <?= csrf_input('set_event') ?>
      <input type="hidden" name="action" value="set_event">
      <div>
        <label for="event"><strong>Event</strong></label>
          <select id="event" name="event" required onchange="this.form.submit()">
            <option value="" <?= $event_explicit ? '' : 'selected' ?>>— Select an event —</option>
            <?php foreach ($events_list as $ev): ?>
              <option value="<?= htmlspecialchars($ev['event_name']) ?>" <?= ($event_explicit && $selected_event===$ev['event_name'])?'selected':'' ?>>
                <?= htmlspecialchars($ev['event_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
      </div>
      <div>
        <label><strong>Description</strong></label>
        <div class="muted" style="padding:8px;border:1px solid #eee;border-radius:8px;min-height:42px;">
            <?= $event_explicit ? htmlspecialchars($selected_desc ?: '—') : '—' ?>
        </div>
      </div>
    </form>

  <?php if ($event_explicit): ?>
      <!-- (2a) Browse & Logger (progressive: visible immediately after event selection) -->
      <div id="sec2a" class="actions" style="margin-top:14px; gap:14px; flex-wrap:wrap;">
        <?php if ($hasSched): ?>
          <!-- Browse: POST to set browse state, then redirect to scheduler URL -->
          <form method="post" style="display:inline;">
            <?= csrf_input('browse') ?>
            <input type="hidden" name="action" value="browse">
            <button class="btn secondary" type="submit">Browse Schedule</button>
          </form>
        <?php else: ?>
          <span class="btn ghost">Scheduler not configured.</span>
        <?php endif; ?>

        <?php if ($hasLogger): ?>
          <a class="btn secondary" href="<?= htmlspecialchars(EVENT_LOGGER_URL) ?>">Event Log System</a>
        <?php else: ?>
          <span class="btn ghost">Event Logger not configured</span>
        <?php endif; ?>
      </div>

      <!-- (2b) Callsign/Name (progressive: visible after event; password waits for probe) -->
      <?php
        $callsign = auth_get_callsign();
        $name     = auth_get_name();
      ?>
      <div id="secIdentity" class="row" style="margin-top:16px;">
        <div>
          <label for="callsign"><strong>Callsign</strong></label>
          <input id="callsign" name="callsign" value="<?= htmlspecialchars($callsign) ?>" autocomplete="username">
        </div>
        <div>
          <label for="name"><strong>Name</strong></label>
          <input id="name" name="name" value="<?= htmlspecialchars($name) ?>">
        </div>
      </div>

      <!-- Password area (hidden until callsign is probed) -->
      <div id="secPassword" style="display:none; margin-top:12px;">
        <div id="pwNew" style="display:none;">
          <div class="msg" style="margin-bottom:8px;">No password on file. Create one below.</div>
          <label for="password"><strong>Create password</strong></label>
          <input id="password" type="password" autocomplete="new-password">
          <label for="password2" style="margin-top:10px;"><strong>Confirm password</strong></label>
          <input id="password2" type="password" autocomplete="new-password">
        </div>
        <div id="pwExists" style="display:none;">
          <label for="passwordX"><strong>Password</strong></label>
          <input id="passwordX" type="password" autocomplete="current-password">
        </div>
      </div>

      <!-- (2c) Secured actions (hidden until callsign probed; login + go) -->
      <div id="secActions" class="actions" style="display:none; margin-top:12px; gap:14px; flex-wrap:wrap;">
        <?php if ($hasSched): ?>
          <button id="btnEdit" class="btn primary" type="button">Edit Schedule</button>
        <?php else: ?>
          <span class="btn ghost">Scheduler not configured.</span>
        <?php endif; ?>

        <?php if ($hasAdmin): ?>
          <button id="btnAdmin" class="btn" type="button">Event Admin</button>
        <?php else: ?>
          <span class="btn ghost">Admin not configured.</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <form id="loginForm" method="post" action="index.php" style="display:none;">
      <?= csrf_input('login') ?>
      <input type="hidden" name="action"      value="login">
      <input type="hidden" name="redirect_to" value="scheduler">
      <input type="hidden" name="callsign"    value="">
      <input type="hidden" name="name"        value="">
      <input type="hidden" name="password"    value="">
      <input type="hidden" name="password2"   value="">
    </form>

  </div>
</div>

<?php
// Robust, folder-safe URL to probe endpoint:
$probe_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/probe_call.php';
$js_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/cactus-index.js';
?>
<div id="cactus-config"
     data-event="<?= htmlspecialchars($event_explicit ? $selected_event : '') ?>"
     data-probe="<?= htmlspecialchars($probe_url) ?>">
</div>
<script src="<?= $js_url.'?v=3' ?>"></script>
</body>
</html>
