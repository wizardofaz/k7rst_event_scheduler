<?php
// update_entry.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logging.php';

$logged_in_call = $_SESSION['logged_in_call'] ?? '';
if (!$logged_in_call) {
    echo "<p>Error: You must be logged in to edit a slot.</p>";
    exit;
}

// Collect inputs
$date = $_POST['date'] ?? '';
$time = $_POST['time'] ?? '';
$call = $_POST['call'] ?? '';
$band = $_POST['band'] ?? '';
$mode = $_POST['mode'] ?? '';
$club_station = $_POST['club_station'] ?? '';
$notes = $_POST['notes'] ?? '';

if (!$date || !$time || !$call || !$band || !$mode) {
    echo "<p>Error: Missing required fields.</p>";
    exit;
}

if (strtoupper($call) !== strtoupper($logged_in_call)) {
    echo "<p>Error: You can only edit your own entries.</p>";
    exit;
}

$conn = get_event_db_connection_from_master(EVENT_NAME);

// Only update if the record matches the current user exactly
$stmt = $conn->prepare("
    UPDATE schedule
    SET band = ?, mode = ?, club_station = ?, notes = ?
    WHERE date = ? AND time = ? AND op_call = ?
");

$stmt->bind_param("sssssss", $band, $mode, $club_station, $notes, $date, $time, $logged_in_call);

if ($stmt->execute()) {
    log_msg(DEBUG_INFO, "Schedule updated by $logged_in_call: $date $time $band $mode");
} else {
    log_msg(DEBUG_ERROR, "Failed update by $logged_in_call: " . $stmt->error);
    echo "<p>Error: Update failed.</p>";
    exit;
}

// Re-display the popup content to reflect changes
echo json_encode(['success' => true]);
exit;
?>