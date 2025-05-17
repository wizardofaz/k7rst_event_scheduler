<?php
// event_admin.php

require_once 'config.php';

function create_event_database($db_host, $admin_user, $admin_pass, $new_db_name, $template_path) {
    $conn = new mysqli($db_host, $admin_user, $admin_pass, $new_db_name);
    if ($conn->connect_error) {
        return "⚠️ Could not connect to database '$new_db_name': " . $conn->connect_error;
    }

    $sql = file_get_contents($template_path);
    if ($sql === false) return "Failed to read SQL template.";

    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS.+?;\s*/i', '', $sql);
    $sql = preg_replace('/USE\s+`?{{DB_NAME}}`?;?/i', '', $sql);

    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        if ($statement === '') continue;
        if (!$conn->query($statement)) {
            return "SQL error: " . $conn->error . "\nStatement: $statement";
        }
    }

    return true;
}

function suggest_empty_databases($host, $user, $pass) {
    $conn = new mysqli($host, $user, $pass);
    if ($conn->connect_error) return [];

    $databases = [];
    $res = $conn->query("SHOW DATABASES");
    if ($res) {
        while ($row = $res->fetch_row()) {
            $db = $row[0];
            if (in_array($db, ['information_schema', 'mysql', 'performance_schema', 'sys'])) continue;
            $test = new mysqli($host, $user, $pass, $db);
            $tableRes = $test->query("SHOW TABLES");
            if ($tableRes && $tableRes->num_rows === 0) {
                $databases[] = $db;
            }
            $test->close();
        }
    }
    return $databases;
}

function list_all_user_databases($host, $user, $pass) {
    $conn = new mysqli($host, $user, $pass);
    if ($conn->connect_error) return [];
    $all = [];
    $res = $conn->query("SHOW DATABASES");
    if ($res) {
        while ($row = $res->fetch_row()) {
            $db = $row[0];
            if (!in_array($db, ['information_schema', 'mysql', 'performance_schema', 'sys'])) {
                $all[] = $db;
            }
        }
    }
    return $all;
}

function get_event_config_as_kv($host, $user, $pass, $db_name) {
    $conn = new mysqli($host, $user, $pass, $db_name);
    if ($conn->connect_error) return [];

    $data = [];
    $res = $conn->query("SELECT name, value FROM event_config");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
}

$suggestions = suggest_empty_databases(DB_SERVER, DB_ADMIN_USER, DB_ADMIN_PASSWORD);
$all_databases = list_all_user_databases(DB_SERVER, DB_ADMIN_USER, DB_ADMIN_PASSWORD);

$config_data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_name']) && !isset($_POST['config_keys'])) {
    $new_db_name = trim($_POST['db_name']);
    if (!$new_db_name) {
        $message = "Database name is required.";
    } else {
        $result = create_event_database(
            DB_SERVER,
            DB_ADMIN_USER,
            DB_ADMIN_PASSWORD,
            $new_db_name,
            'create_event_db_template.sql'
        );
        $message = $result === true ? "✅ Event database '$new_db_name' initialized successfully." : $result;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config_keys']) && isset($_POST['config_values']) && isset($_POST['config_db'])) {
    $target_db = $_POST['config_db'];
    $conn = new mysqli(DB_SERVER, DB_ADMIN_USER, DB_ADMIN_PASSWORD, $target_db);
    if ($conn->connect_error) {
        $config_message = "❌ Failed to connect to $target_db";
    } else {
        $conn->query("DELETE FROM event_config");
        $stmt = $conn->prepare("INSERT INTO event_config (name, value) VALUES (?, ?)");
        if ($stmt === false) {
            $config_message = "❌ Prepare failed: " . $conn->error;
        } else {
            $keys = $_POST['config_keys'] ?? [];
            $values = $_POST['config_values'] ?? [];
            for ($i = 0; $i < count($keys); $i++) {
                $k = strtoupper(trim($keys[$i]));
                $v = trim($values[$i]);
                if ($k === '' && $v === '') continue;
                if (preg_match('/^".*"$/', $v)) {
                    $v = trim($v, '"');
                }
                $stmt->bind_param("ss", $k, $v);
                $stmt->execute();
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?config_db=" . urlencode($target_db));
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['config_db'])) {
    $config_data = get_event_config_as_kv(DB_SERVER, DB_ADMIN_USER, DB_ADMIN_PASSWORD, $_GET['config_db']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Event Admin</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="event_admin.css">
    <link href="https://cdn.jsdelivr.net/npm/jsoneditor@9.10.0/dist/jsoneditor.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsoneditor@9.10.0/dist/jsoneditor.min.js"></script>
    <script>
    function addRow() {
        const table = document.getElementById('config-table');
        const lastRow = table.rows[table.rows.length - 1];
        const keyInput = lastRow.cells[0].firstChild;
        const valInput = lastRow.cells[2]?.querySelector('input');

        if (keyInput.value !== '' || (valInput && valInput.value !== '')) {
            const newRow = table.insertRow();
            newRow.innerHTML = `
                <td><input type="text" name="config_keys[]" oninput="this.value = this.value.toUpperCase(); addRow()" style="width: 100%"></td>
                                <td></td>
                                <td><input type="text" name="config_values[]" oninput="addRow()" style="width: 100%"></td>
            `;
            setupJSONEditors();
        }
    }

    function setupJSONEditors() {
    const rows = document.querySelectorAll('#config-table tr');
    rows.forEach(row => {
        const keyInput = row.cells?.[0]?.querySelector('input');
        const valueCell = row.cells?.[2];
        if (!keyInput || !valueCell) return;

        const toggleCell = row.cells[1];
        if (toggleCell.childNodes.length > 0) return;

        const button = document.createElement('button');
        button.textContent = '📝';
        button.title = 'Toggle JSON editor';
        button.type = 'button';
        button.style.padding = '0';
        button.style.margin = '0';
        button.style.border = 'none';
        button.style.background = 'none';
        button.style.cursor = 'pointer';

        toggleCell.style.textAlign = 'center';
        toggleCell.style.width = '1%';
        toggleCell.appendChild(button);

        button.addEventListener('click', () => {
            const currentCell = row.cells[2];

            const existingEditor = row._jsonEditor;
            const existingHiddenInput = row._hiddenInput;

            if (existingEditor && existingHiddenInput) {
                const editorContent = existingEditor.getText();
                const input = document.createElement('input');
                input.type = 'text';
                input.name = 'config_values[]';
                input.value = editorContent;
                input.style.width = '100%';

                currentCell.innerHTML = '';
                currentCell.appendChild(input);

                delete row._jsonEditor;
                delete row._hiddenInput;
            } else {
                const currentInput = currentCell.querySelector('input');
                const jsonHolder = document.createElement('div');
                jsonHolder.style.height = '200px';
                currentCell.innerHTML = '';
                currentCell.appendChild(jsonHolder);

                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'config_values[]';
                currentCell.appendChild(hiddenInput);

                const editor = new JSONEditor(jsonHolder, {
                    mode: 'code',
                    mainMenuBar: false
                });

                try {
                    editor.set(JSON.parse(currentInput.value));
                } catch (e) {
                    editor.setText(currentInput.value);
                    alert("⚠️ Warning: Value could not be parsed as JSON. Editing as plain text.");
                }          

                row._jsonEditor = editor;
                row._hiddenInput = hiddenInput;
            }
        });
    });
}

    window.addEventListener('DOMContentLoaded', () => {
        setupJSONEditors();
        document.querySelector('form[method="post"]').addEventListener('submit', () => {
            document.querySelectorAll('#config-table tr').forEach(row => {
                if (row._jsonEditor && row._hiddenInput) {
                    row._hiddenInput.value = JSON.stringify(row._jsonEditor.get());
                }
            });
        });
    });
    </script>
</head>
<body>
    <h2>Create New Event Database</h2>
    <?php if (isset($message)) echo "<p><strong>$message</strong></p>"; ?>
    <?php if (!empty($suggestions)): ?>
      <p>Suggested empty databases:</p>
      <ul>
        <?php foreach ($suggestions as $s) echo "<li>$s</li>"; ?>
      </ul>
    <?php else: ?>
      <p><em>(No empty databases found.)</em></p>
    <?php endif; ?>

    <form method="post">
      <label for="db_name">Existing Database Name:</label>
      <select name="db_name" id="db_name" required>
        <option value="">-- Select a database --</option>
        <?php foreach ($suggestions as $db) echo "<option value=\"$db\">$db</option>"; ?>
      </select>
      <button type="submit">Initialize Tables</button>
    </form>

    <hr>
    <h2>Edit Event Configuration</h2>
    <?php if (isset($config_message)) echo "<p><strong>$config_message</strong></p>"; ?>
    <form method="get">
        <label for="config_db">Database:</label>
        <select name="config_db" id="config_db" onchange="this.form.submit()" required>
            <option value="">-- Select an event database --</option>
            <?php foreach ($all_databases as $db) {
                $selected = isset($_GET['config_db']) && $_GET['config_db'] === $db ? 'selected' : '';
                echo "<option value=\"$db\" $selected>$db</option>";
            } ?>
        </select>
    </form>
    <br>
    <form method="post">
        <input type="hidden" name="config_db" value="<?= htmlspecialchars($_GET['config_db'] ?? '') ?>">
        <table id="config-table" border="1" style="width:100%">
            <tr><th style="width:20%">Key</th><th style="width:5%">📝</th><th style="width:75%">Value (JSON if needed)</th></tr>
            <?php foreach ($config_data as $pair): ?>
            <tr>
              <td><input type="text" name="config_keys[]" value="<?= htmlspecialchars($pair['name']) ?>" oninput="this.value = this.value.toUpperCase(); addRow()" style="width: 100%"></td>
              <td></td>
              <td><input type="text" name="config_values[]" value="<?= htmlspecialchars($pair['value']) ?>" oninput="addRow()" style="width: 100%"></td>            
            </tr>
            <?php endforeach; ?>
            <tr>
                <td><input type="text" name="config_keys[]" oninput="this.value = this.value.toUpperCase(); addRow()" style="width: 100%"></td>
                <td></td>
                <td><input type="text" name="config_values[]" oninput="addRow()" style="width: 100%"></td>
            </tr>
        </table><br>
        <button type="submit">Save Configuration</button>
    </form>
</body>
</html>
