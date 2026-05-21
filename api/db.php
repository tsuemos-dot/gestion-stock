<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'gestion_stock_atelier';
const DB_USER = 'root';
const DB_PASS = '';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Credentials: true');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_response(array $data, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(): array
{
    $body = file_get_contents('php://input');

    if ($body === false || trim($body) === '') {
        return [];
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'JSON invalide.'], 400);
    }

    return $data;
}

function integer_input($value, string $label, ?int $default = null): int
{
    if ($value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }

        json_response(['ok' => false, 'error' => $label . ' est obligatoire.'], 400);
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_float($value) && floor($value) === $value) {
        return (int) $value;
    }

    if (is_string($value)) {
        $value = trim($value);
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (preg_match('/^-?\d+\.0+$/', $value) === 1) {
            return (int) $value;
        }
    }

    json_response(['ok' => false, 'error' => $label . ' doit etre un nombre entier, sans virgule.'], 400);
}

function non_negative_integer_input($value, string $label, int $default = 0): int
{
    $number = integer_input($value, $label, $default);

    if ($number < 0) {
        json_response(['ok' => false, 'error' => $label . ' ne peut pas etre negative.'], 400);
    }

    return $number;
}

function positive_integer_input($value, string $label): int
{
    $number = integer_input($value, $label);

    if ($number <= 0) {
        json_response(['ok' => false, 'error' => $label . ' doit etre superieure a zero.'], 400);
    }

    return $number;
}

function normalized_quantity_unit(string $unit): string
{
    return str_replace('m²', 'm2', strtolower(trim($unit)));
}

function unit_allows_decimal_quantity(string $unit): bool
{
    return in_array(normalized_quantity_unit($unit), ['m2', 'ml', 'kg', 'litre(s)', 'litre', 'litres'], true);
}

function format_quantity_for_unit($value, string $unit): string
{
    $decimals = unit_allows_decimal_quantity($unit) ? 2 : 0;
    return number_format((float) $value, $decimals, ',', ' ');
}

function number_input($value, string $label, ?float $default = null): float
{
    if ($value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }

        json_response(['ok' => false, 'error' => $label . ' est obligatoire.'], 400);
    }

    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
        if (is_numeric($value)) {
            return (float) $value;
        }
    }

    json_response(['ok' => false, 'error' => $label . ' doit etre un nombre valide.'], 400);
}

function quantity_input_for_unit($value, string $unit, string $label, ?float $default = null): float
{
    if (!unit_allows_decimal_quantity($unit)) {
        return (float) integer_input($value, $label, $default !== null ? (int) $default : null);
    }

    return number_input($value, $label, $default);
}

function non_negative_quantity_input_for_unit($value, string $unit, string $label, float $default = 0): float
{
    $number = quantity_input_for_unit($value, $unit, $label, $default);

    if ($number < 0) {
        json_response(['ok' => false, 'error' => $label . ' ne peut pas etre negative.'], 400);
    }

    return $number;
}

function positive_quantity_input_for_unit($value, string $unit, string $label): float
{
    $number = quantity_input_for_unit($value, $unit, $label);

    if ($number <= 0) {
        json_response(['ok' => false, 'error' => $label . ' doit etre superieure a zero.'], 400);
    }

    return $number;
}

function ensure_users_table(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          username VARCHAR(80) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          full_name VARCHAR(120) NOT NULL,
          role VARCHAR(40) NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_users_username (username),
          INDEX idx_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    if ($count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role)
             VALUES (:username, :password_hash, :full_name, :role)'
        );

        $defaultUsers = [
            ['admin', 'admin123', 'Administrateur', 'admin'],
            ['stock', 'stock123', 'Moderateur stock', 'moderateur_stock'],
            ['projet', 'projet123', 'Gestionnaire projet', 'gestionnaire_projet'],
        ];

        foreach ($defaultUsers as [$username, $password, $fullName, $role]) {
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':full_name' => $fullName,
                ':role' => $role,
            ]);
        }
    }

    $done = true;
}

function user_to_app(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'name' => $row['full_name'],
        'role' => $row['role'],
    ];
}

function current_user(PDO $pdo): ?array
{
    ensure_users_table($pdo);

    $id = (int) ($_SESSION['user_id'] ?? 0);

    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, role, active
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['active'] !== 1) {
        unset($_SESSION['user_id']);
        return null;
    }

    return user_to_app($user);
}

function require_auth(PDO $pdo): array
{
    $user = current_user($pdo);

    if (!$user) {
        json_response(['ok' => false, 'error' => 'Connexion requise.'], 401);
    }

    return $user;
}

function require_roles(PDO $pdo, array $roles): array
{
    $user = require_auth($pdo);

    if ($user['role'] === 'admin' || in_array($user['role'], $roles, true)) {
        return $user;
    }

    json_response(['ok' => false, 'error' => 'Acces refuse pour ce role.'], 403);
}

function ensure_stock_movements_table(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_movements (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          group_id VARCHAR(40) NULL,
          material_id INT UNSIGNED NULL,
          material_name VARCHAR(150) NOT NULL,
          unit VARCHAR(30) NOT NULL,
          quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
          destination VARCHAR(120) NULL,
          requester VARCHAR(120) NULL,
          notes TEXT NULL,
          movement_date DATE NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_stock_movements_group_id (group_id),
          INDEX idx_stock_movements_material_id (material_id),
          INDEX idx_stock_movements_date (movement_date),
          INDEX idx_stock_movements_destination (destination),
          CONSTRAINT fk_stock_movements_material
            FOREIGN KEY (material_id)
            REFERENCES materials(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Ajouter la colonne group_id si elle n'existe pas (pour les tables existantes)
    $stmt = $pdo->query("SHOW COLUMNS FROM stock_movements LIKE 'group_id'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE stock_movements ADD COLUMN group_id VARCHAR(40) NULL AFTER id');
        $pdo->exec('ALTER TABLE stock_movements ADD INDEX idx_stock_movements_group_id (group_id)');
    }

    $done = true;
}

function ensure_stock_history_table(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_history (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          event_type VARCHAR(30) NOT NULL,
          material_id INT UNSIGNED NULL,
          material_name VARCHAR(150) NOT NULL,
          unit VARCHAR(30) NOT NULL,
          quantity_delta DECIMAL(12,2) NOT NULL DEFAULT 0,
          stock_before DECIMAL(12,2) NULL,
          stock_after DECIMAL(12,2) NULL,
          source_type VARCHAR(40) NULL,
          source_id BIGINT UNSIGNED NULL,
          destination VARCHAR(120) NULL,
          requester VARCHAR(120) NULL,
          actor_id INT UNSIGNED NULL,
          actor_name VARCHAR(120) NULL,
          actor_role VARCHAR(40) NULL,
          notes TEXT NULL,
          event_date DATE NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_stock_history_event_type (event_type),
          INDEX idx_stock_history_material_id (material_id),
          INDEX idx_stock_history_event_date (event_date),
          INDEX idx_stock_history_actor_id (actor_id),
          INDEX idx_stock_history_source (source_type, source_id),
          CONSTRAINT fk_stock_history_material
            FOREIGN KEY (material_id)
            REFERENCES materials(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'actor_id' => 'ALTER TABLE stock_history ADD actor_id INT UNSIGNED NULL AFTER requester',
        'actor_name' => 'ALTER TABLE stock_history ADD actor_name VARCHAR(120) NULL AFTER actor_id',
        'actor_role' => 'ALTER TABLE stock_history ADD actor_role VARCHAR(40) NULL AFTER actor_name',
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_history LIKE " . $pdo->quote($column));
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
        }
    }

    $stmt = $pdo->query("SHOW INDEX FROM stock_history WHERE Key_name = 'idx_stock_history_actor_id'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE stock_history ADD INDEX idx_stock_history_actor_id (actor_id)');
    }

    $done = true;
}

function log_stock_history(PDO $pdo, array $event): void
{
    ensure_stock_history_table($pdo);
    $actor = null;

    if (isset($event['actor']) && is_array($event['actor'])) {
        $actor = $event['actor'];
    } else {
        $actor = current_user($pdo);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO stock_history
         (event_type, material_id, material_name, unit, quantity_delta, stock_before, stock_after,
          source_type, source_id, destination, requester, actor_id, actor_name, actor_role, notes, event_date)
         VALUES
         (:event_type, :material_id, :material_name, :unit, :quantity_delta, :stock_before, :stock_after,
          :source_type, :source_id, :destination, :requester, :actor_id, :actor_name, :actor_role, :notes, :event_date)'
    );
    $stmt->execute([
        ':event_type' => (string) ($event['event_type'] ?? 'correction'),
        ':material_id' => $event['material_id'] ?? null,
        ':material_name' => (string) ($event['material_name'] ?? ''),
        ':unit' => (string) ($event['unit'] ?? ''),
        ':quantity_delta' => (float) ($event['quantity_delta'] ?? 0),
        ':stock_before' => array_key_exists('stock_before', $event) ? $event['stock_before'] : null,
        ':stock_after' => array_key_exists('stock_after', $event) ? $event['stock_after'] : null,
        ':source_type' => $event['source_type'] ?? null,
        ':source_id' => $event['source_id'] ?? null,
        ':destination' => $event['destination'] ?? null,
        ':requester' => $event['requester'] ?? null,
        ':actor_id' => isset($actor['id']) ? (int) $actor['id'] : null,
        ':actor_name' => $actor['name'] ?? $actor['username'] ?? null,
        ':actor_role' => $actor['role'] ?? null,
        ':notes' => $event['notes'] ?? null,
        ':event_date' => $event['event_date'] ?? date('Y-m-d'),
    ]);
}

function ensure_suppliers_table(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS suppliers (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          name VARCHAR(150) NOT NULL,
          contact_person VARCHAR(120) NULL,
          phone VARCHAR(50) NULL,
          email VARCHAR(150) NULL,
          address VARCHAR(200) NULL,
          lead_time_days INT UNSIGNED NOT NULL DEFAULT 7,
          products TEXT NULL,
          notes TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_suppliers_name (name),
          INDEX idx_suppliers_lead_time (lead_time_days)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function material_to_app(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'cat' => $row['category'],
        'qty' => (float) $row['quantity'],
        'min' => (float) $row['min_quantity'],
        'unit' => $row['unit'],
        'price' => (float) $row['unit_price'],
        'conso' => (float) $row['weekly_consumption'],
        'supplier' => $row['supplier'] ?? '',
        'image' => $row['image_url'] ?? '',
    ];
}

function order_to_app(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'groupId' => $row['group_id'] ?? '',
        'itemId' => isset($row['material_id']) ? (int) $row['material_id'] : null,
        'name' => $row['material_name'],
        'unit' => $row['unit'],
        'qty' => (float) $row['quantity'],
        'supplier' => $row['supplier'] ?? '',
        'delay' => (int) $row['delay_days'],
        'cost' => (float) $row['total_cost'],
        'notes' => $row['notes'] ?? '',
        'date' => $row['date_fr'] ?? $row['order_date'],
        'status' => $row['status'],
        'createdAt' => $row['created_at'] ?? '',
    ];
}

function movement_to_app(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'groupId' => $row['group_id'] ?? '',
        'itemId' => isset($row['material_id']) ? (int) $row['material_id'] : null,
        'name' => $row['material_name'],
        'unit' => $row['unit'],
        'qty' => (float) $row['quantity'],
        'destination' => $row['destination'] ?? '',
        'requester' => $row['requester'] ?? '',
        'notes' => $row['notes'] ?? '',
        'date' => $row['date_fr'] ?? $row['movement_date'],
    ];
}

function history_to_app(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'type' => $row['event_type'],
        'itemId' => isset($row['material_id']) ? (int) $row['material_id'] : null,
        'name' => $row['material_name'],
        'unit' => $row['unit'],
        'delta' => (float) $row['quantity_delta'],
        'before' => isset($row['stock_before']) ? (float) $row['stock_before'] : null,
        'after' => isset($row['stock_after']) ? (float) $row['stock_after'] : null,
        'sourceType' => $row['source_type'] ?? '',
        'sourceId' => isset($row['source_id']) ? (int) $row['source_id'] : null,
        'destination' => $row['destination'] ?? '',
        'requester' => $row['requester'] ?? '',
        'actorId' => isset($row['actor_id']) ? (int) $row['actor_id'] : null,
        'actorName' => $row['actor_name'] ?? '',
        'actorRole' => $row['actor_role'] ?? '',
        'notes' => $row['notes'] ?? '',
        'date' => $row['date_fr'] ?? $row['event_date'],
        'createdAt' => $row['created_fr'] ?? $row['created_at'],
    ];
}

function supplier_to_app(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'contact' => $row['contact_person'] ?? '',
        'phone' => $row['phone'] ?? '',
        'email' => $row['email'] ?? '',
        'address' => $row['address'] ?? '',
        'leadTime' => (int) $row['lead_time_days'],
        'products' => $row['products'] ?? '',
        'notes' => $row['notes'] ?? '',
    ];
}

function ensure_orders_table(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS orders (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          group_id VARCHAR(40) NULL,
          material_id INT UNSIGNED NULL,
          material_name VARCHAR(150) NOT NULL,
          unit VARCHAR(30) NOT NULL,
          quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
          supplier VARCHAR(150) NULL,
          delay_days INT UNSIGNED NOT NULL DEFAULT 7,
          total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
          notes TEXT NULL,
          order_date DATE NOT NULL,
          status VARCHAR(30) NOT NULL DEFAULT 'en cours',
          received_at TIMESTAMP NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_orders_group_id (group_id),
          INDEX idx_orders_material_id (material_id),
          INDEX idx_orders_date (order_date),
          INDEX idx_orders_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'group_id'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN group_id VARCHAR(40) NULL AFTER id');
        $pdo->exec('ALTER TABLE orders ADD INDEX idx_orders_group_id (group_id)');
    }

    $done = true;
}

function fetch_materials(PDO $pdo): array
{
    ensure_material_image_column($pdo);

    $stmt = $pdo->query(
        'SELECT id, name, category, quantity, min_quantity, unit, unit_price, weekly_consumption, supplier, image_url
         FROM materials
         ORDER BY id ASC'
    );

    return array_map('material_to_app', $stmt->fetchAll());
}

function ensure_material_image_column(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM materials LIKE 'image_url'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE materials ADD image_url MEDIUMTEXT NULL AFTER supplier');
    }

    $done = true;
}

function fetch_orders(PDO $pdo): array
{
    ensure_orders_table($pdo);

    $stmt = $pdo->query(
        "SELECT id, group_id, material_id, material_name, unit, quantity, supplier, delay_days, total_cost, notes,
                DATE_FORMAT(order_date, '%d/%m/%Y') AS date_fr, status, created_at
         FROM orders
         ORDER BY created_at DESC, id DESC"
    );

    return array_map('order_to_app', $stmt->fetchAll());
}

function fetch_movements(PDO $pdo): array
{
    ensure_stock_movements_table($pdo);

    $stmt = $pdo->query(
        "SELECT id, group_id, material_id, material_name, unit, quantity, destination, requester, notes,
                DATE_FORMAT(movement_date, '%d/%m/%Y') AS date_fr
         FROM stock_movements
         ORDER BY movement_date DESC, id DESC"
    );

    return array_map('movement_to_app', $stmt->fetchAll());
}

function fetch_stock_history(PDO $pdo): array
{
    ensure_stock_history_table($pdo);

    $stmt = $pdo->query(
        "SELECT id, event_type, material_id, material_name, unit, quantity_delta, stock_before, stock_after,
                source_type, source_id, destination, requester, actor_id, actor_name, actor_role, notes,
                DATE_FORMAT(event_date, '%d/%m/%Y') AS date_fr,
                DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS created_fr
         FROM stock_history
         ORDER BY event_date DESC, id DESC
         LIMIT 500"
    );

    return array_map('history_to_app', $stmt->fetchAll());
}

function fetch_suppliers(PDO $pdo): array
{
    ensure_suppliers_table($pdo);

    $stmt = $pdo->query(
        'SELECT id, name, contact_person, phone, email, address, lead_time_days, products, notes
         FROM suppliers
         ORDER BY name ASC'
    );

    return array_map('supplier_to_app', $stmt->fetchAll());
}

function fetch_settings(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT workshop_name, currency FROM settings WHERE id = 1');
    $settings = $stmt->fetch();

    if (!$settings) {
        $pdo->exec("INSERT INTO settings (id, workshop_name, currency) VALUES (1, 'Mon Atelier Rangement', 'FCFA')");

        return [
            'atelier' => 'Mon Atelier Rangement',
            'devise' => 'FCFA',
        ];
    }

    return [
        'atelier' => $settings['workshop_name'],
        'devise' => $settings['currency'],
    ];
}

function ensure_quotes_table(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quotes (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          project_name VARCHAR(150) NOT NULL,
          client_name VARCHAR(150) NULL,
          pieces_count INT NOT NULL DEFAULT 1,
          total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
          quote_data LONGTEXT NULL,
          quote_hash VARCHAR(64) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_quotes_created_at (created_at),
          UNIQUE KEY uq_quotes_hash (quote_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Ajouter la colonne quote_hash si elle n'existe pas
    $stmt = $pdo->query("SHOW COLUMNS FROM quotes LIKE 'quote_hash'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE quotes ADD COLUMN quote_hash VARCHAR(64) NULL UNIQUE AFTER quote_data');
    }

    $done = true;
}
