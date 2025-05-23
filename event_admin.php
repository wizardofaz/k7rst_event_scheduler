<?php
// event_admin.php

require_once 'config.php';
require_once 'event_db.php';

$event_names = list_events_from_master_with_status();
$empty_events = [];
foreach ($event_names as $i => $event_name) {
    if ($event_name['status'] === EVENT_DB_EMPTY) {
        $empty_events[] = $event_name;
        unset($event_names[$i]);
    }
}

$config_data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_name']) && !isset($_POST['config_keys'])) {
    $new_event_name = trim($_POST['event_name']);
    if (!$new_event_name) {
        $message = "Event name is required.";
    } else {
        if ($result = create_event_db_tables($new_event_name)) {
            $message = "✅ Event database for $new_event_name initialized successfully.";
        } else {
            $message = "❌ Event database for $new_event_name' failed to initialize successfully.";
        };

        if ($result === true && isset($_POST['clone_from']) && $_POST['clone_from'] !== '') {
            $source_event = $_POST['clone_from'];
            $source_config = get_event_config_as_kv($source_event);
            $conn = get_event_db_connection_from_master($new_event_name);
            if (!$conn->connect_error && !empty($source_config)) {
                $stmt = $conn->prepare("INSERT INTO event_config (name, value) VALUES (?, ?)");
                if ($stmt) {
                    foreach ($source_config as $name => $value) {
                        $stmt->bind_param("ss", $name, $value);
                        $stmt->execute();
                    }
                    $stmt->close();
                }
            }
            $conn->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config_keys']) && isset($_POST['config_values']) && isset($_POST['config_event'])) {
    $target_event = $_POST['config_event'];
    $conn = new mysqli(DB_SERVER, DB_ADMIN_USER, DB_ADMIN_PASSWORD, $target_event);
    if ($conn->connect_error) {
        $config_message = "❌ Failed to connect to $target_event";
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
            header("Location: " . $_SERVER['PHP_SELF'] . "?config_event=" . urlencode($target_event));
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['config_event'])) {
    $config_data = get_event_config_as_kv($_GET['config_event']);
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
    <h2>Create New Event</h2>
    <?php if (isset($message)) echo "<p><strong>$message</strong></p>"; ?>
    <?php if (!empty($empty_events)): ?>
      <p>Suggested empty events:</p>
      <ul>
        <?php foreach ($empty_events as $s) echo "<li>{$s['event_name']}</li>"; ?>
      </ul>
    <?php else: ?>
      <p><em>(No empty events found.)</em></p>
    <?php endif; ?>

    <form method="post">
      <label for="event_name">Existing Empty Event Name:</label>
      <select name="event_name" id="event_name" required>
        <option value="">-- Select an event --</option>
        <?php foreach ($empty_events as $event): ?>
            <option value="<?= htmlspecialchars($event['event_name']) ?>">
                <?= htmlspecialchars($event['event_name']) ?> 
            </option>
        <?php endforeach; ?>
      </select>

      <label for="clone_from">Clone config from:</label>
      <select name="clone_from" id="clone_from">
        <option value="">-- Optional --</option>
        <?php foreach ($event_names as $event): ?>
            <option value="<?= htmlspecialchars($event['event_name']) ?>">
                <?= htmlspecialchars($event['event_name']) ?> 
            </option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Initialize Tables</button>
    </form>

    <hr>
    <h2>Edit Event Configuration</h2>
    <?php if (isset($config_message)) echo "<p><strong>$config_message</strong></p>"; ?>
    <form method="get">
        <label for="config_event">Event:</label>
        <select name="config_event" id="config_event" onchange="this.form.submit()" required>
            <option value="">-- Select an event --</option>
            <?php foreach ($event_names as $event): ?>
                <?php $selected = isset($_GET['config_event']) && $_GET['config_event'] === $event['event_name'] ? 'selected' : ''; ?>
                <option value="<?= htmlspecialchars($event['event_name']) ?>" 
                    <?= $selected ?>><?= htmlspecialchars($event['event_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <br>
    <?php if (!isset($_GET['config_event']) || $_GET['config_event'] === ''): ?>
        <p><em>Select an event above to view or edit its configuration.</em></p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="config_event" value="<?= htmlspecialchars($_GET['config_event'] ?? '') ?>">
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
    <?php endif; ?>
</body>
</html>
