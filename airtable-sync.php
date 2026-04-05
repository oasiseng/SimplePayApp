<?php
/**
 * airtable-sync.php
 *
 * API endpoint for two-way Airtable sync.
 * Used by the admin panel to:
 *   1. Pull proposals from Airtable → admin portal
 *   2. Write back "Accepted" status to Airtable
 *   3. Manually push an invoice to Airtable
 *
 * Endpoints (via query param ?action=):
 *   GET  ?action=proposals     → Fetch "Proposal Sent" records from Airtable
 *   GET  ?action=records       → Fetch recent Airtable records
 *   POST ?action=accept        → Mark a record as "Accepted" in Airtable
 *   POST ?action=push          → Push a local invoice to Airtable
 *   POST ?action=import        → Import an Airtable proposal into local DB as invoice
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/airtable-helper.php';

$config = [
    'admin_password' => getenv('ADMIN_PASSWORD') ?: 'oasis2026!',
    'db_path'        => __DIR__ . '/data/invoices.sqlite',
    'base_url'       => 'https://pay.yourcompany.com',
];

// Simple auth check (same as admin panel)
$authHeader = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
// For GET requests, also check query param
$authParam = $_GET['auth'] ?? '';
$providedAuth = $authHeader ?: $authParam;

// Note: In production, enforce auth. For now, allow if password matches or is empty.
// Uncomment below to enforce:
// if ($providedAuth !== $config['admin_password']) {
//     http_response_code(401);
//     echo json_encode(['error' => 'Unauthorized']);
//     exit;
// }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // --------------------------------------------------------
        // GET: Fetch proposals from Airtable
        // --------------------------------------------------------
        case 'proposals':
            $records = getAirtableProposals();
            if (isset($records['error'])) {
                throw new Exception($records['error']);
            }
            echo json_encode([
                'success' => true,
                'count' => count($records),
                'records' => array_map('formatAirtableRecord', $records),
            ]);
            break;

        // --------------------------------------------------------
        // GET: Fetch all recent Airtable records
        // --------------------------------------------------------
        case 'records':
            $limit = intval($_GET['limit'] ?? 50);
            $records = getAirtableRecords($limit);
            if (isset($records['error'])) {
                throw new Exception($records['error']);
            }
            echo json_encode([
                'success' => true,
                'count' => count($records),
                'records' => array_map('formatAirtableRecord', $records),
            ]);
            break;

        // --------------------------------------------------------
        // POST: Mark a record as "Accepted" in Airtable
        // --------------------------------------------------------
        case 'accept':
            $input = json_decode(file_get_contents('php://input'), true);
            $invoiceId = $input['invoice_id'] ?? '';
            $airtableRecordId = $input['airtable_record_id'] ?? '';

            if (empty($invoiceId) && empty($airtableRecordId)) {
                throw new Exception('Missing invoice_id or airtable_record_id');
            }

            if ($airtableRecordId) {
                // Update by Airtable record ID directly
                $success = updateAirtableRecordById($airtableRecordId, [
                    'Payment Status' => 'Accepted',
                ]);
            } else {
                // Find by invoice ID and update
                $success = updateAirtableStatus($invoiceId, 'Accepted');
            }

            if (!$success) {
                throw new Exception('Failed to update Airtable record');
            }

            echo json_encode(['success' => true, 'status' => 'Accepted']);
            break;

        // --------------------------------------------------------
        // POST: Push a local invoice to Airtable
        // --------------------------------------------------------
        case 'push':
            $input = json_decode(file_get_contents('php://input'), true);
            $invoiceId = $input['invoice_id'] ?? '';

            if (empty($invoiceId)) {
                throw new Exception('Missing invoice_id');
            }

            // Fetch from local DB
            $db = new SQLite3($config['db_path']);
            $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
            $stmt->bindValue(':id', $invoiceId);
            $result = $stmt->execute();
            $invoice = $result->fetchArray(SQLITE3_ASSOC);

            if (!$invoice) {
                throw new Exception("Invoice {$invoiceId} not found");
            }

            $success = syncInvoiceToAirtable($invoice);

            if (!$success) {
                throw new Exception('Failed to push invoice to Airtable');
            }

            echo json_encode(['success' => true, 'invoice_id' => $invoiceId]);
            break;

        // --------------------------------------------------------
        // POST: Import an Airtable proposal into the local portal
        //       Creates a new invoice from the Airtable record data
        // --------------------------------------------------------
        case 'import':
            $input = json_decode(file_get_contents('php://input'), true);
            $airtableRecordId = $input['airtable_record_id'] ?? '';
            $record = $input['record'] ?? null;

            if (empty($record)) {
                throw new Exception('Missing record data');
            }

            $fields = $record['fields'] ?? $record;

            // Create invoice in local DB
            $db = new SQLite3($config['db_path']);

            // Auto-create table if not exists (same schema as create-invoice.php)
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

            // Generate invoice ID
            $year = date('Y');
            $count = $db->querySingle("SELECT COUNT(*) FROM invoices WHERE id LIKE 'OE-{$year}-%'");
            $invoiceId = "OE-{$year}-" . str_pad(intval($count) + 1, 4, '0', STR_PAD_LEFT);

            // Map Airtable fields to invoice
            $clientName = $fields['Client Name'] ?? $fields['Name'] ?? '';
            $amount = floatval($fields['Amount'] ?? 0);
            $platformFee = round($amount * 0.10, 2);
            $payoutAmount = $amount - $platformFee;
            $serviceType = $fields['Service Type'] ?? '';

            // Build intake JSON from Airtable fields
            $intake = [];
            if (!empty($fields['Project Type']))      $intake['project_type'] = $fields['Project Type'];
            if (!empty($fields['Square Footage']))     $intake['sqft'] = $fields['Square Footage'];
            if (!empty($fields['System Type']))        $intake['system_type'] = $fields['System Type'];
            if (!empty($fields['Ductwork Status']))    $intake['ductwork_status'] = $fields['Ductwork Status'];
            if (!empty($fields['Duct Location']))      $intake['duct_location'] = $fields['Duct Location'];
            if (!empty($fields['Wall Insulation']))    $intake['wall_insulation'] = $fields['Wall Insulation'];
            if (!empty($fields['Ceiling Insulation'])) $intake['ceiling_insulation'] = $fields['Ceiling Insulation'];
            if (!empty($fields['HVAC Info']))          $intake['hvac_info'] = $fields['HVAC Info'];
            if (!empty($fields['Intake Notes']))       $intake['notes'] = $fields['Intake Notes'];

            $stmt = $db->prepare("
                INSERT INTO invoices (id, client_name, client_email, client_phone,
                    project_address, county, service_type, description, amount,
                    platform_fee, payout_amount, payment_url, intake_json)
                VALUES (:id, :name, :email, :phone, :address, :county, :service,
                    :desc, :amount, :fee, :payout, :url, :intake)
            ");

            $paymentUrl = $config['base_url'] . "/pay/{$invoiceId}";

            $stmt->bindValue(':id', $invoiceId);
            $stmt->bindValue(':name', $clientName);
            $stmt->bindValue(':email', $fields['Client Email'] ?? '');
            $stmt->bindValue(':phone', $fields['Client Phone'] ?? '');
            $stmt->bindValue(':address', $fields['Project Address'] ?? '');
            $stmt->bindValue(':county', $fields['County'] ?? '');
            $stmt->bindValue(':service', $serviceType);
            $stmt->bindValue(':desc', $fields['Description'] ?? '');
            $stmt->bindValue(':amount', $amount);
            $stmt->bindValue(':fee', $platformFee);
            $stmt->bindValue(':payout', $payoutAmount);
            $stmt->bindValue(':url', $paymentUrl);
            $stmt->bindValue(':intake', !empty($intake) ? json_encode($intake) : null);

            $stmt->execute();

            // Update Airtable record with the new Invoice ID and status
            if ($airtableRecordId) {
                updateAirtableRecordById($airtableRecordId, [
                    'Invoice ID' => $invoiceId,
                    'Payment Status' => 'Pending Payment',
                    'Payment URL' => $paymentUrl,
                ]);
            }

            echo json_encode([
                'success' => true,
                'invoice_id' => $invoiceId,
                'payment_url' => $paymentUrl,
            ]);
            break;

        default:
            throw new Exception('Invalid action. Use: proposals, records, accept, push, import');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ============================================================
// Format an Airtable record for the admin panel
// ============================================================
function formatAirtableRecord($record) {
    $fields = $record['fields'] ?? [];
    return [
        'airtable_id'    => $record['id'],
        'name'           => $fields['Name'] ?? '',
        'invoice_id'     => $fields['Invoice ID'] ?? '',
        'client_name'    => $fields['Client Name'] ?? '',
        'client_email'   => $fields['Client Email'] ?? '',
        'client_phone'   => $fields['Client Phone'] ?? '',
        'project_address'=> $fields['Project Address'] ?? '',
        'county'         => $fields['County'] ?? '',
        'service_type'   => $fields['Service Type'] ?? '',
        'description'    => $fields['Description'] ?? '',
        'amount'         => $fields['Amount'] ?? 0,
        'payment_status' => $fields['Payment Status'] ?? '',
        'payment_url'    => $fields['Payment URL'] ?? '',
        'project_type'   => $fields['Project Type'] ?? '',
        'sqft'           => $fields['Square Footage'] ?? '',
        'system_type'    => $fields['System Type'] ?? '',
        'created_date'   => $fields['Created Date'] ?? '',
        'paid_date'      => $fields['Paid Date'] ?? '',
    ];
}
