<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_auth($pdo);
    $role = $user['role'];

    $items = fetch_materials($pdo);
    $orders = [];
    $movements = [];
    $history = [];
    $suppliers = [];

    if ($role === 'admin') {
        $orders = fetch_orders($pdo);
        $movements = fetch_movements($pdo);
        $history = fetch_stock_history($pdo);
        $suppliers = fetch_suppliers($pdo);
    } elseif ($role === 'moderateur_stock') {
        $movements = fetch_movements($pdo);
    }

    json_response([
        'ok' => true,
        'user' => $user,
        'items' => $items,
        'orders' => $orders,
        'movements' => $movements,
        'history' => $history,
        'suppliers' => $suppliers,
        'params' => fetch_settings($pdo),
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Connexion a la base de donnees impossible.',
        'detail' => $e->getMessage(),
    ], 500);
}
