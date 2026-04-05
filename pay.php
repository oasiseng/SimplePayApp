<?php
/**
 * pay.php
 * 
 * Serves the client-facing payment page with invoice data injected.
 * Accessed via clean URL: /pay/OE-2026-0042
 */

$invoiceId = $_GET['id'] ?? '';
$config = [
    'db_path' => __DIR__ . '/data/invoices.sqlite',
];

$invoiceData = null;

if (!empty($invoiceId) && file_exists($config['db_path'])) {
    try {
        $db = new SQLite3($config['db_path']);
        $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->bindValue(':id', $invoiceId);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            // Parse line items from JSON if available, otherwise fallback to legacy format
            $lineItems = [
                ['description' => $row['service_type'], 'amount' => floatval($row['amount'])],
                ['description' => 'Electronic Delivery (PDF)', 'amount' => 0],
            ];
            if (!empty($row['line_items_json'])) {
                $decoded = json_decode($row['line_items_json'], true);
                if (is_array($decoded)) {
                    $lineItems = $decoded;
                }
            }

            // Parse intake from JSON if available
            $intake = null;
            if (!empty($row['intake_json'])) {
                $intake = json_decode($row['intake_json'], true);
            }

            // Parse discount from JSON if available
            $discount = null;
            if (!empty($row['discount_json'])) {
                $discount = json_decode($row['discount_json'], true);
            }

            $invoiceData = [
                'id' => $row['id'],
                'status' => $row['status'],
                'created' => substr($row['created_at'], 0, 10),
                'due' => date('Y-m-d', strtotime($row['created_at'] . ' +14 days')),
                'client' => [
                    'name' => $row['client_name'],
                    'email' => $row['client_email'],
                    'phone' => $row['client_phone'],
                ],
                'project' => [
                    'address' => $row['project_address'],
                    'type' => $row['service_type'],
                    'description' => $row['description'],
                    'county' => $row['county'],
                ],
                'lineItems' => $lineItems,
                'intake' => $intake,
                'discount' => $discount,
                'total' => floatval($row['amount']),
                'signature_data' => $row['signature_data'] ?? null,
                'engineer' => 'Oasis Engineering',
            ];

            // Check if returning from successful payment
            if (isset($_GET['status']) && $_GET['status'] === 'success' && $row['status'] === 'pending') {
                // Mark as paid (webhook may not have fired yet)
                $updateStmt = $db->prepare("UPDATE invoices SET status = 'paid', paid_at = datetime('now') WHERE id = :id AND status = 'pending'");
                $updateStmt->bindValue(':id', $invoiceId);
                $updateStmt->execute();
                $invoiceData['status'] = 'paid';
            }
        }
    } catch (Exception $e) {
        // Silently fail — page will show error state
    }
}

if (!$invoiceData) {
    // Show a 404-style page
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Invoice Not Found</title>
    <style>body{font-family:system-ui;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f7f3ed;color:#1a1a1a;text-align:center;}
    .box{max-width:400px;padding:40px;} h1{font-size:48px;margin:0;color:#1a6b4a;} p{color:#4a5568;margin-top:12px;}</style>
    </head><body><div class="box"><h1>404</h1><p>This invoice was not found or has expired.<br>Please contact Oasis Engineering for assistance.</p>
    <p style="margin-top:24px;"><a href="https://yourcompany.com" style="color:#1a6b4a;">yourcompany.com</a></p></div></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oasis Engineering — Invoice <?= htmlspecialchars($invoiceData['id']) ?></title>
    <meta name="description" content="Service agreement and payment for <?= htmlspecialchars($invoiceData['project']['type']) ?>">
    <!-- Open Graph for link previews -->
    <meta property="og:title" content="Oasis Engineering — Invoice <?= htmlspecialchars($invoiceData['id']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($invoiceData['project']['type']) ?> — $<?= number_format($invoiceData['total'], 2) ?>">
    <meta property="og:type" content="website">
    <script>
        window.__INVOICE__ = <?= json_encode($invoiceData, JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>
<body>
<?php
// Include the pay.html content (minus the <html>/<head> tags)
// In production, you'd use a proper template engine.
// For simplicity, we'll redirect to include the static HTML body:
$html = file_get_contents(__DIR__ . '/pay.html');

// Extract just the body content and styles
preg_match('/<style>(.*?)<\/style>/s', $html, $styleMatch);
preg_match('/<body>(.*?)<\/body>/s', $html, $bodyMatch);

if (!empty($styleMatch[1])) {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,600;9..144,700&display=swap" rel="stylesheet">';
    echo '<style>' . $styleMatch[1] . '</style></head>';
}

if (!empty($bodyMatch[1])) {
    echo $bodyMatch[1];
}
?>
</body>
</html>
