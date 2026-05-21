<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    require_roles($pdo, ['admin']);

    ensure_orders_table($pdo);

    $rawId = trim((string) ($_GET['id'] ?? ''));
    $groupId = trim((string) ($_GET['groupId'] ?? ''));
    if ($rawId === '' && $groupId === '') {
        http_response_code(400);
        echo 'Identifiant commande manquant';
        exit;
    }

    $lookupId = ctype_digit($rawId) ? (int) $rawId : 0;
    if ($groupId === '' && $rawId !== '' && !ctype_digit($rawId)) {
        $groupId = $rawId;
    }

    if ($lookupId > 0) {
        $stmt = $pdo->prepare('SELECT group_id FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $lookupId]);
        $found = $stmt->fetch();

        if (!$found) {
            http_response_code(404);
            echo 'Commande introuvable';
            exit;
        }

        $groupId = (string) ($found['group_id'] ?? '');
    }

    if ($groupId !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, group_id, material_name, unit, quantity, supplier, delay_days, total_cost, notes,
                    order_date, status, created_at, received_at
             FROM orders
             WHERE group_id = :group_id
             ORDER BY id ASC'
        );
        $stmt->execute([':group_id' => $groupId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, group_id, material_name, unit, quantity, supplier, delay_days, total_cost, notes,
                    order_date, status, created_at, received_at
             FROM orders
             WHERE id = :id
             ORDER BY id ASC'
        );
        $stmt->execute([':id' => $lookupId]);
    }

    $orders = $stmt->fetchAll();

    if (!$orders) {
        http_response_code(404);
        echo 'Commande introuvable';
        exit;
    }

    $order = $orders[0];

    $settingsStmt = $pdo->prepare('SELECT workshop_name, currency FROM settings WHERE id = 1');
    $settingsStmt->execute();
    $settings = $settingsStmt->fetch();
    $atelierName = $settings['workshop_name'] ?? 'Atelier Rangement';
    $devise = $settings['currency'] ?? 'FCFA';

    $createdDate = DateTime::createFromFormat('Y-m-d H:i:s', (string) ($order['created_at'] ?? '')) ?: new DateTime();
    $orderDate = DateTime::createFromFormat('Y-m-d', (string) ($order['order_date'] ?? '')) ?: $createdDate;
    $expectedDate = clone $orderDate;
    $expectedDate->modify('+' . (int) $order['delay_days'] . ' days');
    $receivedDate = !empty($order['received_at'])
        ? DateTime::createFromFormat('Y-m-d H:i:s', (string) $order['received_at'])
        : null;

    $commandeNumber = 'CMD-' . $createdDate->format('Ymd') . '-' . str_pad((string) $order['id'], 5, '0', STR_PAD_LEFT);
    $createdFormatted = $createdDate->format('d/m/Y à H:i');
    $orderFormatted = $orderDate->format('d/m/Y');
    $expectedFormatted = $expectedDate->format('d/m/Y');
    $receivedFormatted = $receivedDate ? $receivedDate->format('d/m/Y à H:i') : '—';
    $totalCost = array_reduce($orders, fn($sum, $line) => $sum + (float) $line['total_cost'], 0.0);

    header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon de commande - <?php echo htmlspecialchars($commandeNumber); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px solid #1a7a56;
        }
        .company-logo {
            max-width: 80px;
            height: auto;
            margin-bottom: 10px;
        }
        .company-name {
            font-size: 24px;
            font-weight: 600;
            color: #1a7a56;
        }
        .commande-number { text-align: right; padding-top: 10px; }
        .commande-label {
            font-size: 11px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .commande-code {
            font-size: 22px;
            font-weight: 700;
            color: #1a7a56;
            font-family: 'Courier New', monospace;
            margin-top: 5px;
        }
        .document-title {
            text-align: center;
            font-size: 28px;
            font-weight: 700;
            color: #333;
            margin-bottom: 30px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 35px;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 6px;
        }
        .info-label {
            font-size: 11px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 16px;
            font-weight: 500;
            color: #333;
            word-break: break-word;
        }
        .materials-title {
            font-size: 12px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        thead { background: #1a7a56; color: white; }
        th, td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
            text-align: left;
        }
        tbody tr:nth-child(odd) { background: #fafafa; }
        .num { text-align: right; font-family: 'Courier New', monospace; }
        .summary {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
            margin-bottom: 30px;
        }
        .summary-box {
            background: #1a7a56;
            color: white;
            padding: 20px;
            border-radius: 6px;
            min-width: 300px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            font-size: 14px;
        }
        .summary-value {
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        .notes {
            background: #fff8e8;
            border-left: 4px solid #b8720a;
            padding: 14px 16px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        .footer {
            border-top: 2px solid #e0e0e0;
            padding-top: 20px;
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            min-height: 70px;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 35px;
            margin-bottom: 5px;
            width: 160px;
        }
        .signature-label, .print-date { font-size: 11px; color: #666; }
        .action-bar {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 30px;
            padding: 20px;
            background: #f0f4f8;
            border-radius: 6px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #1a7a56; color: white; }
        .btn-secondary { background: #e0e0e0; color: #333; }
        @media print {
            body { background: white; padding: 0; }
            .container { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
            .action-bar { display: none; }
            @page { margin: 0.5cm; }
        }
        @media (max-width: 600px) {
            .container { padding: 20px; }
            .header, .footer { flex-direction: column; gap: 20px; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="action-bar" id="action-bar">
            <button class="btn btn-primary" onclick="downloadPDF()">Télécharger PDF</button>
            <button class="btn btn-primary" onclick="window.print()">Imprimer</button>
            <button class="btn btn-secondary" onclick="window.location.href='../gestion_stock_atelier.html#commandes'">Retour</button>
        </div>

        <div class="header">
            <div>
                <img src="../assets/logo-king-rangement.png" alt="Logo" class="company-logo">
                <div class="company-name"><?php echo htmlspecialchars($atelierName); ?></div>
            </div>
            <div class="commande-number">
                <div class="commande-label">Bon de commande</div>
                <div class="commande-code"><?php echo htmlspecialchars($commandeNumber); ?></div>
            </div>
        </div>

        <div class="document-title">BON DE COMMANDE</div>

        <div class="info-grid">
            <div>
                <div class="info-label">Fournisseur</div>
                <div class="info-value"><?php echo htmlspecialchars($order['supplier'] ?: '—'); ?></div>
            </div>
            <div>
                <div class="info-label">Statut</div>
                <div class="info-value"><?php echo htmlspecialchars($order['status']); ?></div>
            </div>
            <div>
                <div class="info-label">Date de commande</div>
                <div class="info-value"><?php echo htmlspecialchars($orderFormatted); ?></div>
            </div>
            <div>
                <div class="info-label">Date prévue</div>
                <div class="info-value"><?php echo htmlspecialchars($expectedFormatted); ?></div>
            </div>
            <div>
                <div class="info-label">Créée le</div>
                <div class="info-value"><?php echo htmlspecialchars($createdFormatted); ?></div>
            </div>
            <div>
                <div class="info-label">Réceptionnée le</div>
                <div class="info-value"><?php echo htmlspecialchars($receivedFormatted); ?></div>
            </div>
        </div>

        <div class="materials-title">Articles commandés</div>
        <table>
            <thead>
                <tr>
                    <th>Matériau</th>
                    <th>Unité</th>
                    <th class="num">Quantité</th>
                    <th class="num">Prix unit.</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $line): ?>
                <?php
                    $quantity = (float) $line['quantity'];
                    $lineTotal = (float) $line['total_cost'];
                    $unitCost = $quantity > 0 ? $lineTotal / $quantity : 0;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($line['material_name']); ?></td>
                    <td><?php echo htmlspecialchars($line['unit']); ?></td>
                    <td class="num"><?php echo format_quantity_for_unit($quantity, (string) $line['unit']); ?></td>
                    <td class="num"><?php echo number_format($unitCost, 0, ',', ' '); ?> <?php echo htmlspecialchars($devise); ?></td>
                    <td class="num"><strong><?php echo number_format($lineTotal, 0, ',', ' '); ?> <?php echo htmlspecialchars($devise); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="summary">
            <div class="summary-box">
                <div class="summary-row">
                    <span>Total commande</span>
                    <span class="summary-value"><?php echo number_format($totalCost, 0, ',', ' '); ?> <?php echo htmlspecialchars($devise); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($order['notes'])): ?>
        <div class="notes">
            <div class="info-label">Notes</div>
            <div><?php echo htmlspecialchars($order['notes']); ?></div>
        </div>
        <?php endif; ?>

        <div class="footer">
            <div>
                <div class="signature-line"></div>
                <div class="signature-label">Signature / Tampon fournisseur</div>
            </div>
            <div class="print-date">Imprimé le <?php echo date('d/m/Y à H:i'); ?></div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        function downloadPDF() {
            const element = document.querySelector('.container');
            const actionBar = document.getElementById('action-bar');
            const commandeNumber = '<?php echo htmlspecialchars($commandeNumber); ?>';
            const options = {
                margin: 0.5,
                filename: `Bon-Commande-${commandeNumber}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2 },
                jsPDF: { unit: 'cm', format: 'a4', orientation: 'portrait' }
            };

            if (actionBar) actionBar.style.display = 'none';
            html2pdf().set(options).from(element).save().finally(() => {
                if (actionBar) actionBar.style.display = 'flex';
            });
        }

        window.addEventListener('beforeprint', function() {
            document.getElementById('action-bar').style.display = 'none';
        });

        window.addEventListener('afterprint', function() {
            document.getElementById('action-bar').style.display = 'flex';
        });

        if (new URLSearchParams(window.location.search).get('pdf') === 'true') {
            window.addEventListener('load', function() {
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('pdf');
                window.history.replaceState(null, '', cleanUrl.toString());
                setTimeout(downloadPDF, 300);
            });
        }
    </script>
</body>
</html>
<?php
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur serveur bon de commande.';
}
