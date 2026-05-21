<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    ensure_users_table($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response([
            'ok' => true,
            'user' => current_user($pdo),
        ]);
    }

    if ($method === 'POST') {
        $data = read_json();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($username === '' || $password === '') {
            json_response(['ok' => false, 'error' => 'Identifiants obligatoires.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, full_name, role, active
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
            json_response(['ok' => false, 'error' => 'Identifiants incorrects.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        json_response([
            'ok' => true,
            'user' => user_to_app($user),
        ]);
    }

    if ($method === 'DELETE') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Erreur serveur authentification.',
        'detail' => $e->getMessage(),
    ], 500);
}
