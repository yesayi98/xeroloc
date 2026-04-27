<?php

namespace App\Controller;

use App\Service\XeroMockStore;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminController
{
    public function __construct(private XeroMockStore $store)
    {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $tokens = $this->store->tokens();
        $tokenPage = $this->store->tokensPage(
            page: max(1, (int) $request->query->get('token_page', 1)),
            perPage: 10,
        );
        $clients = $this->store->clients();
        $users = $this->store->mockUsers();
        $allContacts = $this->store->contacts();
        $allInvoices = $this->store->invoices();

        $tokenCounts = [
            'ACTIVE' => 0,
            'REVOKED' => 0,
            'EXPIRED' => 0,
        ];

        foreach ($tokens as $token) {
            $status = (string) ($token['status'] ?? 'ACTIVE');
            if (array_key_exists($status, $tokenCounts)) {
                $tokenCounts[$status]++;
            }
        }

        return new Response($this->renderDashboard(
            request: $request,
            tokenPage: $tokenPage,
            clients: $clients,
            users: $users,
            contacts: array_slice($allContacts, 0, 5),
            invoices: array_slice($allInvoices, 0, 5),
            tokenCounts: $tokenCounts,
            clientCount: count($clients),
            userCount: count($users),
            contactCount: count($allContacts),
            invoiceCount: count($allInvoices),
        ));
    }

    #[Route('/admin/clients/create', name: 'admin_clients_create', methods: ['POST'])]
    public function createClient(Request $request): RedirectResponse
    {
        $name = trim((string) $request->request->get('name', ''));
        $redirectUri = trim((string) $request->request->get('redirect_uri', ''));
        $clientId = trim((string) $request->request->get('client_id', ''));
        $clientSecret = trim((string) $request->request->get('client_secret', ''));
        $description = trim((string) $request->request->get('description', ''));
        $homepageUrl = trim((string) $request->request->get('homepage_url', ''));

        if ($name === '' || $redirectUri === '') {
            return $this->redirectToDashboard(
                notice: 'Client name and redirect URI are required.',
                level: 'warning',
                params: ['token_page' => $this->submittedTokenPage($request)],
            );
        }

        if (!$this->store->isValidRedirectUri($redirectUri)) {
            return $this->redirectToDashboard(
                notice: 'Redirect URI must be a valid http:// or https:// URL. A scheme-flexible pattern like http(s?)://app.test/callback is also allowed.',
                level: 'warning',
                params: ['token_page' => $this->submittedTokenPage($request)],
            );
        }

        $client = $this->store->createClient(
            name: $name,
            redirectUri: $redirectUri,
            clientId: $clientId !== '' ? $clientId : null,
            clientSecret: $clientSecret !== '' ? $clientSecret : null,
            description: $description,
            homepageUrl: $homepageUrl,
        );

        return $this->redirectToDashboard(
            notice: sprintf('OAuth client saved: %s.', (string) $client['ClientID']),
            params: ['token_page' => $this->submittedTokenPage($request)],
        );
    }

    #[Route('/admin/tokens/create', name: 'admin_tokens_create', methods: ['POST'])]
    public function createToken(Request $request): RedirectResponse
    {
        $grantType = trim((string) $request->request->get('grant_type', 'client_credentials'));
        $scope = trim((string) $request->request->get('scope', 'accounting.transactions accounting.contacts offline_access'));
        $clientId = trim((string) $request->request->get('client_id', $this->store->defaultClientId()));
        $userEmail = trim((string) $request->request->get('user_email', ''));
        $client = $this->store->findClientById($clientId);
        if ($client === null) {
            return $this->redirectToDashboard(
                notice: 'Select a registered OAuth client before issuing a token.',
                level: 'warning',
                params: ['token_page' => $this->submittedTokenPage($request)],
            );
        }

        $user = $userEmail !== '' ? $this->store->findUserByEmail($userEmail) : null;
        if ($userEmail !== '' && $user === null) {
            return $this->redirectToDashboard(
                notice: 'Selected mock user was not found.',
                level: 'warning',
                params: ['token_page' => $this->submittedTokenPage($request)],
            );
        }

        $this->store->createToken(
            grantType: $grantType !== '' ? $grantType : 'client_credentials',
            scope: $scope !== '' ? $scope : 'accounting.transactions accounting.contacts offline_access',
            userId: is_array($user) ? (string) $user['UserID'] : null,
            userEmail: is_array($user) ? (string) $user['Email'] : null,
            clientId: (string) $client['ClientID'],
        );

        return $this->redirectToDashboard(
            notice: 'Issued a new mock token.',
            params: ['token_page' => $this->submittedTokenPage($request)],
        );
    }

    #[Route('/admin/tokens/revoke', name: 'admin_tokens_revoke', methods: ['POST'])]
    public function revokeToken(Request $request): RedirectResponse
    {
        $token = trim((string) $request->request->get('token', ''));
        if ($token === '') {
            return $this->redirectToDashboard(
                notice: 'Provide an access or refresh token to revoke.',
                level: 'warning',
                params: ['token_page' => $this->submittedTokenPage($request)],
            );
        }

        if ($this->store->revokeToken($token)) {
            return $this->redirectToDashboard(
                notice: 'Token revoked.',
                params: ['token_page' => $this->submittedTokenPage($request)],
            );
        }

        return $this->redirectToDashboard(
            notice: 'Token not found or already revoked.',
            level: 'warning',
            params: ['token_page' => $this->submittedTokenPage($request)],
        );
    }

    /**
     * @param array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     * } $tokenPage
     * @param list<array<string, mixed>> $clients
     * @param list<array<string, mixed>> $users
     * @param list<array<string, mixed>> $contacts
     * @param list<array<string, mixed>> $invoices
     * @param array{ACTIVE: int, REVOKED: int, EXPIRED: int} $tokenCounts
     */
    private function renderDashboard(
        Request $request,
        array $tokenPage,
        array $clients,
        array $users,
        array $contacts,
        array $invoices,
        array $tokenCounts,
        int $clientCount,
        int $userCount,
        int $contactCount,
        int $invoiceCount,
    ): string {
        $notice = trim((string) $request->query->get('notice', ''));
        $level = trim((string) $request->query->get('level', 'success'));
        $currentTokenPage = (int) $tokenPage['page'];
        $tokenItems = $tokenPage['items'];
        $tokenRangeStart = $tokenPage['total'] > 0 ? (($currentTokenPage - 1) * (int) $tokenPage['per_page']) + 1 : 0;
        $tokenRangeEnd = min((int) $tokenPage['total'], $currentTokenPage * (int) $tokenPage['per_page']);
        $clientNamesById = [];

        foreach ($clients as $client) {
            $clientId = (string) ($client['ClientID'] ?? '');
            if ($clientId !== '') {
                $clientNamesById[$clientId] = (string) ($client['Name'] ?? $clientId);
            }
        }

        $tenantId = $this->escape($this->store->tenantId());
        $baseUrlRaw = rtrim($request->getSchemeAndHttpHost(), '/');
        $baseUrl = $this->escape($baseUrlRaw);
        $noticeMarkup = $notice !== ''
            ? sprintf(
                '<div class="notice notice-%s">%s</div>',
                $this->escape($level),
                $this->escape($notice),
            )
            : '';

        $defaultScope = 'accounting.transactions accounting.contacts offline_access';
        $defaultClientId = $this->store->defaultClientId();
        $defaultClient = $this->store->findClientById($defaultClientId);
        $defaultClientRedirectUri = is_array($defaultClient)
            ? $this->store->concreteRedirectUri((string) ($defaultClient['RedirectURI'] ?? 'http://localhost:3000/callback'))
            : 'http://localhost:3000/callback';
        $defaultAuthorizeUrl = $this->escape('/identity/connect/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $defaultClientId,
            'redirect_uri' => $defaultClientRedirectUri,
            'scope' => $defaultScope,
            'state' => 'admin-demo',
        ]));

        $tokenRows = $this->renderTokenRows($tokenItems, $clientNamesById, $currentTokenPage);
        $tokenPagination = $this->renderTokenPagination($request, $tokenPage);
        $clientRows = $this->renderClientRows($clients, $baseUrlRaw, $defaultScope);
        $userRows = $this->renderUserRows($users);
        $contactRows = $this->renderContactRows($contacts);
        $invoiceRows = $this->renderInvoiceRows($invoices);
        $activeTokenCount = $this->escape((string) $tokenCounts['ACTIVE']);
        $revokedTokenCount = $this->escape((string) $tokenCounts['REVOKED']);
        $expiredTokenCount = $this->escape((string) $tokenCounts['EXPIRED']);
        $clientCountLabel = $this->escape((string) $clientCount);
        $userCountLabel = $this->escape((string) $userCount);
        $invoiceCountLabel = $this->escape((string) $invoiceCount);
        $contactCountLabel = $this->escape((string) $contactCount);
        $totalTokensLabel = $this->escape((string) $tokenPage['total']);
        $tokenPageLabel = $this->escape(sprintf('Page %d of %d', $currentTokenPage, (int) $tokenPage['total_pages']));
        $tokenRangeLabel = $this->escape(sprintf('Showing %d-%d of %d', $tokenRangeStart, $tokenRangeEnd, (int) $tokenPage['total']));
        $defaultScopeLabel = $this->escape($defaultScope);
        $defaultClientIdLabel = $this->escape($defaultClientId);
        $defaultRedirectUriLabel = $this->escape($defaultClientRedirectUri);
        $currentTokenPageLabel = $this->escape((string) $currentTokenPage);
        $userOptions = $this->renderUserOptions($users);
        $clientOptions = $this->renderClientOptions($clients, $defaultClientId);
        $addUserCommand = $this->escape('docker compose exec -T app php bin/console app:mock-user:add jane@example.test super-secret --first-name Jane --last-name Doe');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>xeroloc admin</title>
    <style>
        :root {
            --bg: #f4efe3;
            --bg-deep: #ddd4c2;
            --surface: rgba(255, 250, 242, 0.88);
            --text: #1b1915;
            --muted: #625a4d;
            --border: rgba(61, 45, 25, 0.16);
            --accent: #0f766e;
            --accent-soft: rgba(15, 118, 110, 0.12);
            --warning: #9a3412;
            --warning-soft: rgba(154, 52, 18, 0.12);
            --shadow: 0 18px 40px rgba(42, 32, 19, 0.12);
            --radius: 22px;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--text);
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.8), transparent 34%),
                radial-gradient(circle at right, rgba(15, 118, 110, 0.14), transparent 25%),
                linear-gradient(145deg, var(--bg), var(--bg-deep));
        }

        .shell {
            width: min(1260px, calc(100vw - 32px));
            margin: 32px auto 48px;
            display: grid;
            gap: 18px;
        }

        .hero, .panel, .card {
            background: var(--surface);
            backdrop-filter: blur(18px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .hero {
            padding: 28px;
            display: grid;
            gap: 18px;
        }

        .eyebrow {
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 8px;
        }

        h1, h2, h3 {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            font-weight: 600;
        }

        h1 {
            font-size: clamp(36px, 5vw, 58px);
            line-height: 0.95;
            max-width: 11ch;
        }

        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .hero-grid, .stats, .admin-grid, .data-grid {
            display: grid;
            gap: 16px;
        }

        .hero-grid {
            grid-template-columns: 1.35fr 0.9fr;
            align-items: start;
        }

        .stats {
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .card {
            padding: 18px;
        }

        .metric {
            font-size: 32px;
            font-weight: 700;
            margin-top: 8px;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 13px;
        }

        .admin-grid {
            grid-template-columns: 0.92fr 1.08fr;
        }

        .data-grid {
            grid-template-columns: 1fr 1fr;
        }

        .panel-stack {
            display: grid;
            gap: 16px;
            align-content: start;
        }

        .panel {
            padding: 24px;
            display: grid;
            gap: 16px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: end;
        }

        .subheading {
            margin-top: 4px;
            font-size: 14px;
            color: var(--muted);
        }

        .stack {
            display: grid;
            gap: 12px;
        }

        .stack-tight {
            display: grid;
            gap: 6px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        label, th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }

        input, select, textarea, button {
            font: inherit;
            border-radius: 14px;
        }

        input, select, textarea {
            width: 100%;
            border: 1px solid var(--border);
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.84);
            color: var(--text);
        }

        textarea {
            min-height: 96px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        button {
            border: none;
            background: linear-gradient(135deg, #0f766e, #115e59);
            color: #fff;
            padding: 12px 16px;
            cursor: pointer;
            font-weight: 700;
        }

        button.secondary {
            background: linear-gradient(135deg, #7c2d12, #9a3412);
        }

        .subtle-button {
            background: rgba(15, 118, 110, 0.12);
            color: var(--accent);
            border: 1px solid rgba(15, 118, 110, 0.18);
        }

        .notice {
            padding: 14px 18px;
            border-radius: 16px;
            font-size: 14px;
        }

        .notice-success {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .notice-warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .callout {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid var(--border);
        }

        .table-wrap {
            overflow: auto;
            border-radius: 16px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.72);
            height: 1000px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }

        th, td {
            padding: 14px 12px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid rgba(61, 45, 25, 0.08);
        }

        td {
            font-size: 14px;
            color: var(--text);
        }

        tr:last-child td {
            border-bottom: none;
        }

        code {
            font-family: Menlo, Consolas, monospace;
            font-size: 12px;
            word-break: break-all;
            white-space: pre-wrap;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-ACTIVE {
            background: rgba(15, 118, 110, 0.12);
            color: var(--accent);
        }

        .status-REVOKED, .status-EXPIRED {
            background: rgba(154, 52, 18, 0.12);
            color: var(--warning);
        }

        .muted {
            color: var(--muted);
            font-size: 13px;
        }

        .empty {
            padding: 24px;
            text-align: center;
            color: var(--muted);
        }

        .table-meta, .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .pagination-links {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 84px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.72);
            color: var(--text);
            text-decoration: none;
            font-size: 14px;
        }

        .page-link.disabled {
            color: var(--muted);
            pointer-events: none;
            opacity: 0.62;
        }

        .inline-link {
            color: var(--accent);
            text-decoration: none;
        }

        .inline-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 980px) {
            .hero-grid, .admin-grid, .stats, .data-grid, .form-row {
                grid-template-columns: 1fr;
            }

            .shell {
                width: min(100vw - 20px, 1260px);
                margin: 20px auto 32px;
            }

            .hero, .panel {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div class="hero-grid">
                <div class="stack">
                    <div>
                        <div class="eyebrow">xeroloc admin</div>
                        <h1>Control room for the Xero mock service</h1>
                    </div>
                    <p>Use this dashboard to manage OAuth clients, inspect mock users, issue and revoke OAuth tokens, and jump into the Xero endpoints exposed by the service.</p>
                    <div class="meta">
                        <span class="pill">Tenant: <strong>{$tenantId}</strong></span>
                        <span class="pill">Base URL: <strong>{$baseUrl}</strong></span>
                        <span class="pill">Admin panel is local/dev only</span>
                    </div>
                </div>
                <div class="callout stack">
                    <div>
                        <div class="eyebrow">Mock Users</div>
                        <h3>Add More Credentials</h3>
                    </div>
                    <p>Create or update user credentials from the shell, then they immediately become available in authorize flow and in the token issuer below.</p>
                    <code>{$addUserCommand}</code>
                </div>
            </div>
            {$noticeMarkup}
            <div class="stats">
                <article class="card">
                    <div class="eyebrow">Active Tokens</div>
                    <div class="metric">{$activeTokenCount}</div>
                    <p>Live access or refresh pairs.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">Revoked Tokens</div>
                    <div class="metric">{$revokedTokenCount}</div>
                    <p>Revoked via API or dashboard.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">Expired Tokens</div>
                    <div class="metric">{$expiredTokenCount}</div>
                    <p>Past their mock TTL.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">OAuth Clients</div>
                    <div class="metric">{$clientCountLabel}</div>
                    <p>Registered client credentials.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">Mock Users</div>
                    <div class="metric">{$userCountLabel}</div>
                    <p>Stored credential sets.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">Contacts</div>
                    <div class="metric">{$contactCountLabel}</div>
                    <p>Current mocked contacts.</p>
                </article>
            </div>
        </section>

        <section class="admin-grid">
            <div class="panel-stack">
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <div class="eyebrow">OAuth Clients</div>
                            <h2>Add or update a client</h2>
                            <div class="subheading">Leave client ID or secret empty to auto-generate them.</div>
                        </div>
                    </div>
                    <form method="post" action="/admin/clients/create" class="stack">
                        <input type="hidden" name="token_page" value="{$currentTokenPageLabel}">
                        <div class="form-row">
                            <div class="field">
                                <label for="client_name">Client Name</label>
                                <input id="client_name" name="name" placeholder="Finance Sync App">
                            </div>
                            <div class="field">
                                <label for="client_redirect_uri">Redirect URI</label>
                                <input id="client_redirect_uri" name="redirect_uri" value="{$defaultRedirectUriLabel}">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="field">
                                <label for="client_id">Client ID</label>
                                <input id="client_id" name="client_id" placeholder="Optional custom client id">
                            </div>
                            <div class="field">
                                <label for="client_secret">Client Secret</label>
                                <input id="client_secret" name="client_secret" placeholder="Optional custom client secret">
                            </div>
                        </div>
                        <div class="field">
                            <label for="homepage_url">Homepage URL</label>
                            <input id="homepage_url" name="homepage_url" placeholder="https://example.test">
                        </div>
                        <div class="field">
                            <label for="client_description">Description</label>
                            <textarea id="client_description" name="description" placeholder="What this OAuth client is used for inside your mock environment"></textarea>
                        </div>
                        <button type="submit">Save OAuth client</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <div class="eyebrow">Token Workshop</div>
                            <h2>Issue or revoke OAuth tokens</h2>
                        </div>
                    </div>

                    <form method="post" action="/admin/tokens/create" class="stack">
                        <input type="hidden" name="token_page" value="{$currentTokenPageLabel}">
                        <div class="form-row">
                            <div class="field">
                                <label for="grant_type">Grant Type</label>
                                <select id="grant_type" name="grant_type">
                                    <option value="client_credentials">client_credentials</option>
                                    <option value="authorization_code">authorization_code</option>
                                    <option value="refresh_token">refresh_token</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="token_client_id">OAuth Client</label>
                                <select id="token_client_id" name="client_id">
                                    {$clientOptions}
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="field">
                                <label for="user_email">Attach to Mock User</label>
                                <select id="user_email" name="user_email">
                                    <option value="">No user binding</option>
                                    {$userOptions}
                                </select>
                            </div>
                            <div class="field">
                                <label for="scope">Scope</label>
                                <input id="scope" name="scope" value="{$defaultScopeLabel}">
                            </div>
                        </div>
                        <button type="submit">Issue mock token</button>
                    </form>

                    <form method="post" action="/admin/tokens/revoke" class="stack">
                        <input type="hidden" name="token_page" value="{$currentTokenPageLabel}">
                        <div class="field">
                            <label for="revoke_token">Revoke by Token Value</label>
                            <textarea id="revoke_token" name="token" placeholder="Paste an access token or refresh token here"></textarea>
                        </div>
                        <button type="submit" class="secondary">Revoke token</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="stack">
                        <div>
                            <div class="eyebrow">Quick Links</div>
                            <h2>Mock API endpoints</h2>
                            <div class="subheading">Default client ID: {$defaultClientIdLabel}</div>
                        </div>
                        <div class="meta">
                            <span class="pill"><a class="inline-link" href="/connections" target="_blank" rel="noreferrer">GET /connections</a></span>
                            <span class="pill"><a class="inline-link" href="/api.xro/2.0/Accounts" target="_blank" rel="noreferrer">GET /Accounts</a></span>
                            <span class="pill"><a class="inline-link" href="/api.xro/2.0/Contacts" target="_blank" rel="noreferrer">GET /Contacts</a></span>
                            <span class="pill"><a class="inline-link" href="/api.xro/2.0/Invoices" target="_blank" rel="noreferrer">GET /Invoices</a></span>
                            <span class="pill"><a class="inline-link" href="/api.xro/2.0/TaxRates" target="_blank" rel="noreferrer">GET /TaxRates</a></span>
                            <span class="pill"><a class="inline-link" href="{$defaultAuthorizeUrl}" target="_blank" rel="noreferrer">Open authorize screen</a></span>
                        </div>
                    </div>
                </section>
            </div>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <div class="eyebrow">Issued Tokens</div>
                        <h2>Access and refresh pairs</h2>
                    </div>
                    <span class="pill">{$tokenPageLabel}</span>
                </div>
                <div class="table-meta">
                    <span class="muted">{$tokenRangeLabel}</span>
                    <span class="muted">Total tokens: {$totalTokensLabel}</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Client</th>
                                <th>User</th>
                                <th>Grant</th>
                                <th>Access Token</th>
                                <th>Refresh Token</th>
                                <th>Scope</th>
                                <th>Created</th>
                                <th>Expires</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$tokenRows}
                        </tbody>
                    </table>
                </div>
                {$tokenPagination}
            </section>
        </section>

        <section class="data-grid">
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <div class="eyebrow">Registered Clients</div>
                        <h2>OAuth client credentials</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Client ID</th>
                                <th>Client Secret</th>
                                <th>Redirect URI</th>
                                <th>Authorize</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$clientRows}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <div class="eyebrow">Mock Users</div>
                        <h2>Credential sets in SQLite</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Name</th>
                                <th>User ID</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$userRows}
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <section class="data-grid">
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <div class="eyebrow">Recent Contacts</div>
                        <h2>Customer records</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$contactRows}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <div class="eyebrow">Recent Invoices</div>
                        <h2>Transaction records</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$invoiceRows}
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</body>
</html>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @param array<string, string> $clientNamesById
     */
    private function renderTokenRows(array $tokens, array $clientNamesById, int $currentPage): string
    {
        if ($tokens === []) {
            return '<tr><td colspan="10" class="empty">No tokens have been issued yet.</td></tr>';
        }

        $rows = [];
        foreach ($tokens as $token) {
            $status = (string) ($token['status'] ?? 'ACTIVE');
            $clientId = (string) ($token['client_id'] ?? '');
            $clientName = $clientId !== '' && isset($clientNamesById[$clientId]) ? $clientNamesById[$clientId] : '';
            $clientCell = $clientId !== ''
                ? ($clientName !== ''
                    ? sprintf(
                        '%s<div class="muted"><code>%s</code></div>',
                        $this->escape($clientName),
                        $this->escape($clientId),
                    )
                    : sprintf('<code>%s</code>', $this->escape($clientId)))
                : '<span class="muted">No client</span>';
            $userEmail = isset($token['user_email']) && (string) $token['user_email'] !== ''
                ? (string) $token['user_email']
                : 'Unbound';
            $action = $status === 'ACTIVE'
                ? sprintf(
                    '<form method="post" action="/admin/tokens/revoke"><input type="hidden" name="token" value="%s"><input type="hidden" name="token_page" value="%s"><button type="submit" class="subtle-button">Revoke</button></form>',
                    $this->escape((string) ($token['access_token'] ?? '')),
                    $this->escape((string) $currentPage),
                )
                : '<span class="muted">No action</span>';

            $rows[] = sprintf(
                '<tr>
                    <td><span class="status status-%1$s">%1$s</span></td>
                    <td>%2$s</td>
                    <td>%3$s</td>
                    <td>%4$s</td>
                    <td><code>%5$s</code></td>
                    <td><code>%6$s</code></td>
                    <td>%7$s</td>
                    <td>%8$s</td>
                    <td>%9$s</td>
                    <td>%10$s</td>
                </tr>',
                $this->escape($status),
                $clientCell,
                $this->escape($userEmail),
                $this->escape((string) ($token['grant_type'] ?? '')),
                $this->escape((string) ($token['access_token'] ?? '')),
                $this->escape((string) ($token['refresh_token'] ?? '')),
                $this->escape((string) ($token['scope'] ?? '')),
                $this->escape((string) ($token['created_at'] ?? '')),
                $this->escape((string) ($token['expires_at'] ?? '')),
                $action,
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     * } $tokenPage
     */
    private function renderTokenPagination(Request $request, array $tokenPage): string
    {
        if ((int) $tokenPage['total'] === 0) {
            return '';
        }

        $page = (int) $tokenPage['page'];
        $totalPages = (int) $tokenPage['total_pages'];
        $previousLink = $page > 1
            ? sprintf('<a class="page-link" href="%s">Previous</a>', $this->escape($this->dashboardUrl($request, ['token_page' => $page - 1])))
            : '<span class="page-link disabled">Previous</span>';
        $nextLink = $page < $totalPages
            ? sprintf('<a class="page-link" href="%s">Next</a>', $this->escape($this->dashboardUrl($request, ['token_page' => $page + 1])))
            : '<span class="page-link disabled">Next</span>';
        $pageSummary = $this->escape(sprintf('Page %d of %d', $page, $totalPages));

        return <<<HTML
<div class="pagination">
    <span class="muted">{$pageSummary}</span>
    <div class="pagination-links">
        {$previousLink}
        {$nextLink}
    </div>
</div>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function renderUserOptions(array $users): string
    {
        $options = [];
        foreach ($users as $user) {
            $email = $this->escape((string) ($user['Email'] ?? ''));
            $name = trim((string) (($user['FirstName'] ?? '').' '.($user['LastName'] ?? '')));
            $label = $name !== '' ? $email.' - '.$this->escape($name) : $email;
            $options[] = sprintf('<option value="%s">%s</option>', $email, $label);
        }

        return implode("\n", $options);
    }

    /**
     * @param list<array<string, mixed>> $clients
     */
    private function renderClientOptions(array $clients, string $selectedClientId): string
    {
        if ($clients === []) {
            return '';
        }

        $options = [];
        foreach ($clients as $client) {
            $clientId = (string) ($client['ClientID'] ?? '');
            $selected = $clientId === $selectedClientId ? ' selected' : '';
            $label = trim((string) ($client['Name'] ?? '')) !== ''
                ? sprintf('%s - %s', (string) $client['Name'], $clientId)
                : $clientId;
            $options[] = sprintf(
                '<option value="%s"%s>%s</option>',
                $this->escape($clientId),
                $selected,
                $this->escape($label),
            );
        }

        return implode("\n", $options);
    }

    /**
     * @param list<array<string, mixed>> $clients
     */
    private function renderClientRows(array $clients, string $baseUrl, string $defaultScope): string
    {
        if ($clients === []) {
            return '<tr><td colspan="6" class="empty">No OAuth clients registered yet.</td></tr>';
        }

        $rows = [];
        foreach ($clients as $client) {
            $clientId = (string) ($client['ClientID'] ?? '');
            $registeredRedirectUri = (string) ($client['RedirectURI'] ?? '');
            $redirectUri = $this->store->concreteRedirectUri($registeredRedirectUri);
            $authorizeUrl = $baseUrl.'/identity/connect/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $defaultScope,
                'state' => 'admin-demo',
            ]);
            $description = trim((string) ($client['Description'] ?? ''));
            $descriptionMarkup = $description !== ''
                ? sprintf('<div class="muted">%s</div>', $this->escape($description))
                : '';

            $rows[] = sprintf(
                '<tr>
                    <td><div class="stack-tight"><strong>%s</strong>%s</div></td>
                    <td><code>%s</code></td>
                    <td><code>%s</code></td>
                    <td>%s</td>
                    <td><a class="inline-link" href="%s" target="_blank" rel="noreferrer">Open authorize</a></td>
                    <td>%s</td>
                </tr>',
                $this->escape((string) ($client['Name'] ?? '')),
                $descriptionMarkup,
                $this->escape($clientId),
                $this->escape((string) ($client['ClientSecret'] ?? '')),
                $this->escape($registeredRedirectUri),
                $this->escape($authorizeUrl),
                $this->escape((string) ($client['UpdatedAt'] ?? '')),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function renderUserRows(array $users): string
    {
        if ($users === []) {
            return '<tr><td colspan="4" class="empty">No mock users stored yet.</td></tr>';
        }

        $rows = [];
        foreach ($users as $user) {
            $rows[] = sprintf(
                '<tr>
                    <td>%s</td>
                    <td>%s %s</td>
                    <td><code>%s</code></td>
                    <td>%s</td>
                </tr>',
                $this->escape((string) ($user['Email'] ?? '')),
                $this->escape((string) ($user['FirstName'] ?? '')),
                $this->escape((string) ($user['LastName'] ?? '')),
                $this->escape((string) ($user['UserID'] ?? '')),
                $this->escape((string) ($user['UpdatedAt'] ?? '')),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param list<array<string, mixed>> $contacts
     */
    private function renderContactRows(array $contacts): string
    {
        if ($contacts === []) {
            return '<tr><td colspan="4" class="empty">No contacts stored yet.</td></tr>';
        }

        $rows = [];
        foreach ($contacts as $contact) {
            $rows[] = sprintf(
                '<tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                </tr>',
                $this->escape((string) ($contact['Name'] ?? '')),
                $this->escape((string) ($contact['EmailAddress'] ?? '')),
                $this->escape((string) ($contact['ContactStatus'] ?? '')),
                $this->escape((string) ($contact['UpdatedDateUTC'] ?? '')),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param list<array<string, mixed>> $invoices
     */
    private function renderInvoiceRows(array $invoices): string
    {
        if ($invoices === []) {
            return '<tr><td colspan="5" class="empty">No invoices stored yet.</td></tr>';
        }

        $rows = [];
        foreach ($invoices as $invoice) {
            $rows[] = sprintf(
                '<tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                </tr>',
                $this->escape((string) ($invoice['InvoiceNumber'] ?? '')),
                $this->escape((string) ($invoice['Contact']['Name'] ?? '')),
                $this->escape((string) ($invoice['Status'] ?? '')),
                $this->escape((string) ($invoice['Total'] ?? '')),
                $this->escape((string) ($invoice['UpdatedDateUTC'] ?? '')),
            );
        }

        return implode("\n", $rows);
    }

    private function redirectToDashboard(string $notice, string $level = 'success', array $params = []): RedirectResponse
    {
        $query = array_filter(
            array_merge($params, ['notice' => $notice, 'level' => $level]),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return new RedirectResponse('/admin?'.http_build_query($query));
    }

    private function dashboardUrl(Request $request, array $overrides = []): string
    {
        $params = $request->query->all();
        unset($params['notice'], $params['level']);

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
                continue;
            }

            $params[$key] = $value;
        }

        $query = http_build_query($params);

        return $query !== '' ? '/admin?'.$query : '/admin';
    }

    private function submittedTokenPage(Request $request): int
    {
        return max(1, (int) $request->request->get('token_page', 1));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
