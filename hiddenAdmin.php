<?php
// admin.php
echo "Test: Admin page loaded successfully!";

// Authentication check (optional but recommended)
// session_start();
// if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
//     die('Unauthorized access');
// }

// Database connection
require_once 'config.php'; // Assuming config.php holds the DB connection details
$conn = get_event_db_connection_from_master(EVENT_NAME);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Function to run SQL query
function run_query($conn, $query) {
    if ($conn->query($query) === TRUE) {
        echo "Operation successful.\n";
    } else {
        echo "Error: " . $conn->error . "\n";
    }
}

// Handle requests
if (isset($_POST['action'])) {
    $action = $_POST['action'];

    switch ($action) {
        case 'delete_user':
            $op_call_to_delete = $_POST['op_call_to_delete'];
            $delete_user_query = "DELETE FROM operator_passwords WHERE op_call = '$op_call_to_delete'";
            run_query($conn, $delete_user_query);
            break;
        case 'empty_schedule':
            run_query($conn, "TRUNCATE TABLE schedule");
            break;
        case 'empty_passwords':
            run_query($conn, "TRUNCATE TABLE operator_passwords");
            break;
        case 'recreate_all':
            // Drop all tables first
            drop_all_tables($conn);

            // Now create all tables
            $sql = file_get_contents('create_tables.sql'); // Path to your SQL file
            if ($conn->multi_query($sql)) {
                echo "Tables recreated successfully.\n";
            } else {
                echo "Error: " . $conn->error . "\n";
            }
            break;
    }
}

$conn->close();

// Function to drop all tables
function drop_all_tables($conn) {
    // Get list of all tables
    $result = $conn->query("SHOW TABLES");

    if ($result) {
        // Loop through the tables and drop them
        while ($row = $result->fetch_row()) {
            $table = $row[0];
            $dropQuery = "DROP TABLE IF EXISTS `$table`";
            if ($conn->query($dropQuery) === TRUE) {
                echo "Table $table dropped successfully.<br>";
            } else {
                echo "Error dropping table $table: " . $conn->error . "<br>";
            }
        }
    } else {
        echo "Error fetching tables: " . $conn->error . "<br>";
    }
}

// Function to execute SQL file to create tables
function execute_sql_file($conn, $filePath) {
    // Read SQL file
    $sql = file_get_contents($filePath);

    if ($conn->multi_query($sql)) {
        do {
            // Store the first result (useful for debugging if needed)
            if ($result = $conn->store_result()) {
                // Use result here if needed
                $result->free();
            }
        } while ($conn->next_result()); // Loop through each query in the multi_query
    } else {
        echo "Error executing SQL file: " . $conn->error . "<br>";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
</head>
<body>
    <h1>Admin Panel</h1>

    <!-- Form to delete a specific user -->
    <form method="POST">
        <input type="hidden" name="action" value="delete_user">
        <label for="op_call_to_delete">Enter Callsign to Delete:</label>
        <input type="text" name="op_call_to_delete" required>
        <button type="submit">Delete User</button>
    </form>

    <!-- Form to empty schedule table -->
    <form method="POST">
        <input type="hidden" name="action" value="empty_schedule">
        <button type="submit">Empty Schedule Table</button>
    </form>

    <!-- Form to empty password table -->
    <form method="POST">
        <input type="hidden" name="action" value="empty_passwords">
        <button type="submit">Empty Password Table</button>
    </form>

    <!-- Form to delete and recreate all tables -->
    <form method="POST">
        <input type="hidden" name="action" value="recreate_all">
        <button type="submit">Delete and Recreate All Tables</button>
    </form>

</body>
</html>
