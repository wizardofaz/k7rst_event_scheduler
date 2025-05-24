<?php
// db.php

require_once 'config.php';
require_once 'logging.php';

// get password for a given operator's callsign
function db_get_operator_password($conn, $call) {
    // Query to check if the operator's password exists in the database
    $query = "SELECT op_password FROM operator_passwords WHERE op_call = '$call'";
    $result = $conn->query($query);

    // If the operator's password is found, return it
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['op_password'];  // Return the password from the database
    } else {
        return null;  // No password found for the given callsign
    }
}

function db_add_password($conn, $call, $pw){
    $conn->query("INSERT INTO operator_passwords (op_call, op_password) VALUES ('$call', '$pw')");
}

function db_check_club_station_in_use($conn, $date, $time, $club_station) {
    $check = $conn->query("SELECT id FROM schedule WHERE date='$date' AND time='$time' AND club_station='$club_station'");
    return ($check && $check->num_rows > 0);
}

function db_check_club_station_for_date_time($conn, $date, $time) {
    return $conn->query("SELECT club_station FROM schedule WHERE date='{$date}' AND time='{$time}'");
}

function db_check_band_mode_conflict($conn, $date, $time, $band, $mode) {
    $check = $conn->query("SELECT id FROM schedule WHERE date='$date' AND time='$time' AND band='$band' AND mode='$mode'");
    return ($check && $check->num_rows > 0);
}

function db_get_schedule_for_date_time($conn, $date, $time) {
    return $conn->query("SELECT op_call, op_name, band, mode, club_station, notes FROM schedule WHERE date='$date' AND time='$time'");
}

function db_add_schedule_line($conn, $date, $time, $call, $name, $band, $mode, $club_station, $notes) {
    $stmt = $conn->prepare("INSERT INTO schedule (date, time, op_call, op_name, band, mode, club_station, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $date, $time, $call, $name, $band, $mode, $club_station, $notes);
    $stmt->execute();
    $stmt->close();
}

function db_delete_schedule_line($conn, $date, $time, $band, $mode, $call) {
    return $conn->query("DELETE FROM schedule WHERE date='$date' AND time='$time' AND band='$band' AND mode='$mode' AND op_call='$call'");
}

?>
