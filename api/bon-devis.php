<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_auth($pdo);
    
    $id = (int) ($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        http_response_code(400);
        echo 'Identifiant devis manquant';
        exit;
    }
    
    $stmt = $pdo->prepare(
        'SELECT id, project_name, client_name, pieces_count, total_amount, created_at, quote_data
         FROM quotes
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $quote = $stmt->fetch();
    
    if (!$quote) {
        http_response_code(404);
        echo 'Devis introuvable';
        exit;
    }
    
    // Décoder les données du devis
    $quoteData = json_decode($quote['quote_data'], true) ?: [];
    
    // Numéro unique du devis : DEVIS-YYYYMMDD-XXXXX
    $createdDate = DateTime::createFromFormat('Y-m-d H:i:s', $quote['created_at']);
    $devisNumber = 'DEVIS-' . $createdDate->format('Ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    $createdFormatted = $createdDate->format('d/m/Y à H:i');
    
    // Get settings for company name
    $settingsStmt = $pdo->prepare('SELECT workshop_name, currency FROM settings WHERE id = 1');
    $settingsStmt->execute();
    $settings = $settingsStmt->fetch();
    $atelierName = $settings['workshop_name'] ?? 'Atelier Rangement';
    $devise = $settings['currency'] ?? 'FCFA';
    
    header('Content-Type: text/html; charset=utf-8');
    
    // Calculer le total
    $totalAmount = (float) $quote['total_amount'];
    $piecesCount = (int) $quote['pieces_count'];
    
    ?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devis - <?php echo htmlspecialchars($devisNumber); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .company-info {
            flex: 1;
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
            margin-bottom: 5px;
        }
        
        .devis-number {
            text-align: right;
            padding-top: 10px;
        }
        
        .devis-label {
            font-size: 11px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .devis-code {
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
            margin-bottom: 40px;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 6px;
        }
        
        .info-group {
            display: flex;
            flex-direction: column;
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
        
        .materials-section {
            margin-bottom: 30px;
        }
        
        .materials-title {
            font-size: 12px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 15px;
        }
        
        .materials-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .materials-table thead {
            background: #1a7a56;
            color: white;
        }
        
        .materials-table th {
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            border: none;
        }
        
        .materials-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        
        .materials-table tbody tr:nth-child(odd) {
            background: #fafafa;
        }
        
        .materials-table tbody tr:hover {
            background: #f0f0f0;
        }
        
        .qty-col, .price-col, .total-col {
            text-align: right;
            font-family: 'Courier New', monospace;
        }
        
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
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .summary-row:last-child {
            margin-bottom: 0;
            padding-top: 10px;
            border-top: 2px solid rgba(255,255,255,0.3);
            font-size: 18px;
            font-weight: 700;
            margin-top: 10px;
        }
        
        .summary-label {
            font-weight: 500;
        }
        
        .summary-value {
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }
        
        .footer {
            border-top: 2px solid #e0e0e0;
            padding-top: 20px;
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            min-height: 60px;
        }
        
        .footer-left {
            flex: 1;
        }
        
        .footer-right {
            text-align: right;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 30px;
            margin-bottom: 5px;
            width: 150px;
        }
        
        .signature-label {
            font-size: 11px;
            color: #666;
            font-weight: 500;
        }
        
        .print-date {
            font-size: 11px;
            color: #999;
            margin-top: 10px;
        }
        
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
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #1a7a56;
            color: white;
        }
        
        .btn-primary:hover {
            background: #156646;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(26,122,86,0.3);
        }
        
        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }
        
        .btn-secondary:hover {
            background: #d0d0d0;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .container {
                box-shadow: none;
                border-radius: 0;
                padding: 0;
                max-width: 100%;
            }
            
            .action-bar {
                display: none;
            }
            
            @page {
                margin: 0.5cm;
            }
        }
        
        @media (max-width: 600px) {
            .container {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 20px;
            }
            
            .footer {
                flex-direction: column;
                gap: 20px;
            }
            
            .materials-table {
                font-size: 12px;
            }
            
            .materials-table th,
            .materials-table td {
                padding: 8px;
            }
            
            .summary-box {
                min-width: auto;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="action-bar" id="action-bar">
            <button class="btn btn-primary" onclick="downloadPDF()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Télécharger PDF
            </button>
            <button class="btn btn-primary" onclick="window.print()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                Imprimer
            </button>
            <button class="btn btn-secondary" onclick="window.location.href='../gestion_stock_atelier.html#simulation'">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Retour
            </button>
        </div>
        
        <div class="header">
            <div class="company-info">
                <img src="../assets/logo-king-rangement.png" alt="Logo" class="company-logo">
                <div class="company-name"><?php echo htmlspecialchars($atelierName); ?></div>
            </div>
            <div class="devis-number">
                <div class="devis-label">Devis</div>
                <div class="devis-code"><?php echo htmlspecialchars($devisNumber); ?></div>
            </div>
        </div>
        
        <div class="document-title">DEVIS PROJECT</div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">Client</div>
                <div class="info-value"><?php echo htmlspecialchars($quote['client_name'] ?: '—'); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Projet</div>
                <div class="info-value"><?php echo htmlspecialchars($quote['project_name']); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Nombre de pièces</div>
                <div class="info-value"><?php echo htmlspecialchars((string)$piecesCount); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Date du devis</div>
                <div class="info-value"><?php echo htmlspecialchars($createdFormatted); ?></div>
            </div>
        </div>
        
        <div class="materials-section">
            <div class="materials-title">Matériaux et fournitures nécessaires</div>
            <table class="materials-table">
                <thead>
                    <tr>
                        <th style="width: 40%;">Matériau</th>
                        <th style="width: 15%;">Unité</th>
                        <th style="width: 15%;" class="qty-col">Quantité</th>
                        <th style="width: 15%;" class="price-col">Prix unit.</th>
                        <th style="width: 15%;" class="total-col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $subtotal = 0;
                    if (!empty($quoteData) && is_array($quoteData)) {
                        // Les données sont toujours dans 'rows'
                        $rows = $quoteData['rows'] ?? [];
                        if (!is_array($rows)) {
                            $rows = [];
                        }
                        
                        foreach ($rows as $row) {
                            if (!is_array($row)) continue;
                            
                            // L'item contient toutes les infos du produit
                            $item = $row['item'] ?? [];
                            if (!is_array($item) || empty($item)) continue;
                            
                            $itemName = htmlspecialchars($item['name'] ?? '(sans nom)');
                            $itemUnit = htmlspecialchars($item['unit'] ?? 'pce');
                            $itemQty = (float) ($row['totalQty'] ?? 0);
                            $itemPrice = (float) ($item['price'] ?? 0);
                            $itemTotal = $itemQty * $itemPrice;
                            $subtotal += $itemTotal;
                            
                            if ($itemQty > 0 && !empty($itemName)) {
                            ?>
                    <tr>
                        <td><?php echo $itemName; ?></td>
                        <td><?php echo $itemUnit; ?></td>
                        <td class="qty-col"><?php echo format_quantity_for_unit($itemQty, (string) ($item['unit'] ?? '')); ?></td>
                        <td class="price-col"><?php echo number_format($itemPrice, 0, ',', ' '); ?> <?php echo htmlspecialchars($devise); ?></td>
                        <td class="total-col"><strong><?php echo number_format($itemTotal, 0, ',', ' '); ?> <?php echo htmlspecialchars($devise); ?></strong></td>
                    </tr>
                    <?php
                            }
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        
        <div class="summary">
            <div class="summary-box">
                <div class="summary-row">
                    <span class="summary-label">Sous-total :</span>
                    <span class="summary-value"><?php echo number_format($subtotal, 0, ',', ' '); ?> <?php echo htmlspecialchars($devise); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Nombre de pièces :</span>
                    <span class="summary-value"><?php echo $piecesCount; ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">TOTAL :</span>
                    <span class="summary-value"><?php echo number_format($totalAmount, 0, ',', ' '); ?> <?php echo htmlspecialchars($devise); ?></span>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <div class="footer-left">
                <div class="signature-line"></div>
                <div class="signature-label">Signature / Tampon</div>
            </div>
            <div class="footer-right">
                <div class="print-date">Imprimé le <?php echo date('d/m/Y à H:i'); ?></div>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        function downloadPDF() {
            const element = document.querySelector('.container');
            const actionBar = document.getElementById('action-bar');
            const devisNumber = '<?php echo htmlspecialchars($devisNumber); ?>';
            
            const options = {
                margin: 0.5,
                filename: `Devis-${devisNumber}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2 },
                jsPDF: { unit: 'cm', format: 'a4', orientation: 'portrait' }
            };
            
            if (actionBar) actionBar.style.display = 'none';
            html2pdf().set(options).from(element).save().finally(() => {
                if (actionBar) actionBar.style.display = 'flex';
            });
        }
        
        // Hide action bar on print
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
    
} catch (Exception $e) {
    http_response_code(500);
    echo 'Erreur serveur: ' . htmlspecialchars($e->getMessage());
}
