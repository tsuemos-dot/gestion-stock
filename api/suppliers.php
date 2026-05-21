<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    require_roles($pdo, ['admin']);
    ensure_suppliers_table($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response(['ok' => true, 'suppliers' => fetch_suppliers($pdo)]);
    }

    if ($method === 'POST') {
        $data = read_json();
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            json_response(['ok' => false, 'error' => 'Le nom du fournisseur est obligatoire.'], 400);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO suppliers
             (name, contact_person, phone, email, address, lead_time_days, products, notes)
             VALUES (:name, :contact_person, :phone, :email, :address, :lead_time_days, :products, :notes)'
        );
        $stmt->execute([
            ':name' => $name,
            ':contact_person' => trim((string) ($data['contact'] ?? '')),
            ':phone' => trim((string) ($data['phone'] ?? '')),
            ':email' => trim((string) ($data['email'] ?? '')),
            ':address' => trim((string) ($data['address'] ?? '')),
            ':lead_time_days' => (int) ($data['leadTime'] ?? 7),
            ':products' => trim((string) ($data['products'] ?? '')),
            ':notes' => trim((string) ($data['notes'] ?? '')),
        ]);

        json_response(['ok' => true, 'suppliers' => fetch_suppliers($pdo)], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $data = read_json();
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));

        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Identifiant fournisseur manquant.'], 400);
        }

        if ($name === '') {
            json_response(['ok' => false, 'error' => 'Le nom du fournisseur est obligatoire.'], 400);
        }

        $stmt = $pdo->prepare(
            'UPDATE suppliers
             SET name = :name,
                 contact_person = :contact_person,
                 phone = :phone,
                 email = :email,
                 address = :address,
                 lead_time_days = :lead_time_days,
                 products = :products,
                 notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':contact_person' => trim((string) ($data['contact'] ?? '')),
            ':phone' => trim((string) ($data['phone'] ?? '')),
            ':email' => trim((string) ($data['email'] ?? '')),
            ':address' => trim((string) ($data['address'] ?? '')),
            ':lead_time_days' => (int) ($data['leadTime'] ?? 7),
            ':products' => trim((string) ($data['products'] ?? '')),
            ':notes' => trim((string) ($data['notes'] ?? '')),
            ':id' => $id,
        ]);

        json_response(['ok' => true, 'suppliers' => fetch_suppliers($pdo)]);
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            $data = read_json();
            $id = (int) ($data['id'] ?? 0);
        }

        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Identifiant fournisseur manquant.'], 400);
        }

        $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = :id');
        $stmt->execute([':id' => $id]);

        json_response(['ok' => true, 'suppliers' => fetch_suppliers($pdo)]);
    }

    json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Erreur serveur fournisseurs.',
        'detail' => $e->getMessage(),
    ], 500);
}
