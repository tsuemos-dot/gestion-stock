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
        'SELECT id, material_id, material_name, unit, quantity, destination, requester, notes, movement_date,
         COALESCE(created_at, movement_date) as created_at
         FROM stock_movements
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $movement = $stmt->fetch();
    
    if (!$movement) {
        http_response_code(404);
        echo 'Sortie introuvable';
        exit;
    }
    
    // Generate unique bon number: BON-YYYYMMDD-XXXXX
    $bonDate = DateTime::createFromFormat('Y-m-d', $movement['movement_date']) ?: new DateTime();
    $bonNumber = 'BON-' . $bonDate->format('Ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    
    // Format dates
    $createdDate = DateTime::createFromFormat('Y-m-d H:i:s', $movement['created_at'] ?: date('Y-m-d H:i:s'));
    $dateFormatted = $bonDate->format('d/m/Y') . ($createdDate ? ' à ' . $createdDate->format('H:i') : '');
    $createdFormatted = $createdDate ? $createdDate->format('d/m/Y à H:i') : date('d/m/Y');
    
    // Get settings for company name
    $settingsStmt = $pdo->prepare('SELECT workshop_name FROM settings WHERE id = 1');
    $settingsStmt->execute();
    $settings = $settingsStmt->fetch();
    $atelierName = $settings['workshop_name'] ?? 'Atelier Rangement';
    
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
            max-width: 800px;
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
            word-break: break-word;
        }
        
        .material-section {
            background: #fafafa;
            border-left: 4px solid #1a7a56;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        
        .material-title {
            font-size: 12px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 10px;
        }
        
        .material-name {
            font-size: 20px;
            font-weight: 700;
            color: #1a7a56;
            margin-bottom: 10px;
        }
        
        .quantity-box {
            background: white;
            border: 2px solid #1a7a56;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
        }
        
        .quantity-label {
            font-size: 11px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .quantity-value {
            font-size: 32px;
            font-weight: 700;
            color: #1a7a56;
            margin: 8px 0;
        }
        
        .quantity-unit {
            font-size: 14px;
            color: #666;
        }
        
        .notes-section {
            margin-bottom: 30px;
        }
        
        .notes-section h3 {
            font-size: 12px;
            font-weight: 600;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 10px;
        }
        
        .notes-content {
            background: white;
            padding: 12px;
            border-left: 3px solid #ffc107;
            border-radius: 4px;
            color: #555;
            font-size: 14px;
            min-height: 40px;
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
            <button class="btn btn-secondary" onclick="window.location.href='../gestion_stock_atelier.html#sorties'">
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
            <div class="bon-number">
                <div class="bon-label">Bon de sortie</div>
                <div class="bon-code"><?php echo htmlspecialchars($bonNumber); ?></div>
            </div>
        </div>
        
        <div class="document-title">BON DE SORTIE</div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">Demandé par</div>
                <div class="info-value"><?php echo htmlspecialchars($movement['requester'] ?: '—'); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Service / Projet</div>
                <div class="info-value"><?php echo htmlspecialchars($movement['destination'] ?: '—'); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Date de sortie</div>
                <div class="info-value"><?php echo htmlspecialchars($dateFormatted); ?></div>
            </div>
            <div class="info-group">
                <div class="info-label">Enregistré le</div>
                <div class="info-value"><?php echo htmlspecialchars($createdFormatted); ?></div>
            </div>
        </div>
        
        <div class="material-section">
            <div class="material-title">Matériau / Produit sortant</div>
            <div class="material-name"><?php echo htmlspecialchars($movement['material_name']); ?></div>
            <div class="quantity-box">
                <div class="quantity-label">Quantité</div>
                <div class="quantity-value"><?php echo htmlspecialchars(format_quantity_for_unit($movement['quantity'], (string) $movement['unit'])); ?></div>
                <div class="quantity-unit"><?php echo htmlspecialchars($movement['unit']); ?></div>
            </div>
        </div>
        
        <?php if (!empty($movement['notes'])): ?>
        <div class="notes-section">
            <h3>Notes / Observations</h3>
            <div class="notes-content"><?php echo htmlspecialchars($movement['notes']); ?></div>
        </div>
        <?php endif; ?>
        
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
                filename: `Bon-Sortie-${bonNumber}.pdf`,
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
