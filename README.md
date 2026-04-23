# xeroloc

Small Symfony-based mock for Xero API integrations. It runs fully in Docker and stores mock data in SQLite.

## Run

```bash
docker compose up --build
```

The API will be available at `http://localhost:8080`.

## Endpoints

```text
GET  /health
POST /connect/token
GET  /connections
GET  /api.xro/2.0/Organisation
GET  /api.xro/2.0/Contacts
POST /api.xro/2.0/Contacts
GET  /api.xro/2.0/Invoices
POST /api.xro/2.0/Invoices
```

## Examples

```bash
curl http://localhost:8080/health
```

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
