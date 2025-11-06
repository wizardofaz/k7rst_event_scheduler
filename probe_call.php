<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    // --- your existing code goes here ---
    require_once __DIR__ . '/config.php'; // starts session, resolves EVENT_NAME
    require_once __DIR__ . '/auth.php';

    // Prefer explicit event from POST (stateless probe). Fallback to constant.
    $event = strtoupper(trim($_POST['event'] ?? ''));
    if ($event === '' && defined('EVENT_NAME')) {
        $event = (string) EVENT_NAME;
    }

    $calls = strtoupper(trim($_POST['callsign'] ?? ''));

    if ($event === '' || $calls === '') {
        echo json_encode(['ok' => false, 'error' => 'missing']);
        exit;
    }

    try {
        // Read-only check — no session mutation here.
        $status = auth_status_for_callsign($event, $calls); // 'exists' | 'new'
        echo json_encode(['ok' => true, 'status' => $status, 'event' => $event]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'server']);
    }

} catch (Throwable $e) {
    // TEMP: make the server error actionable
    http_response_code(500);
    echo json_encode([
        'ok'     => false,
        'error'  => 'server',
        'detail' => $e->getMessage(),   // <— this is what we need to see
    ]);
    exit;
}
