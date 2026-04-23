<?php

namespace App\Service;

use PDO;

final class XeroMockStore
{
    private PDO $pdo;

    public function __construct()
    {
        $path = $_ENV['DATABASE_PATH'] ?? $_SERVER['DATABASE_PATH'] ?? dirname(__DIR__, 2).'/var/data/xero_mock.sqlite';
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->pdo = new PDO('sqlite:'.$path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
        $this->seed();
    }

    public function tenantId(): string
    {
        return $_ENV['XERO_MOCK_TENANT_ID'] ?? $_SERVER['XERO_MOCK_TENANT_ID'] ?? 'mock-tenant-0001';
    }

    public function requestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    public function createToken(string $grantType, string $scope): array
    {
        $accessToken = 'mock_access_'.bin2hex(random_bytes(24));
        $refreshToken = 'mock_refresh_'.bin2hex(random_bytes(24));

        $statement = $this->pdo->prepare(
            'INSERT INTO tokens (access_token, refresh_token, grant_type, scope, expires_at, created_at)
             VALUES (:access_token, :refresh_token, :grant_type, :scope, :expires_at, :created_at)'
        );
        $statement->execute([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'grant_type' => $grantType,
            'scope' => $scope,
            'expires_at' => gmdate('c', time() + 1800),
            'created_at' => gmdate('c'),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contacts(): array
    {
        $rows = $this->pdo->query('SELECT * FROM contacts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->contactResponse($row), $rows);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createContact(array $payload): array
    {
        $contactId = $payload['ContactID'] ?? $this->uuid();
        $name = (string) ($payload['Name'] ?? $payload['ContactName'] ?? 'Unnamed Contact');
        $email = (string) ($payload['EmailAddress'] ?? '');
        $status = (string) ($payload['ContactStatus'] ?? 'ACTIVE');
        $now = gmdate('c');

        $statement = $this->pdo->prepare(
            'INSERT INTO contacts (contact_id, name, email, status, raw_json, created_at, updated_at)
             VALUES (:contact_id, :name, :email, :status, :raw_json, :created_at, :updated_at)
             ON CONFLICT(contact_id) DO UPDATE SET
                name = excluded.name,
                email = excluded.email,
                status = excluded.status,
                raw_json = excluded.raw_json,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            'contact_id' => $contactId,
            'name' => $name,
            'email' => $email,
            'status' => $status,
            'raw_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->contactResponse([
            'contact_id' => $contactId,
            'name' => $name,
            'email' => $email,
            'status' => $status,
            'raw_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoices(): array
    {
        $rows = $this->pdo->query('SELECT * FROM invoices ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->invoiceResponse($row), $rows);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        $invoiceId = $payload['InvoiceID'] ?? $this->uuid();
        $invoiceNumber = (string) ($payload['InvoiceNumber'] ?? 'INV-'.random_int(1000, 9999));
        $contactName = (string) ($payload['Contact']['Name'] ?? $payload['ContactName'] ?? 'Unnamed Contact');
        $total = (float) ($payload['Total'] ?? $this->lineItemsTotal($payload['LineItems'] ?? []));
        $status = (string) ($payload['Status'] ?? 'DRAFT');
        $now = gmdate('c');

        $statement = $this->pdo->prepare(
            'INSERT INTO invoices (invoice_id, invoice_number, contact_name, total, status, raw_json, created_at, updated_at)
             VALUES (:invoice_id, :invoice_number, :contact_name, :total, :status, :raw_json, :created_at, :updated_at)
             ON CONFLICT(invoice_id) DO UPDATE SET
                invoice_number = excluded.invoice_number,
                contact_name = excluded.contact_name,
                total = excluded.total,
                status = excluded.status,
                raw_json = excluded.raw_json,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'contact_name' => $contactName,
            'total' => $total,
            'status' => $status,
            'raw_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->invoiceResponse([
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'contact_name' => $contactName,
            'total' => $total,
            'status' => $status,
            'raw_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                access_token TEXT NOT NULL,
                refresh_token TEXT NOT NULL,
                grant_type TEXT NOT NULL,
                scope TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                email TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL,
                raw_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id TEXT NOT NULL UNIQUE,
                invoice_number TEXT NOT NULL,
                contact_name TEXT NOT NULL,
                total REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                raw_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    }

    private function seed(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $this->createContact([
            'ContactID' => '00000000-0000-4000-8000-000000000001',
            'Name' => 'Acme Supplies',
            'EmailAddress' => 'billing@acme.test',
            'ContactStatus' => 'ACTIVE',
        ]);
        $this->createInvoice([
            'InvoiceID' => '00000000-0000-4000-8000-000000000101',
            'InvoiceNumber' => 'INV-0001',
            'Contact' => ['Name' => 'Acme Supplies'],
            'Status' => 'AUTHORISED',
            'Total' => 125.50,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function contactResponse(array $row): array
    {
        return [
            'ContactID' => $row['contact_id'],
            'Name' => $row['name'],
            'EmailAddress' => $row['email'],
            'ContactStatus' => $row['status'],
            'UpdatedDateUTC' => $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function invoiceResponse(array $row): array
    {
        return [
            'InvoiceID' => $row['invoice_id'],
            'InvoiceNumber' => $row['invoice_number'],
            'Type' => 'ACCREC',
            'Contact' => [
                'Name' => $row['contact_name'],
            ],
            'Status' => $row['status'],
            'Total' => (float) $row['total'],
            'UpdatedDateUTC' => $row['updated_at'],
        ];
    }

    /**
     * @param mixed $lineItems
     */
    private function lineItemsTotal(mixed $lineItems): float
    {
        if (!is_array($lineItems)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $quantity = (float) ($lineItem['Quantity'] ?? 1);
            $unitAmount = (float) ($lineItem['UnitAmount'] ?? 0);
            $total += $quantity * $unitAmount;
        }

        return $total;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
