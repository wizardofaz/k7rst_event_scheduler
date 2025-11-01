<?php
// index.php — RST Event Selection

require_once __DIR__ . '/config.php';     // loads per-event constants based on ?event=... (or default)
require_once __DIR__ . '/event_db.php';   // list_events_from_master_with_status()
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/util.php';

// put something in the log if logging is enabled
log_msg(DEBUG_INFO, "Session start - Current session data: " . json_encode($_SESSION));

// Pull all events (names + descriptions + status)
$valid_events = list_events_from_master_with_status(); // each: ['event_name','event_description','status']

// Build a quick map for descriptions and status
$valid_events = list_events_from_master_with_status();
$desc_by_name = array_column($valid_events, 'event_description', 'event_name');
$status_by_name = array_column($valid_events, 'status', 'event_name');

// Reflect the event that config.php resolved
$selected_event = defined('EVENT_NAME') ? (string)EVENT_NAME : '';
if ($selected_event === '' || !isset($desc_by_name[$selected_event])) {
    $selected_event = count($desc_by_name) ? array_key_first($desc_by_name) : '';
}

// Use constants populated by load_event_config_constants(EVENT_NAME)
$wavelog_url   = make_url_relative(defined('EVENT_LOGGER_URL')    ? trim((string)EVENT_LOGGER_URL)    : '');
$scheduler_url = make_url_relative(defined('EVENT_SCHEDULER_URL') ? trim((string)EVENT_SCHEDULER_URL) : '');

// Display name (optional, if your config sets it; otherwise just show the event name)
$display_name = defined('EVENT_DISPLAY_NAME') ? (string)EVENT_DISPLAY_NAME : $selected_event;
$desc         = $desc_by_name[$selected_event] ?? '';
$status       = $status_by_name[$selected_event] ?? 'UNKNOWN';
if ($status != EVENT_HAS_SCHEDULE && $status != EVENT_EMPTY_SCHEDULE) {
    $desc = "Event failed validation";
    $wavelog_url = '';
    $scheduler_url = '';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($display_name); ?> — Radio Society of Tucson Event Selection</title>
    <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">
    <link rel="stylesheet" href="scheduler.css">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
      /* Minimal layout helpers; your scheduler.css will handle the rest */
      .wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
      .banner { display:block; align-items: start; width:100%; max-height:160px; object-fit:contain; margin: 8px 0 16px; }
      .card { border: 1px solid #ddd; border-radius: 10px; padding: 16px; }
      .row { display: grid; grid-template-columns: 1fr; gap: 12px; }
      @media (min-width: 720px) { .row { grid-template-columns: 1.2fr .8fr; } }
      .actions { display:flex; gap:10px; flex-wrap:wrap; align-items: center; }
      .btn { display:inline-block; padding:10px 14px; border-radius:10px; text-decoration:none; border:1px solid #999; }
      .btn.primary { background:#0069d9; color:#fff; border-color:#0060c7; }
      .btn.secondary { background:#0aa2a2; color:#fff; }
      .muted { color:#666; }
      textarea { width:100%; }
    </style>
</head>

<body>

<!-- If we got here by detecting an invalid event in the URL, let them know --> 
<?php if (!empty($_GET['invalid_event'])): ?>
  <div class="notice" style="margin:10px 0;padding:8px 12px;border:1px solid #c33;border-radius:8px;background:#fff5f5;color:#a00;">
    Event “<?= htmlspecialchars($_GET['invalid_event']) ?>” isn’t available. Please choose a valid event.
  </div>
<?php endif; ?>

<div class="wrap">

  <img class="banner" src="img/RST-header-768x144.jpg" alt="Radio Society of Tucson">

  <div class="card">
    <h1 style="margin-top:0;">Select an Event</h1>
    <p class="muted" style="margin-top:4px;">Choose an event to open the Scheduler and Logger for that event.</p>

    <!-- Event selector -->
    <form method="get" class="row" style="align-items:start; margin-top:8px;">
      <div>
        <label for="event"><strong>Event</strong></label>
        <select id="event" name="event" onchange="this.form.submit()" style="width:100%; padding:8px;">
          <?php if (empty($valid_events)): ?>
            <option value="">— no events found —</option>
          <?php else: ?>
            <?php foreach ($valid_events as $ev): ?>
              <?php $ename = $ev['event_name']; ?>
              <option value="<?php echo htmlspecialchars($ename); ?>"
                      <?php echo ($ename === $selected_event) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($ename); ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>

        <div class="actions" style="margin-top:12px;">

          <?php if ($scheduler_url !== ''): ?>
            <a class="btn primary"
               href="<?php echo htmlspecialchars($scheduler_url); ?>"
               target="_blank" rel="noopener">
               <?php echo 'Open ' . htmlspecialchars($selected_event) . ' Scheduler'; ?>
            </a>
          <?php else: ?>
            <span class="muted">Scheduler URL not configured for this event.</span>
          <?php endif; ?>

          <?php if ($wavelog_url !== ''): ?>
            <a class="btn secondary"
               href="<?php echo htmlspecialchars($wavelog_url); ?>"
               target="_blank" rel="noopener">
               <?php echo 'Open ' . htmlspecialchars($selected_event) . ' Event Logger'; ?>
            </a>
          <?php else: ?>
            <span class="muted">Event Logger URL not configured for this event.</span>
          <?php endif; ?>

        </div>
      </div>

      <!-- Right side: description -->
      <div>
        <strong>Description</strong>
        <div class="muted" style="margin-top:4px;"><?php echo nl2br(htmlspecialchars($desc)); ?></div>
      </div>
    </form>
  </div>

</div>
</body>
</html>
