<?php
// event_admin.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/event_db.php';
require_once __DIR__ . '/logging.php';

$valid_events = list_events_from_master_with_status();
$empty_events = [];
$non_existant_events = [];
$not_valid_events = [];
foreach ($valid_events as $i => $event) {
    if ($event['status'] === EVENT_DB_EMPTY) {
        $empty_events[] = $event;
        unset($valid_events[$i]);
    } else if ($event['status'] === EVENT_NOT_EXIST) {
        $non_existant_events[] = $event;
        unset($valid_events[$i]);
    } else if ($event['status'] === EVENT_MALSTRUCTURED) {
        $not_valid_events[] = $event;
        unset($valid_events[$i]);
    }
}

$config_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['target_event']) && !isset($_POST['config_keys'])) {
    $new_event_name = trim($_POST['target_event']);
    if (!$new_event_name) {
        $message = "Event name is required.";
    } else {
        if ($result = create_event_db_tables($new_event_name)) {
            $message = "✅ Event database for $new_event_name initialized successfully.";
            log_msg(DEBUG_INFO, "Initialized $new_event_name successfully.");
        } else {
            $message = "❌ Event database for $new_event_name failed to initialize successfully.";
            log_msg(DEBUG_ERROR, "Initialization of $new_event_name failed.");
        };

        if ($result === true && isset($_POST['source_event']) && $_POST['source_event'] !== '') {
            $source_event = $_POST['source_event'];
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
                    log_msg(DEBUG_INFO, "Successfully cloned from $source_event into $new_event_name.");
                    $message .= "<br>✅ Cloned from $source_event into $new_event_name";
                } else {
                    $message .= "<br>❌ Clone config failed: " . $conn->error;
                    log_msg(DEBUG_ERROR, "Clone from $source_event into $new_event_name, failed: " . $conn->error);
                }

                $conn->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['event_description'], $_POST['save_event_description'], $_POST['edit_event_description'])) {

    $target_event = $_POST['edit_event_description'];
    $desc = trim((string)$_POST['event_description']);

    log_msg(DEBUG_INFO, "Description update triggered for event " . $target_event . ": " . $desc);

    $conn = connect_to_master();
    if (!$conn || $conn->connect_error) {
        $config_message = "❌ Failed to connect to master db";
        log_msg(DEBUG_ERROR, $config_message);
    } else {
        if ($desc === '') {
            // Empty → store NULL
            $stmt = $conn->prepare("UPDATE events SET description = NULL WHERE event_name = ?");
            if ($stmt === false) {
                $config_message = "❌ Prepare failed: " . $conn->error;
                log_msg(DEBUG_ERROR, $config_message);
            } else {
                $stmt->bind_param("s", $target_event);
                if (!$stmt->execute()) {
                    $config_message = "❌ Execute failed: " . $stmt->error;
                    log_msg(DEBUG_ERROR, $config_message);
                } else {
                    $stmt->close();
                    // PRG
                    header("Location: " . $_SERVER['PHP_SELF'] . "?config_event=" . urlencode($target_event));
                    log_msg(DEBUG_INFO, "blank event description stored");
                    exit;
                }
                $stmt->close();
            }
        } else {
            // Enforce 512-char cap
            if (mb_strlen($desc) > 512) {
                $desc = mb_substr($desc, 0, 512);
            }
            $stmt = $conn->prepare("UPDATE events SET description = ? WHERE event_name = ?");
            if ($stmt === false) {
                $config_message = "❌ Prepare failed: " . $conn->error;
                log_msg(DEBUG_ERROR, $config_message);
            } else {
                $stmt->bind_param("ss", $desc, $target_event);
                if (!$stmt->execute()) {
                    $config_message = "❌ Execute failed: " . $stmt->error;
                    log_msg(DEBUG_ERROR, $config_message);
                } else {
                    $stmt->close();
                    // PRG
                    header("Location: " . $_SERVER['PHP_SELF'] . "?config_event=" . urlencode($target_event));
                    log_msg(DEBUG_INFO, "New description for " . $target_event . ": " . $desc);
                    exit;
                }
                $stmt->close();
            }
        }
        // $conn->close(); // optional; connection will close at script end
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config_keys']) && isset($_POST['config_values']) && isset($_POST['config_event'])) {
    $target_event = $_POST['config_event'];
    $conn = get_event_db_connection_from_master($target_event);
    if (!$conn || $conn->connect_error) {
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

    <?php if (!empty(array_merge($non_existant_events, $not_valid_events))): ?>
      <p>Listed events with no db or invalid db:</p>
      <ul>
        <?php foreach (array_merge($non_existant_events, $not_valid_events) as $e) echo "<li>{$e['event_name']}</li>"; ?>
      </ul>
    <?php endif; ?>

    <form method="post">
      <label for="target_id">Empty Events (targets):</label>
      <select name="target_event" id="target_id" required>
        <option value="">-- Select a target event --</option>
        <?php foreach ($empty_events as $e): ?>
            <option value="<?= htmlspecialchars($e['event_name']) ?>">
                <?= htmlspecialchars($e['event_name']) ?> 
            </option>
        <?php endforeach; ?>
      </select>

      <label for="source_id">Source Events (for cloning):</label>
      <select name="source_event" id="source_id">
        <option value="">-- Choose clone source (optional) --</option>
        <?php foreach ($valid_events as $e): ?>
            <option value="<?= htmlspecialchars($e['event_name']) ?>">
                <?= htmlspecialchars($e['event_name']) ?> 
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
            <?php foreach ($valid_events as $e): ?>
                <?php $selected = isset($_GET['config_event']) && $_GET['config_event'] === $e['event_name'] ? 'selected' : ''; ?>
                <option value="<?= htmlspecialchars($e['event_name']) ?>" 
                    <?= $selected ?>><?= htmlspecialchars($e['event_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        </form>
        <br>
        <?php if (!isset($_GET['config_event']) || $_GET['config_event'] === ''): ?>
            <p><em>Select an event above to view or edit its configuration.</em></p>
        <?php else: ?>
        <!-- ===== Event Description (inline editor) ===== -->
        <div style="max-width:980px;border:1px solid #ccc;border-radius:8px;padding:12px;margin:10px 0;">
        <h2 style="margin:0 0 6px;">Event Description</h2>
        <p style="margin:4px 0;color:#666;">Short blurb shown on the front page/event picker (max 512 characters). Leave blank to hide.</p>
        <?php if (!empty($__flash_msg)): ?>
            <p style="margin:6px 0;color:#0a7a0a;font-weight:600;"><?php echo htmlspecialchars($__flash_msg); ?></p>
        <?php endif; ?>
        <form method="post" style="margin:0;">
            <input type="hidden" name="edit_event_description" value="<?php echo htmlspecialchars($_GET['config_event'] ?? ''); ?>">
            <textarea name="event_description" rows="3" maxlength="512" style="width:100%;"><?php
                $by_name = array_column($valid_events, 'event_description', 'event_name');
                echo htmlspecialchars($by_name[$_GET['config_event']]);
            ?></textarea>
            <div style="margin-top:8px;">
            <button type="submit" name="save_event_description" value="1">Save Description</button>
            </div>
        </form>
        </div>
        <!-- ============================================ -->
        <form method="post">
            <input type="hidden" name="config_event" value="<?= htmlspecialchars($_GET['config_event'] ?? '') ?>">
            <table id="config-table" border="1" style="width:100%">
                <tr><th style="width:20%">Key</th><th style="width:5%">📝</th><th style="width:75%">Value (JSON if needed)</th></tr>
                <?php foreach ($config_data as $name => $value): ?>
                <tr>
                <td><input type="text" name="config_keys[]" value="<?= htmlspecialchars($name) ?>" oninput="this.value = this.value.toUpperCase(); addRow()" style="width: 100%"></td>
                <td></td>
                <td><input type="text" name="config_values[]" value="<?= htmlspecialchars($value) ?>" oninput="addRow()" style="width: 100%"></td>            
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
