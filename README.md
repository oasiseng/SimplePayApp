# SimplePayApp

A lightweight, self-hosted invoicing and payment portal for small service businesses. Built with vanilla PHP + JavaScript — no frameworks, no build step, no monthly SaaS fees.

## The Problem

Small service businesses (engineers, consultants, contractors, freelancers) waste time and money on invoicing:

- **SaaS tools like Quotient, HoneyBook, or FreshBooks** charge $20-80/month for features you don't need
- **Manual invoicing** via email/PDF means chasing payments, no e-signatures, no file collection
- **Stripe alone** gives you payments but not the full client flow — intake forms, document uploads, signed agreements, branded emails
- **No two-way sync** between your CRM/project management tool and your payment system

## The Solution

SimplePayApp is a single-folder PHP app that gives you a complete invoicing workflow:

**For the business owner (admin):**
- Create invoices in 30 seconds — click service cards, set prices, done
- Automatic Stripe Checkout with configurable platform fee splits (Stripe Connect)
- Invoice history dashboard with revenue stats
- Two-way Airtable sync — pull proposals in, push paid invoices back
- Zapier-powered email notifications (works with any email provider)

**For the client:**
- Clean, branded payment page with project details
- Editable intake form (project info, specs, materials)
- File upload (blueprints, surveys, documents — up to 25MB each)
- Full terms & conditions with collapsible sections
- E-signature pad (mouse + touch)
- Downloadable signed agreement PDF
- Stripe Checkout (cards, Apple Pay, Google Pay)

## How It Works

```
┌─────────────────────────────────────────────────────────────┐
│  ADMIN creates invoice (admin.html)                         │
│  → Selects services, sets price, enters client info         │
│  → System creates Stripe Checkout Session                   │
│  → Fires Zapier webhook → client gets email with pay link   │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│  CLIENT reviews & pays (/pay/OE-2026-0001)                  │
│  → Reviews project details, edits intake form               │
│  → Uploads blueprints/documents                             │
│  → Reads & accepts terms, signs e-signature                 │
│  → Pays via Stripe Checkout                                 │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│  AFTER PAYMENT (webhook.php)                                │
│  → Invoice marked as paid in SQLite                         │
│  → Synced to Airtable (with file attachments)               │
│  → Zapier fires → confirmation email to client              │
│  → Zapier fires → notification email to admin               │
│  → Client can download signed agreement PDF                 │
└─────────────────────────────────────────────────────────────┘
```

## Tech Stack

| Layer | Tech | Why |
|-------|------|-----|
| Backend | PHP 7.4+ | Runs on any shared hosting ($3/mo) |
| Database | SQLite3 | Zero config, file-based, no MySQL needed |
| Frontend | Vanilla JS + CSS | No build step, no node_modules, instant load |
| Payments | Stripe Checkout + Connect | PCI compliant, supports Apple/Google Pay |
| Email | Zapier webhooks | Works with any email provider (Outlook, Gmail, etc.) |
| CRM Sync | Airtable REST API | Two-way sync with file attachments |
| Hosting | Any PHP host | Tested on Hostinger, works on any Apache/PHP host |

## Features

### Admin Dashboard
- **Service menu** with clickable cards — energy calcs, FEMA certificates, site visits, custom services
- **Add-ons** — rush delivery, printed copies, floor plans
- **Editable pricing** per invoice with discount support
- **Intake prepopulation** — fill in project details before sending to client
- **Invoice history** with revenue/paid/pending stats
- **Airtable tab** — pull proposals, import to portal, mark as accepted
- **Settings** — connection status for Stripe, Airtable, Zapier

### Client Payment Page
- **Responsive** — works on desktop, tablet, phone
- **Intake form** — dropdowns for project type, system type, insulation, etc.
- **File upload** — drag-and-drop, 25MB limit, multiple files
- **Service descriptions** — rich detail boxes for complex services
- **Client add-ons** — clients can self-add services before paying
- **E-signature** — HTML5 Canvas with touch support
- **Signed agreement PDF** — generated client-side with jsPDF
- **Terms & conditions** — collapsible, downloadable as PDF

### Integrations
- **Stripe Connect** — platform fee split (configurable %, destination charges)
- **Airtable** — full two-way sync with all invoice fields + file attachments
- **Zapier** — webhook-triggered emails for invoice created + payment confirmed

## File Structure

```
SimplePayApp/
├── .htaccess              # URL routing, HTTPS, security rules
├── admin.html             # Admin dashboard (single-page app)
├── pay.html               # Client payment page template
├── pay.php                # Server-side renderer for /pay/INVOICE-ID
├── create-invoice.php     # API: create invoice + Stripe session
├── create-checkout.php    # API: retrieve Stripe Checkout URL
├── get-invoice.php        # API: fetch invoice data
├── webhook.php            # Stripe webhook handler
├── upload-files.php       # Client file upload handler
├── save-intake.php        # Save intake form + signature
├── email-helper.php       # HTML email templates (SMTP fallback)
├── airtable-helper.php    # Airtable REST API helper
├── airtable-sync.php      # API: two-way Airtable sync
├── zapier-webhook.php     # Zapier integration endpoint
├── data/                  # SQLite database (auto-created)
├── uploads/               # Client file uploads
└── vendor/                # Composer packages (Stripe + PHPMailer)
```

## Setup (15 minutes)

### 1. Upload files
Copy everything to your web root. Create `data/` and `uploads/` folders.

### 2. Install dependencies
```bash
cd /your/web/root
composer require stripe/stripe-php phpmailer/phpmailer
```

### 3. Configure environment
Add to `.htaccess` after `RewriteEngine On`:

```apache
SetEnv STRIPE_SECRET_KEY sk_live_XXXXXXXXXXXX
SetEnv STRIPE_CONNECTED_ACCOUNT_ID acct_XXXXXXXXXXXX
SetEnv STRIPE_WEBHOOK_SECRET whsec_XXXXXXXXXXXX
SetEnv ADMIN_PASSWORD your_secure_password
SetEnv AIRTABLE_PAT pat_XXXXXXXXXXXX
SetEnv ZAPIER_INVOICE_WEBHOOK https://hooks.zapier.com/hooks/catch/XXXXX/XXXXX
SetEnv ZAPIER_PAYMENT_WEBHOOK https://hooks.zapier.com/hooks/catch/XXXXX/XXXXX
SetEnv APP_BASE_URL https://pay.yourcompany.com
```

### 4. Configure Stripe
- Create a [Stripe account](https://dashboard.stripe.com)
- Enable [Stripe Connect](https://dashboard.stripe.com/connect/overview) (Platform mode)
- Add a connected account for the service provider
- Create a webhook endpoint pointing to `/api/webhook.php` with event `checkout.session.completed`

### 5. Configure Airtable (optional)
- Create a [Personal Access Token](https://airtable.com/create/tokens) with `data.records:read` and `data.records:write` scopes
- Create a table with matching fields (or adapt `airtable-helper.php` to your schema)

### 6. Configure Zapier (optional)
- Create two Zaps with "Webhooks by Zapier → Catch Hook" triggers
- Connect to your email provider (Outlook, Gmail, etc.) as the action
- Use the webhook URLs in your `.htaccess`

### 7. Test
- Visit `yoursite.com/admin.html`
- Create a test invoice
- Open the payment link
- Pay with Stripe test card: `4242 4242 4242 4242`

## Customization

### Add your own services
Edit the `SERVICES` array in `admin.html` (~line 1098):

```javascript
const SERVICES = [
    {
        id: 'my-service',
        name: 'My Service Name',
        desc: 'What this service includes',
        defaultPrice: 299,
        deliverables: ['Deliverable 1', 'Deliverable 2']
    },
    // ...
];
```

### Add client-facing service descriptions
Edit the `SERVICE_DESCRIPTIONS` object in `pay.html` to add rich detail boxes that show when a service is on the invoice.

### Change the branding
CSS variables at the top of both `admin.html` and `pay.html`:

```css
:root {
    --oasis-blue: #2c5fa8;        /* Primary brand color */
    --oasis-blue-light: #e8eff8;
    --oasis-blue-dark: #1e3f6f;
    --oasis-green: #1a6b4a;       /* Success/payment color */
}
```

### Adjust the fee split
In `create-invoice.php`:

```php
'platform_fee_percent' => 10, // Change this (includes Stripe's ~3%)
```

## Platform Fee Model

The app uses Stripe Connect destination charges:

```
Client pays $299
  → Stripe takes ~$8.97 (2.9% + 30¢)
  → Platform takes $29.90 (10% of $299)
  → Service provider receives $260.13
```

The `platform_fee_percent` config covers both your cut and Stripe's processing fee. Adjust as needed.

## Security

- API keys stored as server-side environment variables (never exposed to frontend)
- SQLite database blocked from direct HTTP access via `.htaccess`
- Helper PHP files blocked from direct access
- Directory listing disabled
- HTTPS enforced
- File uploads sanitized (filename cleaning, size limits)

## Requirements

- PHP 7.4+ with SQLite3 and cURL extensions
- Apache with mod_rewrite (most shared hosts have this)
- Stripe account
- Composer (for installing Stripe SDK)

## License

MIT — use it however you want.

## Credits

Built as a real production app for a Florida engineering firm. Open-sourced because small businesses shouldn't need to pay $50/month for basic invoicing.
