<?php
/**
 * index.php — Landing page for SimplePayApp
 *
 * If someone hits the root URL, show a clean branded page.
 * The actual app lives at /admin.html (admin portal)
 * and /pay/INVOICE-ID (client payment pages).
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oasis Engineering — Services Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Fraunces:opsz,wght@9..144,400;9..144,600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #f7f3ed;
            color: #1a1a1a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            max-width: 520px;
            padding: 48px 24px;
        }
        .logo-mark {
            width: 64px;
            height: 64px;
            background: #2c5fa8;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 28px;
        }
        .logo-mark svg {
            width: 36px;
            height: 36px;
            fill: white;
        }
        h1 {
            font-family: 'Fraunces', serif;
            font-size: 28px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        .subtitle {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 36px;
            line-height: 1.6;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            text-align: left;
            margin-bottom: 24px;
        }
        .card h2 {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #2c5fa8;
        }
        .card p {
            font-size: 14px;
            color: #4a5568;
            line-height: 1.7;
        }
        .services {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }
        .services span {
            background: #e8eff8;
            color: #2c5fa8;
            font-size: 12px;
            font-weight: 500;
            padding: 6px 14px;
            border-radius: 20px;
        }
        .cta-btn {
            display: inline-block;
            background: #2c5fa8;
            color: white;
            text-decoration: none;
            padding: 14px 32px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .cta-btn:hover { background: #1e3f6f; }
        .footer {
            margin-top: 16px;
            font-size: 12px;
            color: #a0aec0;
        }
        .footer a { color: #4a5568; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }

        .invoice-lookup {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .invoice-lookup label {
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .lookup-row {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        .lookup-row input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            outline: none;
        }
        .lookup-row input:focus { border-color: #2c5fa8; }
        .lookup-row button {
            background: #2c5fa8;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
        }
        .lookup-row button:hover { background: #1e3f6f; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-mark">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
        </div>
        <h1>Oasis Engineering</h1>
        <p class="subtitle">Professional Services Portal</p>

        <div class="card">
            <h2>Our Services</h2>
            <p>Professional energy calculations, code compliance, and building science services for residential and commercial projects.</p>
            <div class="services">
                <span>Manual J/D/S</span>
                <span>EnergyGauge/FSEC</span>
                <span>Duct Leakage Testing</span>
                <span>Blower Door Testing</span>
                <span>Site Visit &amp; Scan</span>
            </div>

            <div class="invoice-lookup">
                <label>Have an invoice?</label>
                <div class="lookup-row">
                    <input type="text" id="invoiceId" placeholder="OE-2026-0001" />
                    <button onclick="goToInvoice()">View</button>
                </div>
            </div>
        </div>

        <a href="https://yourcompany.com" class="cta-btn">Visit Our Website</a>

        <p class="footer">
            &copy; <?= date('Y') ?> Your Company Name &middot; <a href="https://yourcompany.com">Main Site</a>
        </p>
    </div>

    <script>
        function goToInvoice() {
            const id = document.getElementById('invoiceId').value.trim();
            if (id) {
                window.location.href = '/pay/' + encodeURIComponent(id);
            }
        }
        document.getElementById('invoiceId').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') goToInvoice();
        });
    </script>
</body>
</html>
