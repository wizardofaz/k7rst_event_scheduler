<?php

require_once 'logging.php'; // to get DEBUG_ constants

define('APP_VERSION', '1.0.5.15-2 2025-05-15');

if (strpos(__dir__ . '/', '.alpha/') !== false) {
    define('CODE_VERSION', 'alpha');
    define('DB_NAME', 'u419577197_CACTUS_alpha');
    define('DB_USER', 'u419577197_cactus_alpha');
    define('DEBUG_LEVEL', DEBUG_ERROR);
} else {
    define('CODE_VERSION', 'beta');
    define('DB_NAME', 'u419577197_CACTUS_sched');
    define('DB_USER', 'u419577197_cactus');
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
// database password lives here, maybe other stuff too
require_once $secrets_path;
log_msg(DEBUG_INFO, "config.php: DB_NAME: " . DB_NAME);
log_msg(DEBUG_INFO, "config.php: DB_USER: " . DB_USER);
log_msg(DEBUG_INFO, "config.php: secrets_path is: " . $secrets_path);

define('DB_SERVER', '127.0.0.1:3306');

define('EVENT_NAME', '2025 CACTUS');

define('EVENT_START_DATE', '2025-11-20');
define('EVENT_START_TIME', '00:00:00');
define('EVENT_END_DATE', '2025-11-30');
define('EVENT_END_TIME', '00:00:00');
define('TIMEZONE_OFFSET_SECONDS', -7*60*60);

define('DAYTIME_START', 9);  // Start of daytime (9 AM)
define('DAYTIME_END', 20);   // End of daytime (8 PM)
define('WEEKEND_DAY_HEAT', 4);  // Heat value for daytime weekends
define('WEEKDAY_DAY_HEAT', 2);  // Heat value for daytime weekdays
define('WEEKEND_NIGHT_HEAT', 3);  // Heat value for weekend nights
define('WEEKDAY_NIGHT_HEAT', 1);  // Heat value for weekday nights
define('BAND_HEAT_DAY', 2);    // Heat value for certain bands during the day
define('BAND_HEAT_NIGHT', 1);  // Heat value for certain bands during the night
define('DAY_BANDS', ['20m','17m','15m','12m','10m']);
define('NIGHT_BANDS', ['160m','80m','40m','30m']);

$band_opts = ['All','160m','80m','40m','30m','20m','17m','15m','12m','10m','6m','2m','70cm', 'OTHER'];
$mode_opts = ['All','CW','SSB','DIG','OTHER'];

$club_stations = ['N7NBV Corral', 'Days In The Park'];

// day_opts and time_opts should always include 'all' as the first choice
$day_opts = ['all' => 'Any/All', '0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'];

// time_opts and times_by_slot must be kept consistent with each other
$time_opts = [
    'all' => 'Any/All',
    'midnight_to_6' => 'Midnight–6am',
    '6_to_noon' => '6am–Noon',
    'noon_to_6' => 'Noon–6pm',
    '6_to_midnight' => '6pm–Midnight'
];
$times_by_slot = [
    'midnight_to_6' => ['00:00:00','01:00:00','02:00:00','03:00:00','04:00:00','05:00:00'],
    '6_to_noon' => ['06:00:00','07:00:00','08:00:00','09:00:00','10:00:00','11:00:00'],
    'noon_to_6' => ['12:00:00','13:00:00','14:00:00','15:00:00','16:00:00','17:00:00'],
    '6_to_midnight' => ['18:00:00','19:00:00','20:00:00','21:00:00','22:00:00','23:00:00']
];

?>
