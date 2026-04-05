<?php
/**
 * email-helper.php
 *
 * HTML email templates and sending for Oasis Energy Portal.
 *
 * Supports two modes:
 *   1. PHP mail() — works out of the box on most shared hosting (Hostinger, cPanel)
 *   2. SMTP via PHPMailer — more reliable, less likely to land in spam
 *
 * SMTP SETUP (optional but recommended):
 *   1. Run: composer require phpmailer/phpmailer
 *   2. Set environment variables:
 *      - SMTP_HOST (e.g., smtp.hostinger.com or smtp.gmail.com)
 *      - SMTP_PORT (587 for TLS, 465 for SSL)
 *      - SMTP_USER (e.g., billing@yourcompany.com)
 *      - SMTP_PASS (email account password or app password)
 *   3. The system auto-detects PHPMailer and uses it when available.
 *
 * On Hostinger specifically:
 *   - SMTP Host: smtp.hostinger.com
 *   - SMTP Port: 465 (SSL) or 587 (TLS)
 *   - Create the email account in Hostinger panel → Emails → Create Email Account
 */

// ============================================================
// EMAIL CONFIG
// ============================================================
function getEmailConfig() {
    return [
        'from_email'    => getenv('SMTP_USER') ?: 'billing@yourcompany.com',
        'from_name'     => 'Oasis Engineering',
        'reply_to'      => 'info@yourcompany.com',
        'smtp_host'     => getenv('SMTP_HOST') ?: '',
        'smtp_port'     => intval(getenv('SMTP_PORT') ?: 465),
        'smtp_user'     => getenv('SMTP_USER') ?: '',
        'smtp_pass'     => getenv('SMTP_PASS') ?: '',
        'smtp_secure'   => getenv('SMTP_SECURE') ?: 'ssl', // 'ssl' or 'tls'
    ];
}

// ============================================================
// SEND EMAIL (auto-detects SMTP vs mail())
// ============================================================
function sendEmail($to, $subject, $htmlBody, $plainBody = '') {
    $config = getEmailConfig();

    // Try SMTP via PHPMailer first
    if (!empty($config['smtp_host']) && !empty($config['smtp_user']) && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return sendViaSMTP($to, $subject, $htmlBody, $plainBody, $config);
    }

    // Fallback: PHP mail()
    return sendViaMail($to, $subject, $htmlBody, $plainBody, $config);
}

function sendViaSMTP($to, $subject, $htmlBody, $plainBody, $config) {
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_user'];
        $mail->Password   = $config['smtp_pass'];
        $mail->SMTPSecure = $config['smtp_secure'] === 'tls'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $config['smtp_port'];

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addReplyTo($config['reply_to'], $config['from_name']);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody ?: strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log("SMTP send failed: " . $e->getMessage());
        // Fallback to mail()
        return sendViaMail($to, $subject, $htmlBody, $plainBody, $config);
    }
}

function sendViaMail($to, $subject, $htmlBody, $plainBody, $config) {
    $boundary = md5(time());

    $headers  = "From: {$config['from_name']} <{$config['from_email']}>\r\n";
    $headers .= "Reply-To: {$config['reply_to']}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= ($plainBody ?: strip_tags($htmlBody)) . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--";

    return @mail($to, $subject, $body, $headers);
}

// ============================================================
// EMAIL TEMPLATE: NEW INVOICE (sent when admin creates invoice)
// ============================================================
function emailNewInvoice($invoiceId, $clientName, $clientEmail, $serviceType, $projectAddress, $amount, $paymentUrl) {
    $subject = "Oasis Engineering — New Order #{$invoiceId}";

    $html = getEmailWrapper("
        <div style='text-align:center; padding:30px 0 20px;'>
            <div style='font-size:11px; text-transform:uppercase; letter-spacing:2px; color:#8899aa; margin-bottom:6px;'>Service Agreement & Invoice</div>
            <div style='font-size:28px; font-weight:700; color:#2c5fa8; font-family:Georgia,serif;'>#{$invoiceId}</div>
        </div>

        <p style='font-size:15px; color:#1a1a1a;'>Hi {$clientName},</p>

        <p style='color:#4a5568;'>Thank you for choosing Oasis Engineering! We've prepared your service agreement and invoice. Please review the details and complete your payment to get started.</p>

        <div style='background:#f0f4f8; border-radius:10px; padding:20px; margin:24px 0;'>
            <table style='width:100%;' cellpadding='0' cellspacing='0'>
                <tr>
                    <td style='padding:6px 0; font-size:12px; color:#8899aa; text-transform:uppercase; letter-spacing:0.5px;'>Service</td>
                    <td style='padding:6px 0; font-size:14px; font-weight:600; color:#1a1a1a; text-align:right;'>{$serviceType}</td>
                </tr>
                <tr>
                    <td style='padding:6px 0; font-size:12px; color:#8899aa; text-transform:uppercase; letter-spacing:0.5px;'>Project</td>
                    <td style='padding:6px 0; font-size:13px; color:#1a1a1a; text-align:right;'>{$projectAddress}</td>
                </tr>
                <tr>
                    <td style='padding:10px 0 6px; font-size:12px; color:#8899aa; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #dde3ea;'>Amount Due</td>
                    <td style='padding:10px 0 6px; font-size:22px; font-weight:700; color:#2c5fa8; text-align:right; border-top:1px solid #dde3ea; font-family:Georgia,serif;'>$" . number_format($amount, 2) . "</td>
                </tr>
            </table>
        </div>

        <div style='text-align:center; margin:28px 0;'>
            <a href='{$paymentUrl}' style='display:inline-block; background:#2c5fa8; color:#ffffff; padding:16px 40px; border-radius:10px; text-decoration:none; font-size:15px; font-weight:600; letter-spacing:0.3px;'>Review & Pay Securely</a>
        </div>

        <p style='font-size:12px; color:#8899aa; text-align:center;'>This link includes the full service agreement, project details for you to verify, and secure payment via Stripe.</p>

        <div style='margin-top:30px; padding-top:20px; border-top:1px solid #e8e0d4;'>
            <p style='font-size:12px; color:#8899aa;'>What happens next:</p>
            <table style='width:100%; margin-top:8px;' cellpadding='0' cellspacing='0'>
                <tr>
                    <td style='padding:6px 0; vertical-align:top; width:30px;'><span style='background:#2c5fa8; color:white; border-radius:50%; width:20px; height:20px; display:inline-block; text-align:center; font-size:11px; line-height:20px; font-weight:700;'>1</span></td>
                    <td style='padding:6px 0; font-size:13px; color:#4a5568;'>Review your project details and upload any plans or documents</td>
                </tr>
                <tr>
                    <td style='padding:6px 0; vertical-align:top;'><span style='background:#2c5fa8; color:white; border-radius:50%; width:20px; height:20px; display:inline-block; text-align:center; font-size:11px; line-height:20px; font-weight:700;'>2</span></td>
                    <td style='padding:6px 0; font-size:13px; color:#4a5568;'>Sign the agreement and complete payment</td>
                </tr>
                <tr>
                    <td style='padding:6px 0; vertical-align:top;'><span style='background:#2c5fa8; color:white; border-radius:50%; width:20px; height:20px; display:inline-block; text-align:center; font-size:11px; line-height:20px; font-weight:700;'>3</span></td>
                    <td style='padding:6px 0; font-size:13px; color:#4a5568;'>We begin work and deliver within 1-3 business days</td>
                </tr>
            </table>
        </div>
    ");

    $plain = "Hi {$clientName},\n\n"
        . "You have a new invoice from Oasis Engineering.\n\n"
        . "Service: {$serviceType}\n"
        . "Project: {$projectAddress}\n"
        . "Amount: $" . number_format($amount, 2) . "\n\n"
        . "Review and pay here: {$paymentUrl}\n\n"
        . "Thank you,\nOasis Engineering";

    return sendEmail($clientEmail, $subject, $html, $plain);
}

// ============================================================
// EMAIL TEMPLATE: PAYMENT CONFIRMED (sent after Stripe payment)
// ============================================================
function emailPaymentConfirmed($invoiceId, $clientName, $clientEmail, $serviceType, $projectAddress, $amount, $paymentUrl) {
    $subject = "Oasis Engineering — Payment Confirmed #{$invoiceId}";

    $html = getEmailWrapper("
        <div style='text-align:center; padding:30px 0 20px;'>
            <div style='width:56px; height:56px; background:#1a6b4a; border-radius:50%; margin:0 auto 14px; line-height:56px; font-size:28px; color:white;'>✓</div>
            <div style='font-size:22px; font-weight:700; color:#1a6b4a; font-family:Georgia,serif;'>Payment Confirmed!</div>
            <div style='font-size:13px; color:#8899aa; margin-top:4px;'>Invoice #{$invoiceId}</div>
        </div>

        <p style='font-size:15px; color:#1a1a1a;'>Hi {$clientName},</p>

        <p style='color:#4a5568;'>Great news — your payment has been received and we're getting started on your project! Here's a summary of your order:</p>

        <div style='background:#e8f5ee; border:1px solid #c3e6cb; border-radius:10px; padding:20px; margin:24px 0;'>
            <table style='width:100%;' cellpadding='0' cellspacing='0'>
                <tr>
                    <td style='padding:6px 0; font-size:12px; color:#4a5568; text-transform:uppercase; letter-spacing:0.5px;'>Service</td>
                    <td style='padding:6px 0; font-size:14px; font-weight:600; color:#1a1a1a; text-align:right;'>{$serviceType}</td>
                </tr>
                <tr>
                    <td style='padding:6px 0; font-size:12px; color:#4a5568; text-transform:uppercase; letter-spacing:0.5px;'>Project</td>
                    <td style='padding:6px 0; font-size:13px; color:#1a1a1a; text-align:right;'>{$projectAddress}</td>
                </tr>
                <tr>
                    <td style='padding:10px 0 6px; font-size:12px; color:#4a5568; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #c3e6cb;'>Amount Paid</td>
                    <td style='padding:10px 0 6px; font-size:22px; font-weight:700; color:#1a6b4a; text-align:right; border-top:1px solid #c3e6cb; font-family:Georgia,serif;'>$" . number_format($amount, 2) . "</td>
                </tr>
            </table>
        </div>

        <p style='color:#4a5568;'>Your energy calculations will be delivered electronically (PDF) within <strong>1-3 business days</strong>. If we need any additional information, we'll reach out to you directly.</p>

        <div style='text-align:center; margin:28px 0;'>
            <a href='{$paymentUrl}' style='display:inline-block; background:#2c5fa8; color:#ffffff; padding:14px 32px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:600;'>View Order & Download Agreement</a>
        </div>

        <p style='font-size:12px; color:#8899aa; text-align:center;'>A Stripe receipt has also been sent to your email for your records.</p>

        <div style='margin-top:30px; padding-top:20px; border-top:1px solid #e8e0d4; text-align:center;'>
            <p style='font-size:13px; color:#4a5568;'>Questions about your project? We're here to help.</p>
            <p style='font-size:13px; margin-top:6px;'>
                <a href='mailto:info@yourcompany.com' style='color:#2c5fa8; text-decoration:none;'>info@yourcompany.com</a> &nbsp;·&nbsp;
                <a href='tel:8136948989' style='color:#2c5fa8; text-decoration:none;'>555-123-4567</a>
            </p>
        </div>
    ");

    $plain = "Hi {$clientName},\n\n"
        . "Payment confirmed for Invoice #{$invoiceId}!\n\n"
        . "Service: {$serviceType}\n"
        . "Project: {$projectAddress}\n"
        . "Amount Paid: $" . number_format($amount, 2) . "\n\n"
        . "Your energy calculations will be delivered within 1-3 business days.\n\n"
        . "View your order: {$paymentUrl}\n\n"
        . "Thank you,\nOasis Engineering\n"
        . "info@yourcompany.com | 555-123-4567";

    return sendEmail($clientEmail, $subject, $html, $plain);
}

// ============================================================
// EMAIL TEMPLATE: ADMIN NOTIFICATION (sent to Admin on new order)
// ============================================================
function emailAdminNewOrder($invoiceId, $clientName, $clientEmail, $clientPhone, $serviceType, $projectAddress, $amount, $county) {
    $adminEmail = 'info@yourcompany.com';
    $subject = "New Order! #{$invoiceId} — {$clientName} — $" . number_format($amount, 2);

    $html = getEmailWrapper("
        <div style='text-align:center; padding:24px 0 16px;'>
            <div style='font-size:13px; text-transform:uppercase; letter-spacing:2px; color:#2c5fa8; font-weight:700;'>New Order Received</div>
            <div style='font-size:28px; font-weight:700; color:#1a6b4a; font-family:Georgia,serif; margin-top:6px;'>$" . number_format($amount, 2) . "</div>
            <div style='font-size:13px; color:#8899aa; margin-top:4px;'>Invoice #{$invoiceId}</div>
        </div>

        <div style='background:#f0f4f8; border-radius:10px; padding:20px; margin:20px 0;'>
            <table style='width:100%;' cellpadding='0' cellspacing='0'>
                <tr>
                    <td style='padding:8px 0; font-size:12px; color:#8899aa; width:120px;'>Client</td>
                    <td style='padding:8px 0; font-size:14px; font-weight:600; color:#1a1a1a;'>{$clientName}</td>
                </tr>
                <tr>
                    <td style='padding:8px 0; font-size:12px; color:#8899aa;'>Email</td>
                    <td style='padding:8px 0; font-size:13px;'><a href='mailto:{$clientEmail}' style='color:#2c5fa8;'>{$clientEmail}</a></td>
                </tr>
                " . ($clientPhone ? "<tr>
                    <td style='padding:8px 0; font-size:12px; color:#8899aa;'>Phone</td>
                    <td style='padding:8px 0; font-size:13px;'><a href='tel:{$clientPhone}' style='color:#2c5fa8;'>{$clientPhone}</a></td>
                </tr>" : "") . "
                <tr>
                    <td style='padding:8px 0; font-size:12px; color:#8899aa;'>Service</td>
                    <td style='padding:8px 0; font-size:13px; color:#1a1a1a;'>{$serviceType}</td>
                </tr>
                <tr>
                    <td style='padding:8px 0; font-size:12px; color:#8899aa;'>Project</td>
                    <td style='padding:8px 0; font-size:13px; color:#1a1a1a;'>{$projectAddress}</td>
                </tr>
                <tr>
                    <td style='padding:8px 0; font-size:12px; color:#8899aa;'>County</td>
                    <td style='padding:8px 0; font-size:13px; color:#1a1a1a;'>{$county}</td>
                </tr>
            </table>
        </div>

        <p style='font-size:12px; color:#8899aa; text-align:center;'>Client has been sent a payment link. You'll get another email when payment is confirmed.</p>
    ");

    return sendEmail($adminEmail, $subject, $html);
}

// ============================================================
// HTML EMAIL WRAPPER (shared layout)
// ============================================================
function getEmailWrapper($bodyContent) {
    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background:#f7f3ed; font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif; font-size:14px; line-height:1.6; color:#1a1a1a;">
    <div style="max-width:580px; margin:0 auto; padding:20px;">

        <!-- Logo Header -->
        <div style="text-align:center; padding:24px 0 16px;">
            <div style="font-size:26px; font-weight:700; color:#2c5fa8; font-family:Georgia,serif; letter-spacing:-0.5px;">Oasis Engineering</div>
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:2px; color:#8899aa; margin-top:2px;">Energy Calculation Services</div>
        </div>

        <!-- Card -->
        <div style="background:#ffffff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); padding:32px; margin-bottom:20px;">
            ' . $bodyContent . '
        </div>

        <!-- Footer -->
        <div style="text-align:center; padding:16px 0; font-size:11px; color:#8899aa; line-height:1.8;">
            Oasis Engineering · 3702 W Spruce St, #1033 Your City, ST 33607<br>
            <a href="https://yourcompany.com" style="color:#2c5fa8; text-decoration:none;">yourcompany.com</a> ·
            <a href="https://windcalculations.com" style="color:#2c5fa8; text-decoration:none;">windcalculations.com</a><br>
            555-123-4567 · info@yourcompany.com
        </div>
    </div>
</body>
</html>';
}
