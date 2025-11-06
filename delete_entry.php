<?php
// delete_entry.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logging.php';

$conn = get_event_db_connection_from_master(EVENT_NAME);

$op_call = auth_is_authenticated() ? auth_get_callsign() : '';
$op_name = auth_is_authenticated() ? auth_get_name() : '';
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

log_msg(DEBUG_INFO, "DELETE attempt for $op_call at $date $time band=$band mode=$mode");

$authorized = auth_is_authenticated();

if (!$authorized) {
    http_response_code(403);
    echo "Not authorized.";
    exit;
}

$result = db_delete_schedule_line($conn, $date, $time, $band, $mode, $op_call);

if ($result) {
    log_msg(DEBUG_INFO, "Deleted successfully");
    echo "OK";
} else {
    http_response_code(404);
    log_msg(DEBUG_ERROR, "Delete failed");
    echo "Entry not found or deletion failed.";
}
?>
