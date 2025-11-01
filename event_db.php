<?php
// event_db.php — loads config constants from a given event database
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/master.php';

define('EVENT_NOT_EXIST',0);        // no db by that name or can't access
define('EVENT_MALSTRUCTURED',1);    // db exists but not valid as an event db
define('EVENT_DB_EMPTY',2);         // db is completely empty      
define('EVENT_EMPTY_SCHEDULE',3);   // valid event db but schedule is empty
define('EVENT_HAS_SCHEDULE',4);     // valid event db with some schedule entries

function get_status_label($code) {
    switch ($code) {
        case EVENT_NOT_EXIST: return 'NOT_EXIST';
        case EVENT_MALSTRUCTURED: return 'MALSTRUCTURED';
        case EVENT_DB_EMPTY: return 'DB_EMPTY';
        case EVENT_EMPTY_SCHEDULE: return 'EMPTY_SCHEDULE';
        case EVENT_HAS_SCHEDULE: return 'HAS_SCHEDULE';
        default: return 'UNKNOWN';
    }
}

function connect_to_event_db($info) {
    $conn = new mysqli($info['host'], $info['user'], $info['pass'], $info['name']);
    if ($conn->connect_error) {
        log_msg(DEBUG_ERROR, "Failed to connect to event DB '{$info['name']}': " . $conn->connect_error);
        return null;
    }
    log_msg(DEBUG_INFO, "Connected to event DB '{$info['name']}' successfully.");
    return $conn;
}

// "errors" here are warnings because validate is used to find out status of
// often non-qualifying dbs. So not really an error.
function validate_event_db($conn_info) {
    $conn = connect_to_event_db($conn_info);
    if (!$conn) {
        log_msg(DEBUG_WARNING, "Could not connect to event DB '{$conn_info['name']}'");
        return [null, EVENT_NOT_EXIST];
    }

    // Check tables exist
    $res = $conn->query("SHOW TABLES");
    if (!$res || $res->num_rows === 0) {
        log_msg(DEBUG_WARNING, "No tables found in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_DB_EMPTY];
    }

    // Check event_config exists and is populated
    $res = $conn->query("SHOW TABLES LIKE 'event_config'");
    if (!$res || $res->num_rows === 0) {
        log_msg(DEBUG_WARNING, "Table 'event_config' does not exist in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }
    
    $res = $conn->query("SELECT COUNT(*) as count FROM event_config");
    if (!$res) {
        log_msg(DEBUG_WARNING, "Table 'event_config' missing or malformed in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }
    $row = $res->fetch_assoc();
    if ((int)$row['count'] === 0) {
        log_msg(DEBUG_WARNING, "Table 'event_config' is empty in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];  // or define a new code EVENT_EMPTY_CONFIG?
    }

    // Check schedule table exists and has data
    $res = $conn->query("SHOW TABLES LIKE 'schedule'");
    if (!$res || $res->num_rows === 0) {
        log_msg(DEBUG_WARNING, "Table 'schedule' does not exist in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }

    $res = $conn->query("SELECT COUNT(*) as count FROM schedule");
    if (!$res) {
        log_msg(DEBUG_WARNING, "Table 'schedule' missing or malformed in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }
    $row = $res->fetch_assoc();
    if ((int)$row['count'] === 0) {
        log_msg(DEBUG_INFO, "Table 'schedule' exists but has no rows in '{$conn_info['name']}'");
        return [$conn, EVENT_EMPTY_SCHEDULE];
    }

    log_msg(DEBUG_INFO, "Valid event DB '{$conn_info['name']}' with populated schedule and config.");
    return [$conn, EVENT_HAS_SCHEDULE];
}

function get_event_config_as_kv($event_name) {
    $conn = get_event_db_connection_from_master($event_name);
    if (!$conn) {
        log_msg(DEBUG_ERROR, "Failed to connect to event DB for '$event_name'");
        return null;
    }

    $data = [];
    try {
        $res = $conn->query("SELECT name, value FROM event_config");
        while ($row = $res->fetch_assoc()) {
            $data[$row['name']] = $row['value'];
        }
    } catch (mysqli_sql_exception $e) {
        log_msg(DEBUG_ERROR, "Query event_config failed: " . $e->getMessage());
        return null;
    } finally {
        $conn->close();
    }
    log_msg(DEBUG_INFO, "Loaded " . count($data) . " config entries from '$event_name'");

    return $data;
}

function load_event_config_constants($event_name) {
    $data = get_event_config_as_kv($event_name);
    if (!$data) return false;

    foreach ($data as $key => $val) {
        $key = strtoupper($key);
        if (defined($key)) {
            $existing = constant($key);
            log_msg(DEBUG_ERROR, "Constant $key is already defined! (new: '$val' vs existing: '$existing')");
            continue;
        }
        $json = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            define($key, $json);
            log_msg(DEBUG_INFO, "Defined constant $key (from JSON)");
        } else if (is_numeric($val)) {
            define($key, $val + 0);
            log_msg(DEBUG_INFO, "Defined constant $key = $val (numeric)");
        } else {
            define($key, $val);
            log_msg(DEBUG_INFO, "Defined constant $key = '$val' (string, non-JSON)");
        }
    }
    return true;
}

// Returns list of assigned calls already used in this slot.
function event_used_calls_in_slot(string $event_name, string $dateYmd, string $timeHis): array {
    $conn = get_event_db_connection_from_master($event_name);
    if (!$conn) { log_msg(DEBUG_ERROR, "DB connect failed for $event_name"); return []; }
    $out = [];
    try {
        $stmt = $conn->prepare("
            SELECT assigned_call
            FROM schedule
            WHERE `date`=? AND `time`=? AND assigned_call IS NOT NULL
        ");
        $stmt->bind_param('ss', $dateYmd, $timeHis);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $out[] = $row['assigned_call']; }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        log_msg(DEBUG_ERROR, "event_used_calls_in_slot: " . $e->getMessage());
    } finally { $conn->close(); }
    return $out;
}

/**
 * Find closest neighbor calls for the same operator according to policy.
 * policy: 'none' | 'same_band_mode' | 'same_operator_any'
 * lookaround: how many hours back/forward to search (default 1)
 * Returns ['prev'=>?string, 'next'=>?string]
 */
function event_neighbor_assigned_calls(
    string $event_name,
    string $dateYmd,
    string $timeHis,
    string $op_call,
    string $band,
    string $mode,
    ?string $policy = null,
    ?int $lookaround = null
): array {
    $policy     = $policy     ?? (defined('CALL_ASSIGN_STICKY_POLICY') ? CALL_ASSIGN_STICKY_POLICY : 'same_band_mode');
    $lookaround = $lookaround ?? (defined('CALL_ASSIGN_STICKY_LOOKAROUND') ? (int)CALL_ASSIGN_STICKY_LOOKAROUND : 1);

    if ($policy === 'none' || $lookaround <= 0) return ['prev'=>null, 'next'=>null];

    $conn = get_event_db_connection_from_master($event_name);
    if (!$conn) { log_msg(DEBUG_ERROR, "DB connect failed for $event_name"); return ['prev'=>null, 'next'=>null]; }

    // Build WHERE fragment from policy
    $where = "op_call=?";
    $types = "s";
    $args  = [$op_call];

    if ($policy === 'same_band_mode') {
        $where .= " AND band=? AND mode=?";
        $types .= "ss";
        $args[] = $band; $args[] = $mode;
    }

    $prev = null; $next = null;
    try {
        $base = new DateTimeImmutable($dateYmd.' '.$timeHis, new DateTimeZone('UTC'));

        // prepared (date,time) + dynamic policy filter
        $sql = "SELECT assigned_call FROM schedule WHERE `date`=? AND `time`=? AND $where LIMIT 1";

        for ($k=1; $k <= $lookaround && ($prev === null || $next === null); $k++) {
            // prev hour
            if ($prev === null) {
                $dt = $base->sub(new DateInterval('PT1H'));
                for ($i=1; $i<$k; $i++) { $dt = $dt->sub(new DateInterval('PT1H')); }
                $d = $dt->format('Y-m-d'); $t = $dt->format('H:i:s');
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss'.$types, $d, $t, ...$args);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                if ($r && !empty($r['assigned_call'])) $prev = $r['assigned_call'];
                $stmt->close();
            }
            // next hour
            if ($next === null) {
                $dt = $base->add(new DateInterval('PT1H'));
                for ($i=1; $i<$k; $i++) { $dt = $dt->add(new DateInterval('PT1H')); }
                $d = $dt->format('Y-m-d'); $t = $dt->format('H:i:s');
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss'.$types, $d, $t, ...$args);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                if ($r && !empty($r['assigned_call'])) $next = $r['assigned_call'];
                $stmt->close();
            }
        }
    } catch (mysqli_sql_exception $e) {
        log_msg(DEBUG_ERROR, "event_neighbor_assigned_calls: " . $e->getMessage());
    } finally { $conn->close(); }

    return ['prev'=>$prev, 'next'=>$next];
}

/**
 * Decide a preferred sticky callsign from neighbors, avoiding used calls.
 * Returns preferred call or null.
 */
function ac_prefer_from_neighbors(array $neighbors, array $usedCalls): ?string {
    $used = [];
    foreach ($usedCalls as $u) { if ($u) $used[trim($u)] = true; }
    if (!empty($neighbors['prev']) && empty($used[$neighbors['prev']])) return $neighbors['prev'];
    if (!empty($neighbors['next']) && empty($used[$neighbors['next']])) return $neighbors['next'];
    return null;
}

