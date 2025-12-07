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

/**
 * For a given date, time, op return the schedule if exists
 */

function event_lookup_op_schedule($date, $time, $op) {

    // default date/time to now
    if (!$date || !$time) {
        $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
        $target = clone $nowUtc;
        if ((int)$nowUtc->format('i') >= 30) {
            $target->modify('+1 hour');
        }
        $date = $target->format('Y-m-d');
        $time = $target->format('H:00:00');
    }

    // Lookup schedule rows for that hour
    $rows = [];
    try {
        $db_conn = get_event_db_connection_from_master(EVENT_NAME);
        $rows = db_get_schedule_for_date_time($db_conn, $date, $time);
    } catch (Throwable $e) {
        log_msg(DEBUG_WARNING, 'selfspot: schedule lookup failed ' . json_encode(['err'=>$e->getMessage(), 'date'=>$date, 'time'=>$time]));
    }

    $cs_eq = static function (?string $a, ?string $b): bool {
        return $a !== null && $b !== null && strtoupper(trim($a)) === strtoupper(trim($b));
    };

    // Find the row belonging to the logged-in op
    $matched = null;
    foreach ($rows as $row) {
        if (!isset($row['op_call'])) continue;
        if ($cs_eq($row['op_call'], $op)) { $matched = $row; break; }
    }

    if ($matched) {
        $matched['date'] = $date;
        $matched['time'] = $time;
    }
    return $matched;
}

// return all or selected qsos of the event 
function get_event_qsos(
    $non_event_calls = false, 
    $select_station_callsign = null, 
    $select_operator = null, 
    $select_date = null,      // not used yet
    $select_time = null       // not used yet
) {
    $conn = get_event_qsolog_connection();
    if (!$conn) {
        log_msg(DEBUG_ERROR, "could not get connection to qso log db");
        return null;
    }

    $rows = [];

    // Safety: if no callsigns, nothing to do.
    if (!defined('EVENT_CALLSIGNS') || !is_array(EVENT_CALLSIGNS) || count(EVENT_CALLSIGNS) === 0) {
        log_msg(DEBUG_ERROR, "No event callsigns defined.");
        return $rows;
    }

    // Build start/end timestamps from event constants (assumed UTC)
    $start_ts = EVENT_START_DATE . ' ' . EVENT_START_TIME;
    $end_ts   = EVENT_END_DATE   . ' ' . EVENT_END_TIME;

    // Base SQL
    $sql = "
        SELECT
            COL_TIME_ON AS `time`,
            COL_CALL AS `call`,
            COL_BAND AS `band`,
            COL_MODE AS `mode`,
            COL_STATION_CALLSIGN AS `station_callsign`,
            COL_OPERATOR AS `operator`,
            COL_COMMENT AS `comment`
        FROM TABLE_HRD_CONTACTS_V01
        WHERE COL_TIME_ON BETWEEN ? AND ?
    ";

    // IN / NOT IN for event callsigns
    $placeholders = implode(',', array_fill(0, count(EVENT_CALLSIGNS), '?'));
    if ($non_event_calls) {
        $sql .= " AND COL_STATION_CALLSIGN NOT IN ($placeholders)";
    } else {
        $sql .= " AND COL_STATION_CALLSIGN IN ($placeholders)";
    }

    // Normalize optional filters (treat empty string as "not set")
    $select_operator = isset($select_operator) ? trim($select_operator) : null;
    if ($select_operator === '') $select_operator = null;

    $select_station_callsign = isset($select_station_callsign) ? trim($select_station_callsign) : null;
    if ($select_station_callsign === '') $select_station_callsign = null;

    // Optional operator filter
    if (!is_null($select_operator)) {
        $sql .= " AND COL_OPERATOR = ?";
    }

    // Optional specific station_callsign filter
    if (!is_null($select_station_callsign)) {
        $sql .= " AND COL_STATION_CALLSIGN = ?";
    }

    // Final ordering
    $sql .= " ORDER BY COL_STATION_CALLSIGN, COL_OPERATOR, COL_BAND, COL_MODE, COL_TIME_ON";

    // Build params in the same order as the placeholders
    $params = [];
    $params[] = $start_ts;
    $params[] = $end_ts;

    // Event callsigns for IN/NOT IN
    foreach (EVENT_CALLSIGNS as $cs) {
        $params[] = $cs;
    }

    // Then operator, then specific station (if present)
    if (!is_null($select_operator)) {
        $params[] = $select_operator;
    }
    if (!is_null($select_station_callsign)) {
        $params[] = $select_station_callsign;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        log_msg(DEBUG_ERROR, "Prepare failed: " . $conn->error);
        return $rows;
    }

    $types = str_repeat('s', count($params));
    if (!$stmt->bind_param($types, ...$params)) {
        log_msg(DEBUG_ERROR, "bind_param failed: " . $stmt->error);
        $stmt->close();
        return $rows;
    }

    if (!$stmt->execute()) {
        log_msg(DEBUG_ERROR, "Execute failed: " . $stmt->error);
        $stmt->close();
        return $rows;
    }

    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();
    return $rows;
}

