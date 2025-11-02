<?php
// add_entry.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/login.php';
require_once __DIR__ . '/assigned_call.php';

$conn = get_event_db_connection_from_master(EVENT_NAME);

$op_call = strtoupper($_POST['call'] ?? ($_SESSION['logged_in_call'] ?? ''));
$op_name = $_POST['name'] ?? ($_SESSION['logged_in_name'] ?? '');
$op_pw   = $_POST['password'] ?? '';
$date    = $_POST['date'] ?? '';
$time    = $_POST['time'] ?? '';
$band    = $_POST['band'] ?? '';
$mode    = $_POST['mode'] ?? '';
$club    = $_POST['club_station'] ?? '';
$notes   = $_POST['notes'] ?? '';

if (!$op_call || !$date || !$time || !$band || !$mode) {
    http_response_code(400);
    echo "Missing required fields.";
    exit;
}

$authorized = login($conn, $op_call, $op_name, $op_pw);

if (!$authorized) {
    http_response_code(403);
    echo "Not authorized.";
    exit;
}

// Check for band/mode conflict or club station conflict
if (db_check_band_mode_conflict($conn, $date, $time, $band, $mode)) {
    http_response_code(409);
    echo "Band/Mode already scheduled for this hour.";
    exit;
}

if ($club && db_check_club_station_in_use($conn, $date, $time, $club)) {
    http_response_code(409);
    echo "Club station already in use.";
    exit;
}

if(EVENT_CALLSIGNS_REQUIRED) {
    $assigned_call = choose_assigned_call($date, $time, $op_call, $band, $mode);
    if ($assigned_call === null) {
        // all calls are in use in this slot, must reject
        $_SESSION['slot_full_flash'] = true;
        log_msg(DEBUG_INFO, "No callsign available for {$date} {$time} (slot full).");
    } else {
        log_msg(DEBUG_VERBOSE, "assigned_call is {$assigned_call} for {$date} {$time} {$op_call}.");
    }
} else {
    $assigned_call = null;
}


db_add_schedule_line($conn, $date, $time, $op_call, $op_name, $band, $mode, $assigned_call, $club, $notes);
echo "OK";
?>
