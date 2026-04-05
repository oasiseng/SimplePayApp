<?php
/**
 * save-intake.php
 *
 * Simple endpoint to save intake data and e-signature to an invoice.
 * Used when client updates intake information without file uploads.
 *
 * Expected POST JSON:
 * - invoice_id: string
 * - intake: object with project intake fields
 * - signature: base64 data URL of e-signature
 */

// ============================================================
// CORS & Headers
// ============================================================
header('Content-Type: application/json');
$allowed_origin = getenv('APP_ORIGIN') ?: 'https://pay.yourcompany.com';
header('Access-Control-Allow-Origin: ' . $allowed_origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$config = [
    'db_path' => __DIR__ . '/data/invoices.sqlite',
];

try {
    // ========================================================
    // Parse JSON input
    // ========================================================
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $invoiceId = $input['invoice_id'] ?? '';
    $intake = $input['intake'] ?? null;
    $signature = $input['signature'] ?? '';

    if (empty($invoiceId)) {
        throw new Exception('Missing required field: invoice_id');
    }

    // Validate invoice ID format
    if (!preg_match('/^OE-\d{4}-\d{4}$/', $invoiceId)) {
        throw new Exception('Invalid invoice ID format');
    }

    // ========================================================
    // Validate that invoice exists
    // ========================================================
    if (!file_exists($config['db_path'])) {
        throw new Exception('Database not found');
    }

    $db = new SQLite3($config['db_path']);
    $checkStmt = $db->prepare("SELECT id FROM invoices WHERE id = :id");
    $checkStmt->bindValue(':id', $invoiceId);
    $result = $checkStmt->execute();
    if (!$result->fetchArray(SQLITE3_ASSOC)) {
        throw new Exception('Invoice not found');
    }

    // ========================================================
    // Update invoice with intake and signature
    // ========================================================
    $intakeJson = isset($intake) ? json_encode($intake) : null;
    $signatureData = !empty($signature) ? $signature : null;

    $updateStmt = $db->prepare("
        UPDATE invoices
        SET intake_json = :intake,
            signature_data = :signature
        WHERE id = :id
    ");

    $updateStmt->bindValue(':id', $invoiceId);
    $updateStmt->bindValue(':intake', $intakeJson);
    $updateStmt->bindValue(':signature', $signatureData);
    $updateStmt->execute();

    // ========================================================
    // Return success
    // ========================================================
    echo json_encode([
        'success' => true,
        'invoice_id' => $invoiceId,
        'message' => 'Intake data and signature saved successfully',
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
