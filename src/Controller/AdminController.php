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
        $allContacts = $this->store->contacts();
        $allInvoices = $this->store->invoices();
        $contacts = array_slice($allContacts, 0, 5);
        $invoices = array_slice($allInvoices, 0, 5);
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
            tokens: $tokens,
            contacts: $contacts,
            invoices: $invoices,
            tokenCounts: $tokenCounts,
            contactCount: count($allContacts),
            invoiceCount: count($allInvoices),
        ));
    }

    #[Route('/admin/tokens/create', name: 'admin_tokens_create', methods: ['POST'])]
    public function createToken(Request $request): RedirectResponse
    {
        $grantType = trim((string) $request->request->get('grant_type', 'client_credentials'));
        $scope = trim((string) $request->request->get('scope', 'accounting.transactions accounting.contacts offline_access'));

        $this->store->createToken(
            $grantType !== '' ? $grantType : 'client_credentials',
            $scope !== '' ? $scope : 'accounting.transactions accounting.contacts offline_access',
        );

        return $this->redirectToDashboard('Issued a new mock token.');
    }

    #[Route('/admin/tokens/revoke', name: 'admin_tokens_revoke', methods: ['POST'])]
    public function revokeToken(Request $request): RedirectResponse
    {
        $token = trim((string) $request->request->get('token', ''));
        if ($token === '') {
            return $this->redirectToDashboard('Provide an access or refresh token to revoke.', 'warning');
        }

        if ($this->store->revokeToken($token)) {
            return $this->redirectToDashboard('Token revoked.');
        }

        return $this->redirectToDashboard('Token not found or already revoked.', 'warning');
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @param list<array<string, mixed>> $contacts
     * @param list<array<string, mixed>> $invoices
     * @param array{ACTIVE: int, REVOKED: int, EXPIRED: int} $tokenCounts
     */
    private function renderDashboard(
        Request $request,
        array $tokens,
        array $contacts,
        array $invoices,
        array $tokenCounts,
        int $contactCount,
        int $invoiceCount,
    ): string {
        $notice = trim((string) $request->query->get('notice', ''));
        $level = trim((string) $request->query->get('level', 'success'));
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $tenantId = $this->escape($this->store->tenantId());
        $baseUrlLabel = $this->escape($baseUrl);
        $activeTokenCount = $this->escape((string) $tokenCounts['ACTIVE']);
        $revokedTokenCount = $this->escape((string) $tokenCounts['REVOKED']);
        $contactCountLabel = $this->escape((string) $contactCount);
        $invoiceCountLabel = $this->escape((string) $invoiceCount);

        $noticeMarkup = $notice !== ''
            ? sprintf(
                '<div class="notice notice-%s">%s</div>',
                $this->escape($level),
                $this->escape($notice),
            )
            : '';

        $tokenRows = $this->renderTokenRows($tokens);
        $contactRows = $this->renderContactRows($contacts);
        $invoiceRows = $this->renderInvoiceRows($invoices);

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
            --surface-strong: #fffaf2;
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

        a { color: inherit; }

        .shell {
            width: min(1240px, calc(100vw - 32px));
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
            max-width: 10ch;
        }

        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .hero-grid, .stats, .admin-grid {
            display: grid;
            gap: 16px;
        }

        .hero-grid {
            grid-template-columns: 1.35fr 0.9fr;
            align-items: start;
        }

        .stats {
            grid-template-columns: repeat(4, minmax(0, 1fr));
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
            grid-template-columns: 0.96fr 1.24fr;
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

        .stack {
            display: grid;
            gap: 12px;
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

        .warning-box {
            padding: 16px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(154, 52, 18, 0.12), rgba(154, 52, 18, 0.06));
            border: 1px solid rgba(154, 52, 18, 0.18);
        }

        .link-grid {
            display: grid;
            gap: 10px;
        }

        .endpoint {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid var(--border);
            text-decoration: none;
        }

        .endpoint:hover {
            transform: translateY(-1px);
        }

        .method {
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--accent);
            font-weight: 700;
        }

        .table-wrap {
            overflow: auto;
            border-radius: 16px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.72);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
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

        .data-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .empty {
            padding: 24px;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 980px) {
            .hero-grid, .admin-grid, .stats, .data-grid, .form-row {
                grid-template-columns: 1fr;
            }

            .shell {
                width: min(100vw - 20px, 1240px);
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
                    <p>Use this screen to mint, inspect and revoke mock OAuth tokens, then jump straight into the accounting endpoints that the service exposes.</p>
                    <div class="meta">
                        <span class="pill">Tenant: <strong>{$tenantId}</strong></span>
                        <span class="pill">Base URL: <strong>{$baseUrlLabel}</strong></span>
                        <span class="pill">Connections endpoint is live</span>
                    </div>
                </div>
                <div class="warning-box">
                    <h3>Local-first admin surface</h3>
                    <p>This page has no separate authentication layer. Keep it on local or isolated dev environments only.</p>
                </div>
            </div>
            {$noticeMarkup}
            <div class="stats">
                <article class="card">
                    <div class="eyebrow">Active Tokens</div>
                    <div class="metric">{$activeTokenCount}</div>
                    <p>Live access or refresh pairs that have not been revoked yet.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">Revoked Tokens</div>
                    <div class="metric">{$revokedTokenCount}</div>
                    <p>Tokens revoked via `/connect/revocation` or the admin action.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">Contacts</div>
                    <div class="metric">{$contactCountLabel}</div>
                    <p>Current records available through `/api.xro/2.0/Contacts`.</p>
                </article>
                <article class="card">
                    <div class="eyebrow">Invoices</div>
                    <div class="metric">{$invoiceCountLabel}</div>
                    <p>Current records available through `/api.xro/2.0/Invoices`.</p>
                </article>
            </div>
        </section>

        <section class="admin-grid">
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <div class="eyebrow">Token Workshop</div>
                        <h2>Issue or revoke OAuth tokens</h2>
                    </div>
                </div>

                <form method="post" action="/admin/tokens/create" class="stack">
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
                            <label for="scope">Scope</label>
                            <input id="scope" name="scope" value="accounting.transactions accounting.contacts offline_access">
                        </div>
                    </div>
                    <button type="submit">Issue mock token</button>
                </form>

                <form method="post" action="/admin/tokens/revoke" class="stack">
                    <div class="field">
                        <label for="revoke_token">Revoke by Token Value</label>
                        <textarea id="revoke_token" name="token" placeholder="Paste an access token or refresh token here"></textarea>
                    </div>
                    <button type="submit" class="secondary">Revoke token</button>
                </form>

                <div class="stack">
                    <div>
                        <div class="eyebrow">Quick Links</div>
                        <h2>Read-only mock endpoints</h2>
                    </div>
                    <div class="link-grid">
                        <a class="endpoint" href="/connections" target="_blank" rel="noreferrer">
                            <span><span class="method">GET</span><br><strong>/connections</strong></span>
                            <span class="muted">tenant binding</span>
                        </a>
                        <a class="endpoint" href="/api.xro/2.0/Accounts" target="_blank" rel="noreferrer">
                            <span><span class="method">GET</span><br><strong>/api.xro/2.0/Accounts</strong></span>
                            <span class="muted">chart of accounts</span>
                        </a>
                        <a class="endpoint" href="/api.xro/2.0/Contacts" target="_blank" rel="noreferrer">
                            <span><span class="method">GET</span><br><strong>/api.xro/2.0/Contacts</strong></span>
                            <span class="muted">customer directory</span>
                        </a>
                        <a class="endpoint" href="/api.xro/2.0/Invoices" target="_blank" rel="noreferrer">
                            <span><span class="method">GET</span><br><strong>/api.xro/2.0/Invoices</strong></span>
                            <span class="muted">invoice ledger</span>
                        </a>
                        <a class="endpoint" href="/api.xro/2.0/TaxRates" target="_blank" rel="noreferrer">
                            <span><span class="method">GET</span><br><strong>/api.xro/2.0/TaxRates</strong></span>
                            <span class="muted">tax setup</span>
                        </a>
                        <a class="endpoint" href="/api.xro/2.0/TrackingCategories" target="_blank" rel="noreferrer">
                            <span><span class="method">GET</span><br><strong>/api.xro/2.0/TrackingCategories</strong></span>
                            <span class="muted">tracking setup</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <div class="eyebrow">Issued Tokens</div>
                        <h2>Access and refresh pairs</h2>
                    </div>
                    <span class="pill">Latest first</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Status</th>
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
            </div>
        </section>

        <section class="data-grid">
            <div class="panel">
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
            </div>

            <div class="panel">
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
            </div>
        </section>
    </main>
</body>
</html>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $tokens
     */
    private function renderTokenRows(array $tokens): string
    {
        if ($tokens === []) {
            return '<tr><td colspan="8" class="empty">No tokens have been issued yet.</td></tr>';
        }

        $rows = [];
        foreach ($tokens as $token) {
            $status = (string) ($token['status'] ?? 'ACTIVE');
            $action = $status === 'ACTIVE'
                ? sprintf(
                    '<form method="post" action="/admin/tokens/revoke"><input type="hidden" name="token" value="%s"><button type="submit" class="subtle-button">Revoke</button></form>',
                    $this->escape((string) ($token['access_token'] ?? '')),
                )
                : '<span class="muted">No action</span>';

            $rows[] = sprintf(
                '<tr>
                    <td><span class="status status-%1$s">%1$s</span></td>
                    <td>%2$s</td>
                    <td><code>%3$s</code></td>
                    <td><code>%4$s</code></td>
                    <td>%5$s</td>
                    <td>%6$s</td>
                    <td>%7$s</td>
                    <td>%8$s</td>
                </tr>',
                $this->escape($status),
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

    private function redirectToDashboard(string $notice, string $level = 'success'): RedirectResponse
    {
        return new RedirectResponse(sprintf(
            '/admin?notice=%s&level=%s',
            rawurlencode($notice),
            rawurlencode($level),
        ));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
