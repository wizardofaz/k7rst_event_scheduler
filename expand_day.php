<?php
require_once 'config.php';
require_once 'logging.php';

ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();

log_msg(DEBUG_INFO, "expand_day.php");

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Get the selected day from the URL parameter
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');  // Default to today's date if not provided

// Fetch the day's details from the schedule
$scheduled_slots = [];

$query = "SELECT * FROM schedule WHERE date = '$date' ORDER BY time ASC";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $scheduled_slots[] = $row;
    }
}

// Handle slot booking or deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle adding a new slot
    if (isset($_POST['schedule']) && isset($_POST['slot_time']) && isset($_POST['band']) && isset($_POST['mode'])) {
        $slot_time = $_POST['slot_time'];
        $band = $_POST['band'];
        $mode = $_POST['mode'];
        $op_call = $_POST['op_call'];  // The callsign of the operator
        $op_name = $_POST['op_name'];  // The operator's name

        // Insert into schedule table
        $stmt = $conn->prepare("INSERT INTO schedule (date, time, band, mode, op_call, op_name) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $date, $slot_time, $band, $mode, $op_call, $op_name);
        $stmt->execute();
        $stmt->close();

        // Redirect back to the same day page
        header("Location: expand_day.php?date=" . urlencode($date));
        exit();
    }

    // Handle deleting a scheduled slot
    if (isset($_POST['delete_slot'])) {
        $slot_id = $_POST['delete_slot'];

        // Delete the schedule entry
        $conn->query("DELETE FROM schedule WHERE id = '$slot_id'");

        // Redirect back to the same day page
        header("Location: expand_day.php?date=" . urlencode($date));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expand Day - <?= date('M d, Y', strtotime($date)) ?></title>
    <style>
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }
        .schedule-table th, .schedule-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        .schedule-table th {
            background-color: #f2f2f2;
        }
        .available {
            background-color: #c8e6c9;
        }
        .booked {
            background-color: #ffcccb;
        }
        .form-section {
            margin-top: 20px;
        }
    </style>
</head>
<body>

<h2>Schedule for <?= date('M d, Y', strtotime($date)) ?></h2>

<!-- Display the scheduled slots for the day -->
<table class="schedule-table">
    <thead>
        <tr>
            <th>Time</th>
            <th>Band</th>
            <th>Mode</th>
            <th>Operator</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $hours = range(0, 23);
        foreach ($hours as $hour) {
            foreach ($bands_list as $band) {
                foreach ($modes_list as $mode) {
                    // Check if this slot is already scheduled
                    $slot_found = false;
                    foreach ($scheduled_slots as $slot) {
                        if ($slot['time'] == $hour && $slot['band'] == $band && $slot['mode'] == $mode) {
                            $slot_found = true;
                            break;
                        }
                    }
                    $formatted_time = str_pad($hour, 2, "0", STR_PAD_LEFT) . ":00";

                    if ($slot_found) {
                        echo "<tr class='booked'>
                                <td>$formatted_time</td>
                                <td>$band</td>
                                <td>$mode</td>
                                <td>" . $slot['op_call'] . " (" . $slot['op_name'] . ")</td>
                                <td>
                                    <form method='POST' style='display:inline'>
                                        <input type='hidden' name='delete_slot' value='" . $slot['id'] . "'>
                                        <button type='submit'>Delete</button>
                                    </form>
                                </td>
                              </tr>";
                    } else {
                        echo "<tr class='available'>
                                <td>$formatted_time</td>
                                <td>$band</td>
                                <td>$mode</td>
                                <td>Available</td>
                                <td>
                                    <form method='POST' style='display:inline'>
                                        <input type='hidden' name='slot_time' value='$formatted_time'>
                                        <input type='hidden' name='band' value='$band'>
                                        <input type='hidden' name='mode' value='$mode'>
                                        <label>Operator: <input type='text' name='op_call' required></label>
                                        <label>Name: <input type='text' name='op_name' required></label>
                                        <button type='submit' name='schedule'>Schedule</button>
                                    </form>
                                </td>
                              </tr>";
                    }
                }
            }
        }
        ?>
    </tbody>
</table>

</body>
</html>
