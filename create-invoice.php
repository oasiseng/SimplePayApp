<?php
/**
 * Oasis Engineering — Energy Portal API
 * create-invoice.php
 *
 * Creates an invoice record and generates a Stripe Checkout Session
 * with automatic 90/10 split (destination charge via Stripe Connect).
 *
 * SETUP REQUIRED:
 * 1. Install Stripe PHP SDK: composer require stripe/stripe-php
 * 2. Set environment variables (or update config below):
 *    - STRIPE_SECRET_KEY (your platform Stripe secret key)
 *    - STRIPE_CONNECTED_ACCOUNT_ID (your dad's connected Stripe account)
 *    - ADMIN_PASSWORD (password for admin panel)
 * 3. Create a MySQL/SQLite database and run schema below
 */

// ============================================================
// CONFIGURATION — update these or use environment variables
// ============================================================
$config = [
    'stripe_secret_key'     => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_XXXXXXXXXXXXXXXX',
    'connected_account_id'  => getenv('STRIPE_CONNECTED_ACCOUNT_ID') ?: 'acct_XXXXXXXXXXXXXXXX',
    'platform_fee_percent'  => 10, // 7% you + 3% Stripe ≈ 10% total off the top
    'base_url'              => 'https://pay.yourcompany.com',
    'admin_password'        => getenv('ADMIN_PASSWORD') ?: 'oasis2026!',
    'db_path'               => __DIR__ . '/data/invoices.sqlite',
    'zapier_invoice_webhook' => getenv('ZAPIER_INVOICE_WEBHOOK') ?: '', // Fires when invoice is created
    'zapier_payment_webhook' => getenv('ZAPIER_PAYMENT_WEBHOOK') ?: '', // Fires when payment confirmed
    'airtable_pat'          => getenv('AIRTABLE_PAT') ?: '',
];

// ============================================================
// CORS & Headers
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// Database Setup (SQLite — zero config, perfect for this scale)
// ============================================================
function getDB($config) {
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $db = new SQLite3($config['db_path']);
    $db->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id TEXT PRIMARY KEY,
            created_at TEXT DEFAULT (datetime('now')),
            status TEXT DEFAULT 'pending',
            client_name TEXT,
            client_email TEXT,
            client_phone TEXT,
            project_address TEXT,
            county TEXT,
            service_type TEXT,
            description TEXT,
            amount REAL,
            platform_fee REAL,
            payout_amount REAL,
            stripe_session_id TEXT,
            stripe_payment_intent TEXT,
            payment_url TEXT,
            paid_at TEXT,
            line_items_json TEXT,
            intake_json TEXT,
            discount_json TEXT,
            signature_data TEXT,
            files_json TEXT
        );
    ");
    return $db;
}

// ============================================================
// Generate Invoice ID
// ============================================================
function generateInvoiceId($db) {
    $year = date('Y');
    $result = $db->querySingle("SELECT COUNT(*) FROM invoices WHERE id LIKE 'OE-{$year}-%'");
    $num = intval($result) + 1;
    return "OE-{$year}-" . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// Main Handler
// ============================================================
try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    // Validate required fields
    $required = ['client_name', 'client_email', 'project_address', 'service_type', 'amount'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    $amount = floatval($input['amount']);
    if ($amount < 50 || $amount > 25000) {
        throw new Exception('Amount must be between $50 and $25,000');
    }

    // Calculate fees
    // Stripe charges ~2.9% + 30¢, but we'll simplify to 3%
    // You keep 7% → total 10% platform fee
    $platformFeeAmount = round($amount * ($config['platform_fee_percent'] / 100), 2);
    $payoutAmount = $amount - $platformFeeAmount;

    // Init database
    $db = getDB($config);
    $invoiceId = generateInvoiceId($db);

    // --------------------------------------------------------
    // Create Stripe Checkout Session
    // --------------------------------------------------------
    require_once __DIR__ . '/vendor/autoload.php'; // Composer autoloader

    \Stripe\Stripe::setApiKey($config['stripe_secret_key']);

    $session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => $input['service_type'],
                    'description' => "Invoice {$invoiceId} — " . ($input['description'] ?: $input['service_type']),
                    'metadata' => [
                        'invoice_id' => $invoiceId,
                        'project_address' => $input['project_address'],
                    ],
                ],
                'unit_amount' => intval($amount * 100), // cents
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'customer_email' => $input['client_email'],

        // Stripe Connect: destination charge
        // The full amount is charged, then `application_fee_amount` goes to YOUR platform account
        // and the rest goes to the connected account (your dad)
        'payment_intent_data' => [
            'application_fee_amount' => intval($platformFeeAmount * 100), // cents
            'transfer_data' => [
                'destination' => $config['connected_account_id'],
            ],
            'metadata' => [
                'invoice_id' => $invoiceId,
            ],
        ],

        'success_url' => $config['base_url'] . "/pay/{$invoiceId}?status=success",
        'cancel_url'  => $config['base_url'] . "/pay/{$invoiceId}?status=cancelled",

        'metadata' => [
            'invoice_id' => $invoiceId,
        ],
    ]);

    $paymentUrl = $config['base_url'] . "/pay/{$invoiceId}";

    // --------------------------------------------------------
    // Save to database
    // --------------------------------------------------------
    // Prepare JSON fields
    $lineItemsJson = isset($input['line_items']) ? json_encode($input['line_items']) : null;
    $intakeJson = isset($input['intake']) ? json_encode($input['intake']) : null;
    $discountJson = isset($input['discount']) ? json_encode($input['discount']) : null;

    $stmt = $db->prepare("
        INSERT INTO invoices (id, client_name, client_email, client_phone, project_address,
            county, service_type, description, amount, platform_fee, payout_amount,
            stripe_session_id, payment_url, line_items_json, intake_json, discount_json)
        VALUES (:id, :name, :email, :phone, :address, :county, :service, :desc,
            :amount, :fee, :payout, :session, :url, :line_items, :intake, :discount)
    ");

    $stmt->bindValue(':id', $invoiceId);
    $stmt->bindValue(':name', $input['client_name']);
    $stmt->bindValue(':email', $input['client_email']);
    $stmt->bindValue(':phone', $input['client_phone'] ?? '');
    $stmt->bindValue(':address', $input['project_address']);
    $stmt->bindValue(':county', $input['county'] ?? '');
    $stmt->bindValue(':service', $input['service_type']);
    $stmt->bindValue(':desc', $input['description'] ?? '');
    $stmt->bindValue(':amount', $amount);
    $stmt->bindValue(':fee', $platformFeeAmount);
    $stmt->bindValue(':payout', $payoutAmount);
    $stmt->bindValue(':session', $session->id);
    $stmt->bindValue(':url', $paymentUrl);
    $stmt->bindValue(':line_items', $lineItemsJson);
    $stmt->bindValue(':intake', $intakeJson);
    $stmt->bindValue(':discount', $discountJson);

    $stmt->execute();

    // --------------------------------------------------------
    // Notify via Zapier webhook (sends emails via Outlook/M365)
    // --------------------------------------------------------
    if (!empty($config['zapier_invoice_webhook'])) {
        $zapierPayload = [
            'event'           => 'invoice_created',
            'invoice_id'      => $invoiceId,
            'client_name'     => $input['client_name'],
            'client_email'    => $input['client_email'],
            'client_phone'    => $input['client_phone'] ?? '',
            'service_type'    => $input['service_type'],
            'description'     => $input['description'] ?? '',
            'project_address' => $input['project_address'],
            'county'          => $input['county'] ?? '',
            'amount'          => $amount,
            'platform_fee'    => $platformFeeAmount,
            'payout'          => $payoutAmount,
            'payment_url'     => $paymentUrl,
            'line_items'      => $input['line_items'] ?? [],
            'intake'          => $input['intake'] ?? [],
            'created_at'      => date('Y-m-d H:i:s'),
        ];
        fireZapierWebhook($config['zapier_invoice_webhook'], $zapierPayload);
    }

    // --------------------------------------------------------
    // Return success
    // --------------------------------------------------------
    echo json_encode([
        'success' => true,
        'invoice_id' => $invoiceId,
        'payment_url' => $paymentUrl,
        'stripe_checkout_url' => $session->url,
        'amount' => $amount,
        'platform_fee' => $platformFeeAmount,
        'payout' => $payoutAmount,
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Stripe error: ' . $e->getMessage(),
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

// ============================================================
// Zapier Webhook Helper
// ============================================================
function fireZapierWebhook($url, $data) {
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        error_log("Zapier webhook fired: HTTP {$httpCode}");
    } catch (Exception $e) {
        error_log("Zapier webhook error: " . $e->getMessage());
    }
}
