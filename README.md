# GestaoRemocoes

[Português (Brasil)](README-PT.md)

CopartRemocoes is an operational web application for coordinating total-loss vehicle removals between insurance orders and transport operations. It remains in operational use and is hosted on a VPS.

## Business workflow

When an insurer declares a vehicle a total loss, its removal order starts a workflow that tracks the vehicle, insurer, origin and destination, collection and delivery deadlines, and operational status. Staff use the system to follow each removal through collection and delivery and to prepare completed records for payment processing.

## Product capabilities

- **Operational register:** A spreadsheet-style table for searching and filtering records, updating statuses, highlighting deadlines, and reviewing vehicle and route details. Search accepts multiple pasted license plates. Selected records can be exported to Excel or JSON, or copied as an image.
- **Removal-order PDFs:** Poppler's `pdftotext` extracts supported fields from known PDF layouts and pre-fills the registration form. Staff review the extracted data before saving; this is text extraction, not general-purpose OCR.
- **Email workflows:** Microsoft Graph workflows process removal requests, digital checklists, and CT-e error messages. An integration inbox supports human review and reconciliation, with retries for eligible removal-request imports. Checklist synchronization is scheduled every five minutes when the application scheduler is running.
- **Brazilian transport documents:** Configurable workflows cover CT-e (electronic freight transport document) and MDF-e (electronic road freight manifest), submitted to SEFAZ (Brazilian state tax authorities), as well as CIOT (transport operation identifier), submitted to ANTT (the national land transport authority). Capabilities include document generation, retries, reconciliation, and coordinated closure. Dry-run and homologation settings are available alongside guarded live modes. Production use depends on the deployment's configuration, certificates, credentials, and enablement flags; implementation in the codebase does not mean every integration is active in production.
- **Payment batches:** Delivered records can be grouped into batches for review and manual confirmation in the application. The workflow does not initiate bank transfers.
- **Activity history:** The application records selected field changes and actions in key workflows, associated with the acting user.
- **Document storage:** Removal-order PDFs are uploaded to S3 with public visibility. Fiscal DFe documents use a separate private S3 disk.

## Technology

- PHP 8.2+, Laravel 12, Filament 3, and Livewire 3
- Tailwind CSS 4, Alpine.js 3, and Vite 6
- MySQL and SQLite configurations; the example environment uses SQLite
- Poppler for supported PDF text extraction

## Local setup

Requirements: PHP 8.2+, Composer, Node.js (22+ recommended), and Poppler for PDF text extraction. On Debian/Ubuntu, install Poppler with `sudo apt install poppler-utils`; on macOS, use `brew install poppler`. On Windows, the same dependency can be installed in WSL.

```bash
git clone https://github.com/lanng/CopartRemocoes.git
cd CopartRemocoes
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan make:filament-user
composer run dev
```

The example environment defaults to SQLite, and `database/database.sqlite` must exist before migrations run. `make:filament-user` prompts for the account details. Open `/admin` and sign in. `composer run dev` starts the web server, queue listener, log output, and Vite; it does not run the scheduler. Set `PAYMENT_BATCH_START_DATE` before testing scheduled payment-batch generation locally. `php artisan schedule:work` runs all scheduled tasks, including the cleanup jobs described below.

Configure integrations in your own `.env` only when needed. The dashboard does not require Microsoft Graph or fiscal-service credentials to start. Register PDF uploads explicitly use the `s3` disk: set `AWS_BUCKET` to the actual bucket and configure its credentials and, if applicable, endpoint. `FILESYSTEM_DISK=local` does not redirect these uploads. `AWS_S3_BUCKET` is used by this application as an upload directory/prefix despite its name. Fiscal document storage can use `DFE_S3_BUCKET` and `DFE_S3_PREFIX` for its separate private disk.

## Checks

```bash
php artisan test --compact
npm run build
```

## VPS deployment

The application is hosted on a VPS. Point the web server's document root to `public/`, build the frontend assets, apply database migrations, keep a queue worker running under a process supervisor, and configure Laravel's scheduler to run every minute when scheduled workflows are required. Install Poppler and configure S3, Microsoft Graph, and fiscal-service credentials and certificates for the integrations enabled in that environment.

Review scheduled jobs before enabling the scheduler: they include payment-batch generation and retention cleanup. Set `PAYMENT_BATCH_START_DATE` for payment processing. The register cleanup job removes paid or cancelled records older than 15 days, along with their S3 files. Other retention jobs remove eligible resolved email-integration inbox items older than 30 days and confirmed payment batches older than 90 days.
