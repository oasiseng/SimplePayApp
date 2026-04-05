<?php
/**
 * upload-files.php
 *
 * Accepts file uploads with invoice intake data and signature.
 * Saves files to uploads/{invoice_id}/ directory and updates invoice record.
 *
 * Expected POST data:
 * - invoice_id: string (invoice ID)
 * - intake: JSON string with project intake information
 * - signature: base64 data URL of e-signature
 * - files[]: array of files to upload
 */

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

$config = [
    'db_path'           => __DIR__ . '/data/invoices.sqlite',
    'upload_base_dir'   => __DIR__ . '/uploads',
    'max_file_size'     => 25 * 1024 * 1024, // 25MB
    'base_url'          => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                           . '://' . ($_SERVER['HTTP_HOST'] ?? 'pay.yourcompany.com'),
];

try {
    // ========================================================
    // Validate required fields
    // ========================================================
    $invoiceId = $_POST['invoice_id'] ?? '';
    $intakeJson = $_POST['intake'] ?? '';
    $signatureData = $_POST['signature'] ?? '';

    if (empty($invoiceId)) {
        throw new Exception('Missing required field: invoice_id');
    }

    // Validate that invoice exists
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
    // Create upload directory for this invoice
    // ========================================================
    $uploadDir = $config['upload_base_dir'] . '/' . $invoiceId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // ========================================================
    // Handle file uploads
    // ========================================================
    $uploadedFiles = [];

    if (isset($_FILES['files'])) {
        $files = $_FILES['files'];
        $count = is_array($files['name']) ? count($files['name']) : 1;

        // Handle both single and multiple file uploads
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
        $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

        foreach ($names as $idx => $filename) {
            if ($errors[$idx] !== UPLOAD_ERR_OK) {
                throw new Exception("File upload error for {$filename}: " . $errors[$idx]);
            }

            $fileSize = $sizes[$idx];
            if ($fileSize > $config['max_file_size']) {
                throw new Exception("File {$filename} exceeds 25MB limit");
            }

            // Sanitize filename
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename));
            $filePath = $uploadDir . '/' . $safeFilename;

            if (!move_uploaded_file($tmpNames[$idx], $filePath)) {
                throw new Exception("Failed to save uploaded file: {$filename}");
            }

            $fileUrl = $config['base_url'] . '/uploads/' . $invoiceId . '/' . $safeFilename;
            $uploadedFiles[] = [
                'filename' => $safeFilename,
                'url'      => $fileUrl,
                'size'     => $fileSize,
            ];
        }
    }

    // ========================================================
    // Update invoice with intake, signature, and files
    // ========================================================
    $filesJson = !empty($uploadedFiles) ? json_encode($uploadedFiles) : null;
    $intakeToStore = !empty($intakeJson) ? $intakeJson : null;
    $signatureToStore = !empty($signatureData) ? $signatureData : null;

    $updateStmt = $db->prepare("
        UPDATE invoices
        SET intake_json = :intake,
            signature_data = :signature,
            files_json = :files
        WHERE id = :id
    ");

    $updateStmt->bindValue(':id', $invoiceId);
    $updateStmt->bindValue(':intake', $intakeToStore);
    $updateStmt->bindValue(':signature', $signatureToStore);
    $updateStmt->bindValue(':files', $filesJson);
    $updateStmt->execute();

    // ========================================================
    // Return success
    // ========================================================
    echo json_encode([
        'success' => true,
        'invoice_id' => $invoiceId,
        'files_uploaded' => $uploadedFiles,
        'message' => 'Files and intake data saved successfully',
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
