# xeroloc

Small Symfony-based mock for Xero API integrations. It runs fully in Docker and stores mock data in SQLite.

## Run

```bash
docker compose up --build
```

The API will be available at `http://localhost:8080`.

The admin UI is available at `http://localhost:8080/admin`.

Use the admin UI to:

- register OAuth clients with `client_id`, `client_secret`, `redirect_uri`
- inspect client credentials and open the authorize screen for any client
- issue and revoke tokens
- browse token history with pagination

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

## Mock Users

The authorize flow uses mock users stored in SQLite.

Default seeded users:

```text
alice@example.test / alice-pass
bob@example.test   / bob-pass
```

Add or update another user:

```bash
docker compose exec -T app php bin/console app:mock-user:add jane@example.test super-secret --first-name Jane --last-name Doe
```

## OAuth Clients

OAuth clients are also stored in SQLite.

Default seeded client:

```text
mock-client-id / mock-client-secret
redirect_uri: http://localhost:3000/callback
```

Add more clients from `http://localhost:8080/admin`.

## Examples

```bash
curl http://localhost:8080/health
```

Open `http://localhost:8080/admin` in your browser to use the built-in dashboard for OAuth clients, tokens and mock users.

```bash
curl -X POST http://localhost:8080/connect/token \
  -d client_id=mock-client-id \
  -d client_secret=mock-client-secret \
  -d grant_type=client_credentials \
  -d scope="accounting.transactions accounting.contacts"
```

Open this URL in a browser and sign in with one of the mock users to get an authorization code on the Xero-style client authorize page:

```text
http://localhost:8080/identity/connect/authorize?response_type=code&client_id=mock-client-id&redirect_uri=http://localhost:3000/callback&scope=accounting.transactions%20accounting.contacts&state=demo
```

Exchange the resulting code for a token:

```bash
curl -X POST http://localhost:8080/connect/token \
  -d grant_type=authorization_code \
  -d client_id=mock-client-id \
  -d client_secret=mock-client-secret \
  -d redirect_uri=http://localhost:3000/callback \
  -d code=mock_auth_code_...
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
