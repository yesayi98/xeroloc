# xeroloc

Small Symfony-based mock for Xero API integrations. It runs fully in Docker and stores mock data in SQLite.

## Run

```bash
docker compose up --build
```

The API will be available at `http://localhost:8080`.

The admin UI will be available at `http://localhost:8080/admin`.

## Endpoints

```text
GET  /health
GET  /identity/connect/authorize
POST /connect/token
POST /connect/revocation
GET  /connections
GET  /api.xro/2.0/Organisation
GET  /api.xro/2.0/Accounts
GET  /api.xro/2.0/Contacts
POST /api.xro/2.0/Contacts
GET  /api.xro/2.0/Invoices
POST /api.xro/2.0/Invoices
PUT  /api.xro/2.0/Invoices
POST /api.xro/2.0/Invoices/{InvoiceID}
PUT  /api.xro/2.0/Invoices/{InvoiceID}
POST /api.xro/2.0/Payments
PUT  /api.xro/2.0/Payments
POST /api.xro/2.0/Payments/{PaymentID}
PUT  /api.xro/2.0/Payments/{PaymentID}
GET  /api.xro/2.0/TaxRates
GET  /api.xro/2.0/TrackingCategories
PUT  /api.xro/2.0/ManualJournals
```

## Examples

```bash
curl http://localhost:8080/health
```

Open `http://localhost:8080/admin` in your browser to use the built-in admin UI.

```bash
curl -X POST http://localhost:8080/connect/token \
  -d grant_type=client_credentials \
  -d scope="accounting.transactions accounting.contacts"
```

```bash
curl http://localhost:8080/api.xro/2.0/Contacts
```

```bash
curl -X POST http://localhost:8080/api.xro/2.0/Contacts \
  -H 'Content-Type: application/json' \
  -d '{"Contacts":[{"Name":"Demo Customer","EmailAddress":"demo@example.test"}]}'
```

SQLite data is stored in `var/data/xero_mock.sqlite`.
