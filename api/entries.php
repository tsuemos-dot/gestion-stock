<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    require_roles($pdo, ['admin']);
    ensure_stock_history_table($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
    }

    $data = read_json();
    $materialId = (int) ($data['itemId'] ?? $data['material_id'] ?? 0);
    $quantityRaw = $data['qty'] ?? null;

    if ($materialId <= 0) {
        json_response(['ok' => false, 'error' => 'Materiau d entree manquant.'], 400);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT id, name, unit, quantity
         FROM materials
         WHERE id = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $materialId]);
    $material = $stmt->fetch();

    if (!$material) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Materiau introuvable.'], 404);
    }

    $quantity = positive_quantity_input_for_unit($quantityRaw, (string) $material['unit'], 'La quantite');

    $before = (float) $material['quantity'];
    $after = $before + $quantity;

    $stmt = $pdo->prepare('UPDATE materials SET quantity = quantity + :quantity WHERE id = :id');
    $stmt->execute([
        ':quantity' => $quantity,
        ':id' => $materialId,
    ]);

    $date = trim((string) ($data['date'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    }

    $supplier = trim((string) ($data['supplier'] ?? ''));
    $reference = trim((string) ($data['reference'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $fullNotes = trim(($reference !== '' ? 'Ref: ' . $reference . '. ' : '') . $notes);

    log_stock_history($pdo, [
        'event_type' => 'entree',
        'material_id' => $materialId,
        'material_name' => $material['name'],
        'unit' => $material['unit'],
        'quantity_delta' => $quantity,
        'stock_before' => $before,
        'stock_after' => $after,
        'source_type' => 'entree_directe',
        'source_id' => $materialId,
        'destination' => $supplier,
        'requester' => '',
        'notes' => $fullNotes,
        'event_date' => $date,
    ]);

    $pdo->commit();

    json_response([
        'ok' => true,
        'items' => fetch_materials($pdo),
        'history' => fetch_stock_history($pdo),
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'ok' => false,
        'error' => 'Erreur serveur entrees.',
        'detail' => $e->getMessage(),
    ], 500);
}
