<?php
// db.php

require_once 'config.php';
require_once 'logging.php';

// Function to get password for a given operator's callsign
function db_get_operator_password($conn, $op_call_input) {
    // Query to check if the operator's password exists in the database
    $query = "SELECT op_password FROM operator_passwords WHERE op_call = '$op_call_input'";
    $result = $conn->query($query);

    // If the operator's password is found, return it
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['op_password'];  // Return the password from the database
    } else {
        return null;  // No password found for the given callsign
    }
}
?>
