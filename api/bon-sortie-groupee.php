<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = db();
    $user = require_auth($pdo);
    ensure_stock_movements_table($pdo);
    
    $bonId = trim((string) ($_GET['id'] ?? ''));
    
    if (!$bonId) {
        http_response_code(400);
        echo 'Identifiant invalide';
        exit;
    }
    
    // Chercher toutes les sorties du groupe spécifié
    $stmt = $pdo->prepare(
        'SELECT destination, requester, movement_date, created_at, material_name, unit, quantity
         FROM stock_movements
         WHERE group_id = :group_id
         ORDER BY id ASC'
    );
    $stmt->execute([':group_id' => $bonId]);
    $allMovements = $stmt->fetchAll();
    
    if (!$allMovements) {
        http_response_code(404);
        echo 'Sorties introuvables';
        exit;
    }
    
    // Prendre les infos du dernier mouvement
    $groupInfo = [
        'destination' => $allMovements[0]['destination'],
        'requester' => $allMovements[0]['requester'],
        'movement_date' => $allMovements[0]['movement_date'],
        'created_at' => $allMovements[0]['created_at']
    ];
    
    $movements = $allMovements;
    
    $settingsStmt = $pdo->prepare('SELECT workshop_name, currency FROM settings WHERE id = 1');
    $settingsStmt->execute();
    $settings = $settingsStmt->fetch();
    $atelierName = $settings['workshop_name'] ?? 'Atelier Rangement';
    $devise = $settings['currency'] ?? 'FCFA';
    
    $bonNumber = 'BON-SORTIE-GROUPE-' . $bonId;
    $createdFormatted = date('d/m/Y à H:i', strtotime($groupInfo['created_at']));
    
    header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon de sortie - <?php echo htmlspecialchars($bonNumber); ?></title>
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
        
        .bon-number {
            text-align: right;
            padding-top: 10px;
        }
        
        .bon-label {
            font-size: 11px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .bon-code {
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
        
        .qty-col {
            text-align: right;
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
        }
        
        .btn-secondary {
            background: #e0e0e0;
            color: #333;
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
                <span>🖨️</span>
                Imprimer
            </button>
            <button class="btn btn-secondary" onclick="window.location.href='../gestion_stock_atelier.html#sorties'">
                <span>←</span>
                Retour
            </button>
        </div>
        
        <div class="header">
            <div class="company-info">
                <img src="../assets/logo-king-rangement.png" alt="Logo" class="company-logo">
                <div class="company-name"><?php echo htmlspecialchars($atelierName); ?></div>
            </div>
            <div class="bon-number">
                <div class="bon-label">Bon de Sortie</div>
                <div class="bon-code"><?php echo htmlspecialchars($bonNumber); ?></div>
            </div>
        </div>
        
        <div class="document-title">BON DE SORTIE DE STOCK</div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">Service / Projet</div>
                <div class="info-value"><?php echo htmlspecialchars($groupInfo['destination'] ?? '—'); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Demandé par</div>
                <div class="info-value"><?php echo htmlspecialchars($groupInfo['requester'] ?? '—'); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Date</div>
                <div class="info-value"><?php echo htmlspecialchars($createdFormatted); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Nombre d'articles</div>
                <div class="info-value"><?php echo count($movements); ?></div>
            </div>
        </div>
        
        <div class="materials-section">
            <div class="materials-title">Matériaux retirés du stock</div>
            <table class="materials-table">
                <thead>
                    <tr>
                        <th style="width: 60%;">Matériau</th>
                        <th style="width: 20%;">Unité</th>
                        <th style="width: 20%;" class="qty-col">Quantité</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $mvt): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($mvt['material_name']); ?></td>
                        <td><?php echo htmlspecialchars($mvt['unit']); ?></td>
                        <td class="qty-col"><?php echo format_quantity_for_unit($mvt['quantity'], (string) $mvt['unit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
            const bonNumber = '<?php echo htmlspecialchars($bonNumber); ?>';
            
            const options = {
                margin: 0.5,
                filename: `${bonNumber}.pdf`,
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
    
} catch (Exception $e) {
    http_response_code(500);
    echo 'Erreur serveur: ' . htmlspecialchars($e->getMessage());
}
