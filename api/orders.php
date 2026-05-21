<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    require_roles($pdo, ['admin']);
    ensure_orders_table($pdo);
    ensure_stock_history_table($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response(['ok' => true, 'orders' => fetch_orders($pdo)]);
    }

    if ($method === 'POST') {
        $data = read_json();
        $supplier = trim((string) ($data['supplier'] ?? ''));
        $delay = max(1, (int) ($data['delay'] ?? 7));
        $notes = trim((string) ($data['notes'] ?? ''));
        $date = trim((string) ($data['date'] ?? ''));
        if ($date === '') {
            json_response(['ok' => false, 'error' => 'Date de commande obligatoire.'], 400);
        }
        if ($supplier === '') {
            json_response(['ok' => false, 'error' => 'Fournisseur obligatoire.'], 400);
        }
        if ($delay <= 0) {
            json_response(['ok' => false, 'error' => 'Delai de commande obligatoire.'], 400);
        }

        if (!empty($data['bulk_orders']) && is_array($data['bulk_orders'])) {
            $groupId = bin2hex(random_bytes(10));
            $pdo->beginTransaction();
            $created = 0;

            foreach ($data['bulk_orders'] as $orderLine) {
                $materialId = (int) ($orderLine['itemId'] ?? 0);
                $quantityRaw = $orderLine['qty'] ?? null;
                $cost = (float) ($orderLine['cost'] ?? 0);

                if ($materialId <= 0 || $cost < 0) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'Ligne de commande incomplete.'], 400);
                }

                $stmt = $pdo->prepare(
                    'SELECT id, name, unit
                     FROM materials
                     WHERE id = :id'
                );
                $stmt->execute([':id' => $materialId]);
                $material = $stmt->fetch();

                if (!$material) {
                    continue;
                }

                $quantity = positive_quantity_input_for_unit($quantityRaw, (string) $material['unit'], 'La quantite');

                $stmt = $pdo->prepare(
                    'INSERT INTO orders
                     (group_id, material_id, material_name, unit, quantity, supplier, delay_days, total_cost, notes, order_date, status)
                     VALUES (:group_id, :material_id, :material_name, :unit, :quantity, :supplier, :delay_days, :total_cost, :notes, :order_date, :status)'
                );
                $stmt->execute([
                    ':group_id' => $groupId,
                    ':material_id' => $materialId,
                    ':material_name' => $material['name'],
                    ':unit' => $material['unit'],
                    ':quantity' => $quantity,
                    ':supplier' => $supplier,
                    ':delay_days' => $delay,
                    ':total_cost' => $cost,
                    ':notes' => $notes,
                    ':order_date' => $date,
                    ':status' => 'en cours',
                ]);
                $created++;
            }

            if ($created === 0) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'Aucun materiau valide dans la commande.'], 400);
            }

            $pdo->commit();
            json_response(['ok' => true, 'groupId' => $groupId, 'orders' => fetch_orders($pdo)], 201);
        }

        $materialId = (int) ($data['itemId'] ?? $data['material_id'] ?? 0);
        $quantityRaw = $data['qty'] ?? null;
        $cost = (float) ($data['cost'] ?? 0);

        if ($materialId <= 0) {
            json_response(['ok' => false, 'error' => 'Materiau de commande manquant.'], 400);
        }
        if ($cost < 0) {
            json_response(['ok' => false, 'error' => 'Quantite ou cout de commande invalide.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, unit
             FROM materials
             WHERE id = :id'
        );
        $stmt->execute([':id' => $materialId]);
        $material = $stmt->fetch();

        if (!$material) {
            json_response(['ok' => false, 'error' => 'Materiau introuvable.'], 404);
        }

        $quantity = positive_quantity_input_for_unit($quantityRaw, (string) $material['unit'], 'La quantite');

        $stmt = $pdo->prepare(
            'INSERT INTO orders
             (group_id, material_id, material_name, unit, quantity, supplier, delay_days, total_cost, notes, order_date, status)
             VALUES (:group_id, :material_id, :material_name, :unit, :quantity, :supplier, :delay_days, :total_cost, :notes, :order_date, :status)'
        );
        $stmt->execute([
            ':group_id' => bin2hex(random_bytes(10)),
            ':material_id' => $materialId,
            ':material_name' => $material['name'],
            ':unit' => $material['unit'],
            ':quantity' => $quantity,
            ':supplier' => $supplier,
            ':delay_days' => $delay,
            ':total_cost' => $cost,
            ':notes' => $notes,
            ':order_date' => $date,
            ':status' => 'en cours',
        ]);

        json_response(['ok' => true, 'orders' => fetch_orders($pdo)], 201);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        $data = read_json();
        $id = (int) ($data['id'] ?? 0);
        $groupId = trim((string) ($data['groupId'] ?? ''));
        $status = (string) ($data['status'] ?? '');

        if ($id <= 0 && $groupId === '') {
            json_response(['ok' => false, 'error' => 'Identifiant commande manquant.'], 400);
        }

        if ($status === 'reçu') {
            $pdo->beginTransaction();

            if ($groupId !== '') {
                $stmt = $pdo->prepare(
                    'SELECT id, material_id, material_name, unit, quantity, status
                     FROM orders
                     WHERE group_id = :group_id
                     FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId]);
                $groupOrders = $stmt->fetchAll();

                if (!$groupOrders) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'Commande introuvable.'], 404);
                }

                foreach ($groupOrders as $order) {
                    if ($order['status'] !== 'en cours' || $order['material_id'] === null) {
                        continue;
                    }

                    $receivedQuantity = positive_quantity_input_for_unit($order['quantity'] ?? null, (string) $order['unit'], 'La quantite commandee');

                    $stmt = $pdo->prepare(
                        'SELECT id, name, unit, quantity
                         FROM materials
                         WHERE id = :id
                         FOR UPDATE'
                    );
                    $stmt->execute([':id' => (int) $order['material_id']]);
                    $material = $stmt->fetch();
                    $before = $material ? (float) $material['quantity'] : null;
                    $after = $before !== null ? $before + $receivedQuantity : null;

                    $stmt = $pdo->prepare('UPDATE materials SET quantity = quantity + :quantity WHERE id = :id');
                    $stmt->execute([
                        ':quantity' => $receivedQuantity,
                        ':id' => (int) $order['material_id'],
                    ]);

                    log_stock_history($pdo, [
                        'event_type' => 'entree',
                        'material_id' => (int) $order['material_id'],
                        'material_name' => $material['name'] ?? $order['material_name'],
                        'unit' => $material['unit'] ?? $order['unit'],
                        'quantity_delta' => $receivedQuantity,
                        'stock_before' => $before,
                        'stock_after' => $after,
                        'source_type' => 'commande',
                        'source_id' => (int) $order['id'],
                        'notes' => 'Reception commande fournisseur groupée.',
                    ]);
                }

                $stmt = $pdo->prepare("UPDATE orders SET status = 'reçu', received_at = NOW() WHERE group_id = :group_id AND status = 'en cours'");
                $stmt->execute([':group_id' => $groupId]);

                $pdo->commit();
                json_response([
                    'ok' => true,
                    'items' => fetch_materials($pdo),
                    'orders' => fetch_orders($pdo),
                    'history' => fetch_stock_history($pdo),
                ]);
            }

            $stmt = $pdo->prepare(
                'SELECT id, material_id, material_name, unit, quantity, status
                 FROM orders
                 WHERE id = :id
                 FOR UPDATE'
            );
            $stmt->execute([':id' => $id]);
            $order = $stmt->fetch();

            if (!$order) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'Commande introuvable.'], 404);
            }

            if ($order['status'] === 'en cours') {
                if ($order['material_id'] !== null) {
                    $receivedQuantity = positive_quantity_input_for_unit($order['quantity'] ?? null, (string) $order['unit'], 'La quantite commandee');

                    $stmt = $pdo->prepare(
                        'SELECT id, name, unit, quantity
                         FROM materials
                         WHERE id = :id
                         FOR UPDATE'
                    );
                    $stmt->execute([':id' => (int) $order['material_id']]);
                    $material = $stmt->fetch();
                    $before = $material ? (float) $material['quantity'] : null;
                    $after = $before !== null ? $before + $receivedQuantity : null;

                    $stmt = $pdo->prepare('UPDATE materials SET quantity = quantity + :quantity WHERE id = :id');
                    $stmt->execute([
                        ':quantity' => $receivedQuantity,
                        ':id' => (int) $order['material_id'],
                    ]);

                    log_stock_history($pdo, [
                        'event_type' => 'entree',
                        'material_id' => (int) $order['material_id'],
                        'material_name' => $material['name'] ?? $order['material_name'],
                        'unit' => $material['unit'] ?? $order['unit'],
                        'quantity_delta' => $receivedQuantity,
                        'stock_before' => $before,
                        'stock_after' => $after,
                        'source_type' => 'commande',
                        'source_id' => (int) $order['id'],
                        'notes' => 'Reception commande fournisseur.',
                    ]);
                }

                $stmt = $pdo->prepare("UPDATE orders SET status = 'reçu', received_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $id]);
            }

            $pdo->commit();

            json_response([
                'ok' => true,
                'items' => fetch_materials($pdo),
                'orders' => fetch_orders($pdo),
                'history' => fetch_stock_history($pdo),
            ]);
        }

        if ($status === 'annulé' || $status === 'en cours') {
            $stmt = $pdo->prepare(
                'SELECT id, material_id, material_name, unit, quantity, status
                 FROM orders
                 WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            $order = $stmt->fetch();

            if (!$order) {
                json_response(['ok' => false, 'error' => 'Commande introuvable.'], 404);
            }

            $stmt = $pdo->prepare('UPDATE orders SET status = :status WHERE id = :id');
            $stmt->execute([
                ':status' => $status,
                ':id' => $id,
            ]);

            if ($status === 'annulé' && $order['status'] !== 'annulé') {
                log_stock_history($pdo, [
                    'event_type' => 'annulation',
                    'material_id' => $order['material_id'] !== null ? (int) $order['material_id'] : null,
                    'material_name' => $order['material_name'],
                    'unit' => $order['unit'],
                    'quantity_delta' => 0,
                    'stock_before' => null,
                    'stock_after' => null,
                    'source_type' => 'commande',
                    'source_id' => (int) $order['id'],
                    'notes' => 'Annulation commande fournisseur.',
                ]);
            }

            json_response(['ok' => true, 'orders' => fetch_orders($pdo), 'history' => fetch_stock_history($pdo)]);
        }

        json_response(['ok' => false, 'error' => 'Statut non autorise.'], 400);
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        $groupId = trim((string) ($_GET['groupId'] ?? ''));

        if ($id <= 0 && $groupId === '') {
            $data = read_json();
            $id = (int) ($data['id'] ?? 0);
            $groupId = trim((string) ($data['groupId'] ?? ''));
        }

        if ($id <= 0 && $groupId === '') {
            json_response(['ok' => false, 'error' => 'Identifiant commande manquant.'], 400);
        }

        if ($groupId !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, material_id, material_name, unit, quantity, status
                 FROM orders
                 WHERE group_id = :group_id'
            );
            $stmt->execute([':group_id' => $groupId]);
            $groupOrders = $stmt->fetchAll();

            foreach ($groupOrders as $order) {
                log_stock_history($pdo, [
                    'event_type' => 'annulation',
                    'material_id' => $order['material_id'] !== null ? (int) $order['material_id'] : null,
                    'material_name' => $order['material_name'],
                    'unit' => $order['unit'],
                    'quantity_delta' => 0,
                    'stock_before' => null,
                    'stock_after' => null,
                    'source_type' => 'commande',
                    'source_id' => (int) $order['id'],
                    'notes' => 'Suppression commande fournisseur groupée.',
                ]);
            }

            $stmt = $pdo->prepare('DELETE FROM orders WHERE group_id = :group_id');
            $stmt->execute([':group_id' => $groupId]);

            json_response(['ok' => true, 'orders' => fetch_orders($pdo), 'history' => fetch_stock_history($pdo)]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, material_id, material_name, unit, quantity, status
             FROM orders
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch();

        if ($order) {
            log_stock_history($pdo, [
                'event_type' => 'annulation',
                'material_id' => $order['material_id'] !== null ? (int) $order['material_id'] : null,
                'material_name' => $order['material_name'],
                'unit' => $order['unit'],
                'quantity_delta' => 0,
                'stock_before' => null,
                'stock_after' => null,
                'source_type' => 'commande',
                'source_id' => (int) $order['id'],
                'notes' => 'Suppression commande fournisseur.',
            ]);
        }

        $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);

        json_response(['ok' => true, 'orders' => fetch_orders($pdo), 'history' => fetch_stock_history($pdo)]);
    }

    json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'ok' => false,
        'error' => 'Erreur serveur orders.',
        'detail' => $e->getMessage(),
    ], 500);
}
