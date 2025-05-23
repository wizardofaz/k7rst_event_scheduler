<?php
// delete_entry.php

session_start();
require_once 'config.php';
require_once 'db.php';
require_once 'login.php';
require_once 'logging.php';

$conn = db_get_connection();

$op_call = strtoupper($_POST['call'] ?? ($_SESSION['logged_in_call'] ?? ''));
$op_name = $_POST['name'] ?? ($_SESSION['logged_in_name'] ?? '');
$op_pw   = $_POST['password'] ?? '';
$date    = $_POST['date'] ?? '';
$time    = $_POST['time'] ?? '';
$band    = $_POST['band'] ?? '';
$mode    = $_POST['mode'] ?? '';

log_msg(DEBUG_INFO, "DELETE attempt for $op_call at $date $time band=$band mode=$mode");

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
