<?php
// config.php — dynamic event config loader

ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();

require_once 'logging.php'; // to get DEBUG_ constants

define('APP_VERSION', '1.0.5.23 2025-05-23');

if (strpos(__dir__ . '/', '.alpha/') !== false) {
    define('CODE_VERSION', 'alpha');
    define('DEBUG_LEVEL', DEBUG_ERROR);
} else {
    define('CODE_VERSION', 'beta');
    define('DEBUG_LEVEL', DEBUG_ERROR);
}

// assuming our code lives in some directory below public_html
// and the secrets file lives a parallel path with /secrets/ 
// substituted for /public_html/, this should find the correct secrets.php.
// Get the name of the current app directory (e.g., "cactus" or "cactus.alpha")
$subdir = basename(__DIR__);
$secrets_path = __DIR__ . "/../../secrets/{$subdir}/secrets.php";
if (!file_exists($secrets_path)) {
    die("❌ secrets.php not found for environment '{$subdir}'");
}

require_once $secrets_path; // master database password lives here
require_once 'master.php';
require_once 'event_db.php';

// Determine which event we're loading
$event_name = $_GET['event'] ?? 'CACTUS2025TST';
define('EVENT_NAME', $event_name);

load_event_config_constants($event_name); // this defines all the constants relevant to this event
