<?php
/**
 * webhook.php
 * 
 * Stripe webhook handler — listens for checkout.session.completed
 * and marks the invoice as paid in the database.
 * 
 * SETUP:
 * 1. In Stripe Dashboard → Developers → Webhooks
 * 2. Add endpoint: https://pay.yourcompany.com/api/webhook.php
 * 3. Select event: checkout.session.completed
 * 4. Copy the webhook signing secret to STRIPE_WEBHOOK_SECRET env var
 */

$config = [
    'stripe_secret_key'    => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_XXXXXXXXXXXXXXXX',
    'webhook_secret'       => getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_XXXXXXXXXXXXXXXX',
    'db_path'              => __DIR__ . '/data/invoices.sqlite',
    'airtable_pat'         => getenv('AIRTABLE_PAT') ?: '',
    'zapier_payment_webhook' => getenv('ZAPIER_PAYMENT_WEBHOOK') ?: '',
    'base_url'             => getenv('APP_BASE_URL') ?: 'https://pay.yourcompany.com',
];

require_once __DIR__ . '/vendor/autoload.php';

\Stripe\Stripe::setApiKey($config['stripe_secret_key']);

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $config['webhook_secret']
    );
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit('Invalid payload');
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit('Invalid signature');
}

// Handle the event
if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;
    $invoiceId = $session->metadata->invoice_id ?? null;

    if ($invoiceId) {
        $db = new SQLite3($config['db_path']);
        $stmt = $db->prepare("
            UPDATE invoices
            SET status = 'paid',
                paid_at = datetime('now'),
                stripe_payment_intent = :pi
            WHERE id = :id
        ");
        $stmt->bindValue(':id', $invoiceId);
        $stmt->bindValue(':pi', $session->payment_intent ?? '');
        $stmt->execute();

        error_log("Invoice {$invoiceId} marked as paid");

        // Notify via Zapier webhook (sends confirmation emails via Outlook/M365)
        sendPaymentZapierWebhook($invoiceId, $db, $config);

        // Sync invoice data to Airtable via direct API
        syncInvoiceToAirtableDirect($invoiceId, $db);
    }
}

http_response_code(200);
echo json_encode(['received' => true]);

// ============================================================
// Send Payment Notification via Zapier
// ============================================================
function sendPaymentZapierWebhook($invoiceId, $db, $config) {
    if (empty($config['zapier_payment_webhook'])) {
        error_log("Zapier payment webhook not configured, skipping for {$invoiceId}");
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->bindValue(':id', $invoiceId);
        $result = $stmt->execute();
        $invoice = $result->fetchArray(SQLITE3_ASSOC);

        if (!$invoice) return;

        $paymentUrl = $config['base_url'] . "/pay/{$invoiceId}";

        $payload = [
            'event'           => 'payment_confirmed',
            'invoice_id'      => $invoiceId,
            'client_name'     => $invoice['client_name'],
            'client_email'    => $invoice['client_email'],
            'client_phone'    => $invoice['client_phone'] ?? '',
            'service_type'    => $invoice['service_type'],
            'project_address' => $invoice['project_address'],
            'county'          => $invoice['county'] ?? '',
            'amount'          => floatval($invoice['amount']),
            'platform_fee'    => floatval($invoice['platform_fee']),
            'payout'          => floatval($invoice['payout_amount']),
            'payment_url'     => $paymentUrl,
            'paid_at'         => date('Y-m-d H:i:s'),
            'stripe_payment_intent' => $invoice['stripe_payment_intent'] ?? '',
        ];

        $ch = curl_init($config['zapier_payment_webhook']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("Zapier payment webhook for {$invoiceId}: HTTP {$httpCode}");
    } catch (Exception $e) {
        error_log("Zapier payment webhook error for {$invoiceId}: " . $e->getMessage());
    }
}

// ============================================================
// Sync Invoice Data to Airtable (Direct API)
// ============================================================
/**
 * Syncs invoice data directly to Airtable using the REST API.
 * Uses airtable-helper.php for the actual API calls.
 *
 * SETUP:
 * 1. Create a Personal Access Token at https://airtable.com/create/tokens
 * 2. Set environment variable: AIRTABLE_PAT=pat_XXXXXXXX
 */
function syncInvoiceToAirtableDirect($invoiceId, $db) {
    try {
        require_once __DIR__ . '/airtable-helper.php';

        // Fetch the complete invoice record
        $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->bindValue(':id', $invoiceId);
        $result = $stmt->execute();
        $invoice = $result->fetchArray(SQLITE3_ASSOC);

        if (!$invoice) {
            error_log("Invoice {$invoiceId} not found for Airtable sync");
            return;
        }

        $success = syncInvoiceToAirtable($invoice);

        if ($success) {
            error_log("Successfully synced invoice {$invoiceId} to Airtable");
        }
    } catch (Exception $e) {
        error_log("Error syncing invoice {$invoiceId} to Airtable: " . $e->getMessage());
    }
}
