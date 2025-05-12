<?php
// slot_edit.php

session_start();
require_once 'config.php';
require_once 'db.php';
require_once 'logging.php';

$conn = db_get_connection();
$date = $_GET['date'] ?? '';
$time = $_GET['time'] ?? '';
$logged_in_call = $_SESSION['logged_in_call'] ?? '';

if (!$date || !$time) {
    echo "<p>Missing date or time.</p>";
    exit;
}

$result = db_get_schedule_for_date_time($conn, $date, $time);
$entries = [];
if ($result) while ($row = $result->fetch_assoc()) $entries[] = $row;

echo "<h3>Scheduled for $date at $time</h3>";

$current_call_is_scheduled = false;
if (count($entries) === 0) {
    echo "<p>No operators scheduled.</p>";
} else {
    echo "<table border='1' cellpadding='4'><tr><th>Call</th><th>Name</th><th>Band</th><th>Mode</th><th>Club</th><th>Notes</th>";
    if ($logged_in_call !== '') echo "<th>Action</th>";
    echo "</tr>";
    foreach ($entries as $entry) {
        if ($entry['op_call'] === $logged_in_call) $current_call_is_scheduled = true;
        echo "<tr>";
        echo "<td>{$entry['op_call']}</td><td>{$entry['op_name']}</td><td>{$entry['band']}</td><td>{$entry['mode']}</td><td>{$entry['club_station']}</td><td>{$entry['notes']}</td>";
        if ($logged_in_call === $entry['op_call']) {
            echo "<td><form onsubmit='return deleteEntry(this);'>
                <input type='hidden' name='date' value='$date'>
                <input type='hidden' name='time' value='$time'>
                <input type='hidden' name='band' value='{$entry['band']}'>
                <input type='hidden' name='mode' value='{$entry['mode']}'>
                <input type='hidden' name='call' value='{$entry['op_call']}'>
                <button type='submit'>Delete</button>
            </form></td>";
        } elseif ($logged_in_call !== '') {
            echo "<td></td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

if ($logged_in_call !== '') {
    if (!$current_call_is_scheduled) {
        echo "<h4>Add a new slot:</h4>";
        echo "<form onsubmit='return addEntry(this);'>
            <input type='hidden' name='date' value='$date'>
            <input type='hidden' name='time' value='$time'>
            Band: <select name='band'>";
        foreach ($band_opts as $band) echo "<option>$band</option>";
        echo "</select> Mode: <select name='mode'>";
        foreach ($mode_opts as $mode) echo "<option>$mode</option>";
        echo "</select><br>
            Club Station: <select name='club_station'><option value=''></option>";
        foreach ($club_stations as $club) echo "<option>$club</option>";
        echo "</select><br>
            Notes: <input type='text' name='notes' size='40'><br>
            <input type='hidden' name='call' value='$logged_in_call'>
            <button type='submit'>Add</button>
        </form>";
    }
} else {
    echo "<p><em>Login to add or edit schedule.</em></p>";
}

?>
<script>
document.addEventListener('click', function(event) {
    const popup = document.getElementById('popup');
    if (popup.style.display === 'block' && !popup.contains(event.target)) {
        popup.style.display = 'none';
    }
});
</script>
