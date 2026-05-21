<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    require_roles($pdo, ['admin']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response([
            'ok' => true,
            'history' => fetch_stock_history($pdo),
        ]);
    }

    if ($method === 'DELETE') {
        ensure_stock_history_table($pdo);
        $pdo->exec('TRUNCATE TABLE stock_history');

        json_response([
            'ok' => true,
            'history' => [],
        ]);
    }

    if ($method !== 'GET') {
        json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
    }
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Erreur serveur historique.',
        'detail' => $e->getMessage(),
    ], 500);
}
