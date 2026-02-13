<?php
// master.php — helpers for querying the master database to resolve event DB connection info
require_once __DIR__ . '/config.php'; // config.php pulls in secrets.php
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/event_db.php';

function connect_to_master() {
    $conn = new mysqli(MASTER_SERVER, MASTER_USER, MASTER_PASSWORD, MASTER_DB);
    if ($conn->connect_error) {
        log_msg(DEBUG_ERROR, "Failed to connect to master DB: " . $conn->connect_error);
    } else {
        log_msg(DEBUG_INFO, "Connected to master DB " . MASTER_DB . " at " . MASTER_SERVER . " as " . MASTER_USER);
    }
    return $conn;
}

function is_authorized_master_user($user, $pass) {
    $conn = connect_to_master();
    if (!$conn) return false;

    $stmt = $conn->prepare("SELECT 1 FROM authorized_users WHERE db_user = ? AND db_pass = ?");
    $stmt->bind_param("ss", $user, $pass);
    $stmt->execute();
    $stmt->store_result();

    $auth_user = ($stmt->num_rows > 0);
    $stmt->close();
    $conn->close();

    if($auth_user) log_msg(DEBUG_INFO, "Authorized master user '$user'.");
    else log_msg(DEBUG_ERROR, "Failed master auth for user '$user'.");

    return $auth_user;

}

function list_events_from_master_with_status() {
    $developer_flag = (defined('DEVELOPER_FLAG') && DEVELOPER_FLAG);
    $conn = connect_to_master();
    if ($conn->connect_error) {
        log_msg(DEBUG_ERROR, "Unable to connect to master DB to list events.");
        return [];
    }

    $result = $conn->query("SELECT event_name, description, developer_flag FROM events ORDER BY event_name");
    if (!$result) {
        $conn->close();
        return [];
    }

    $events = [];
    while ($row = $result->fetch_assoc()) {
        // skip over developer only rows unless in developer context
        if (!$developer_flag && $row['developer_flag']) continue;
        $event_name = $row['event_name'];
        $event_description = $row['description'];
        $info = get_event_connection_info_from_master($event_name);
        if (!$info) {
            $status = 'EVENT_NOT_EXIST';
            $conn_obj = null;
        } else {
            list($conn_obj, $status) = validate_event_db($info);
            if ($conn_obj) $conn_obj->close();
        }
        $events[] = [
            'event_name' => $event_name,
            'event_description' => ($event_description ?? ''),
            'status' => ($status ?? 'UNKNOWN')
        ];
    }

    log_msg(DEBUG_INFO, "Retrieved " . count($events) . " event(s) with status.");

    $conn->close();
    return $events;
}

function get_event_creation_sql_from_master($event_name) {
    $conn = connect_to_master();
    if ($conn->connect_error) {
        log_msg(DEBUG_ERROR, "Could not connect to master db.");
        return null;
    }

    $sql = null;

    // Try event-specific SQL
    $stmt = $conn->prepare("SELECT create_sql FROM events WHERE event_name = ?");
    if (!$stmt) {
        log_msg(DEBUG_ERROR, "Prepare for event-specific SQL failed: " . $conn->error);
    } else {
        $stmt->bind_param("s", $event_name);
        $stmt->execute();
        $stmt->bind_result($sql);
        if ($stmt->fetch() && trim($sql) !== "") {
            log_msg(DEBUG_INFO, "Fetched SQL for event '$event_name': $sql");
            $stmt->close();
            $conn->close();
            return $sql;
        }
        $stmt->close();
    }

    log_msg(DEBUG_INFO, "No create_sql for event '$event_name'; falling back to default.");

    // Try default SQL
    $stmt = $conn->prepare("SELECT create_sql FROM default_schema WHERE id = 1");
    if (!$stmt) {
        log_msg(DEBUG_ERROR, "Prepare for default SQL failed: " . $conn->error);
        $conn->close();
        return null;
    }

    $stmt->execute();
    $stmt->bind_result($sql);
    if ($stmt->fetch() && trim($sql) !== "") {
        log_msg(DEBUG_INFO, "Fetched default SQL for events: $sql");
    } else {
        log_msg(DEBUG_ERROR, "Could not fetch valid default SQL for events.");
        $sql = null;
    }

    $stmt->close();
    $conn->close();
    return $sql;
}

function create_event_db_tables($event_name) {
    $sql = get_event_creation_sql_from_master($event_name);
    if (!$sql) {
        log_msg(DEBUG_ERROR, "Failed to read SQL template for $event_name from master db.");
        return false;
    }
    log_msg(DEBUG_DEBUG, "Event creation SQL from master: \n$sql");

    $connect_info = get_event_connection_info_from_master($event_name);
    if (!$connect_info) {
        log_msg(DEBUG_ERROR, "Failed to get db info for $event_name.");
        return false;
    }

    $conn = new mysqli($connect_info['host'], $connect_info['user'], $connect_info['pass'], $connect_info['name'] );
    if ($conn->connect_error) {
        log_msg(DEBUG_ERROR, "Failed to get db connection $event_name, " . $conn->connect_error . ".");
        return false;
    }

    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS.+?;\s*/i', '', $sql);
    $sql = preg_replace('/USE\s+`?{{DB_NAME}}`?;?/i', '', $sql);

    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if ($statement === '') continue;

        try {
            if (!$conn->query($statement)) {
                log_msg(DEBUG_ERROR, "SQL error (no exception thrown): " . $conn->error . "\n\tStatement: $statement.");
                $conn->close();
                return false;
            } else {
                log_msg(DEBUG_INFO, "SQL good query:\n\t$statement");
            }
        } catch (mysqli_sql_exception $e) {
            log_msg(DEBUG_ERROR, "SQL exception: " . $e->getMessage() . "\n\tStatement: $statement.");
            $conn->close();
            return false;
        }
    }

    log_msg(DEBUG_INFO, "successfully created all tables for $event_name.");
    $conn->close();
    return true;
}

function get_event_connection_info_from_master($event_name) {
    $conn = connect_to_master();
    if ($conn->connect_error) {
        log_msg(DEBUG_ERROR, "Could not connect to master db.");
        return null;
    }

    $stmt = $conn->prepare("SELECT db_host, db_name, db_user, db_pass FROM events WHERE event_name = ?");
    if (!$stmt) {
        $conn->close();
        log_msg(DEBUG_ERROR, "Prepare failed: " . $conn->error);
        return null;
    }

    $stmt->bind_param("s", $event_name);
    $stmt->execute();
    $stmt->bind_result($host, $name, $user, $pass);
    if ($stmt->fetch()) {
        log_msg(DEBUG_INFO, "Fetched connection info for event '$event_name': host=$host, name=$name, user=$user");
        $stmt->close();
        $conn->close(); 
        return [
            'host' => $host,
            'name' => $name,
            'user' => $user,
            'pass' => $pass
        ];
    }

    log_msg(DEBUG_ERROR, "No matching row found for event '$event_name'.");
    $stmt->close();
    $conn->close(); 
    return null;
}

function get_event_db_connection_from_master($event_name) {
    $conn_info = get_event_connection_info_from_master($event_name);
    if (!$conn_info) {
        log_msg(DEBUG_ERROR, "no connection info found for event '$event_name'");
        return null;
    }

    $conn = new mysqli($conn_info['host'], $conn_info['user'], $conn_info['pass'], $conn_info['name']);
    if (!$conn->connect_error) return $conn;
    log_msg(DEBUG_ERROR, "could not connect to event db $event_name, SQL error: " . $conn->connect_error);
    return null;
}

function get_event_qsolog_connection() {
    if (!defined('QSOLOG_DB')) {
        if (defined('EVENT_NAME')) {
            log_msg(DEBUG_ERROR, "QSOLOG_DB not defined for event ".EVENT_NAME);
        } else {
            log_msg(DEBUG_ERROR, "QSO_LOG and EVENT_NAME both not defined.");
        }
        return null;
    }

    $conn = new mysqli(QSOLOG_DB['host'].':'.QSOLOG_DB['port'], QSOLOG_DB['user'], QSOLOG_DB['password'], QSOLOG_DB['database']);
    if (!$conn->connect_error) return $conn;
    log_msg(DEBUG_ERROR, "could not connect to qsolog db for ".EVENT_NAME.", SQL error: " . $conn->connect_error);
    return null;
}
