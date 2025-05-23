<?php
// master.php — helpers for querying the master database to resolve event DB connection info
require_once 'config.php'; // config.php pulls in secrets.php
require_once 'logging.php';
require_once 'event_db.php';

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
    $conn = connect_to_master();
    if ($conn->connect_error) {
        log_msg(DEBUG_ERROR, "Unable to connect to master DB to list events.");
        return [];
    }

    $result = $conn->query("SELECT event_name FROM events ORDER BY event_name");
    if (!$result) {
        log_msg(DEBUG_ERROR, "Query failed in list_events_from_master_with_status: " . $conn->error);
        $conn->close();
        return [];
    }

    $events = [];
    while ($row = $result->fetch_assoc()) {
        $event_name = $row['event_name'];
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
            'status' => $status
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

    $stmt = $conn->prepare("SELECT create_sql FROM events WHERE event_name = ?");
    if (!$stmt) {
        $conn->close();
        log_msg(DEBUG_ERROR, "Prepare failed: " . $conn->error);
        return null;
    }

    $stmt->bind_param("s", $event_name);
    $stmt->execute();
    $stmt->bind_result($sql);
    if ($stmt->fetch()) {
        $stmt->close();
        $conn->close();
        log_msg(DEBUG_INFO, "Fetched SQL for event '$event_name': $sql");
        return $sql;
    } 

    log_msg(DEBUG_INFO, "Could not fetch SQL for event '$event_name': " . ($sql ?? '[undefined]') . "; will fetch default.");
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT create_sql FROM default_schema WHERE id = 1");
    if (!$stmt) {
        $conn->close();
        log_msg(DEBUG_ERROR, "Prepare failed: " . $conn->error);
        return null;
    }

    $stmt->execute();
    $stmt->bind_result($sql);
    if ($stmt->fetch()) {
        log_msg(DEBUG_INFO, "Fetched default SQL for events: $sql");
    } else {
        log_msg(DEBUG_ERROR, "Could not fetch default SQL for events" . ($sql ?? '[undefined]'));
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
        if (!$conn->query($statement)) {
            log_msg(DEBUG_ERROR, "SQL error: " . $conn->error . "\n\tStatement: $statement.");
            $conn->close();
            return false;
        } else {
            log_msg(DEBUG_INFO, "SQL good query: \n\tStatement: $statement.");
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