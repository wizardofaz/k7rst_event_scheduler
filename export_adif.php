<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['__RST_EVENT_ACTIVE__'])) {
    echo "<pre>";
    echo "You must be logged in to the scheduler in another browser tab before opening this page.\n";
    echo "Go log in then come back here and refresh the page.";
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/event_db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/fpdf/fpdf.php';

// Simple escaper
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ADIF helpers ---------------------------------------------------------

function adif_escape_value($value) {
    // Basic: cast to string and strip newlines (ADIF fields shouldn't contain raw newlines)
    $value = (string)$value;
    $value = str_replace(["\r", "\n"], ' ', $value);
    return $value;
}

function adif_field($name, $value) {
    $name  = strtoupper($name);
    $value = adif_escape_value($value);
    $len   = strlen($value);
    return sprintf('<%s:%d>%s', $name, $len, $value);
}

function build_adif_header_for_group($operator, $station_callsign, $band = '', $mode = '') {
    $lines = [];

    $lines[] = "CACTUS Event Log Export";
    $lines[] = adif_field('PROGRAMID', 'CACTUS');
    $lines[] = adif_field('PROGRAMVERSION', '1.0');

    if ($station_callsign !== '') {
        $lines[] = adif_field('STATION_CALLSIGN', $station_callsign);
    }
    if ($operator !== '') {
        $lines[] = adif_field('OPERATOR', $operator);
    }
    if ($band !== '') {
        $lines[] = adif_field('BAND', $band);
    }
    if ($mode !== '') {
        $lines[] = adif_field('MODE', $mode);
    }

    $lines[] = '<EOH>';

    return implode("\r\n", $lines) . "\r\n";
}

function build_adif_record_from_row(array $row) {
    $time_str = $row['time'] ?? '';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $time_str);
    $qso_date = '';
    $time_on  = '';
    if ($dt) {
        $qso_date = $dt->format('Ymd');
        $time_on  = $dt->format('His');
    }

    $call     = $row['call'] ?? '';
    $band     = $row['band'] ?? '';
    $mode     = $row['mode'] ?? '';
    $stn_call = $row['station_callsign'] ?? '';
    $operator = $row['operator'] ?? '';
    $comment  = $row['comment'] ?? '';

    $parts = [];

    if ($call !== '')          $parts[] = adif_field('CALL', $call);
    if ($band !== '')          $parts[] = adif_field('BAND', $band);
    if ($mode !== '')          $parts[] = adif_field('MODE', $mode);
    if ($stn_call !== '')      $parts[] = adif_field('STATION_CALLSIGN', $stn_call);
    if ($operator !== '')      $parts[] = adif_field('OPERATOR', $operator);
    if ($qso_date !== '')      $parts[] = adif_field('QSO_DATE', $qso_date);
    if ($time_on !== '')       $parts[] = adif_field('TIME_ON', $time_on);
    if ($comment !== '')       $parts[] = adif_field('COMMENT', $comment);

    $parts[] = '<EOR>';

    return implode('', $parts) . "\r\n";
}

function build_adif_for_group(array $g): string
{
    $op   = $g['operator'] ?? '';
    $stn  = $g['station'] ?? '';
    $band = $g['band'] ?? '';
    $mode = $g['mode'] ?? '';

    $adif = build_adif_header_for_group($op, $stn, $band, $mode);
    foreach ($g['rows'] as $row) {
        $adif .= build_adif_record_from_row($row);
    }
    return $adif;
}

function make_adif_filename_for_group(array $g, bool $group_whole_station): string
{
    $station  = make_safe_slug($g['station']  ?? 'UNKNOWNSTN');
    $operator = make_safe_slug($g['operator'] ?? 'UNKNOWNOP');

    if ($group_whole_station) {
        return "cactus_{$station}_{$operator}.adi";
    }

    $band = make_safe_slug($g['band'] ?? 'UNKBAND');
    $mode = make_safe_slug($g['mode'] ?? 'UNKMODE');

    $start = 'UNKNOWNSTART';
    $end   = 'UNKNOWNEND';

    if (!empty($g['first_dt']) && $g['first_dt'] instanceof DateTime) {
        $start = $g['first_dt']->format('Ymd-Hi\Z');
    }
    if (!empty($g['last_dt']) && $g['last_dt'] instanceof DateTime) {
        $end = $g['last_dt']->format('Ymd-Hi\Z');
    }

    $start = make_safe_slug($start);
    $end   = make_safe_slug($end);

    return "cactus_{$station}_{$operator}_{$band}_{$mode}_{$start}_to_{$end}.adi";
}

function make_safe_slug($s) {
    $s = strtoupper(trim((string)$s));
    // Replace anything non [A-Za-z0-9_.+-] with underscore
    $s = preg_replace('/[^A-Za-z0-9_\.\+\-]+/', '_', $s);
    if ($s === '' || $s === null) {
        $s = 'UNKNOWN';
    }
    return $s;
}

function build_adif_files_grouped(array $rows) {
    // Assumes rows are already ordered by station_callsign, operator, band, mode, time
    $adif_files = [];

    $current_key      = null;
    $current_operator = '';
    $current_station  = '';
    $current_adif     = '';

    foreach ($rows as $row) {
        $op   = $row['operator'] ?? '';
        $stn  = $row['station_callsign'] ?? '';
        $key  = $op . '|' . $stn;

        if ($key !== $current_key) {
            // flush previous group
            if ($current_key !== null) {
                $fname = 'cactus_' . make_safe_slug($current_station) . '_' . make_safe_slug($current_operator) . '.adi';
                $adif_files[$fname] = $current_adif;
            }

            // start new group
            $current_key      = $key;
            $current_operator = $op;
            $current_station  = $stn;
            $current_adif     = build_adif_header_for_group($current_operator, $current_station);
        }

        $current_adif .= build_adif_record_from_row($row);
    }

    // flush last group
    if ($current_key !== null) {
        $fname = 'cactus_' . make_safe_slug($current_station) . '_' . make_safe_slug($current_operator) . '.adi';
        $adif_files[$fname] = $current_adif;
    }

    return $adif_files;
}

// pdf helpers
function build_pdf_for_group(array $g): string
{
    $station  = $g['station']  ?? '';
    $operator = $g['operator'] ?? '';
    $band     = $g['band']     ?? '';
    $mode     = $g['mode']     ?? '';

    $start_str = '';
    $end_str   = '';

    if (!empty($g['first_dt']) && $g['first_dt'] instanceof DateTime) {
        $start_str = $g['first_dt']->format('Y-m-d H:i:s') . 'Z';
    }
    if (!empty($g['last_dt']) && $g['last_dt'] instanceof DateTime) {
        $end_str = $g['last_dt']->format('Y-m-d H:i:s') . 'Z';
    }

    $pdf = new FPDF();
    $pdf->AddPage();

    // 🔧 Use ASCII hyphens to avoid encoding issues
    $pdf->SetFont('Arial', 'B', 12);
    $header = "CACTUS - {$station} - {$operator}";
    if ($band !== '' || $mode !== '') {
        $header .= " - {$band} {$mode}";
    }
    $pdf->Cell(0, 7, $header, 0, 1);

    if ($start_str !== '' || $end_str !== '') {
        $pdf->SetFont('Arial', '', 10);
        $range = "From {$start_str}";
        if ($end_str !== '') {
            $range .= " to {$end_str}";
        }
        $pdf->Cell(0, 6, $range, 0, 1);
    }

    $pdf->Ln(3);

    // Table header
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(36, 6, 'Date/Time (UTC)', 1, 0);
    $pdf->Cell(25, 6, 'Call',           1, 0);
    $pdf->Cell(15, 6, 'Band',           1, 0);
    $pdf->Cell(15, 6, 'Mode',           1, 0);
    $pdf->Cell(0,  6, 'Comment',        1, 1);

    // Rows
    $pdf->SetFont('Arial', '', 9);
    foreach ($g['rows'] as $row) {
        $time_str = $row['time'] ?? '';
        $dt = null;
        if ($time_str !== '') {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $time_str);
        }
        $dt_disp = $dt ? $dt->format('Y-m-d H:i:s') . 'Z' : $time_str;

        $call    = $row['call'] ?? '';
        $band    = $row['band'] ?? '';
        $mode    = $row['mode'] ?? '';
        $comment = $row['comment'] ?? '';

        $pdf->Cell(36, 6, $dt_disp, 1, 0);
        $pdf->Cell(25, 6, $call,    1, 0);
        $pdf->Cell(15, 6, $band,    1, 0);
        $pdf->Cell(15, 6, $mode,    1, 0);

        // multi-cell for comments: simple approach: truncated in one line
        $maxComment = 80;
        if (mb_strlen($comment) > $maxComment) {
            $comment = mb_substr($comment, 0, $maxComment - 1) . '…';
        }
        $pdf->Cell(0, 6, $comment, 1, 1);
    }

    return $pdf->Output('S'); // return as string
}

function group_qsos_into_blocks(array $rows, bool $group_whole_station): array
{
    $groups = [];
    $current = null;

    foreach ($rows as $row) {
        $station  = $row['station_callsign'] ?? '';
        $operator = $row['operator'] ?? '';
        $band     = $row['band'] ?? '';
        $mode     = $row['mode'] ?? '';

        $dt = null;
        if (!empty($row['time'])) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $row['time']);
        }

        $start_new_group = false;

        if ($current === null) {
            $start_new_group = true;
        } else {
            if ($station !== $current['station'] || $operator !== $current['operator']) {
                $start_new_group = true;
            } elseif (!$group_whole_station && ($band !== $current['band'] || $mode !== $current['mode'])) {
                $start_new_group = true;
            }
        }

        if ($start_new_group) {
            if ($current !== null) {
                $groups[] = $current;
            }
            $current = [
                'station'  => $station,
                'operator' => $operator,
                'band'     => $band,
                'mode'     => $mode,
                'first_dt' => $dt,
                'last_dt'  => $dt,
                'rows'     => [],
            ];
        } else {
            // update last_dt
            if ($dt && $current['last_dt'] && $dt > $current['last_dt']) {
                $current['last_dt'] = $dt;
            } elseif ($dt && !$current['last_dt']) {
                $current['last_dt'] = $dt;
            }
        }

        if ($dt && !$current['first_dt']) {
            $current['first_dt'] = $dt;
            $current['last_dt']  = $dt;
        }

        $current['rows'][] = $row;
    }

    if ($current !== null) {
        $groups[] = $current;
    }

    return $groups;
}

// CSV helper -----------------------------------------------------------

function build_csv_from_rows(array $rows) {
    $fh = fopen('php://temp', 'r+');

    // header row
    fputcsv($fh, ['time', 'call', 'band', 'mode', 'station_callsign', 'operator', 'comment']);

    foreach ($rows as $row) {
        fputcsv($fh, [
            $row['time'] ?? '',
            $row['call'] ?? '',
            $row['band'] ?? '',
            $row['mode'] ?? '',
            $row['station_callsign'] ?? '',
            $row['operator'] ?? '',
            $row['comment'] ?? '',
        ]);
    }

    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);

    return $csv;
}

// ZIP export -----------------------------------------------------------

function export_zip_with_adif_and_csv(array $rows, $op_call, $cactus_call, bool $group_whole_station)
{
    // Clear any previous output (critical for valid ZIP)
    if (ob_get_level()) {
        ob_end_clean();
    }

    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "ZipArchive is not available on this PHP installation; cannot create ZIP export.\n";
        exit;
    }

    $groups = group_qsos_into_blocks($rows, $group_whole_station);
    $csv_data = build_csv_from_rows($rows);

    $zipPath = tempnam(sys_get_temp_dir(), 'cactus_export_');
    $zip     = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Failed to create ZIP archive.\n";
        exit;
    }

    // Global CSV with all QSOs
    $zip->addFromString('cactus_export.csv', $csv_data);

    $pdf_available = class_exists('FPDF');

    // One ADIF + one PDF per group
    foreach ($groups as $g) {
        $adif_content  = build_adif_for_group($g);
        $adif_filename = make_adif_filename_for_group($g, $group_whole_station);
        $zip->addFromString($adif_filename, $adif_content);

        if ($pdf_available) {
            $pdf_bytes    = build_pdf_for_group($g);
            $pdf_filename = preg_replace('/\.adi$/i', '.pdf', $adif_filename);
            $zip->addFromString($pdf_filename, $pdf_bytes);
        }
    }

    $zip->close();

    // Build a friendly download name
    $parts = ['cactus_export'];
    if ($op_call !== '') {
        $parts[] = make_safe_slug($op_call);
    }
    if ($cactus_call !== '') {
        $parts[] = make_safe_slug($cactus_call);
    }
    $downloadName = implode('_', $parts) . '.zip';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($zipPath));

    readfile($zipPath);
    unlink($zipPath);
    exit;
}

// ---------------------------------------------------------------------
// Main controller
// ---------------------------------------------------------------------

$op_call      = '';
$cactus_call  = '';
$results      = [];
$limit        = 100;
$error_msg    = '';
$action       = 'preview';
$group_whole_station = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'preview';

    $op_call     = isset($_POST['op_call']) ? strtoupper(trim($_POST['op_call'])) : '';
    $cactus_call = isset($_POST['cactus_call']) ? strtoupper(trim($_POST['cactus_call'])) : '';

    $group_whole_station = !empty($_POST['group_whole_station']);

    $select_operator         = $op_call !== '' ? $op_call : null;
    $select_station_callsign = $cactus_call !== '' ? $cactus_call : null;

    // non_event_calls = false → event calls only
    $results = get_event_qsos(false, $select_station_callsign, $select_operator);

    if ($results === null) {
        $error_msg = 'get_event_qsos() returned null – check logs.';
        $results   = [];
    }

    // If user requested download and we have rows, export ZIP and exit before any HTML
    if ($action === 'download' && !empty($results)) {
        export_zip_with_adif_and_csv($results, $op_call, $cactus_call, $group_whole_station);
    }

    // If requested download but no rows, just fall through to HTML and show message
    if ($action === 'download' && empty($results) && $error_msg === '') {
        $error_msg = 'No QSOs matched the selected criteria; nothing to export.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>CACTUS QSO Export</title>
    <style>
        body {
            font-family: sans-serif;
            margin: 1rem;
        }
        label {
            display: inline-block;
            width: 8rem;
        }
        input[type="text"] {
            width: 12rem;
        }
        .form-row {
            margin-bottom: 0.5rem;
        }
        pre {
            background: #f5f5f5;
            padding: 0.75rem;
            border: 1px solid #ccc;
            max-height: 60vh;
            overflow: auto;
        }
        .error {
            color: darkred;
            font-weight: bold;
        }
        .buttons {
            margin-top: 0.5rem;
        }
        .buttons button {
            margin-right: 0.5rem;
        }
    </style>
</head>
<body>

<h1>CACTUS QSO Export Test</h1>

<p>This page is a test harness for <code>get_event_qsos()</code> and the ADIF/CSV/PDF export. Enter an operator callsign and/or an event callsign, then preview or download a ZIP of grouped ADIF files plus a CSV.</p>

<form method="post" action="">
    <div class="form-row">
        <label for="op_call">Operator call:</label>
        <input type="text" name="op_call" id="op_call" value="<?= h($op_call) ?>">
        <small>(optional; filters COL_OPERATOR)</small>
    </div>
    <div class="form-row">
        <label for="cactus_call">Event callsign:</label>
        <input type="text" name="cactus_call" id="cactus_call" value="<?= h($cactus_call) ?>">
        <small>(optional; filters COL_STATION_CALLSIGN)</small>
    </div>
    <div class="form-row">
        <label>&nbsp;</label>
        <input type="checkbox" name="group_whole_station" id="group_whole_station" value="1"
            <?= $group_whole_station ? 'checked' : '' ?>>
        <label for="group_whole_station" style="width:auto;">
            Group by callsign only (one ADIF per event callsign)
        </label>
    </div>
    <div class="buttons">
        <button type="submit" name="action" value="preview">Run query</button>
        <button type="submit" name="action" value="download">Download ZIP (ADIF,CSV,PDF)</button>
    </div>
</form>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>

    <h2>Results</h2>

    <?php if ($error_msg): ?>
        <p class="error"><?= h($error_msg) ?></p>
    <?php endif; ?>

    <?php if (empty($results)): ?>
        <p>No QSOs matched the selected criteria.</p>
    <?php else: ?>
        <p>Showing up to <?= $limit ?> QSOs
            (total fetched: <?= count($results) ?>)
            for
            operator <?= $op_call ? h($op_call) : '<em>ANY</em>' ?>
            and event callsign <?= $cactus_call ? h($cactus_call) : '<em>ANY</em>' ?>.
        </p>
        <pre><?php
            $count = 0;
            foreach ($results as $row) {
                if ($count >= $limit) {
                    echo "...\n(truncated after {$limit} rows)\n";
                    break;
                }
                $count++;

                $time      = $row['time'] ?? '';
                $call      = $row['call'] ?? '';
                $band      = $row['band'] ?? '';
                $mode      = $row['mode'] ?? '';
                $stn_call  = $row['station_callsign'] ?? '';
                $operator  = $row['operator'] ?? '';
                $comment   = $row['comment'] ?? '';

                $comment_short = $comment;
                if (mb_strlen($comment_short) > 40) {
                    $comment_short = mb_substr($comment_short, 0, 40) . '…';
                }

                $line = sprintf(
                    "%-20s %-12s %-8s %-8s %-12s %-12s %s",
                    $time,
                    $call,
                    $band,
                    $mode,
                    $stn_call,
                    $operator,
                    $comment_short
                );
                echo h($line) . "\n";
            }
        ?></pre>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>