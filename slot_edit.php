<?php
// slot_edit.php

require_once 'config.php';
require_once 'db.php';
require_once 'logging.php';

$conn = get_event_db_connection_from_master(EVENT_NAME);
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
    echo "<table class='slot-edit-table' border='1' cellpadding='4'>";
    echo '<colgroup>
        <col class="call">
        <col class="name">
        <col class="band">
        <col class="mode">
        <col class="station">
        <col class="notes">
        <col class="action">
      </colgroup>';
    echo "<tr><th>Call</th><th>Name</th><th>Band</th><th>Mode</th><th>Club Station</th><th>Notes</th>";
    if ($logged_in_call !== '') echo "<th>Action</th>";
    echo "</tr>";
    foreach ($entries as $entry) {
        $is_mine = $entry['op_call'] === $logged_in_call;
        if ($is_mine) $current_call_is_scheduled = true;

        echo "<tr>";
        echo "<td>{$entry['op_call']}</td>";
        echo "<td>{$entry['op_name']}</td>";

        if ($is_mine) {
            // Band dropdown
            echo "<td><select name='band' form='form-{$entry['band']}{$entry['mode']}'>";
            // TODO: let's take "all" out of the array and deal with it more rationally
            foreach (BANDS_LIST as $band) {
                if (strtolower($band) === 'all') continue;
                $selected = ($entry['band'] === $band) ? 'selected' : '';
                echo "<option value='$band' $selected>$band</option>";
            }
            echo "</select></td>";

            // Mode dropdown
            echo "<td><select name='mode' form='form-{$entry['band']}{$entry['mode']}'>";
            foreach (MODES_LIST as $mode) {
                if (strtolower($mode) === 'all') continue;
                $selected = ($entry['mode'] === $mode) ? 'selected' : '';
                echo "<option value='$mode' $selected>$mode</option>";
            }
            echo "</select></td>";
            // Club Station dropdown
            echo "<td><select name='club_station' form='form-{$entry['club_station']}{$entry['club_station']}'>";
            foreach (CLUB_STATIONS as $club_station) {
                $selected = ($entry['club_station'] === $club_station) ? 'selected' : '';
                echo "<option value='$club_station' $selected>$club_station</option>";
            }
            echo "</select></td>";
            echo "<td><input name='notes' value='{$entry['notes']}' size='10' form='form-{$entry['band']}{$entry['mode']}'></td>";
            echo "<td>
                <form id='form-{$entry['band']}{$entry['mode']}' onsubmit='return updateEntry(this);' style='display:inline;'>
                    <input type='hidden' name='date' value='$date'>
                    <input type='hidden' name='time' value='$time'>
                    <input type='hidden' name='call' value='{$entry['op_call']}'>
                    <button type='submit'>Save</button>
                </form>
                <form onsubmit='return deleteEntry(this);' style='display:inline; margin-left: 5px;'>
                    <input type='hidden' name='date' value='$date'>
                    <input type='hidden' name='time' value='$time'>
                    <input type='hidden' name='band' value='{$entry['band']}'>
                    <input type='hidden' name='mode' value='{$entry['mode']}'>
                    <input type='hidden' name='call' value='{$entry['op_call']}'>
                    <button type='submit'>Delete</button>
                </form>
            </td>";
        } else {
            echo "<td>{$entry['band']}</td>
                <td>{$entry['mode']}</td>
                <td>{$entry['club_station']}</td>
                <td>{$entry['notes']}</td>";
            if ($logged_in_call !== '') echo "<td></td>";
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
        foreach (BANDS_LIST as $band) {
            if (strtolower($band) === 'all') continue;
            echo "<option>$band</option>";
        }
        echo "</select> Mode: <select name='mode'>";
        foreach (MODES_LIST as $mode) {
            if (strtolower($mode) === 'all') continue;
            echo "<option>$mode</option>";
        }
        echo "</select><br>
            Club Station: <select name='club_station'><option value=''></option>";
        foreach (CLUB_STATIONS as $club) echo "<option>$club</option>";
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
