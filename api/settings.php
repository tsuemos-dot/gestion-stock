<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_auth($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response(['ok' => true, 'params' => fetch_settings($pdo)]);
    }

    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        if ($user['role'] !== 'admin') {
            json_response(['ok' => false, 'error' => 'Acces refuse pour ce role.'], 403);
        }

        $data = read_json();
        $atelier = trim((string) ($data['atelier'] ?? 'Mon Atelier'));
        $devise = trim((string) ($data['devise'] ?? 'FCFA'));

        if ($atelier === '') {
            $atelier = 'Mon Atelier';
        }

        if ($devise === '') {
            $devise = 'FCFA';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO settings (id, workshop_name, currency)
             VALUES (1, :workshop_name, :currency)
             ON DUPLICATE KEY UPDATE
               workshop_name = VALUES(workshop_name),
               currency = VALUES(currency)'
        );
        $stmt->execute([
            ':workshop_name' => $atelier,
            ':currency' => $devise,
        ]);

        json_response(['ok' => true, 'params' => fetch_settings($pdo)]);
    }

    json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Erreur serveur settings.',
        'detail' => $e->getMessage(),
    ], 500);
}
