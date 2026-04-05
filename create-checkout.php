<?php
/**
 * create-checkout.php
 * 
 * Called from the client-facing pay page when they click "Pay".
 * Looks up the invoice from SQLite and redirects to Stripe Checkout.
 */

header('Content-Type: application/json');

$config = [
    'stripe_secret_key'     => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_XXXXXXXXXXXXXXXX',
    'db_path'               => __DIR__ . '/data/invoices.sqlite',
];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $invoiceId = $input['invoice_id'] ?? '';

    if (empty($invoiceId)) {
        throw new Exception('Missing invoice_id');
    }

    $db = new SQLite3($config['db_path']);
    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id AND status = 'pending'");
    $stmt->bindValue(':id', $invoiceId);
    $result = $stmt->execute();
    $invoice = $result->fetchArray(SQLITE3_ASSOC);

    if (!$invoice) {
        throw new Exception('Invoice not found or already paid');
    }

    // Look up the Stripe Checkout Session to get the URL
    require_once __DIR__ . '/vendor/autoload.php';
    \Stripe\Stripe::setApiKey($config['stripe_secret_key']);

    $session = \Stripe\Checkout\Session::retrieve($invoice['stripe_session_id']);

    if ($session->status === 'expired') {
        // Session expired, create a new one
        throw new Exception('Payment session expired. Please request a new invoice.');
    }

    echo json_encode([
        'success' => true,
        'url' => $session->url,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
