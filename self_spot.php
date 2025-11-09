<?php
declare(strict_types=1);

/*
 self_spot.php — CACTUS self-spot tool (DX cluster via TCP/telnet)

 Requirements (defined by event config / app context):
   - EVENT_NAME (string)
   - EVENT_CALLSIGNS (array of strings, e.g., ["K7C","K7A","N7C","K7T","K7U","K7S"])
   - CLUSTER_NODES (array of nodes: each has id,name,host,port[,prompt])

 Auth:
   - Requires an already-active session & logged-in user; fails politely (403) if not.

 Logging:
   - Uses log_msg(DEBUG_*, string) from logging.php
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/event_db.php';

// NOTE: Do NOT call session_start() here. Require an active session.
if (session_status() !== PHP_SESSION_ACTIVE) {
    http_response_code(403);
    log_msg(DEBUG_WARNING, 'selfspot: no active session (not started here)');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Not authorized</title></head><body>";
    echo "<h2>Not authorized</h2><p>Please access this page from the CACTUS scheduler while logged in.</p></body></html>";
    exit;
}

// --- Hard requirements: constants must exist ---
if (!defined('EVENT_CALLSIGNS') || !is_array(EVENT_CALLSIGNS)) {
    http_response_code(500);
    log_msg(DEBUG_ERROR, 'selfspot: EVENT_CALLSIGNS not defined or not array');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Configuration error</title></head><body>";
    echo "<h2>Configuration error</h2><p>EVENT_CALLSIGNS is not defined.</p></body></html>";
    exit;
}
if (!defined('CLUSTER_NODES') || !is_array(CLUSTER_NODES) || count(CLUSTER_NODES) === 0) {
    http_response_code(500);
    log_msg(DEBUG_ERROR, 'selfspot: CLUSTER_NODES not defined or empty');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Configuration error</title></head><body>";
    echo "<h2>Configuration error</h2><p>CLUSTER_NODES is not defined.</p></body></html>";
    exit;
}

$CLUSTER_NODES   = CLUSTER_NODES;
$EVENT_CALLSIGNS = EVENT_CALLSIGNS;

// --- Auth gate ---
$logged_in_call   = auth_get_callsign();
$logged_in_name   = auth_get_name();
$edit_authorized  = auth_is_authenticated();

if (!$edit_authorized || !$logged_in_call) {
    http_response_code(403);
    log_msg(DEBUG_WARNING, 'selfspot: unauthorized access attempt (no auth or callsign)');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Not authorized</title></head><body>";
    echo "<h2>Not authorized</h2><p>You must be logged in to use the CACTUS self-spot tool.</p></body></html>";
    exit;
}

// --- CSRF token (requires active session) ---
if (empty($_SESSION['selfspot_csrf'])) {
    $_SESSION['selfspot_csrf'] = bin2hex(random_bytes(16));
}
$CSRF_TOKEN = $_SESSION['selfspot_csrf'];

// --- Rate limit knobs ---
const SELFSPOT_MIN_INTERVAL = 15; // seconds
const SELFSPOT_MAX_PER_HOUR = 5;  // per session

// --- Optional test time override (UTC) ---
// Accepts ?test_utc=YYYY-MM-DDTHH:MM[:SS][Z] or ?test_date=YYYY-MM-DD&test_time=HH:MM[:SS]
$using_test_time = false;
$overrideUtc = null;
if (!empty($_GET['test_utc'])) {
    $raw = trim((string)$_GET['test_utc']);
    // Allow trailing Z or not
    $raw = rtrim($raw, 'Zz');
    try {
        $overrideUtc = new DateTime($raw, new DateTimeZone('UTC'));
        $using_test_time = true;
    } catch (Throwable $e) {
        log_msg(DEBUG_WARNING, 'selfspot: invalid test_utc ' . $raw);
    }
} elseif (!empty($_GET['test_date']) && !empty($_GET['test_time'])) {
    $raw = trim((string)$_GET['test_date']) . ' ' . trim((string)$_GET['test_time']);
    try {
        $overrideUtc = new DateTime($raw, new DateTimeZone('UTC'));
        $using_test_time = true;
    } catch (Throwable $e) {
        log_msg(DEBUG_WARNING, 'selfspot: invalid test_date/test_time ' . json_encode($_GET));
    }
}

// --- Schedule prefill (current hour if < :30 past; otherwise upcoming hour) ---
$SELFSPOT_PREFILL = [
    'event_call' => strtoupper($EVENT_CALLSIGNS[0] ?? $logged_in_call ?? 'K7RST'),
    'band'       => null,
    'mode'       => null,
    'freq_khz'   => null,
    'target_date'=> null,
    'target_time'=> null,
    'matched'    => false,
];

$nowUtc = $overrideUtc ?: new DateTime('now', new DateTimeZone('UTC'));
$target = clone $nowUtc;
if ((int)$nowUtc->format('i') >= 30) {
    $target->modify('+1 hour');
}
$date = $target->format('Y-m-d');
$time = $target->format('H:00:00');

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
    if ($cs_eq($row['op_call'], $logged_in_call)) { $matched = $row; break; }
}

if ($matched) {
    if (!empty($matched['assigned_call'])) $SELFSPOT_PREFILL['event_call'] = strtoupper(trim((string)$matched['assigned_call']));
    if (!empty($matched['band']))         $SELFSPOT_PREFILL['band']       = strtolower(trim((string)$matched['band']));
    if (!empty($matched['mode']))         $SELFSPOT_PREFILL['mode']       = strtoupper(trim((string)$matched['mode']));
    $SELFSPOT_PREFILL['target_date'] = $date;
    $SELFSPOT_PREFILL['target_time'] = $time;
    $SELFSPOT_PREFILL['matched']     = true;
}

// Band/mode → suggested frequency (kHz)
$SUGGESTED_FREQ_KHZ = [
    '160m' => ['CW'=>1805,  'SSB'=>1880, 'FT8'=>1840],
    '80m'  => ['CW'=>3565,  'SSB'=>3850, 'FT8'=>3573],
    '40m'  => ['CW'=>7035,  'SSB'=>7200, 'FT8'=>7074],
    '30m'  => ['CW'=>10115, 'FT8'=>10136],
    '20m'  => ['CW'=>14035, 'SSB'=>14250, 'FT8'=>14074],
    '17m'  => ['CW'=>18085, 'SSB'=>18130, 'FT8'=>18100],
    '15m'  => ['CW'=>21035, 'SSB'=>21250, 'FT8'=>21074],
    '12m'  => ['CW'=>24905, 'SSB'=>24950, 'FT8'=>24915],
    '10m'  => ['CW'=>28035, 'SSB'=>28400, 'FT8'=>28074],
    '6m'   => ['CW'=>50095, 'SSB'=>50125, 'FT8'=>50313],
];
if ($SELFSPOT_PREFILL['band'] && isset($SUGGESTED_FREQ_KHZ[$SELFSPOT_PREFILL['band']])) {
    $modeMap = $SUGGESTED_FREQ_KHZ[$SELFSPOT_PREFILL['band']];
    $SELFSPOT_PREFILL['freq_khz'] = $SELFSPOT_PREFILL['mode'] && isset($modeMap[$SELFSPOT_PREFILL['mode']])
        ? $modeMap[$SELFSPOT_PREFILL['mode']]
        : (array_values($modeMap)[0] ?? null);
}

// --- Helpers ---
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function normalize_freq_to_khz(string $input): ?int {
    $s = trim($input);
    if ($s === '') return null;
    $s = str_replace([',',' '], '', $s);
    if (strpos($s, '.') !== false) {
        if (!is_numeric($s)) return null;
        return (int)round(((float)$s) * 1000.0);
    }
    if (!ctype_digit($s)) return null;
    $val = (int)$s;
    if ($val < 100) return null;
    return $val;
}

function sanitize_comment(string $c, int $max = 160): string {
    $c = str_replace(["\r","\n"], ' ', trim($c));
    if (mb_strlen($c) > $max) $c = mb_substr($c, 0, $max);
    return $c;
}

function read_until_prompt($fp, float $timeoutSeconds = 3.0): string {
    if (!is_resource($fp)) return '';
    stream_set_blocking($fp, false);
    $start = microtime(true);
    $buf = '';
    while ((microtime(true) - $start) < $timeoutSeconds) {
        $chunk = @fread($fp, 4096);
        if ($chunk === false || $chunk === '') { usleep(50000); continue; }
        $buf .= $chunk;
        if (preg_match('/(^|\n)[^\n]{0,60}>\s*$/m', $buf) || preg_match('/dxspider>/i', $buf)) { break; }
        if (strlen($buf) > 8192) break;
    }
    $buf = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $buf);
    return trim($buf);
}

// --- Handle POST (send spot) ---
$feedback = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['selfspot_csrf'] ?? '', (string)$token)) {
        $errors[] = 'Invalid CSRF token.';
        log_msg(DEBUG_WARNING, 'selfspot: CSRF mismatch');
    }

    // Rate limiting
    $now = time();
    $last        = $_SESSION['selfspot_last_time']  ?? 0;
    $count_hour  = $_SESSION['selfspot_count_hour'] ?? 0;
    $hour_start  = $_SESSION['selfspot_hour_start'] ?? ($now - ($now % 3600));
    if ($now - $hour_start >= 3600) { $hour_start = $now - ($now % 3600); $count_hour = 0; }
    if ($now - $last < SELFSPOT_MIN_INTERVAL) $errors[] = 'Too soon since last spot. Please wait a few seconds.';
    if ($count_hour >= SELFSPOT_MAX_PER_HOUR) $errors[] = 'Rate limit: maximum spots per hour reached.';

    // Gather/validate
    $login_call = strtoupper(trim((string)$logged_in_call));
    $event_call = strtoupper(trim((string)$_POST['event_call'] ?? ''));
    $freq_input = trim((string)($_POST['frequency'] ?? ''));
    $comment_raw= (string)($_POST['comment'] ?? '');
    $node_id    = trim((string)($_POST['node_id'] ?? ''));

    if ($login_call === '') $errors[] = 'Login callsign unavailable.';
    if ($event_call === '') $errors[] = 'Event callsign required.';

    $freq_khz = normalize_freq_to_khz($freq_input);
    if ($freq_khz === null) $errors[] = 'Frequency not recognized. Use MHz (14.035) or kHz (14035).';

    // Node selection
    $node = null;
    foreach ($CLUSTER_NODES as $n) { if (($n['id'] ?? '') === $node_id) { $node = $n; break; } }
    if ($node === null) { $node = $CLUSTER_NODES[0]; } // default first

    $comment = sanitize_comment($comment_raw, 160);
    if (stripos($comment, 'cactus') === false) $comment = trim($comment . ' CACTUS Special Event');

    if (empty($errors)) {
        $dx_cmd = sprintf('dx %d %s %s', $freq_khz, $event_call, $comment);
        $host = $node['host'] ?? '';
        $port = (int)($node['port'] ?? 23);

        $remote = "tcp://{$host}:{$port}";
        $t0 = microtime(true);
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 4.0, STREAM_CLIENT_CONNECT);
        $elapsed_ms_connect = (int)((microtime(true) - $t0) * 1000);

        if (!$fp) {
            $errors[] = "Could not connect to cluster node {$host}:{$port} — {$errstr} ({$errno})";
            log_msg(DEBUG_ERROR, 'selfspot: connect fail ' . json_encode(['host'=>$host,'port'=>$port,'errno'=>$errno,'err'=>$errstr]));
        } else {
            stream_set_timeout($fp, 3);

            $banner = read_until_prompt($fp, 2.0);
            fwrite($fp, $login_call . "\r\n");
            $after_login = read_until_prompt($fp, 2.0);
            fwrite($fp, $dx_cmd . "\r\n");
            $after_dx = read_until_prompt($fp, 2.0);
            fclose($fp);

            $elapsed_total_ms = (int)((microtime(true) - $t0) * 1000);

            $feedback = [
                'node' => $node['name'] ?? ($host . ':' . $port),
                'host' => $host,
                'port' => $port,
                'connect_ms' => $elapsed_ms_connect,
                'total_ms' => $elapsed_total_ms,
                'dx_cmd' => $dx_cmd,
                'after_dx' => $after_dx,
            ];

            // Rate-limit counters
            $_SESSION['selfspot_last_time']  = $now;
            $_SESSION['selfspot_hour_start'] = $hour_start;
            $_SESSION['selfspot_count_hour'] = $count_hour + 1;

            log_msg(DEBUG_INFO, 'selfspot: sent ' . json_encode([
                'user'=>$login_call,'event_call'=>$event_call,'kHz'=>$freq_khz,'node'=>$node['id'] ?? $host
            ]));
        }
    } else {
        log_msg(DEBUG_WARNING, 'selfspot: validation errors ' . json_encode($errors));
    }
}

// --- Render HTML ---
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>CACTUS Self Spot</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; padding: 14px; color:#111; background: #fff; }
  .card { max-width:820px; margin: 0 auto; border:1px solid #ddd; padding:14px; border-radius:8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
  label { display:block; margin-top:10px; font-weight:600; }
  input[type=text], textarea, select { width:100%; padding:8px; margin-top:6px; box-sizing: border-box; font-size:14px; }
  .row { display:flex; gap:8px; }
  .col { flex:1; }
  .small { font-size:13px; color:#555; }
  button { margin-top:12px; padding:8px 12px; font-size:15px; border-radius:6px; cursor:pointer; }
  pre { background:#f7f7f7; padding:8px; border-radius:6px; overflow:auto; }
  .ok { border-left:4px solid #2a9d8f; padding-left:10px; }
  .err { border-left:4px solid #e63946; padding-left:10px; }
  .hint { font-size:13px; color:#666; margin-top:6px; }
</style>
</head>
<body>
<div class="card">
  <h2>CACTUS Self Spot</h2>
  <p class="small">Use only when self-spotting is permitted (e.g., session start or QSY during CACTUS).</p>
  <?php if ($using_test_time): ?>
    <p class="small"><em>Using test time:</em> <?= h($nowUtc->format('Y-m-d H:i:s')) ?> UTC</p>
  <?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="err"><strong>Errors:</strong>
    <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if (isset($feedback) && is_array($feedback)): ?>
  <div class="ok">
    <strong>Spot attempt summary</strong>
    <p class="small">
      Node: <?= h($feedback['node']) ?> (<?= h($feedback['host']) ?>:<?= h((string)$feedback['port']) ?>)<br>
      Time: <?= gmdate('Y-m-d H:i:s') ?> UTC — Connect: <?= h((string)$feedback['connect_ms']) ?> ms — Total: <?= h((string)$feedback['total_ms']) ?> ms
    </p>
    <p><strong>Command sent:</strong></p>
    <pre><?= h($feedback['dx_cmd']) ?></pre>
    <?php if (!empty($feedback['after_dx'])): ?>
      <p><strong>Node reply (truncated):</strong></p>
      <pre><?= h(mb_substr($feedback['after_dx'], 0, 1000)) ?></pre>
    <?php else: ?>
      <p class="small">No reply captured from the node.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <form method="post" id="selfspotForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($CSRF_TOKEN) ?>">

    <label>Your login callsign (used to log in)
      <input type="text" name="login_call" value="<?= h(strtoupper($logged_in_call ?? '')) ?>" readonly>
      <div class="hint">Login uses your personal call; the spot uses the Event Callsign below.</div>
    </label>

    <label>Event callsign (the CACTUS callsign you want to spot)
      <input type="text" name="event_call" id="event_call" value="<?= h($SELFSPOT_PREFILL['event_call']) ?>">
      <div class="hint">Common event calls: <?= h(implode(', ', $EVENT_CALLSIGNS)) ?></div>
    </label>

    <div class="row">
      <div class="col">
        <label>Frequency (MHz or kHz)
          <input type="text" name="frequency" id="frequency" value="<?=
            h($SELFSPOT_PREFILL['freq_khz'] ? (string)((int)$SELFSPOT_PREFILL['freq_khz']) : '')
          ?>">
        </label>
        <div class="hint">Examples: <code>14.035</code> (MHz) or <code>14035</code> (kHz).</div>
      </div>

      <div class="col">
        <label>Cluster node
          <select name="node_id" id="node_id">
            <?php foreach ($CLUSTER_NODES as $idx => $n): ?>
              <option value="<?= h($n['id']) ?>"<?= $idx === 0 ? ' selected' : '' ?>>
                <?= h(($n['name'] ?? $n['host']) . ' (' . $n['host'] . ':' . $n['port'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </div>

    <label>Comment (optional)
      <textarea name="comment" id="comment" rows="3">CACTUS Special Event</textarea>
      <div class="hint">Short note (mode, op name, etc.). Newlines removed; max ~160 chars.</div>
    </label>

    <div style="display:flex;gap:8px;align-items:center;">
      <button type="submit">Send Spot</button>
      <div class="small" style="margin-left:auto">Logged in as <strong><?= h(strtoupper($logged_in_call)) ?></strong></div>
    </div>
  </form>

  <hr>
  <div class="small">
    <strong>Schedule suggestion:</strong>
    <?php if ($SELFSPOT_PREFILL['matched']): ?>
      <div>Matched hour: <?= h($SELFSPOT_PREFILL['target_date'] . ' ' . $SELFSPOT_PREFILL['target_time']) ?> UTC.</div>
      <?php if ($SELFSPOT_PREFILL['band']): ?>
        <div>Suggested: <?= h($SELFSPOT_PREFILL['band']) ?> / <?= h($SELFSPOT_PREFILL['mode'] ?? '') ?>
          <?php if ($SELFSPOT_PREFILL['freq_khz']) echo ' @ ' . h((string)$SELFSPOT_PREFILL['freq_khz']) . ' kHz'; ?>
        </div>
      <?php endif; ?>
      <div class="hint">Edit any field above as needed before sending.</div>
    <?php else: ?>
      <div>No scheduled slot detected for the current/upcoming hour for your callsign.</div>
    <?php endif; ?>
  </div>

</div>

<script>
(function () {
  const form = document.getElementById('selfspotForm');
  const STORAGE_KEY = 'selfspot_last';
  function $(id){ return document.getElementById(id); }

  // load remembered fields
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    if (saved.event_call && !$('event_call').value) $('event_call').value = saved.event_call;
    if (saved.frequency && !$('frequency').value) $('frequency').value = saved.frequency;
    if (saved.node_id) $('node_id').value = saved.node_id;
    if (saved.comment && !document.getElementById('comment').value) document.getElementById('comment').value = saved.comment;
  } catch (e) {}

  form.addEventListener('submit', function () {
    const payload = {
      event_call: $('event_call').value || '',
      frequency: $('frequency').value || '',
      node_id: $('node_id').value || '',
      comment: document.getElementById('comment').value || ''
    };
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(payload)); } catch (e) {}
  });
})();
</script>

</body>
</html>
