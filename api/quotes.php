<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_auth($pdo);
    ensure_quotes_table($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT id, project_name, client_name, pieces_count, total_amount, created_at, quote_data
             FROM quotes
             ORDER BY created_at DESC
             LIMIT 100'
        );
        $stmt->execute();
        $quotes = $stmt->fetchAll();
        
        json_response(['ok' => true, 'quotes' => $quotes]);
    }

    if ($method === 'POST') {
        $data = read_json();
        $projectName = trim((string) ($data['project'] ?? ''));
        $clientName = trim((string) ($data['client'] ?? ''));
        $piecesCount = positive_integer_input($data['pieces'] ?? null, 'Le nombre de pieces');
        $totalAmount = (float) ($data['total'] ?? 0);
        $quoteData = (string) ($data['quoteData'] ?? '');
        $quoteHash = (string) ($data['quoteHash'] ?? '');

        if ($projectName === '') {
            json_response(['ok' => false, 'error' => 'Le nom du projet est obligatoire.'], 400);
        }

        if ($quoteHash === '') {
            json_response(['ok' => false, 'error' => 'Hash devis manquant.'], 400);
        }

        $decodedQuote = json_decode($quoteData, true);
        if (is_array($decodedQuote) && isset($decodedQuote['rows']) && is_array($decodedQuote['rows'])) {
            foreach ($decodedQuote['rows'] as $row) {
                $unit = (string) ($row['item']['unit'] ?? '');
                positive_quantity_input_for_unit($row['qtyPerPiece'] ?? null, $unit, 'La quantite par piece');
                positive_quantity_input_for_unit($row['totalQty'] ?? null, $unit, 'La quantite totale');
            }
        }

        // Vérifier si ce devis existe déjà (doublon)
        $stmt = $pdo->prepare('SELECT id FROM quotes WHERE quote_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $quoteHash]);
        $existing = $stmt->fetch();

        if ($existing) {
            json_response([
                'ok' => false,
                'error' => 'Ce devis est déjà enregistré.',
                'isDuplicate' => true,
                'quoteId' => (int) $existing['id'],
            ], 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO quotes
             (project_name, client_name, pieces_count, total_amount, quote_data, quote_hash, created_at)
             VALUES (:project_name, :client_name, :pieces_count, :total_amount, :quote_data, :quote_hash, NOW())'
        );
        $stmt->execute([
            ':project_name' => $projectName,
            ':client_name' => $clientName,
            ':pieces_count' => $piecesCount,
            ':total_amount' => $totalAmount,
            ':quote_data' => $quoteData,
            ':quote_hash' => $quoteHash,
        ]);

        $id = (int) $pdo->lastInsertId();
        json_response(['ok' => true, 'quoteId' => $id], 201);
    }

    if ($method === 'GET' && isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare(
            'SELECT id, project_name, client_name, pieces_count, total_amount, created_at, quote_data
             FROM quotes
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $quote = $stmt->fetch();

        if (!$quote) {
            json_response(['ok' => false, 'error' => 'Devis introuvable.'], 404);
        }

        json_response(['ok' => true, 'quote' => $quote]);
    }

    if ($method === 'DELETE') {
        $user = require_roles($pdo, ['admin']);
        $data = read_json();
        $id = (int) ($data['id'] ?? 0);

        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Identifiant devis manquant.'], 400);
        }

        $stmt = $pdo->prepare('DELETE FROM quotes WHERE id = :id');
        $stmt->execute([':id' => $id]);

        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Erreur serveur devis.',
        'detail' => $e->getMessage(),
    ], 500);
}
