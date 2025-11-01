<?php
// assigned_call.php — DB-agnostic callsign selection helpers with debug logs
// Requires: config.php defines EVENT_CALLSIGNS (PHP array) and optionally:
//   EVENT_CALLSIGN_PARAMS (array), EVENT_START_DATE (UTC anchor string)
// Uses your log_msg(LEVEL, "msg") if present; otherwise no-op.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';

// --- Debug shim (no-op if you didn't include your logger yet) ---
if (!function_exists('log_msg')) {
    function log_msg($level, $msg) { /* no-op */ }
}

// --- Policy defaults (override in config.php if you wish) ---
if (!defined('EVENT_CALLSIGN_PARAMS')) {
    define('EVENT_CALLSIGN_PARAMS', [
        'daily_step'       => 3,
        'extra_bump_hours' => 8,
        'per_mode_salt'    => true,
        'per_band_salt'    => false,
    ]);
}

function ac_get_calls_array(): array {
    if (!defined('EVENT_CALLSIGNS') || !is_array(EVENT_CALLSIGNS)) {
        log_msg(DEBUG_ERROR, 'EVENT_CALLSIGNS not defined or not an array.');
        return [];
    }
    $out = [];
    foreach (EVENT_CALLSIGNS as $c) {
        $c = trim((string)$c);
        if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
    }
    log_msg(DEBUG_DEBUG, 'Loaded calls list: [' . implode(',', $out) . ']');
    return $out;
}

function ac_params(): array {
    $p = is_array(EVENT_CALLSIGN_PARAMS) ? EVENT_CALLSIGN_PARAMS : [];
    log_msg(DEBUG_VERBOSE, 'Policy params: ' . json_encode($p));
    return $p;
}

function ac_rotate(array $arr, int $k): array {
    $n = count($arr);
    if ($n === 0) return $arr;
    $k = (($k % $n) + $n) % $n;
    if ($k === 0) return $arr;
    return array_merge(array_slice($arr, $k), array_slice($arr, 0, $k));
}

function ac_anchor_timestamp(): ?int {
    if (defined('EVENT_START_DATE') && EVENT_START_DATE) {
        try {
            $dt = new DateTimeImmutable(EVENT_START_DATE, new DateTimeZone('UTC'));
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            log_msg(DEBUG_WARNING, 'Bad EVENT_START_DATE: ' . $e->getMessage());
        }
    }
    return null;
}

function ac_slot_dt_utc(string $dateYmd, string $timeHis): DateTimeImmutable {
    // Ensure UTC; convert before calling if you store local times.
    return new DateTimeImmutable($dateYmd . ' ' . $timeHis, new DateTimeZone('UTC'));
}

/**
 * Deterministic ordered list for a slot; returns full permutation for that context.
 */
function ac_order_for_slot(string $dateYmd, string $timeHis, string $mode = '', string $band = ''): array {
    $calls = ac_get_calls_array();
    $n = count($calls);
    if ($n === 0) return [];

    $p = ac_params();
    $dailyStep       = (int)($p['daily_step']       ?? 3);
    $extraBumpHours  = (int)($p['extra_bump_hours'] ?? 8);
    $perModeSalt     = !empty($p['per_mode_salt']);
    $perBandSalt     = !empty($p['per_band_salt']);

    $slotStartUtc = ac_slot_dt_utc($dateYmd, $timeHis);
    $anchor = ac_anchor_timestamp();
    $t0 = $anchor ?? 0;

    $secs      = $slotStartUtc->getTimestamp() - $t0;
    $hourIndex = intdiv($secs, 3600);
    $dayIndex  = intdiv($secs, 86400);

    $offset = $n > 0 ? ($hourIndex % $n) : 0;
    if ($extraBumpHours > 0) {
        $hourOfDay = (int)$slotStartUtc->format('G');
        $offset += intdiv($hourOfDay, $extraBumpHours);
    }
    if ($n > 0) {
        $offset += ($dayIndex * ($dailyStep % $n)) % $n;
    }

    $salt = 0;
    if ($perModeSalt && $mode !== '')  $salt += crc32('mode:' . strtoupper($mode)) % ($n ?: 1);
    if ($perBandSalt && $band !== '')  $salt += crc32('band:' . strtolower($band)) % ($n ?: 1);
    $offset += $salt;

    $offset = (($offset % $n) + $n) % $n;
    $order = ac_rotate($calls, $offset);

    log_msg(DEBUG_VERBOSE, sprintf(
        'Order for %s %s (mode=%s band=%s): offset=%d, order=[%s]',
        $dateYmd, $timeHis, $mode, $band, $offset, implode(',', $order)
    ));

    return $order;
}

/**
 * Pick a call given the list already used in that (date,time) slot.
 * $usedCalls: array of strings (assigned_call values already present).
 * $prefer: if provided and still free, keep it (useful for edits).
 */
function ac_pick_from_used(
    string $dateYmd,
    string $timeHis,
    array $usedCalls = [],
    string $mode = '',
    string $band = '',
    ?string $prefer = null
): ?string {
    $order = ac_order_for_slot($dateYmd, $timeHis, $mode, $band);
    if (empty($order)) return null;

    $usedSet = [];
    foreach ($usedCalls as $u) {
        if ($u === null || $u === '') continue;
        $usedSet[trim((string)$u)] = true;
    }
    log_msg(DEBUG_DEBUG, sprintf(
        'Used in slot %s %s: [%s]', $dateYmd, $timeHis, implode(',', array_keys($usedSet))
    ));

    if ($prefer) {
        $p = trim($prefer);
        if ($p !== '' && empty($usedSet[$p])) {
            log_msg(DEBUG_INFO, "Keeping preferred call: $p");
            return $p;
        }
    }

    foreach ($order as $c) {
        if (empty($usedSet[$c])) {
            log_msg(DEBUG_INFO, "Picked callsign: $c");
            return $c;
        }
    }
    log_msg(DEBUG_WARNING, 'No callsigns available for this slot.');
    return null;
}

function choose_assigned_call($date, $time, $op_call, $band, $mode): ?string {
    // assign an event call
    // 1) what calls are already used in this slot
    $usedCalls = event_used_calls_in_slot(EVENT_NAME, $date, $time);
    // 2) get calls used by neighbors
    $neighbors = event_neighbor_assigned_calls(EVENT_NAME, $date, $time, $op_call, $band, $mode);
    // 3) preferred call heuristics (try not to require a call change within a block)
    $prefer = ac_prefer_from_neighbors($neighbors, $usedCalls);
    // 4) pick a call
    $assigned_call = ac_pick_from_used($date, $time, $usedCalls, $mode, $band, $prefer);

    return $assigned_call;
}
