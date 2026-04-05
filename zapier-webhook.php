<?php
/**
 * zapier-webhook.php
 * 
 * Receives webhook from Zapier/Make triggered by a new Airtable record.
 * Creates an invoice and returns the payment link.
 * 
 * ZAPIER SETUP:
 * 1. Trigger: New Record in Airtable (your projects table)
 * 2. Action: Webhooks by Zapier → POST
 * 3. URL: https://pay.yourcompany.com/api/zapier-webhook.php
 * 4. Headers: X-Api-Key: YOUR_WEBHOOK_SECRET
 * 5. Body: Map Airtable fields to JSON keys below
 * 
 * Expected JSON body from Zapier:
 * {
 *   "client_name": "{{Client Name}}",
 *   "client_email": "{{Client Email}}",
 *   "client_phone": "{{Client Phone}}",
 *   "project_address": "{{Project Address}}",
 *   "county": "{{County}}",
 *   "service_type": "{{Service Type}}",
 *   "description": "{{Description}}",
 *   "amount": "{{Amount}}"
 * }
 */

$config = [
    'webhook_api_key' => getenv('WEBHOOK_API_KEY') ?: '',
];

// Verify API key
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($providedKey !== $config['webhook_api_key']) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// Forward to the main create-invoice endpoint
$input = file_get_contents('php://input');

// Use internal request to create-invoice.php
$_SERVER['REQUEST_METHOD'] = 'POST';

// We'll just include the create-invoice script
// But first, write the input so it can be read
$tmpFile = tempnam(sys_get_temp_dir(), 'invoice_');
file_put_contents($tmpFile, $input);

// Simple approach: use cURL to call our own endpoint
$ch = curl_init('http://localhost/api/create-invoice.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $input,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

unlink($tmpFile);

http_response_code($httpCode);
header('Content-Type: application/json');
echo $response;
