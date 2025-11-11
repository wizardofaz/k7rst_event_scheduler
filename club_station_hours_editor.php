<?php
// club_station_hours_editor.php — JSON editor/validator for CLUB_STATION_HOURS
declare(strict_types=1);
session_start();

// --- Optional during debugging ---
// error_reporting(E_ALL);
// ini_set('display_errors', '1');

/* ----------------- CSRF (double-submit cookie) ----------------- */
if (empty($_COOKIE['csh_csrf']) || !is_string($_COOKIE['csh_csrf'])) {
    $t = bin2hex(random_bytes(16));
    setcookie('csh_csrf', $t, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['csh_csrf'] = $t;
}
$csrf_cookie = (string)($_COOKIE['csh_csrf'] ?? '');

/* ----------------- Helpers ----------------- */
function h($s): string {
    if (is_string($s))        $out = $s;
    elseif (is_scalar($s))    $out = (string)$s;
    else {
        $out = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($out === false) $out = '[unprintable]';
    }
    return htmlspecialchars($out, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function is_assoc(array $a): bool {
    return $a === [] ? true : array_keys($a) !== range(0, count($a) - 1);
}
function json_error_message(): string {
    return match (json_last_error()) {
        JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
        JSON_ERROR_STATE_MISMATCH => 'Underflow or the modes mismatch',
        JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
        JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
        JSON_ERROR_UTF8 => 'Malformed UTF-8 characters (invalid UTF-8)',
        default => 'Unknown JSON error',
    };
}
function try_timezone(string $tz): bool {
    try { new DateTimeZone($tz); return true; } catch (Throwable) { return false; }
}
function try_dt(string $localYmdHi, DateTimeZone $tz): ?DateTimeImmutable {
    try { return new DateTimeImmutable($localYmdHi, $tz); } catch (Throwable) { return null; }
}
/** Always returns a string (never array/object) for POST values. */
function post_str(string $key): string {
    if (!isset($_POST[$key])) return '';
    $v = $_POST[$key];
    if (is_array($v)) $v = reset($v);
    return is_scalar($v) ? (string)$v : '';
}

/* Validate against Option A shape */
function validate_hours(array $root): array {
    $errors = [];
    $warn   = [];

    if (!is_assoc($root)) {
        $errors[] = 'Top-level JSON must be an object mapping station name → object.';
        return [$errors, $warn];
    }

    foreach ($root as $station => $cfg) {
        if (!is_string($station) || $station === '') {
            $errors[] = 'Station name keys must be non-empty strings.';
            continue;
        }
        if (!is_array($cfg)) {
            $errors[] = "Station \"$station\" must be an object.";
            continue;
        }
        $tzName = $cfg['tz'] ?? null;
        if (!is_string($tzName) || $tzName === '') {
            $errors[] = "Station \"$station\": missing \"tz\" (IANA timezone required).";
            continue;
        }
        if (!try_timezone($tzName)) {
            $errors[] = "Station \"$station\": invalid timezone \"$tzName\".";
            continue;
        }
        $tz = new DateTimeZone($tzName);

        if (!array_key_exists('windows', $cfg) || !is_array($cfg['windows'])) {
            $errors[] = "Station \"$station\": \"windows\" must be an array.";
            continue;
        }

        $windows = $cfg['windows'];
        $blackouts = $cfg['blackouts'] ?? [];

        $wParsed = [];
        foreach ($windows as $i => $win) {
            $prefix = "Station \"$station\" windows[$i]";
            if (!is_array($win)) { $errors[] = "$prefix must be an object."; continue; }
            $s = $win['start'] ?? null; $e = $win['end'] ?? null;
            if (!is_string($s) || !is_string($e) || $s === '' || $e === '') {
                $errors[] = "$prefix must have non-empty \"start\" and \"end\" strings (Y-m-d H:i).";
                continue;
            }
            $ds = try_dt($s, $tz); $de = try_dt($e, $tz);
            if (!$ds || !$de) { $errors[] = "$prefix has unparseable datetime(s)."; continue; }
            if ($ds >= $de)    { $errors[] = "$prefix has start >= end."; continue; }
            $wParsed[] = [$ds, $de, $i];
        }

        $bParsed = [];
        if (!is_array($blackouts)) {
            $errors[] = "Station \"$station\": \"blackouts\" must be an array if present.";
            $blackouts = [];
        }
        foreach ($blackouts as $i => $blk) {
            $prefix = "Station \"$station\" blackouts[$i]";
            if (!is_array($blk)) { $errors[] = "$prefix must be an object."; continue; }
            $s = $blk['start'] ?? null; $e = $blk['end'] ?? null;
            if (!is_string($s) || !is_string($e) || $s === '' || $e === '') {
                $errors[] = "$prefix must have non-empty \"start\" and \"end\" strings (Y-m-d H:i).";
                continue;
            }
            $ds = try_dt($s, $tz); $de = try_dt($e, $tz);
            if (!$ds || !$de) { $errors[] = "$prefix has unparseable datetime(s)."; continue; }
            if ($ds >= $de)    { $errors[] = "$prefix has start >= end."; continue; }
            $bParsed[] = [$ds, $de, $i];
        }

        // Overlap heads-up for windows
        usort($wParsed, fn($a,$b)=> $a[0] <=> $b[0]);
        for ($i=1; $i<count($wParsed); $i++) {
            [$ps, $pe] = $wParsed[$i-1];
            [$cs, $ce] = $wParsed[$i];
            if ($cs < $pe) {
                $warn[] = "Station \"$station\": windows overlap (indexes {$wParsed[$i-1][2]} & {$wParsed[$i][2]}).";
            }
        }

        // Optional: warn if window outside event dates
        if (defined('EVENT_START_DATE') && defined('EVENT_END_DATE')) {
            $es = try_dt(EVENT_START_DATE . ' 00:00', $tz);
            $ee = try_dt(EVENT_END_DATE   . ' 23:59', $tz);
            if ($es && $ee) {
                foreach ($wParsed as [$ws,$we,$idx]) {
                    if ($we < $es || $ws > $ee) {
                        $warn[] = "Station \"$station\": windows[$idx] is outside event dates.";
                    }
                }
            }
        }
    }

    return [$errors, $warn];
}

function normalize_hours(array $root): array {
    $stations = array_keys($root);
    sort($stations, SORT_NATURAL | SORT_FLAG_CASE);
    $out = [];
    foreach ($stations as $station) {
        $cfg = $root[$station];
        $tz = $cfg['tz'] ?? 'UTC';
        $windows = $cfg['windows'] ?? [];
        $blackouts = $cfg['blackouts'] ?? [];

        usort($windows,   fn($a,$b)=>($a['start'] ?? '') <=> ($b['start'] ?? ''));
        usort($blackouts, fn($a,$b)=>($a['start'] ?? '') <=> ($b['start'] ?? ''));

        $out[$station] = [
            'tz' => $tz,
            'windows' => array_values($windows),
        ];
        if (!empty($blackouts)) $out[$station]['blackouts'] = array_values($blackouts);
    }
    return $out;
}

/* ----------------- Request state ----------------- */
$inputJson = '';
$resultJson = '';
$messages  = [];
$warnings  = [];
$hadPost   = ($_SERVER['REQUEST_METHOD'] === 'POST');

/* On first load, show an example so the page isn't blank */
if (!$hadPost) {
    $example = [
        "N7NBV Barn" => [
            "tz" => "America/Phoenix",
            "windows" => [
                ["start"=>"2025-11-21 08:00","end"=>"2025-11-21 12:00"],
                ["start"=>"2025-11-21 17:00","end"=>"2025-11-21 22:00"],
                ["start"=>"2025-11-22 23:00","end"=>"2025-11-23 02:00"]
            ],
            "blackouts" => [
                ["start"=>"2025-11-24 00:00","end"=>"2025-11-24 23:59"]
            ]
        ],
        "Main Club Station" => [
            "tz" => "America/Phoenix",
            "windows" => [
                ["start"=>"2025-11-23 09:00","end"=>"2025-11-23 18:00"]
            ]
        ]
    ];
    $resultJson = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/* ----------------- Handle POST ----------------- */
if ($hadPost) {
    $action    = post_str('action');         // validate | lint | download | example
    $inputJson = post_str('json');
    $postCsrf  = post_str('csrf');

    // CSRF guard: cookie vs hidden field
    $csrf_ok = ($postCsrf !== '' && $csrf_cookie !== '' && hash_equals($csrf_cookie, $postCsrf));
    if (!$csrf_ok) {
        http_response_code(400);
        $messages[] = ['type' => 'error', 'text' => 'Bad CSRF token. Reload and try again.'];
        $hadPost = false; // abort handling
    }
}

if ($hadPost) {
    if ($action === 'example') {
        $arr = json_decode($resultJson, true);
        if (!is_array($arr)) $arr = [];
        $resultJson = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $messages[] = ['type'=>'ok', 'text'=>'Loaded example JSON.'];
    } elseif ($action === 'lint' || $action === 'validate') {
        if ($inputJson === '') {
            $messages[] = ['type'=>'error', 'text'=>'Please paste JSON first.'];
        } else {
            $arr = json_decode($inputJson, true);
            if ($arr === null && json_last_error() !== JSON_ERROR_NONE) {
                $messages[] = ['type'=>'error', 'text'=>'JSON parse error: '.json_error_message()];
            } elseif (!is_array($arr)) {
                $messages[] = ['type'=>'error', 'text'=>'Top-level JSON must be an object (mapping station names).'];
            } else {
                [$errs, $warn] = validate_hours($arr);
                if ($errs) {
                    foreach ($errs as $e) $messages[] = ['type'=>'error', 'text'=>$e];
                } else {
                    foreach ($warn as $w) $warnings[] = $w;
                    if ($action === 'validate') {
                        $norm = normalize_hours($arr);
                        $resultJson = json_encode($norm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        $messages[] = ['type'=>'ok', 'text'=>'Valid JSON. Pretty-printed and normalized.'];
                    } else {
                        $resultJson = $inputJson; // keep original formatting
                        $messages[] = ['type'=>'ok', 'text'=>'Valid JSON. Left formatting unchanged.'];
                    }
                }
            }
        }
    } elseif ($action === 'download') {
        if ($inputJson === '') {
            $messages[] = ['type'=>'error', 'text'=>'Please paste JSON first.'];
        } else {
            $arr = json_decode($inputJson, true);
            if ($arr === null && json_last_error() !== JSON_ERROR_NONE) {
                $messages[] = ['type'=>'error', 'text'=>'JSON parse error: '.json_error_message()];
            } else {
                [$errs, $warn] = validate_hours($arr);
                if ($errs) {
                    foreach ($errs as $e) $messages[] = ['type'=>'error', 'text'=>$e];
                } else {
                    $norm   = normalize_hours($arr);
                    $pretty = json_encode($norm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $filename = 'club_station_hours.json';
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename="'.$filename.'"');
                    header('Content-Length: '.strlen($pretty));
                    echo $pretty;
                    exit;
                }
            }
        }
    } else {
        $messages[] = ['type'=>'info', 'text'=>'Choose an action.'];
    }
}

// If no new result produced, keep textarea showing last input
if ($resultJson === '') $resultJson = $inputJson;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Club Station Hours — JSON Editor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { color-scheme: light dark; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, sans-serif; margin: 24px; background: #111; color: #eee; }
  h1 { margin: 0 0 16px; font-weight: 800; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
  @media (max-width: 980px){ .grid { grid-template-columns: 1fr; } }
  .bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
  textarea {
    width: 100%; min-height: 60vh;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: 13px; line-height: 1.4; padding: 12px; border-radius: 10px;
    background: #1b1b1b; color: #f5f5f5; border: 1px solid #3a3a3a;
    box-shadow: inset 0 0 0 1px #000;
  }
  textarea:focus { outline: 2px solid #caa574; }
  button {
    padding: 10px 14px; border-radius: 10px; border: 1px solid #3a3a3a;
    background: #222; color: #f5f5f5; cursor: pointer;
    box-shadow: 0 1px 0 rgba(0,0,0,.6);
  }
  button:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,.25); }
  button.primary { background: #caa574; color: #111; border-color: #caa574; font-weight: 700; }
  .card { background:#151515; border:1px solid #2a2a2a; border-radius:12px; padding:14px; }
  .row { display:flex; gap:8px; align-items:center; }
  .col { display:flex; flex-direction:column; gap:6px; }
  .field { display:flex; flex-direction:column; gap:4px; }
  input[type="text"], select, input[type="datetime-local"] {
    background:#1b1b1b; color:#f5f5f5; border:1px solid #3a3a3a; border-radius:8px; padding:8px 10px;
  }
  .list { display:flex; flex-direction:column; gap:8px; }
  .list .item { display:grid; grid-template-columns: 1fr 1fr auto; gap:8px; align-items:center; }
  .list .item button { padding:8px 10px; }
  .msg { border-left: 4px solid; padding: 10px 12px; border-radius: 8px; margin: 10px 0; background: #1b1b1b; }
  .ok { border-color: #4caf50; }
  .error { border-color: #ff5252; }
  .warn { border-color: #ff9800; }
  .info { border-color: #64b5f6; }
  .notes { font-size: 0.9em; color: #cfcfcf; margin-top: 10px; }
  .splithead { display:flex; align-items:center; justify-content:space-between; margin: 8px 0 12px; }
</style>
</head>
<body>
  <h1>Club Station Hours — JSON Editor</h1>

  <div class="grid">
    <!-- LEFT: JSON / server actions -->
    <div class="card">
      <form method="post" autocomplete="off" id="jsonForm">
        <input type="hidden" name="csrf" value="<?=h($csrf_cookie)?>">
        <div class="bar">
          <button class="primary" name="action" value="validate" title="Parse, lint, sort, and pretty-print">Validate &amp; Pretty-print</button>
          <button name="action" value="lint" title="Parse &amp; lint only; keep your formatting">Lint Only</button>
          <button name="action" value="download" title="Validate and download normalized JSON">Download JSON</button>
          <button name="action" value="example" type="submit" title="Load a minimal valid template">Start from Example</button>
        </div>

        <?php
          $textareaVal = is_string($resultJson)
            ? $resultJson
            : json_encode($resultJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ?>
        <textarea id="jsonText" name="json" placeholder="Paste club station hours JSON here (Option A shape: station → { tz, windows[], blackouts[] })"><?=h($textareaVal ?? '')?></textarea>

        <div class="notes">
          <p><strong>Format:</strong> Local times per station, <code>Y-m-d H:i</code>. Half-open intervals (end exclusive).</p>
          <p><strong>Keys:</strong> <code>{ "Station Name": { "tz": "America/Phoenix", "windows": [ { "start": "...", "end": "..." } ], "blackouts": [ ... ] } }</code></p>
          <p>CSRF cookie (masked): <?=h(substr($csrf_cookie,0,6))?>…</p>
        </div>
      </form>

      <?php foreach ($messages as $m): ?>
        <div class="msg <?=h($m['type'])?>"><?=h($m['text'])?></div>
      <?php endforeach; ?>

      <?php if ($warnings): ?>
        <div class="msg warn">
          <strong>Warnings:</strong>
          <ul>
            <?php foreach ($warnings as $w): ?>
              <li><?=h($w)?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="msg info">
        Tip: After “Validate &amp; Pretty-print,” copy the JSON above and paste it back into your event config storage.
        This page does not write to your database.
      </div>
    </div>

    <!-- RIGHT: Form editor -->
    <div class="card">
      <div class="splithead">
        <strong>Form Editor</strong>
        <div class="row">
          <button id="btnLoad">Load from JSON</button>
          <button id="btnQuickLint">Quick Validate (client)</button>
          <button class="primary" id="btnApply">Apply to JSON</button>
        </div>
      </div>

      <div class="field">
        <label for="stationPick"><strong>Station</strong></label>
        <div class="row" style="gap:8px; flex-wrap:wrap;">
          <select id="stationPick"></select>
          <button id="btnAddStation">+ Add Station</button>
          <button id="btnRenameStation">Rename</button>
          <button id="btnDeleteStation">Delete</button>
        </div>
      </div>

      <div class="field" style="margin-top:8px;">
        <label for="tzInput"><strong>Timezone (IANA)</strong></label>
        <input list="tzList" id="tzInput" type="text" placeholder="e.g., America/Phoenix">
        <datalist id="tzList">
          <option value="America/Phoenix">
          <option value="America/Denver">
          <option value="America/Los_Angeles">
          <option value="America/Chicago">
          <option value="America/New_York">
          <option value="UTC">
          <option value="Europe/London">
          <option value="Europe/Berlin">
          <option value="Asia/Tokyo">
          <option value="Australia/Sydney">
        </datalist>
      </div>

      <div class="col" style="margin-top:14px;">
        <strong>Windows</strong>
        <div id="windowsList" class="list"></div>
        <button id="btnAddWindow" style="margin-top:6px;">+ Add Window</button>
      </div>

      <div class="col" style="margin-top:14px;">
        <strong>Blackouts</strong>
        <div id="blackoutsList" class="list"></div>
        <button id="btnAddBlackout" style="margin-top:6px;">+ Add Blackout</button>
      </div>

      <div id="clientLint" class="msg info" style="display:none; margin-top:14px;"></div>
    </div>
  </div>

  <script>
  // ---------- Editor state ----------
  const jsonText = document.getElementById('jsonText');
  const stationPick = document.getElementById('stationPick');
  const tzInput = document.getElementById('tzInput');
  const windowsList = document.getElementById('windowsList');
  const blackoutsList = document.getElementById('blackoutsList');
  const clientLint = document.getElementById('clientLint');

  const btnLoad = document.getElementById('btnLoad');
  const btnApply = document.getElementById('btnApply');
  const btnQuickLint = document.getElementById('btnQuickLint');

  const btnAddStation = document.getElementById('btnAddStation');
  const btnRenameStation = document.getElementById('btnRenameStation');
  const btnDeleteStation = document.getElementById('btnDeleteStation');

  const btnAddWindow = document.getElementById('btnAddWindow');
  const btnAddBlackout = document.getElementById('btnAddBlackout');

  // The model is a plain JS object: { stationName: { tz, windows: [{start,end}], blackouts: [...] } }
  let model = {};

  function safeParse(text) {
    try { return JSON.parse(text); } catch { return null; }
  }
  function pretty(obj) {
    return JSON.stringify(obj, null, 2);
  }
  function clone(obj) { return JSON.parse(JSON.stringify(obj)); }

  function toDTLocalValue(ymdHi) {
    // 'YYYY-MM-DD HH:MM' -> 'YYYY-MM-DDTHH:MM' for datetime-local
    if (!ymdHi || typeof ymdHi !== 'string') return '';
    return ymdHi.replace(' ', 'T');
  }
  function fromDTLocalValue(dt) {
    // 'YYYY-MM-DDTHH:MM' -> 'YYYY-MM-DD HH:MM'
    if (!dt || typeof dt !== 'string') return '';
    return dt.replace('T', ' ');
  }

  function currentStation() {
    return stationPick.value || '';
  }

  function refreshStationPicker(selectName=null) {
    const names = Object.keys(model).sort((a,b)=> a.localeCompare(b, undefined, {numeric:true, sensitivity:'base'}));
    stationPick.innerHTML = '';
    for (const n of names) {
      const opt = document.createElement('option');
      opt.value = n; opt.textContent = n;
      stationPick.appendChild(opt);
    }
    if (selectName && names.includes(selectName)) {
      stationPick.value = selectName;
    } else {
      stationPick.value = names[0] || '';
    }
    renderStation();
  }

  function renderStation() {
    clientLint.style.display = 'none';
    const name = currentStation();
    const cfg = model[name];
    if (!cfg) {
      tzInput.value = '';
      windowsList.innerHTML = '';
      blackoutsList.innerHTML = '';
      return;
    }
    tzInput.value = cfg.tz || '';

    // Windows
    windowsList.innerHTML = '';
    (cfg.windows || []).forEach((w, idx) => {
      const row = document.createElement('div');
      row.className = 'item';
      const s = document.createElement('input');
      s.type = 'datetime-local';
      s.value = toDTLocalValue(w.start);
      const e = document.createElement('input');
      e.type = 'datetime-local';
      e.value = toDTLocalValue(w.end);
      const del = document.createElement('button');
      del.textContent = 'Delete';
      del.addEventListener('click', (ev)=>{ ev.preventDefault(); cfg.windows.splice(idx,1); renderStation(); });
      s.addEventListener('change', ()=>{ w.start = fromDTLocalValue(s.value); });
      e.addEventListener('change', ()=>{ w.end   = fromDTLocalValue(e.value); });
      row.appendChild(s); row.appendChild(e); row.appendChild(del);
      windowsList.appendChild(row);
    });

    // Blackouts
    blackoutsList.innerHTML = '';
    (cfg.blackouts || []).forEach((b, idx) => {
      const row = document.createElement('div');
      row.className = 'item';
      const s = document.createElement('input');
      s.type = 'datetime-local';
      s.value = toDTLocalValue(b.start);
      const e = document.createElement('input');
      e.type = 'datetime-local';
      e.value = toDTLocalValue(b.end);
      const del = document.createElement('button');
      del.textContent = 'Delete';
      del.addEventListener('click', (ev)=>{ ev.preventDefault(); cfg.blackouts.splice(idx,1); renderStation(); });
      s.addEventListener('change', ()=>{ b.start = fromDTLocalValue(s.value); });
      e.addEventListener('change', ()=>{ b.end   = fromDTLocalValue(e.value); });
      row.appendChild(s); row.appendChild(e); row.appendChild(del);
      blackoutsList.appendChild(row);
    });
  }

  // Add/rename/delete station
  btnAddStation.addEventListener('click', (ev)=>{
    ev.preventDefault();
    const name = prompt('New station name:');
    if (!name) return;
    if (model[name]) { alert('That station already exists.'); return; }
    model[name] = { tz: 'America/Phoenix', windows: [], blackouts: [] };
    refreshStationPicker(name);
  });

  btnRenameStation.addEventListener('click', (ev)=>{
    ev.preventDefault();
    const oldName = currentStation();
    if (!oldName) return;
    const newName = prompt('Rename station to:', oldName);
    if (!newName || newName === oldName) return;
    if (model[newName]) { alert('A station with that name already exists.'); return; }
    model[newName] = model[oldName];
    delete model[oldName];
    refreshStationPicker(newName);
  });

  btnDeleteStation.addEventListener('click', (ev)=>{
    ev.preventDefault();
    const name = currentStation();
    if (!name) return;
    if (!confirm(`Delete station "${name}"?`)) return;
    delete model[name];
    refreshStationPicker();
  });

  // Change selected station
  stationPick.addEventListener('change', ()=>{
    renderStation();
  });

  // TZ change
  tzInput.addEventListener('change', ()=>{
    const name = currentStation();
    if (!name) return;
    model[name].tz = tzInput.value.trim();
  });

  // Add window/blackout
  btnAddWindow.addEventListener('click', (ev)=>{
    ev.preventDefault();
    const name = currentStation();
    if (!name) return;
    const cfg = model[name];
    cfg.windows = cfg.windows || [];
    cfg.windows.push({ start: '', end: '' });
    renderStation();
  });

  btnAddBlackout.addEventListener('click', (ev)=>{
    ev.preventDefault();
    const name = currentStation();
    if (!name) return;
    const cfg = model[name];
    cfg.blackouts = cfg.blackouts || [];
    cfg.blackouts.push({ start: '', end: '' });
    renderStation();
  });

  // Load from JSON (textarea -> editor)
  btnLoad.addEventListener('click', (ev)=>{
    ev.preventDefault();
    const obj = safeParse(jsonText.value.trim());
    if (!obj || typeof obj !== 'object' || Array.isArray(obj)) {
      alert('Paste valid JSON (top-level object).');
      return;
    }
    model = obj;
    refreshStationPicker();
  });

  // Apply to JSON (editor -> textarea)
  btnApply.addEventListener('click', (ev)=>{
    ev.preventDefault();
    // Clean up: ensure tz exists, arrays exist, sort by start
    for (const [name, cfg] of Object.entries(model)) {
      if (!cfg || typeof cfg !== 'object') { delete model[name]; continue; }
      if (!cfg.tz) cfg.tz = 'UTC';
      cfg.windows = Array.isArray(cfg.windows) ? cfg.windows : [];
      cfg.blackouts = Array.isArray(cfg.blackouts) ? cfg.blackouts : [];
      cfg.windows = cfg.windows.filter(w => w && w.start && w.end);
      cfg.blackouts = cfg.blackouts.filter(b => b && b.start && b.end);
      cfg.windows.sort((a,b)=> (a.start||'').localeCompare(b.start||''));
      cfg.blackouts.sort((a,b)=> (a.start||'').localeCompare(b.start||''));
    }
    // Sort stations by name
    const ordered = {};
    Object.keys(model).sort((a,b)=> a.localeCompare(b, undefined, {numeric:true, sensitivity:'base'}))
      .forEach(k => ordered[k] = model[k]);
    jsonText.value = pretty(ordered);
    // Optional: quick lint feedback
    quickLint();
  });

  // Client-side quick lint
  function quickLint() {
    const issues = [];
    // shape
    if (!model || typeof model !== 'object' || Array.isArray(model)) {
      issues.push('Top-level must be an object.');
    }
    for (const [station, cfg] of Object.entries(model)) {
      if (!station.trim()) issues.push('Station name cannot be empty.');
      if (!cfg || typeof cfg !== 'object') { issues.push(`Station "${station}" must be an object.`); continue; }
      if (!cfg.tz || typeof cfg.tz !== 'string') issues.push(`Station "${station}" missing tz string.`);
      if (!Array.isArray(cfg.windows)) issues.push(`Station "${station}" windows must be an array.`);
      if (cfg.blackouts && !Array.isArray(cfg.blackouts)) issues.push(`Station "${station}" blackouts must be an array.`);
      const lists = [['windows', cfg.windows||[]], ['blackouts', cfg.blackouts||[]]];
      for (const [label, arr] of lists) {
        arr.forEach((row, idx)=>{
          if (!row || typeof row !== 'object') issues.push(`Station "${station}" ${label}[${idx}] must be an object.`);
          if (!row.start || !row.end) issues.push(`Station "${station}" ${label}[${idx}] requires start and end.`);
          if (row.start && row.end && row.start >= row.end) issues.push(`Station "${station}" ${label}[${idx}] start >= end.`);
        });
        if (label === 'windows') {
          // overlap heads-up (naive local compare)
          const sorted = [...arr].sort((a,b)=> (a.start||'').localeCompare(b.start||''));
          for (let i=1; i<sorted.length; i++) {
            const prev = sorted[i-1], cur = sorted[i];
            if (cur.start < prev.end) {
              issues.push(`Station "${station}" windows overlap around ${cur.start}.`);
              break;
            }
          }
        }
      }
    }
    clientLint.className = 'msg ' + (issues.length ? 'warn' : 'ok');
    clientLint.style.display = 'block';
    clientLint.innerHTML = issues.length
      ? '<strong>Client checks:</strong><ul style="margin:6px 0 0 18px;">' + issues.map(i=>`<li>${escapeHtml(i)}</li>`).join('') + '</ul>'
      : 'Client checks: OK.';
  }

  btnQuickLint.addEventListener('click', (ev)=>{ ev.preventDefault(); quickLint(); });

  function escapeHtml(s) {
    return s.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  // Initialize editor from the preloaded example so the right panel isn't empty
  (()=> {
    const obj = safeParse(jsonText.value.trim());
    model = obj && typeof obj === 'object' && !Array.isArray(obj) ? obj : {};
    refreshStationPicker();
  })();
  </script>
</body>
</html>
