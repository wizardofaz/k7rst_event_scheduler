<?php
// backfill_assigned_calls.php — fills assigned_call for existing rows 
// SAFE to run on test data; uses deterministic picker.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/assigned_call.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/event_db.php';

// Strict errors as exceptions (mysqli >= 5.4)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// get a connection to the selected event db
$mysqli = get_event_db_connection_from_master(EVENT_NAME);

$mysqli->set_charset('utf8mb4');

// ---- Mode & output ---------------------------------------------------------
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }

$doRun     = (isset($_GET['run']) && $_GET['run'] == '1');
$overwrite = (isset($_GET['overwrite']) && $_GET['overwrite'] == '1');
$clearAll = (isset($_GET['clearall']) && $_GET['clearall'] == '1');

// Helper: run prepared SELECT returning all rows (assoc)
function db_select_all(mysqli $db, string $sql, array $params = [], string $types = ''): array {
    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Given rows for ONE operator/band/mode sorted by date+time ASC,
 * split into contiguous +1h blocks. Each row must have keys: date, time.
 * Returns array of blocks; each block is an array of rows (original assoc arrays).
 */
function split_into_hour_blocks(array $rows): array {
    $blocks = [];
    $block = [];
    $prevTs = null;

    foreach ($rows as $r) {
        $ts = strtotime($r['date'].' '.$r['time'].' UTC'); // dates stored UTC
        if ($prevTs === null || $ts === $prevTs + 3600) {
            $block[] = $r;
        } else {
            if ($block) $blocks[] = $block;
            $block = [$r];
        }
        $prevTs = $ts;
    }
    if ($block) $blocks[] = $block;
    return $blocks;
}

echo "Backfill assigned_call for event ".EVENT_NAME." — Mode: " . ($doRun ? "RUN" : "TEST")  . 
     ($overwrite ? " + OVERWRITE" : "") . ($clearAll ? " + CLEARALL" : "") . " \n";

// if doing clearall, do it and get out     
if ($clearAll) {
    // Count how many would be cleared
    $res = $mysqli->query("SELECT COUNT(*) AS n FROM schedule WHERE assigned_call IS NOT NULL");
    $n = (int)($res ? ($res->fetch_assoc()['n'] ?? 0) : 0);
    echo "Clear assigned_call — " . ($doRun ? "RUN" : "TEST") . ": rows to clear = {$n}\n";

    if ($doRun) {
        $mysqli->begin_transaction();
        try {
            $mysqli->query("UPDATE schedule SET assigned_call = NULL WHERE assigned_call IS NOT NULL");
            $aff = $mysqli->affected_rows;
            $mysqli->commit();
            echo "Cleared rows: {$aff}  [CLEARED]\n";
        } catch (mysqli_sql_exception $e) {
            $mysqli->rollback();
            throw $e;
        }
    } else {
        echo "Dry run only — no rows changed. Append ?run=1 to execute.\n";
    }
    exit;
}

// 1) Find (date,time) groups needing backfill
$groups = db_select_all(
    $mysqli,
    "SELECT `date`, `time`
     FROM schedule
     WHERE assigned_call IS NULL
     GROUP BY `date`, `time`
     ORDER BY `date` ASC, `time` ASC"
);

$totalUpdated = 0;
$totalConsidered = 0;

if ($overwrite) {
    // Fetch ALL rows; we will regroup by (op_call, band, mode) and process chronologically
    $all = db_select_all(
        $mysqli,
        "SELECT id, `date`, `time`, op_call, band, mode, assigned_call
         FROM schedule
         ORDER BY op_call ASC, band ASC, mode ASC, `date` ASC, `time` ASC"
    );

    $totalConsidered = 0;
    $totalUpdated    = 0;

    // Prepared once
    $upd = $mysqli->prepare("UPDATE schedule SET assigned_call = ? WHERE id = ?");

    // Walk by (op_call, band, mode)
    $i = 0; $n = count($all);
    while ($i < $n) {
        $op   = $all[$i]['op_call'];
        $band = $all[$i]['band'];
        $mode = $all[$i]['mode'];

        // collect rows for this key
        $start = $i;
        while ($i < $n &&
               $all[$i]['op_call'] === $op &&
               $all[$i]['band']    === $band &&
               $all[$i]['mode']    === $mode) {
            $i++;
        }
        $chunk = array_slice($all, $start, $i - $start); // already sorted by date+time

        // split into contiguous +1h blocks
        $blocks = split_into_hour_blocks($chunk);

        foreach ($blocks as $block) {
            $blockCall = null; // what we try to keep for this block

            // Optional: try previous neighbor OUTSIDE the block as first preference
            // (We’ll only use it if free in the first slot.)
            // Compute used list per slot dynamically, so we don’t step on other ops.

            foreach ($block as $row) {
                $dateYmd = $row['date'];
                $timeHis = $row['time'];

                // 1) Build used set for this slot (exclude current row’s existing value,
                // since we’re recomputing everything):
                $usedRows = db_select_all(
                    $mysqli,
                    "SELECT assigned_call FROM schedule
                     WHERE `date`=? AND `time`=? AND assigned_call IS NOT NULL",
                    [$dateYmd, $timeHis],
                    'ss'
                );
                $used = array_column($usedRows, 'assigned_call');

                // 2) Ask event_db.php neighbor helper (same op/band/mode), then avoid used
                $neighbors = event_neighbor_assigned_calls(EVENT_NAME, $dateYmd, $timeHis, $row['op_call'], $row['band'], $row['mode']);
                $prefer = ac_prefer_from_neighbors($neighbors, $used);

                // 3) Pick with prefer
                $pick = ac_pick_from_used($dateYmd, $timeHis, $used, $row['mode'], $row['band'], $prefer);
                if ($pick === null) {
                    // extremely rare: all calls used in this hour; skip
                    continue;
                }

                // Log before→after
                $before = $row['assigned_call'] ?: '(null)';
                $line = sprintf("[%s %s] id=%d op=%s band=%s mode=%s  %s -> %s",
                                $dateYmd, $timeHis, $row['id'], $op, $band, $mode, $before, $pick);

                if (!$doRun) {
                    echo $line . "  [TEST]\n";
                } else {
                    $mysqli->begin_transaction();
                    try {
                        $upd->bind_param('si', $pick, $row['id']);
                        $upd->execute();
                        $mysqli->commit();
                        echo $line . "  [UPDATED]\n";
                        $totalUpdated++;
                    } catch (mysqli_sql_exception $e) {
                        $mysqli->rollback();
                        throw $e;
                    }
                }

                // Establish or keep the block call for subsequent hours
                if ($blockCall === null) $blockCall = $pick;

                // Consume for this slot (so the next hour’s used list *in our process*
                // reflects this choice when there are multiple unprocessed rows in same slot)
                $used[] = $pick;
                $totalConsidered++;
            }
        }
    }

    echo "Backfill complete. Considered: {$totalConsidered}  Updated: {$totalUpdated}\n";
    exit;
}

// Overwrite not requested (if it was we exited above)
foreach ($groups as $g) {
    $dateYmd = $g['date'];
    $timeHis = $g['time'];

    // 2) Fetch all rows for this slot, stable order
    $rows = db_select_all(
        $mysqli,
        "SELECT id, op_call, op_name, band, mode, assigned_call
         FROM schedule
         WHERE `date` = ? AND `time` = ?
         ORDER BY id ASC",
        [$dateYmd, $timeHis],
        'ss'
    );
    if (!$rows) continue;

    // Build used list from already-assigned rows
    $used = [];
    foreach ($rows as $r) {
        if (!empty($r['assigned_call'])) $used[] = $r['assigned_call'];
    }

    // 3) Prepare the UPDATE once
    $upd = $mysqli->prepare("UPDATE schedule SET assigned_call = ? WHERE id = ? AND assigned_call IS NULL");

    // 4) Process each unassigned row
    foreach ($rows as $r) {
        if (!empty($r['assigned_call'])) continue;

        $mode = $r['mode'] ?? '';
        $band = $r['band'] ?? '';
        $used = event_used_calls_in_slot(EVENT_NAME, $dateYmd, $timeHis);

        $neighbors = event_neighbor_assigned_calls(EVENT_NAME, $dateYmd, $timeHis, $r['op_call'], $r['band'], $r['mode']);
        $prefer = ac_prefer_from_neighbors($neighbors, $used);
        $pick = ac_pick_from_used($dateYmd, $timeHis, $used, $mode, $band, $prefer);

        if ($pick === null) {
            // no available calls for this slot
            continue;
        }
        // Log before/after for this row
        $before = $r['assigned_call'] ?: '(null)';
        $line = sprintf(
            "[%s %s] id=%d op=%s band=%s mode=%s  %s -> %s",
            $dateYmd, $timeHis, $r['id'], $r['op_call'], $band, $mode, $before, $pick
        );

        // TEST mode: print and consume, no DB write
        if (!$doRun) {
            echo $line . "  [TEST]\n";
            $used[] = $pick;          // simulate taking this callsign in this slot
            $totalConsidered++;
            continue;
        }

        $totalConsidered++;

        $attempts = 0;
        while ($attempts < 2) {
            $attempts++;

            try {
                $mysqli->begin_transaction();

                // Try update
                $upd->bind_param('si', $pick, $r['id']);
                $upd->execute();

                // If 1 row changed, success
                if ($upd->affected_rows === 1) {
                    $mysqli->commit();
                    $used[] = $pick;
                    $totalUpdated++;
                    echo $line . "  [UPDATED]\n";
                    break;
                } else {
                    // Possibly someone else filled it; re-read used and stop retrying this row
                    $mysqli->rollback();
                    $fresh = db_select_all(
                        $mysqli,
                        "SELECT assigned_call
                         FROM schedule
                         WHERE `date` = ? AND `time` = ? AND assigned_call IS NOT NULL",
                        [$dateYmd, $timeHis],
                        'ss'
                    );
                    $used = array_column($fresh, 'assigned_call');
                    break;
                }
            } catch (mysqli_sql_exception $e) {
                // Unique violation is 1062 in MySQL/MariaDB
                if ($mysqli->errno == 1062 && $attempts < 2) {
                    $mysqli->rollback();
                    // Rebuild used and recompute pick, then retry
                    $fresh = db_select_all(
                        $mysqli,
                        "SELECT assigned_call
                         FROM schedule
                         WHERE `date` = ? AND `time` = ? AND assigned_call IS NOT NULL",
                        [$dateYmd, $timeHis],
                        'ss'
                    );
                    $used = array_column($fresh, 'assigned_call');
                    $pick = ac_pick_from_used($dateYmd, $timeHis, $used, $mode, $band, null);
                    if ($pick === null) break;
                    continue;
                }
                // Other error: propagate
                if ($mysqli->errno) $mysqli->rollback();
                throw $e;
            }
        }
    }

    $upd->close();
}

echo "Backfill complete. Considered: {$totalConsidered}  Updated: {$totalUpdated}\n";
