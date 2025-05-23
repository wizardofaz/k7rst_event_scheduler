<?php
// event_db.php — loads config constants from a given event database
require_once 'config.php';
require_once 'logging.php';
require_once 'master.php';

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
        log_msg(DEBUG_ERROR, "❌ Failed to connect to event DB '{$info['name']}': " . $conn->connect_error);
        return null;
    }
    log_msg(DEBUG_INFO, "✅ Connected to event DB '{$info['name']}' successfully.");
    return $conn;
}

function validate_event_db($conn_info) {
    $conn = connect_to_event_db($conn_info);
    if (!$conn) {
        log_msg(DEBUG_ERROR, "❌ Could not connect to event DB '{$conn_info['name']}'");
        return [null, EVENT_NOT_EXIST];
    }

    // Check tables exist
    $res = $conn->query("SHOW TABLES");
    if (!$res || $res->num_rows === 0) {
        log_msg(DEBUG_ERROR, "❌ No tables found in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_DB_EMPTY];
    }

    // Check event_config exists and is populated
    $res = $conn->query("SHOW TABLES LIKE 'event_config'");
    if (!$res || $res->num_rows === 0) {
        log_msg(DEBUG_ERROR, "❌ Table 'event_config' does not exist in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }
    
    $res = $conn->query("SELECT COUNT(*) as count FROM event_config");
    if (!$res) {
        log_msg(DEBUG_ERROR, "❌ Table 'event_config' missing or malformed in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }
    $row = $res->fetch_assoc();
    if ((int)$row['count'] === 0) {
        log_msg(DEBUG_INFO, "ℹ️ 'event_config' table is empty in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];  // or define a new code EVENT_EMPTY_CONFIG?
    }

    // Check schedule table exists and has data
    $res = $conn->query("SHOW TABLES LIKE 'schedule'");
    if (!$res || $res->num_rows === 0) {
        log_msg(DEBUG_ERROR, "❌ Table 'schedule' does not exist in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }

    $res = $conn->query("SELECT COUNT(*) as count FROM schedule");
    if (!$res) {
        log_msg(DEBUG_ERROR, "❌ Table 'schedule' missing or malformed in '{$conn_info['name']}'");
        $conn->close();
        return [null, EVENT_MALSTRUCTURED];
    }
    $row = $res->fetch_assoc();
    if ((int)$row['count'] === 0) {
        log_msg(DEBUG_INFO, "ℹ️ 'schedule' table exists but has no rows in '{$conn_info['name']}'");
        return [$conn, EVENT_EMPTY_SCHEDULE];
    }

    log_msg(DEBUG_INFO, "✅ Valid event DB '{$conn_info['name']}' with populated schedule and config.");
    return [$conn, EVENT_HAS_SCHEDULE];
}

function get_event_config_as_kv($event_name) {
    $conn = get_event_db_connection_from_master($event_name);
    if (!$conn) {
        log_msg(DEBUG_ERROR, "❌ event_db: failed to connect to event DB for '$event_name'");
        return [];
    }

    $data = [];
    $res = $conn->query("SELECT name, value FROM event_config");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[$row['name']] = $row['value']; 
        }
    }
    $conn->close();
    log_msg(DEBUG_INFO, "✅ Loaded " . count($data) . " config entries from '$event_name'");

    return $data;
}

function load_event_config_constants($event_name) {
    $data = get_event_config_as_kv($event_name);

    foreach ($data as $key => $val) {
        $key = strtoupper($key);
        if (defined($key)) {
            $existing = constant($key);
            log_msg(DEBUG_ERROR, "❌ Constant $key is already defined! (new: '$val' vs existing: '$existing')");
            continue;
        }
        $json = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            define($key, $json);
            log_msg(DEBUG_INFO, "ℹ️ Defined constant $key (from JSON)");
        } else if (is_numeric($val)) {
            define($key, $val + 0);
            log_msg(DEBUG_INFO, "ℹ️ Defined constant $key = $val (numeric)");
        } else {
            define($key, $val);
            log_msg(DEBUG_INFO, "ℹ️ Defined constant $key = '$val' (string, non-JSON)");
        }
    }
}
