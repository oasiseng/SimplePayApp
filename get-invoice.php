<?php
/**
 * get-invoice.php
 *
 * Returns invoice data as JSON.
 *
 * Usage:
 *   Single invoice: /api/get-invoice.php?id=OE-2026-0042
 *   Recent list:    /api/get-invoice.php?recent=1&limit=20
 *   All invoices:   /api/get-invoice.php?all=1
 */

// Auth: listing endpoints protected by .htpasswd in .htaccess
header('Content-Type: application/json');
$allowed_origin = getenv('APP_ORIGIN') ?: 'https://pay.yourcompany.com';
header('Access-Control-Allow-Origin: ' . $allowed_origin);

$config = [
    'db_path' => __DIR__ . '/data/invoices.sqlite',
];

try {
    if (!file_exists($config['db_path'])) {
        throw new Exception('Database not found');
    }

    $db = new SQLite3($config['db_path']);

    // --- Recent invoices list ---
    if (isset($_GET['recent']) || isset($_GET['all'])) {
        $limit = intval($_GET['limit'] ?? 50);
        $limit = max(1, min($limit, 200));

        $stmt = $db->prepare("SELECT id, created_at, status, client_name, client_email, client_phone,
            project_address, county, service_type, description, amount, platform_fee, payout_amount,
            payment_url, paid_at, line_items_json, intake_json, discount_json
            FROM invoices ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $invoices = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $invoices[] = [
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'status' => $row['status'],
                'client_name' => $row['client_name'],
                'client_email' => $row['client_email'],
                'client_phone' => $row['client_phone'],
                'project_address' => $row['project_address'],
                'county' => $row['county'],
                'service_type' => $row['service_type'],
                'amount' => floatval($row['amount']),
                'platform_fee' => floatval($row['platform_fee']),
                'payout_amount' => floatval($row['payout_amount']),
                'payment_url' => $row['payment_url'],
                'paid_at' => $row['paid_at'],
            ];
        }

        echo json_encode([
            'success' => true,
            'invoices' => $invoices,
            'count' => count($invoices),
        ]);
        exit;
    }

    // --- Single invoice ---
    $invoiceId = $_GET['id'] ?? '';
    if (empty($invoiceId)) {
        throw new Exception('Missing invoice ID');
    }

    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
    $stmt->bindValue(':id', $invoiceId);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        throw new Exception('Invoice not found');
    }

    // Parse line items
    $lineItems = [
        ['description' => $row['service_type'], 'amount' => floatval($row['amount'])],
        ['description' => 'Electronic Delivery (PDF)', 'amount' => 0],
    ];
    if (!empty($row['line_items_json'])) {
        $decoded = json_decode($row['line_items_json'], true);
        if (is_array($decoded)) $lineItems = $decoded;
    }

    echo json_encode([
        'success' => true,
        'invoice' => [
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
            'intake' => !empty($row['intake_json']) ? json_decode($row['intake_json'], true) : null,
            'discount' => !empty($row['discount_json']) ? json_decode($row['discount_json'], true) : null,
            'total' => floatval($row['amount']),
            'engineer' => 'Oasis Engineering',
        ],
    ]);

} catch (Exception $e) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
