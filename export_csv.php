<?php
require_once 'config.php';

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$op_call = strtoupper(trim($_POST['op_call'] ?? ''));
$results = [];

$query = "SELECT date, time, band, mode, op_call FROM schedule ORDER BY date, time, band, mode";
$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['status'] = ($row['op_call'] === $op_call) ? "Booked by you" : "Booked by {$row['op_call']}";
        unset($row['op_call']);
        $results[] = $row;
    }
}

// Send CSV headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="schedule_export.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['date', 'time', 'band', 'mode', 'status']);
foreach ($results as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
?>
