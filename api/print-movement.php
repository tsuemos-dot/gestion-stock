<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_roles($pdo, ['moderateur_stock']);
    
    $id = (int) ($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'Identifiant sortie manquant.'], 400);
    }
    
    $stmt = $pdo->prepare(
        'SELECT id, material_id, material_name, unit, quantity, destination, requester, notes, movement_date
         FROM stock_movements
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $movement = $stmt->fetch();
    
    if (!$movement) {
        json_response(['ok' => false, 'error' => 'Sortie introuvable.'], 404);
    }
    
    // Format the date
    $date = DateTime::createFromFormat('Y-m-d', $movement['movement_date']);
    if ($date === false) {
        $dateFormatted = $movement['movement_date'];
    } else {
        $dateFormatted = $date->format('d/m/Y');
    }
    
    // Return JSON with movement details formatted for PDF
    json_response([
        'ok' => true,
        'movement' => [
            'id' => (int) $movement['id'],
            'material_name' => $movement['material_name'],
            'unit' => $movement['unit'],
            'quantity' => (float) $movement['quantity'],
            'destination' => $movement['destination'],
            'requester' => $movement['requester'],
            'notes' => $movement['notes'],
            'movement_date' => $movement['movement_date'],
            'movement_date_formatted' => $dateFormatted,
        ]
    ]);
    
} catch (Exception $e) {
    json_response(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()], 500);
}
