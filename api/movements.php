<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_roles($pdo, ['moderateur_stock']);
    ensure_stock_movements_table($pdo);
    ensure_stock_history_table($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response(['ok' => true, 'movements' => fetch_movements($pdo)]);
    }

    if ($method === 'POST') {
        $data = read_json();
        
        // Vérifier si c'est une sortie groupée (bulk_movements)
        if (!empty($data['bulk_movements']) && is_array($data['bulk_movements'])) {
            // Endpoint de sorties groupées
            $groupId = bin2hex(random_bytes(10)); // Générer un ID unique pour le groupe
            $destination = trim((string) ($data['destination'] ?? ''));
            $requester = trim((string) ($data['requester'] ?? ''));
            $date = trim((string) ($data['date'] ?? ''));
            if ($date === '') {
                $date = date('Y-m-d');
            }
            
            $pdo->beginTransaction();
            
            foreach ($data['bulk_movements'] as $mvt) {
                $materialId = (int) ($mvt['itemId'] ?? 0);
                $quantityRaw = $mvt['qty'] ?? null;
                
                if ($materialId <= 0) continue;
                
                $stmt = $pdo->prepare(
                    'SELECT id, name, unit, quantity
                     FROM materials
                     WHERE id = :id
                     FOR UPDATE'
                );
                $stmt->execute([':id' => $materialId]);
                $material = $stmt->fetch();
                
                if (!$material) continue;
                $quantity = positive_quantity_input_for_unit($quantityRaw, (string) $material['unit'], 'La quantite');
                if ((float) $material['quantity'] < $quantity) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'Stock insuffisant pour ' . $material['name']], 400);
                    return;
                }
                
                $before = (float) $material['quantity'];
                $after = $before - $quantity;
                
                $stmt = $pdo->prepare('UPDATE materials SET quantity = quantity - :quantity WHERE id = :id');
                $stmt->execute([':quantity' => $quantity, ':id' => $materialId]);
                
                $stmt = $pdo->prepare(
                    'INSERT INTO stock_movements
                     (group_id, material_id, material_name, unit, quantity, destination, requester, notes, movement_date)
                     VALUES (:group_id, :material_id, :material_name, :unit, :quantity, :destination, :requester, :notes, :movement_date)'
                );
                $stmt->execute([
                    ':group_id' => $groupId,
                    ':material_id' => $materialId,
                    ':material_name' => $material['name'],
                    ':unit' => $material['unit'],
                    ':quantity' => $quantity,
                    ':destination' => $destination,
                    ':requester' => $requester,
                    ':notes' => trim((string) ($mvt['notes'] ?? '')),
                    ':movement_date' => $date,
                ]);
                
                log_stock_history($pdo, [
                    'event_type' => 'sortie',
                    'material_id' => $materialId,
                    'material_name' => $material['name'],
                    'unit' => $material['unit'],
                    'quantity_delta' => 0 - $quantity,
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'source_type' => 'sortie_interne',
                    'source_id' => $groupId,
                    'destination' => $destination,
                    'requester' => $requester,
                    'notes' => trim((string) ($mvt['notes'] ?? '')),
                    'event_date' => $date,
                ]);
            }
            
            $pdo->commit();
            
            json_response([
                'ok' => true,
                'groupId' => $groupId,
                'items' => fetch_materials($pdo),
                'movements' => fetch_movements($pdo),
                'history' => fetch_stock_history($pdo),
            ], 201);
            return;
        }
        
        // Endpoint sortie unique (ancien code)
        $materialId = (int) ($data['itemId'] ?? $data['material_id'] ?? 0);
        $quantityRaw = $data['qty'] ?? null;
        $destination = trim((string) ($data['destination'] ?? ''));
        $requester = trim((string) ($data['requester'] ?? ''));

        if ($materialId <= 0) {
            json_response(['ok' => false, 'error' => 'Materiau de sortie manquant.'], 400);
        }

        if ($destination === '') {
            json_response(['ok' => false, 'error' => 'Le service ou projet est obligatoire.'], 400);
        }

        if ($requester === '') {
            json_response(['ok' => false, 'error' => 'Le nom de la personne est obligatoire.'], 400);
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

        if ((float) $material['quantity'] < $quantity) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'Stock insuffisant pour cette sortie.'], 400);
        }

        $before = (float) $material['quantity'];
        $after = $before - $quantity;

        $stmt = $pdo->prepare('UPDATE materials SET quantity = quantity - :quantity WHERE id = :id');
        $stmt->execute([
            ':quantity' => $quantity,
            ':id' => $materialId,
        ]);

        $date = trim((string) ($data['date'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO stock_movements
             (material_id, material_name, unit, quantity, destination, requester, notes, movement_date)
             VALUES (:material_id, :material_name, :unit, :quantity, :destination, :requester, :notes, :movement_date)'
        );
        $stmt->execute([
            ':material_id' => $materialId,
            ':material_name' => $material['name'],
            ':unit' => $material['unit'],
            ':quantity' => $quantity,
            ':destination' => trim((string) ($data['destination'] ?? '')),
            ':requester' => trim((string) ($data['requester'] ?? '')),
            ':notes' => trim((string) ($data['notes'] ?? '')),
            ':movement_date' => $date,
        ]);
        $movementId = (int) $pdo->lastInsertId();

        log_stock_history($pdo, [
            'event_type' => 'sortie',
            'material_id' => $materialId,
            'material_name' => $material['name'],
            'unit' => $material['unit'],
            'quantity_delta' => 0 - $quantity,
            'stock_before' => $before,
            'stock_after' => $after,
            'source_type' => 'sortie_interne',
            'source_id' => $movementId,
            'destination' => trim((string) ($data['destination'] ?? '')),
            'requester' => trim((string) ($data['requester'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'event_date' => $date,
        ]);

        $pdo->commit();

        json_response([
            'ok' => true,
            'items' => fetch_materials($pdo),
            'movements' => fetch_movements($pdo),
            'history' => fetch_stock_history($pdo),
        ], 201);
    }

    if ($method === 'DELETE') {
        if ($user['role'] !== 'admin') {
            json_response(['ok' => false, 'error' => 'Seul l administrateur peut annuler une sortie.'], 403);
        }

        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            $data = read_json();
            $id = (int) ($data['id'] ?? 0);
        }

        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Identifiant sortie manquant.'], 400);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, material_id, material_name, unit, quantity, destination, requester, notes, movement_date
             FROM stock_movements
             WHERE id = :id
             FOR UPDATE'
        );
        $stmt->execute([':id' => $id]);
        $movement = $stmt->fetch();

        if (!$movement) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'Sortie introuvable.'], 404);
        }

        if ($movement['material_id'] !== null) {
            $restoredQuantity = positive_quantity_input_for_unit($movement['quantity'] ?? null, (string) $movement['unit'], 'La quantite sortie');

            $stmt = $pdo->prepare(
                'SELECT id, name, unit, quantity
                 FROM materials
                 WHERE id = :id
                 FOR UPDATE'
            );
            $stmt->execute([':id' => (int) $movement['material_id']]);
            $material = $stmt->fetch();
            $before = $material ? (float) $material['quantity'] : null;
            $after = $before !== null ? $before + $restoredQuantity : null;

            $stmt = $pdo->prepare('UPDATE materials SET quantity = quantity + :quantity WHERE id = :id');
            $stmt->execute([
                ':quantity' => $restoredQuantity,
                ':id' => (int) $movement['material_id'],
            ]);
        } else {
            $restoredQuantity = 0;
            $before = null;
            $after = null;
        }

        log_stock_history($pdo, [
            'event_type' => 'annulation',
            'material_id' => $movement['material_id'] !== null ? (int) $movement['material_id'] : null,
            'material_name' => $movement['material_name'],
            'unit' => $movement['unit'],
            'quantity_delta' => $after !== null ? $restoredQuantity : 0,
            'stock_before' => $before,
            'stock_after' => $after,
            'source_type' => 'sortie_interne',
            'source_id' => (int) $movement['id'],
            'destination' => $movement['destination'] ?? '',
            'requester' => $movement['requester'] ?? '',
            'notes' => 'Annulation sortie interne. ' . trim((string) ($movement['notes'] ?? '')),
            'event_date' => date('Y-m-d'),
        ]);

        $stmt = $pdo->prepare('DELETE FROM stock_movements WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $pdo->commit();

        json_response([
            'ok' => true,
            'items' => fetch_materials($pdo),
            'movements' => fetch_movements($pdo),
            'history' => fetch_stock_history($pdo),
        ]);
    }

    json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'ok' => false,
        'error' => 'Erreur serveur sorties.',
        'detail' => $e->getMessage(),
    ], 500);
}
