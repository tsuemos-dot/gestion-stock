<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_auth($pdo);
    ensure_stock_history_table($pdo);
    ensure_material_image_column($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response(['ok' => true, 'items' => fetch_materials($pdo)]);
    }

    if ($method === 'POST') {
        if ($user['role'] !== 'admin') {
            json_response(['ok' => false, 'error' => 'Acces refuse pour ce role.'], 403);
        }

        $data = read_json();
        $name = trim((string) ($data['name'] ?? ''));
        $unit = trim((string) ($data['unit'] ?? 'piece(s)'));
        $quantity = non_negative_quantity_input_for_unit($data['qty'] ?? null, $unit, 'La quantite', 0);
        $minQuantity = non_negative_quantity_input_for_unit($data['min'] ?? null, $unit, 'Le seuil d alerte', 0);
        $weeklyConsumption = non_negative_quantity_input_for_unit($data['conso'] ?? null, $unit, 'La consommation hebdomadaire', 0);

        if ($name === '') {
            json_response(['ok' => false, 'error' => 'Le nom du materiau est obligatoire.'], 400);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO materials
             (name, category, quantity, min_quantity, unit, unit_price, weekly_consumption, supplier, image_url)
             VALUES (:name, :category, :quantity, :min_quantity, :unit, :unit_price, :weekly_consumption, :supplier, :image_url)'
        );
        $stmt->execute([
            ':name' => $name,
            ':category' => (string) ($data['cat'] ?? 'Accessoires'),
            ':quantity' => $quantity,
            ':min_quantity' => $minQuantity,
            ':unit' => $unit,
            ':unit_price' => (float) ($data['price'] ?? 0),
            ':weekly_consumption' => $weeklyConsumption,
            ':supplier' => trim((string) ($data['supplier'] ?? '')),
            ':image_url' => trim((string) ($data['image'] ?? '')),
        ]);

        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'SELECT id, name, category, quantity, min_quantity, unit, unit_price, weekly_consumption, supplier, image_url
             FROM materials
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();

        log_stock_history($pdo, [
            'event_type' => 'entree',
            'material_id' => $id,
            'material_name' => $item['name'],
            'unit' => $item['unit'],
            'quantity_delta' => (float) $item['quantity'],
            'stock_before' => 0,
            'stock_after' => (float) $item['quantity'],
            'source_type' => 'creation',
            'source_id' => $id,
            'notes' => 'Creation du produit avec stock initial.',
        ]);

        json_response(['ok' => true, 'item' => material_to_app($item), 'history' => fetch_stock_history($pdo)], 201);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        if (!in_array($user['role'], ['admin', 'moderateur_stock'], true)) {
            json_response(['ok' => false, 'error' => 'Acces refuse pour ce role.'], 403);
        }

        $data = read_json();
        $id = (int) ($data['id'] ?? 0);

        if ($user['role'] === 'moderateur_stock') {
            $allowedKeys = ['id', 'qty', 'sourceType', 'notes'];
            foreach (array_keys($data) as $key) {
                if (!in_array($key, $allowedKeys, true)) {
                    json_response(['ok' => false, 'error' => 'Le moderateur stock peut modifier uniquement la quantite.'], 403);
                }
            }
        }

        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Identifiant materiau manquant.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, category, quantity, min_quantity, unit, unit_price, weekly_consumption, supplier, image_url
             FROM materials
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $oldItem = $stmt->fetch();

        if (!$oldItem) {
            json_response(['ok' => false, 'error' => 'Materiau introuvable.'], 404);
        }

        $targetUnit = array_key_exists('unit', $data)
            ? trim((string) $data['unit'])
            : (string) $oldItem['unit'];

        $fields = [
            'name' => ['column' => 'name', 'type' => 'string'],
            'cat' => ['column' => 'category', 'type' => 'string'],
            'qty' => ['column' => 'quantity', 'type' => 'quantity', 'label' => 'La quantite'],
            'min' => ['column' => 'min_quantity', 'type' => 'quantity', 'label' => 'Le seuil d alerte'],
            'unit' => ['column' => 'unit', 'type' => 'string'],
            'price' => ['column' => 'unit_price', 'type' => 'float'],
            'conso' => ['column' => 'weekly_consumption', 'type' => 'quantity', 'label' => 'La consommation hebdomadaire'],
            'supplier' => ['column' => 'supplier', 'type' => 'string'],
            'image' => ['column' => 'image_url', 'type' => 'string'],
        ];

        $updates = [];
        $params = [':id' => $id];

        if (array_key_exists('unit', $data)) {
            $currentQuantityFields = [
                'qty' => ['value' => $oldItem['quantity'], 'label' => 'La quantite'],
                'min' => ['value' => $oldItem['min_quantity'], 'label' => 'Le seuil d alerte'],
                'conso' => ['value' => $oldItem['weekly_consumption'], 'label' => 'La consommation hebdomadaire'],
            ];

            foreach ($currentQuantityFields as $key => $currentField) {
                if (!array_key_exists($key, $data)) {
                    non_negative_quantity_input_for_unit($currentField['value'], $targetUnit, $currentField['label']);
                }
            }
        }

        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $param = ':' . $key;
            $updates[] = $field['column'] . ' = ' . $param;
            if ($field['type'] === 'float') {
                $params[$param] = (float) $data[$key];
            } elseif ($field['type'] === 'quantity') {
                $params[$param] = non_negative_quantity_input_for_unit($data[$key], $targetUnit, $field['label'] ?? 'La valeur');
            } else {
                $params[$param] = trim((string) $data[$key]);
            }
        }

        if (array_key_exists('name', $data) && $params[':name'] === '') {
            json_response(['ok' => false, 'error' => 'Le nom du materiau est obligatoire.'], 400);
        }

        if ($updates !== []) {
            $stmt = $pdo->prepare('UPDATE materials SET ' . implode(', ', $updates) . ' WHERE id = :id');
            $stmt->execute($params);
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, category, quantity, min_quantity, unit, unit_price, weekly_consumption, supplier, image_url
             FROM materials
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            json_response(['ok' => false, 'error' => 'Materiau introuvable.'], 404);
        }

        if (array_key_exists('qty', $data) && (float) $oldItem['quantity'] !== (float) $item['quantity']) {
            log_stock_history($pdo, [
                'event_type' => 'correction',
                'material_id' => (int) $item['id'],
                'material_name' => $item['name'],
                'unit' => $item['unit'],
                'quantity_delta' => (float) $item['quantity'] - (float) $oldItem['quantity'],
                'stock_before' => (float) $oldItem['quantity'],
                'stock_after' => (float) $item['quantity'],
                'source_type' => trim((string) ($data['sourceType'] ?? 'correction')),
                'source_id' => (int) $item['id'],
                'notes' => trim((string) ($data['notes'] ?? 'Correction manuelle du stock.')),
            ]);
        }

        json_response(['ok' => true, 'item' => material_to_app($item), 'history' => fetch_stock_history($pdo)]);
    }

    if ($method === 'DELETE') {
        if ($user['role'] !== 'admin') {
            json_response(['ok' => false, 'error' => 'Acces refuse pour ce role.'], 403);
        }

        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            $data = read_json();
            $id = (int) ($data['id'] ?? 0);
        }

        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Identifiant materiau manquant.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, quantity, unit
             FROM materials
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            json_response(['ok' => false, 'error' => 'Materiau introuvable.'], 404);
        }

        log_stock_history($pdo, [
            'event_type' => 'annulation',
            'material_id' => (int) $item['id'],
            'material_name' => $item['name'],
            'unit' => $item['unit'],
            'quantity_delta' => 0 - (float) $item['quantity'],
            'stock_before' => (float) $item['quantity'],
            'stock_after' => 0,
            'source_type' => 'suppression',
            'source_id' => (int) $item['id'],
            'notes' => 'Suppression de la fiche produit.',
        ]);

        $stmt = $pdo->prepare('DELETE FROM materials WHERE id = :id');
        $stmt->execute([':id' => $id]);

        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Erreur serveur materials.',
        'detail' => $e->getMessage(),
    ], 500);
}
