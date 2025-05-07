<?php
// slot_edit.php

session_start();
require_once 'config.php';
require_once 'db.php';

$conn = db_get_connection();
$date = $_GET['date'] ?? '';
$time = $_GET['time'] ?? '';

if (!$date || !$time) {
    echo "<p>Missing date or time.</p>";
    exit;
}

$result = db_get_schedule_for_date_time($conn, $date, $time);
$entries = [];
if ($result) while ($row = $result->fetch_assoc()) $entries[] = $row;

echo "<h3>Scheduled for $date at $time</h3>";

if (count($entries) === 0) {
    echo "<p>No operators scheduled.</p>";
} else {
    echo "<table border='1' cellpadding='4'><tr><th>Call</th><th>Name</th><th>Band</th><th>Mode</th><th>Club</th><th>Notes</th>";
    if (!empty($_SESSION['authenticated_users'])) echo "<th>Action</th>";
    echo "</tr>";
    foreach ($entries as $entry) {
        echo "<tr>";
        echo "<td>{$entry['op_call']}</td><td>{$entry['op_name']}</td><td>{$entry['band']}</td><td>{$entry['mode']}</td><td>{$entry['club_station']}</td><td>{$entry['notes']}</td>";
        if (!empty($_SESSION['authenticated_users'][$entry['op_call']])) {
            echo "<td><form onsubmit='return deleteEntry(this);'>
                <input type='hidden' name='date' value='$date'>
                <input type='hidden' name='time' value='$time'>
                <input type='hidden' name='band' value='{$entry['band']}'>
                <input type='hidden' name='mode' value='{$entry['mode']}'>
                <input type='hidden' name='call' value='{$entry['op_call']}'>
                <button type='submit'>Delete</button>
            </form></td>";
        } elseif (!empty($_SESSION['authenticated_users'])) {
            echo "<td></td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

$logged_in_calls = array_keys($_SESSION['authenticated_users'] ?? []);
if (!empty($logged_in_calls)) {
    $this_call = $logged_in_calls[0];
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
        Name: <input type='text' name='name' value=''><br>
        <input type='hidden' name='call' value='$this_call'>
        <button type='submit'>Add</button>
    </form>";
} else {
    echo "<p><em>Login to add or edit schedule.</em></p>";
}

?>
<script>
function addEntry(form) {
    const data = new FormData(form);
    fetch('add_entry.php', {
        method: 'POST',
        body: data
    }).then(res => res.text()).then(msg => {
        if (msg === 'OK') location.reload();
        else alert(msg);
    });
    return false;
}

function deleteEntry(form) {
    const data = new FormData(form);
    fetch('delete_entry.php', {
        method: 'POST',
        body: data
    }).then(res => res.text()).then(msg => {
        if (msg === 'OK') location.reload();
        else alert(msg);
    });
    return false;
}
</script>
