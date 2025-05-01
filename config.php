<?php
define('DEBUG_LEVEL', 3);

define('DB_SERVER', '127.0.0.1:3306');
define('DB_NAME', 'u419577197_CACTUS_sched');
define('DB_USER', 'u419577197_cactus');
define('DB_PASSWORD', 'RST_k7rst');

define('EVENT_START_DATE', '2025-11-20');
define('EVENT_END_DATE', '2025-11-30');
define('TIMEZONE_OFFSET_SECONDS', -7*60*60);

define('DAYTIME_START', 9);  // Start of daytime (9 AM)
define('DAYTIME_END', 20);   // End of daytime (8 PM)
define('WEEKEND_DAY_HEAT', 4);  // Heat value for daytime weekends
define('WEEKDAY_DAY_HEAT', 2);  // Heat value for daytime weekdays
define('WEEKEND_NIGHT_HEAT', 3);  // Heat value for weekend nights
define('WEEKDAY_NIGHT_HEAT', 1);  // Heat value for weekday nights
define('BAND_HEAT_DAY', 2);    // Heat value for certain bands during the day
define('BAND_HEAT_NIGHT', 1);  // Heat value for certain bands during the night


$bands_list = ['160m','80m','40m','30m','20m','17m','15m','12m','10m','6m','2m','70cm'];
$modes_list = ['CW','SSB','DIG','OTHER'];

$club_stations = ['N7NBV Corral', 'Days In The Park'];

$times_by_slot = [
    'midnight_to_6' => ['00:00:00','01:00:00','02:00:00','03:00:00','04:00:00','05:00:00'],
    '6_to_noon' => ['06:00:00','07:00:00','08:00:00','09:00:00','10:00:00','11:00:00'],
    'noon_to_6' => ['12:00:00','13:00:00','14:00:00','15:00:00','16:00:00','17:00:00'],
    '6_to_midnight' => ['18:00:00','19:00:00','20:00:00','21:00:00','22:00:00','23:00:00']
];

?>
