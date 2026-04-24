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
            'INSERT INTO tokens (access_token, refresh_token, grant_type, scope, expires_at, created_at, revoked_at)
             VALUES (:access_token, :refresh_token, :grant_type, :scope, :expires_at, :created_at, :revoked_at)'
        );
        $statement->execute([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'grant_type' => $grantType,
            'scope' => $scope,
            'expires_at' => gmdate('c', time() + 1800),
            'created_at' => gmdate('c'),
            'revoked_at' => null,
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tokens(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tokens ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $now = time();

        return array_map(function (array $row) use ($now): array {
            $revokedAt = isset($row['revoked_at']) && $row['revoked_at'] !== '' ? (string) $row['revoked_at'] : null;
            $expiresAt = strtotime((string) $row['expires_at']);
            $status = 'ACTIVE';

            if ($revokedAt !== null) {
                $status = 'REVOKED';
            } elseif ($expiresAt !== false && $expiresAt < $now) {
                $status = 'EXPIRED';
            }

            return [
                'access_token' => (string) $row['access_token'],
                'refresh_token' => (string) $row['refresh_token'],
                'grant_type' => (string) $row['grant_type'],
                'scope' => (string) $row['scope'],
                'expires_at' => (string) $row['expires_at'],
                'created_at' => (string) $row['created_at'],
                'revoked_at' => $revokedAt,
                'status' => $status,
            ];
        }, $rows);
    }

    public function revokeToken(string $token): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE tokens
             SET revoked_at = :revoked_at
             WHERE revoked_at IS NULL
               AND (access_token = :token OR refresh_token = :token)'
        );
        $statement->execute([
            'revoked_at' => gmdate('c'),
            'token' => $token,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        return [
            [
                'AccountID' => '10000000-0000-4000-8000-000000000001',
                'Code' => '090',
                'Name' => 'Business Bank Account',
                'Type' => 'BANK',
                'Class' => 'ASSET',
                'Status' => 'ACTIVE',
                'TaxType' => 'NONE',
            ],
            [
                'AccountID' => '10000000-0000-4000-8000-000000000002',
                'Code' => '200',
                'Name' => 'Sales',
                'Type' => 'REVENUE',
                'Class' => 'REVENUE',
                'Status' => 'ACTIVE',
                'TaxType' => 'OUTPUT',
            ],
            [
                'AccountID' => '10000000-0000-4000-8000-000000000003',
                'Code' => '400',
                'Name' => 'Office Expenses',
                'Type' => 'EXPENSE',
                'Class' => 'EXPENSE',
                'Status' => 'ACTIVE',
                'TaxType' => 'INPUT',
            ],
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

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createPayment(array $payload): array
    {
        $paymentId = $payload['PaymentID'] ?? $this->uuid();
        $invoiceId = (string) ($payload['Invoice']['InvoiceID'] ?? $payload['InvoiceID'] ?? '');
        $accountCode = (string) ($payload['Account']['Code'] ?? $payload['AccountCode'] ?? '');
        $amount = (float) ($payload['Amount'] ?? 0);
        $status = (string) ($payload['Status'] ?? 'AUTHORISED');
        $date = (string) ($payload['Date'] ?? gmdate('Y-m-d'));
        $now = gmdate('c');

        $statement = $this->pdo->prepare(
            'INSERT INTO payments (payment_id, invoice_id, account_code, amount, status, payment_date, raw_json, created_at, updated_at)
             VALUES (:payment_id, :invoice_id, :account_code, :amount, :status, :payment_date, :raw_json, :created_at, :updated_at)
             ON CONFLICT(payment_id) DO UPDATE SET
                invoice_id = excluded.invoice_id,
                account_code = excluded.account_code,
                amount = excluded.amount,
                status = excluded.status,
                payment_date = excluded.payment_date,
                raw_json = excluded.raw_json,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'account_code' => $accountCode,
            'amount' => $amount,
            'status' => $status,
            'payment_date' => $date,
            'raw_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->paymentResponse([
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'account_code' => $accountCode,
            'amount' => $amount,
            'status' => $status,
            'payment_date' => $date,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function taxRates(): array
    {
        return [
            [
                'Name' => 'Tax Exempt',
                'TaxType' => 'NONE',
                'DisplayTaxRate' => 0.0,
                'EffectiveRate' => 0.0,
                'Status' => 'ACTIVE',
            ],
            [
                'Name' => 'Sales Tax',
                'TaxType' => 'OUTPUT',
                'DisplayTaxRate' => 10.0,
                'EffectiveRate' => 10.0,
                'Status' => 'ACTIVE',
            ],
            [
                'Name' => 'Purchases Tax',
                'TaxType' => 'INPUT',
                'DisplayTaxRate' => 10.0,
                'EffectiveRate' => 10.0,
                'Status' => 'ACTIVE',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trackingCategories(): array
    {
        return [
            [
                'TrackingCategoryID' => '30000000-0000-4000-8000-000000000001',
                'Name' => 'Region',
                'Status' => 'ACTIVE',
                'Options' => [
                    [
                        'TrackingOptionID' => '30000000-0000-4000-8000-000000000011',
                        'Name' => 'North',
                        'Status' => 'ACTIVE',
                    ],
                    [
                        'TrackingOptionID' => '30000000-0000-4000-8000-000000000012',
                        'Name' => 'South',
                        'Status' => 'ACTIVE',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createManualJournal(array $payload): array
    {
        $manualJournalId = $payload['ManualJournalID'] ?? $this->uuid();
        $narration = (string) ($payload['Narration'] ?? 'Mock manual journal');
        $status = (string) ($payload['Status'] ?? 'DRAFT');
        $date = (string) ($payload['Date'] ?? gmdate('Y-m-d'));
        $lineAmountTypes = (string) ($payload['LineAmountTypes'] ?? 'Exclusive');
        $total = (float) ($payload['Total'] ?? $this->journalLinesTotal($payload['JournalLines'] ?? []));
        $now = gmdate('c');

        $statement = $this->pdo->prepare(
            'INSERT INTO manual_journals (manual_journal_id, narration, status, journal_date, line_amount_types, total, raw_json, created_at, updated_at)
             VALUES (:manual_journal_id, :narration, :status, :journal_date, :line_amount_types, :total, :raw_json, :created_at, :updated_at)
             ON CONFLICT(manual_journal_id) DO UPDATE SET
                narration = excluded.narration,
                status = excluded.status,
                journal_date = excluded.journal_date,
                line_amount_types = excluded.line_amount_types,
                total = excluded.total,
                raw_json = excluded.raw_json,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            'manual_journal_id' => $manualJournalId,
            'narration' => $narration,
            'status' => $status,
            'journal_date' => $date,
            'line_amount_types' => $lineAmountTypes,
            'total' => $total,
            'raw_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->manualJournalResponse([
            'manual_journal_id' => $manualJournalId,
            'narration' => $narration,
            'status' => $status,
            'journal_date' => $date,
            'line_amount_types' => $lineAmountTypes,
            'total' => $total,
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
                created_at TEXT NOT NULL,
                revoked_at TEXT DEFAULT NULL
            )'
        );
        $this->ensureColumnExists('tokens', 'revoked_at', 'TEXT DEFAULT NULL');

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

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_id TEXT NOT NULL UNIQUE,
                invoice_id TEXT NOT NULL DEFAULT "",
                account_code TEXT NOT NULL DEFAULT "",
                amount REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                payment_date TEXT NOT NULL,
                raw_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS manual_journals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                manual_journal_id TEXT NOT NULL UNIQUE,
                narration TEXT NOT NULL,
                status TEXT NOT NULL,
                journal_date TEXT NOT NULL,
                line_amount_types TEXT NOT NULL,
                total REAL NOT NULL DEFAULT 0,
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
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function paymentResponse(array $row): array
    {
        return [
            'PaymentID' => $row['payment_id'],
            'Invoice' => [
                'InvoiceID' => $row['invoice_id'],
            ],
            'Account' => [
                'Code' => $row['account_code'],
            ],
            'Date' => $row['payment_date'],
            'Amount' => (float) $row['amount'],
            'Status' => $row['status'],
            'UpdatedDateUTC' => $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function manualJournalResponse(array $row): array
    {
        return [
            'ManualJournalID' => $row['manual_journal_id'],
            'Narration' => $row['narration'],
            'Status' => $row['status'],
            'Date' => $row['journal_date'],
            'LineAmountTypes' => $row['line_amount_types'],
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

    /**
     * @param mixed $journalLines
     */
    private function journalLinesTotal(mixed $journalLines): float
    {
        if (!is_array($journalLines)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($journalLines as $journalLine) {
            if (!is_array($journalLine)) {
                continue;
            }

            $total += (float) ($journalLine['LineAmount'] ?? 0);
        }

        return $total;
    }

    private function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $columns = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $columnInfo) {
            if (($columnInfo['name'] ?? null) === $column) {
                return;
            }
        }

        $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
