<?php
/**
 * airtable-helper.php
 *
 * Two-way Airtable sync for Oasis Energy Portal.
 * Uses Airtable REST API v0 with Personal Access Token.
 *
 * SETUP:
 * 1. Create a Personal Access Token at https://airtable.com/create/tokens
 *    - Scope: data.records:read, data.records:write
 *    - Access: Green Building Projects base
 * 2. Set environment variable: AIRTABLE_PAT=pat_XXXXXXXX
 */

// ============================================================
// Configuration
// ============================================================
function getAirtableConfig() {
    return [
        'pat'       => getenv('AIRTABLE_PAT') ?: '',
        'base_id'   => 'appXXXXXXXXXXXXXXX',
        'table_id'  => 'tblXXXXXXXXXXXXXXX',  // EnergyCalcs table
        'api_url'   => 'https://api.airtable.com/v0',
    ];
}

// ============================================================
// Core API Functions
// ============================================================

/**
 * Make an authenticated request to the Airtable API.
 */
function airtableRequest($method, $endpoint, $data = null) {
    $config = getAirtableConfig();

    if (empty($config['pat'])) {
        error_log("Airtable PAT not configured");
        return ['error' => 'Airtable PAT not configured'];
    }

    $url = $config['api_url'] . '/' . $config['base_id'] . '/' . $config['table_id'] . $endpoint;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $config['pat'],
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'GET') {
        // For GET with query params, append to URL
        if ($data) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Airtable curl error: {$curlError}");
        return ['error' => $curlError];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $errMsg = $decoded['error']['message'] ?? "HTTP {$httpCode}";
        error_log("Airtable API error: {$errMsg}");
        return ['error' => $errMsg, 'http_code' => $httpCode];
    }

    return $decoded;
}

// ============================================================
// PUSH: Send invoice data to Airtable (after payment)
// ============================================================

/**
 * Create or update an Airtable record for a paid invoice.
 * Called from webhook.php after checkout.session.completed.
 */
function syncInvoiceToAirtable($invoice) {
    // Parse JSON fields
    $lineItems = [];
    if (!empty($invoice['line_items_json'])) {
        $lineItems = json_decode($invoice['line_items_json'], true) ?: [];
    }
    $intake = [];
    if (!empty($invoice['intake_json'])) {
        $intake = json_decode($invoice['intake_json'], true) ?: [];
    }
    $discount = [];
    if (!empty($invoice['discount_json'])) {
        $discount = json_decode($invoice['discount_json'], true) ?: [];
    }
    $files = [];
    if (!empty($invoice['files_json'])) {
        $files = json_decode($invoice['files_json'], true) ?: [];
    }

    // Build base URL for file links (fallback for legacy filename-only entries)
    $baseUrl = getenv('APP_BASE_URL') ?: 'https://pay.yourcompany.com';

    // Build line items summary for Airtable
    $lineItemsSummary = '';
    foreach ($lineItems as $item) {
        $name = $item['name'] ?? $item['service'] ?? 'Item';
        $price = $item['price'] ?? $item['amount'] ?? 0;
        $lineItemsSummary .= "• {$name}: \${$price}\n";
    }

    // Build discount info
    $discountInfo = '';
    if (!empty($discount)) {
        $label = $discount['label'] ?? 'Discount';
        $amount = $discount['amount'] ?? 0;
        $discountInfo = "{$label}: -\${$amount}";
    }

    // Map invoice fields to Airtable fields
    $fields = [
        'Name'              => $invoice['client_name'] . ' — ' . $invoice['service_type'],
        'Invoice ID'        => $invoice['id'],
        'Client Name'       => $invoice['client_name'],
        'Client Email'      => $invoice['client_email'],
        'Client Phone'      => $invoice['client_phone'] ?? '',
        'Project Address'   => $invoice['project_address'],
        'County'            => $invoice['county'] ?? '',
        'Service Type'      => mapServiceType($invoice['service_type']),
        'Description'       => $invoice['description'] ?? '',
        'Amount'            => floatval($invoice['amount']),
        'Platform Fee'      => floatval($invoice['platform_fee']),
        'Payout Amount'     => floatval($invoice['payout_amount']),
        'Payment Status'    => 'Paid',
        'Payment URL'       => $invoice['payment_url'] ?? '',
        'Stripe Payment Intent' => $invoice['stripe_payment_intent'] ?? '',
        'Line Items'        => $lineItemsSummary,
        'Has Signature'     => !empty($invoice['signature_data']),
        'Files Uploaded'    => count($files),
        'Discount Info'     => $discountInfo,
    ];

    // Build Airtable Attachments array from uploaded files
    if (!empty($files)) {
        $attachments = [];
        foreach ($files as $file) {
            if (is_array($file) && !empty($file['url'])) {
                // New format: {filename, url, size}
                $attachments[] = [
                    'url'      => $file['url'],
                    'filename' => $file['filename'] ?? basename($file['url']),
                ];
            } elseif (is_string($file)) {
                // Legacy format: just a filename string
                $attachments[] = [
                    'url'      => $baseUrl . '/uploads/' . $invoice['id'] . '/' . $file,
                    'filename' => $file,
                ];
            }
        }
        if (!empty($attachments)) {
            $fields['Attachments'] = $attachments;
        }
    }

    // Add dates
    if (!empty($invoice['created_at'])) {
        $fields['Created Date'] = formatDateForAirtable($invoice['created_at']);
    }
    if (!empty($invoice['paid_at'])) {
        $fields['Paid Date'] = formatDateForAirtable($invoice['paid_at']);
    }

    // Add intake fields
    if (!empty($intake)) {
        if (!empty($intake['project_type']))    $fields['Project Type'] = mapProjectType($intake['project_type']);
        if (!empty($intake['sqft']))            $fields['Square Footage'] = intval($intake['sqft']);
        if (!empty($intake['system_type']))     $fields['System Type'] = mapSystemType($intake['system_type']);
        if (!empty($intake['ductwork_status'])) $fields['Ductwork Status'] = mapDuctworkStatus($intake['ductwork_status']);
        if (!empty($intake['duct_location']))   $fields['Duct Location'] = mapDuctLocation($intake['duct_location']);
        if (!empty($intake['wall_insulation'])) $fields['Wall Insulation'] = $intake['wall_insulation'];
        if (!empty($intake['ceiling_insulation'])) $fields['Ceiling Insulation'] = $intake['ceiling_insulation'];
        if (!empty($intake['hvac_info']))       $fields['HVAC Info'] = $intake['hvac_info'];
        if (!empty($intake['notes']))           $fields['Intake Notes'] = $intake['notes'];
    }

    // Check if record already exists (search by Invoice ID)
    $existing = findAirtableRecordByInvoiceId($invoice['id']);

    if ($existing) {
        // Update existing record
        $result = airtableRequest('PATCH', '', [
            'records' => [[
                'id' => $existing['id'],
                'fields' => $fields,
            ]],
        ]);
        $action = 'updated';
    } else {
        // Create new record
        $result = airtableRequest('POST', '', [
            'records' => [['fields' => $fields]],
        ]);
        $action = 'created';
    }

    if (isset($result['error'])) {
        error_log("Failed to sync invoice {$invoice['id']} to Airtable: {$result['error']}");
        return false;
    }

    error_log("Invoice {$invoice['id']} {$action} in Airtable");
    return true;
}

// ============================================================
// PULL: Fetch proposals from Airtable for admin review
// ============================================================

/**
 * Get all records from Airtable with Payment Status = "Proposal Sent"
 * These are proposals ready to be imported into the admin portal.
 */
function getAirtableProposals() {
    $result = airtableRequest('GET', '', [
        'filterByFormula' => '{Payment Status} = "Proposal Sent"',
        'sort[0][field]' => 'Created Date',
        'sort[0][direction]' => 'desc',
    ]);

    if (isset($result['error'])) {
        return ['error' => $result['error']];
    }

    return $result['records'] ?? [];
}

/**
 * Get all Airtable records (for browsing/importing).
 */
function getAirtableRecords($maxRecords = 50) {
    $result = airtableRequest('GET', '', [
        'maxRecords' => $maxRecords,
        'sort[0][field]' => 'Created Date',
        'sort[0][direction]' => 'desc',
    ]);

    if (isset($result['error'])) {
        return ['error' => $result['error']];
    }

    return $result['records'] ?? [];
}

// ============================================================
// WRITEBACK: Update Airtable record status
// ============================================================

/**
 * Update the Payment Status field for a record found by Invoice ID.
 */
function updateAirtableStatus($invoiceId, $status) {
    $record = findAirtableRecordByInvoiceId($invoiceId);

    if (!$record) {
        error_log("Cannot update Airtable: Invoice {$invoiceId} not found");
        return false;
    }

    $result = airtableRequest('PATCH', '', [
        'records' => [[
            'id' => $record['id'],
            'fields' => ['Payment Status' => $status],
        ]],
    ]);

    if (isset($result['error'])) {
        error_log("Failed to update Airtable status for {$invoiceId}: {$result['error']}");
        return false;
    }

    error_log("Airtable status for {$invoiceId} updated to '{$status}'");
    return true;
}

/**
 * Update Airtable record by its Airtable record ID (not invoice ID).
 */
function updateAirtableRecordById($recordId, $fields) {
    $result = airtableRequest('PATCH', '', [
        'records' => [[
            'id' => $recordId,
            'fields' => $fields,
        ]],
    ]);

    if (isset($result['error'])) {
        error_log("Failed to update Airtable record {$recordId}: {$result['error']}");
        return false;
    }

    return true;
}

// ============================================================
// Helper: Find record by Invoice ID
// ============================================================
function findAirtableRecordByInvoiceId($invoiceId) {
    $result = airtableRequest('GET', '', [
        'filterByFormula' => '{Invoice ID} = "' . addslashes($invoiceId) . '"',
        'maxRecords' => 1,
    ]);

    if (isset($result['error']) || empty($result['records'])) {
        return null;
    }

    return $result['records'][0];
}

// ============================================================
// Field Value Mappers (DB values → Airtable select options)
// ============================================================

function mapServiceType($dbValue) {
    $map = [
        'Manual J & S (Existing Home)'      => 'Manual J & S (Existing Home)',
        'Manual J, S & D (Existing Home)'    => 'Manual J, S & D (Existing Home)',
        'Manual J & S (New Construction)'    => 'Manual J & S (New Construction)',
        'Site Visit + Digital Property Scan' => 'Site Visit + Digital Property Scan',
        'energy-js'     => 'Manual J & S (Existing Home)',
        'energy-jsd'    => 'Manual J, S & D (Existing Home)',
        'energy-js-new' => 'Manual J & S (New Construction)',
        'site-visit'    => 'Site Visit + Digital Property Scan',
    ];
    return $map[$dbValue] ?? 'Other';
}

function mapProjectType($value) {
    $map = [
        'single_family'     => 'Single Family',
        'multi_family'      => 'Multi-Family',
        'townhome'          => 'Townhome',
        'commercial'        => 'Commercial',
        'addition_remodel'  => 'Addition/Remodel',
        'Single Family'     => 'Single Family',
        'Multi-Family'      => 'Multi-Family',
        'Townhome'          => 'Townhome',
        'Commercial'        => 'Commercial',
        'Addition/Remodel'  => 'Addition/Remodel',
    ];
    return $map[$value] ?? $value;
}

function mapSystemType($value) {
    $map = [
        'central_ac'    => 'Central AC Split System',
        'heat_pump'     => 'Heat Pump',
        'package_unit'  => 'Package Unit',
        'mini_split'    => 'Ductless Mini-Split',
        'Central AC Split System' => 'Central AC Split System',
        'Heat Pump'     => 'Heat Pump',
        'Package Unit'  => 'Package Unit',
        'Ductless Mini-Split' => 'Ductless Mini-Split',
    ];
    return $map[$value] ?? 'Other';
}

function mapDuctworkStatus($value) {
    $map = [
        'existing_keep'     => 'Existing - Keep',
        'existing_replace'  => 'Existing - Replace',
        'new_install'       => 'New Install',
        'na'                => 'N/A',
        'Existing - Keep'   => 'Existing - Keep',
        'Existing - Replace'=> 'Existing - Replace',
        'New Install'       => 'New Install',
        'N/A'               => 'N/A',
    ];
    return $map[$value] ?? $value;
}

function mapDuctLocation($value) {
    $map = [
        'attic'         => 'Attic',
        'garage'        => 'Garage',
        'crawlspace'    => 'Crawlspace',
        'closet'        => 'Closet',
        'Attic'         => 'Attic',
        'Garage'        => 'Garage',
        'Crawlspace'    => 'Crawlspace',
        'Closet'        => 'Closet',
    ];
    return $map[$value] ?? 'Other';
}

function formatDateForAirtable($dateStr) {
    // Airtable expects ISO 8601: "2026-04-05T14:30:00.000Z"
    $ts = strtotime($dateStr);
    if ($ts === false) return null;
    return date('Y-m-d\TH:i:s.000\Z', $ts);
}
