<?php
require_once __DIR__ . '/config.php';

$table_data = json_decode($_POST['table_data'] ?? '[]', true);
if (!is_array($table_data)) {
    die("Invalid table data.");
}

// Send CSV headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="schedule_export.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['date', 'time', 'band', 'mode', 'status']);

foreach ($table_data as $row) {
    fputcsv($output, [
        $row['date'],
        $row['time'],
        $row['band'],
        $row['mode'],
        $row['op'],
        $row['name'],
        $row['club_station'] ?? '',
        $row['notes'] ?? '',  // fallback just in case
    ]);
}

fclose($output);
exit;
?>
