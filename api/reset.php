<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    require_roles($pdo, ['admin']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'Methode non autorisee.'], 405);
    }

    ensure_stock_movements_table($pdo);
    ensure_stock_history_table($pdo);
    ensure_suppliers_table($pdo);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE stock_history');
    $pdo->exec('TRUNCATE TABLE stock_movements');
    $pdo->exec('TRUNCATE TABLE orders');
    $pdo->exec('TRUNCATE TABLE suppliers');
    $pdo->exec('TRUNCATE TABLE materials');
    $pdo->exec('TRUNCATE TABLE settings');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $pdo->exec(
        "INSERT INTO materials
         (id, name, category, quantity, min_quantity, unit, unit_price, weekly_consumption, supplier)
         VALUES
         (1, 'Panneau MDF 18mm', 'Accessoires', 12, 5, 'm²', 8500, 3, 'Bois & Co'),
         (2, 'Panneau contre-plaqué 15mm', 'Accessoires', 4, 5, 'm²', 7200, 2, 'Bois & Co'),
         (3, 'Charnières à encastrer', 'Quincaillerie', 45, 20, 'pièce(s)', 350, 8, 'QuincaShop'),
         (4, 'Coulisses à billes 45cm', 'Quincaillerie', 6, 10, 'pièce(s)', 2200, 4, 'QuincaShop'),
         (5, 'Vernis bois mat', 'Finition', 2, 3, 'litre(s)', 6500, 1, 'FinitionsPlus'),
         (6, 'Vis à bois 4×40', 'Visserie', 380, 100, 'pièce(s)', 15, 60, 'Quincaillerie centrale'),
         (7, 'Poignées métal noir', 'Accessoires', 18, 10, 'pièce(s)', 1800, 5, 'DecoHandle'),
         (8, 'Pied réglable', 'Accessoires', 24, 12, 'pièce(s)', 550, 8, 'DecoHandle')"
    );

    $pdo->exec('ALTER TABLE materials AUTO_INCREMENT = 9');
    $pdo->exec(
        "INSERT INTO suppliers
         (name, contact_person, phone, email, address, lead_time_days, products, notes)
         VALUES
         ('Bois & Co', '', '', '', '', 5, 'Panneaux MDF, contre-plaque', ''),
         ('QuincaShop', '', '', '', '', 7, 'Charnieres, coulisses, quincaillerie', ''),
         ('FinitionsPlus', '', '', '', '', 4, 'Vernis, peinture, finition bois', ''),
         ('Quincaillerie centrale', '', '', '', '', 3, 'Visserie et consommables', ''),
         ('DecoHandle', '', '', '', '', 6, 'Poignees, pieds, accessoires', '')"
    );
    $pdo->exec("INSERT INTO settings (id, workshop_name, currency) VALUES (1, 'Mon Atelier Rangement', 'FCFA')");

    json_response([
        'ok' => true,
        'items' => fetch_materials($pdo),
        'orders' => fetch_orders($pdo),
        'movements' => fetch_movements($pdo),
        'history' => fetch_stock_history($pdo),
        'suppliers' => fetch_suppliers($pdo),
        'params' => fetch_settings($pdo),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    json_response([
        'ok' => false,
        'error' => 'Erreur serveur reset.',
        'detail' => $e->getMessage(),
    ], 500);
}
