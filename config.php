<?php
// config.php — dynamic event config loader

ini_set('session.gc_maxlifetime', 7200);
// Best: set via php.ini or .user.ini, but runtime works too:
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax'); 

require_once __DIR__ . '/logging.php'; // debug logging helpers
require_once __DIR__ . '/debug_list.php'; // limits on which files produce debug lines
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/csrf.php';

csrf_start_session_if_needed();

define('APP_VERSION', '1.0.10.28 2025-10-28');

if (strpos(__dir__ . '/', '.alpha/') !== false) {
    define('DEVELOPER_FLAG', true);
    define('CODE_VERSION', 'alpha');
    define('DEBUG_LEVEL', DEBUG_VERBOSE);
} else {
    define('CODE_VERSION', 'beta');
    define('DEBUG_LEVEL', DEBUG_ERROR);
}

$secrets_path = secrets_path();

require_once __DIR__ . '/util.php';
require_once $secrets_path; // master database password lives here
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/event_db.php';
require_once __DIR__ . '/auth.php';

auth_initialize_if_absent();

// --- Event resolution: session-sticky; URL only sets it on first arrival ---
$eventFromSess  = auth_get_event();
$eventFromUrl   = isset($_GET['event']) ? trim((string)$_GET['event']) : null;

$here     = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isIndex  = ($here === 'index.php');

// Build valid set once
$event_names = array_column(list_events_from_master_with_status(), 'event_name');
$isValidUrlEvent = $eventFromUrl && in_array($eventFromUrl, $event_names, true);

// 1) If NO session event yet and ?event= is valid → adopt on ANY page (first-touch)
if (!$eventFromSess && $isValidUrlEvent) {
    auth_set_event($eventFromUrl);
    log_msg("DEBUG_VERBOSE", "no session event, url event is valid, session set to ".auth_get_event());
}

// 2) If already have a session event, only allow URL-based switching on index.php
if ($eventFromSess && $isValidUrlEvent && $isIndex && $eventFromUrl !== $eventFromSess) {
    auth_set_event($eventFromUrl);
    log_msg("DEBUG_VERBOSE", "have session event, url event is valid, here=index, switching session to ".auth_get_event());
}

// 3) If this is index.php, allow defaulting to first event in list
// If not index, valid event must be given on url. 
if (!auth_get_event() && $isIndex && isset($event_names[0])) {
    auth_set_event($event_names[0]);
    log_msg("DEBUG_VERBOSE", "no session event, we are index.php, allow default, session set to ".auth_get_event());
}

// 4) Finally if there is still no event chosen, abort
if (!auth_get_event()) {
    echo "No valid event could be determined from URL or current session state.";
    log_msg("DEBUG_VERBOSE", "no event could be chosen, aborting");
    exit;
}

// 5) Always strip ?event= from the URL and redirect back to here 
if (isset($_GET['event'])) {
    $parts = parse_url($_SERVER['REQUEST_URI'] ?? '/');
    $path  = $parts['path'] ?? '/';
    parse_str($parts['query'] ?? '', $qs);
    unset($qs['event']);
    $dest = $path . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $dest, true, 302);
    log_msg("DEBUG_VERBOSE", "URL event stripped, reloading self");
    exit;
}

// Resolve final event 
$eventName = auth_get_event();
define('EVENT_NAME', $eventName);
log_msg("DEBUG_INFO", "event resolution resulted in " . $eventName);

// Load per-event constants; handle invalid without loops
if ($eventName !== '' && !load_event_config_constants(EVENT_NAME)) {
    auth_set_event(null);
    if ($here !== 'index.php') {
        header('Location: index.php?invalid_event=' . urlencode((string)EVENT_NAME));
        exit;
    }
    // On index, define harmless placeholders so the page can render.
    defined('EVENT_DISPLAY_NAME')   || define('EVENT_DISPLAY_NAME', '');
    defined('EVENT_LOGGER_URL')     || define('EVENT_LOGGER_URL', '');
    defined('EVENT_SCHEDULER_URL')  || define('EVENT_SCHEDULER_URL', '');
}