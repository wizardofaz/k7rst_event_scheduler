<?php
require_once 'config.php';
$table_data = json_decode($_POST['table_data'] ?? '[]', true);
if (!is_array($table_data)) {
    die("Invalid or missing table data.");
}

// Send iCal headers
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="schedule_export.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//N7DZ//CACTUS Scheduler//EN\r\n";


foreach ($table_data as $row) {

    $date = $row['date'] ?? '';
    $time = $row['time'] ?? '00:00';
    $band = $row['band'] ?? '';
    $mode = $row['mode'] ?? '';
    $op = $row['op'] ?? '';
    $station = $row['club_station'] ?? '(personal station)';
    $notes = $row['notes'] ?? '';
    $name = $row['name'] ?? '';

    if (!$date || !$time) continue; // skip incomplete entries

    // Format as UTC-ish timestamp (naive)
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', "$date $time");
    if (!$dt) continue;

    $start = $dt->format('Ymd\THis');
    $end = $dt->modify('+1 hour')->format('Ymd\THis');
    $uid = uniqid("cactus-", true);

    echo "BEGIN:VEVENT\r\n";
    echo "UID:$uid@n7dz.net\r\n";
    echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    echo "DTSTART:$start\r\n";
    echo "DTEND:$end\r\n";
    echo "SUMMARY: " . EVENT_DISPLAY_NAME . "(" . EVENT_NAME . "): " . "$band $mode - $op\r\n";
    echo "DESCRIPTION:" . addcslashes("Operator: $op $name\nBand/Mode: $band $mode\nStation: $station\n$notes", "\n") . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
exit;
?>