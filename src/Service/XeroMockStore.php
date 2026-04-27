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

    public function defaultClientId(): string
    {
        return 'mock-client-id';
    }

    public function concreteRedirectUri(string $redirectUri, string $preferredScheme = 'http'): string
    {
        $normalized = trim($redirectUri);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, 'http(s?)://')) {
            return $preferredScheme.'://'.substr($normalized, strlen('http(s?)://'));
        }

        return $normalized;
    }

    public function redirectUriMatches(string $registeredRedirectUri, string $candidateRedirectUri): bool
    {
        $registered = trim($registeredRedirectUri);
        $candidate = trim($candidateRedirectUri);

        if ($registered === '' || $candidate === '') {
            return $registered === $candidate;
        }

        if ($registered === $candidate) {
            return true;
        }

        foreach ([[$registered, $candidate], [$candidate, $registered]] as [$pattern, $value]) {
            if (!str_starts_with($pattern, 'http(s?)://')) {
                continue;
            }

            $suffix = substr($pattern, strlen('http(s?)://'));
            $regex = '#^https?://'.preg_quote($suffix, '#').'$#';
            if (preg_match($regex, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public function isValidRedirectUri(string $redirectUri): bool
    {
        $normalized = trim($redirectUri);
        if ($normalized === '' || preg_match('/\s/', $normalized) === 1) {
            return false;
        }

        if (str_starts_with($normalized, 'http(s?)://')) {
            return $this->isValidConcreteHttpUrl($this->concreteRedirectUri($normalized, 'http'))
                && $this->isValidConcreteHttpUrl($this->concreteRedirectUri($normalized, 'https'));
        }

        return $this->isValidConcreteHttpUrl($normalized);
    }

    public function requestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return array{access_token: string, refresh_token: string, scope: string}
     */
    public function createToken(
        string $grantType,
        string $scope,
        ?string $userId = null,
        ?string $userEmail = null,
        ?string $clientId = null,
    ): array
    {
        $accessToken = 'mock_access_'.bin2hex(random_bytes(24));
        $refreshToken = 'mock_refresh_'.bin2hex(random_bytes(24));

        $statement = $this->pdo->prepare(
            'INSERT INTO tokens (access_token, refresh_token, grant_type, scope, client_id, user_id, user_email, expires_at, created_at, revoked_at)
             VALUES (:access_token, :refresh_token, :grant_type, :scope, :client_id, :user_id, :user_email, :expires_at, :created_at, :revoked_at)'
        );
        $statement->execute([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'grant_type' => $grantType,
            'scope' => $scope,
            'client_id' => $clientId,
            'user_id' => $userId,
            'user_email' => $userEmail ?? '',
            'expires_at' => gmdate('c', time() + 1800),
            'created_at' => gmdate('c'),
            'revoked_at' => null,
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'scope' => $scope,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tokens(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tokens ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

        return $this->mapTokenRows($rows);
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     * }
     */
    public function tokensPage(int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 100));
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            'SELECT *
             FROM tokens
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $this->mapTokenRows($rows),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
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
     * @return array{access_token: string, refresh_token: string, scope: string}|null
     */
    public function refreshToken(string $refreshToken, ?string $scope = null): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT client_id, user_id, user_email, scope
             FROM tokens
             WHERE refresh_token = :refresh_token
               AND revoked_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['refresh_token' => $refreshToken]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->createToken(
            grantType: 'refresh_token',
            scope: $scope ?? (string) $row['scope'],
            userId: $row['user_id'] !== '' ? (string) $row['user_id'] : null,
            userEmail: $row['user_email'] !== '' ? (string) $row['user_email'] : null,
            clientId: $row['client_id'] !== '' ? (string) $row['client_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createClient(
        string $name,
        string $redirectUri,
        ?string $clientId = null,
        ?string $clientSecret = null,
        string $description = '',
        string $homepageUrl = '',
    ): array {
        $normalizedName = trim($name);
        $normalizedRedirectUri = trim($redirectUri);
        $normalizedClientId = trim((string) $clientId);
        $normalizedClientSecret = trim((string) $clientSecret);
        $normalizedDescription = trim($description);
        $normalizedHomepageUrl = trim($homepageUrl);

        if ($normalizedClientId === '') {
            $normalizedClientId = 'mock-client-'.bin2hex(random_bytes(8));
        }

        if ($normalizedClientSecret === '') {
            $normalizedClientSecret = 'mock-secret-'.bin2hex(random_bytes(24));
        }

        $existing = $this->findClientRowById($normalizedClientId);
        $now = gmdate('c');

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO oauth_clients (
                    client_id,
                    client_secret,
                    name,
                    redirect_uri,
                    description,
                    homepage_url,
                    created_at,
                    updated_at
                 ) VALUES (
                    :client_id,
                    :client_secret,
                    :name,
                    :redirect_uri,
                    :description,
                    :homepage_url,
                    :created_at,
                    :updated_at
                 )'
            );
            $statement->execute([
                'client_id' => $normalizedClientId,
                'client_secret' => $normalizedClientSecret,
                'name' => $normalizedName,
                'redirect_uri' => $normalizedRedirectUri,
                'description' => $normalizedDescription,
                'homepage_url' => $normalizedHomepageUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE oauth_clients
                 SET client_secret = :client_secret,
                     name = :name,
                     redirect_uri = :redirect_uri,
                     description = :description,
                     homepage_url = :homepage_url,
                     updated_at = :updated_at
                 WHERE client_id = :client_id'
            );
            $statement->execute([
                'client_id' => $normalizedClientId,
                'client_secret' => $normalizedClientSecret,
                'name' => $normalizedName,
                'redirect_uri' => $normalizedRedirectUri,
                'description' => $normalizedDescription,
                'homepage_url' => $normalizedHomepageUrl,
                'updated_at' => $now,
            ]);
        }

        return $this->findClientById($normalizedClientId) ?? [
            'ClientID' => $normalizedClientId,
            'ClientSecret' => $normalizedClientSecret,
            'Name' => $normalizedName,
            'RedirectURI' => $normalizedRedirectUri,
            'Description' => $normalizedDescription,
            'HomepageURL' => $normalizedHomepageUrl,
            'CreatedAt' => $now,
            'UpdatedAt' => $now,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClientById(string $clientId): ?array
    {
        $row = $this->findClientRowById(trim($clientId));

        return $row !== null ? $this->clientResponse($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clients(): array
    {
        $rows = $this->pdo
            ->query('SELECT * FROM oauth_clients ORDER BY created_at DESC, name ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->clientResponse($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function createMockUser(
        string $email,
        string $password,
        string $firstName = 'Mock',
        string $lastName = 'User',
    ): array {
        $normalizedEmail = strtolower(trim($email));
        $existing = $this->findUserRowByEmail($normalizedEmail);
        $now = gmdate('c');

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (user_id, email, password_hash, first_name, last_name, created_at, updated_at)
                 VALUES (:user_id, :email, :password_hash, :first_name, :last_name, :created_at, :updated_at)'
            );
            $statement->execute([
                'user_id' => $this->uuid(),
                'email' => $normalizedEmail,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     first_name = :first_name,
                     last_name = :last_name,
                     updated_at = :updated_at
                 WHERE email = :email'
            );
            $statement->execute([
                'email' => $normalizedEmail,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'updated_at' => $now,
            ]);
        }

        return $this->findUserByEmail($normalizedEmail) ?? [
            'UserID' => '',
            'Email' => $normalizedEmail,
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'CreatedAt' => $now,
            'UpdatedAt' => $now,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyMockUserCredentials(string $email, string $password): ?array
    {
        $row = $this->findUserRowByEmail(strtolower(trim($email)));
        if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        return $this->mockUserResponse($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $row = $this->findUserRowByEmail(strtolower(trim($email)));

        return $row !== null ? $this->mockUserResponse($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mockUsers(): array
    {
        $rows = $this->pdo->query('SELECT * FROM users ORDER BY email')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->mockUserResponse($row), $rows);
    }

    public function createAuthorizationCode(
        string $userId,
        string $userEmail,
        string $clientId,
        string $redirectUri,
        string $scope,
    ): string {
        $code = 'mock_auth_code_'.bin2hex(random_bytes(16));

        $statement = $this->pdo->prepare(
            'INSERT INTO authorization_codes (
                code,
                user_id,
                user_email,
                client_id,
                redirect_uri,
                scope,
                expires_at,
                created_at,
                consumed_at
             ) VALUES (
                :code,
                :user_id,
                :user_email,
                :client_id,
                :redirect_uri,
                :scope,
                :expires_at,
                :created_at,
                :consumed_at
             )'
        );
        $statement->execute([
            'code' => $code,
            'user_id' => $userId,
            'user_email' => $userEmail,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'expires_at' => gmdate('c', time() + 300),
            'created_at' => gmdate('c'),
            'consumed_at' => null,
        ]);

        return $code;
    }

    /**
     * @return array{user_id: string, user_email: string, scope: string}|null
     */
    public function consumeAuthorizationCode(string $code, string $clientId, string $redirectUri): ?array
    {
        if ($code === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT *
             FROM authorization_codes
             WHERE code = :code
             LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $expiresAt = strtotime((string) $row['expires_at']);
        if (
            $row['consumed_at'] !== null
            || ($expiresAt !== false && $expiresAt < time())
            || (string) $row['client_id'] !== $clientId
            || !$this->redirectUriMatches((string) $row['redirect_uri'], $redirectUri)
        ) {
            return null;
        }

        $update = $this->pdo->prepare(
            'UPDATE authorization_codes
             SET consumed_at = :consumed_at
             WHERE code = :code'
        );
        $update->execute([
            'consumed_at' => gmdate('c'),
            'code' => $code,
        ]);

        return [
            'user_id' => (string) $row['user_id'],
            'user_email' => (string) $row['user_email'],
            'scope' => (string) $row['scope'],
        ];
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
                client_id TEXT DEFAULT NULL,
                user_id TEXT DEFAULT NULL,
                user_email TEXT NOT NULL DEFAULT "",
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                revoked_at TEXT DEFAULT NULL
            )'
        );
        $this->ensureColumnExists('tokens', 'revoked_at', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('tokens', 'client_id', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('tokens', 'user_id', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('tokens', 'user_email', 'TEXT NOT NULL DEFAULT ""');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS oauth_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id TEXT NOT NULL UNIQUE,
                client_secret TEXT NOT NULL,
                name TEXT NOT NULL,
                redirect_uri TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                homepage_url TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->ensureColumnExists('oauth_clients', 'description', 'TEXT NOT NULL DEFAULT ""');
        $this->ensureColumnExists('oauth_clients', 'homepage_url', 'TEXT NOT NULL DEFAULT ""');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS authorization_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                user_id TEXT NOT NULL,
                user_email TEXT NOT NULL,
                client_id TEXT NOT NULL,
                redirect_uri TEXT NOT NULL,
                scope TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                consumed_at TEXT DEFAULT NULL
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
        $clientCount = (int) $this->pdo->query('SELECT COUNT(*) FROM oauth_clients')->fetchColumn();
        if ($clientCount === 0) {
            $this->createClient(
                name: 'Default Demo App',
                redirectUri: 'http://localhost:3000/callback',
                clientId: $this->defaultClientId(),
                clientSecret: 'mock-client-secret',
                description: 'Default OAuth client used by local examples and manual testing.',
                homepageUrl: 'http://localhost:3000',
            );
        }

        $userCount = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount === 0) {
            $this->createMockUser('alice@example.test', 'alice-pass', 'Alice', 'Mock');
            $this->createMockUser('bob@example.test', 'bob-pass', 'Bob', 'Mock');
        }

        $contactCount = (int) $this->pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
        if ($contactCount === 0) {
            $this->createContact([
                'ContactID' => '00000000-0000-4000-8000-000000000001',
                'Name' => 'Acme Supplies',
                'EmailAddress' => 'billing@acme.test',
                'ContactStatus' => 'ACTIVE',
            ]);
        }

        $invoiceCount = (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        if ($invoiceCount === 0) {
            $this->createInvoice([
                'InvoiceID' => '00000000-0000-4000-8000-000000000101',
                'InvoiceNumber' => 'INV-0001',
                'Contact' => ['Name' => 'Acme Supplies'],
                'Status' => 'AUTHORISED',
                'Total' => 125.50,
            ]);
        }
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
     * @return list<array<string, mixed>>
     */
    private function mapTokenRows(array $rows): array
    {
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
                'client_id' => isset($row['client_id']) && $row['client_id'] !== '' ? (string) $row['client_id'] : null,
                'expires_at' => (string) $row['expires_at'],
                'created_at' => (string) $row['created_at'],
                'revoked_at' => $revokedAt,
                'user_id' => isset($row['user_id']) && $row['user_id'] !== '' ? (string) $row['user_id'] : null,
                'user_email' => isset($row['user_email']) && $row['user_email'] !== '' ? (string) $row['user_email'] : null,
                'status' => $status,
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findClientRowById(string $clientId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM oauth_clients
             WHERE client_id = :client_id
             LIMIT 1'
        );
        $statement->execute(['client_id' => $clientId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserRowByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mockUserResponse(array $row): array
    {
        return [
            'UserID' => $row['user_id'],
            'Email' => $row['email'],
            'FirstName' => $row['first_name'],
            'LastName' => $row['last_name'],
            'CreatedAt' => $row['created_at'],
            'UpdatedAt' => $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function clientResponse(array $row): array
    {
        return [
            'ClientID' => $row['client_id'],
            'ClientSecret' => $row['client_secret'],
            'Name' => $row['name'],
            'RedirectURI' => $row['redirect_uri'],
            'Description' => $row['description'],
            'HomepageURL' => $row['homepage_url'],
            'CreatedAt' => $row['created_at'],
            'UpdatedAt' => $row['updated_at'],
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

    private function isValidConcreteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipv6Host = substr($host, 1, -1);
            if (filter_var($ipv6Host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return false;
            }
        } elseif (str_contains($host, ':')) {
            return false;
        } elseif (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            return false;
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return false;
            }
        }

        return true;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
