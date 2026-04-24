<?php

namespace App\Controller;

use App\Service\XeroMockStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class XeroAuthController
{
    public function __construct(private XeroMockStore $store)
    {
    }

    #[Route('/identity/connect/authorize', name: 'xero_authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request): Response
    {
        $source = $request->isMethod('POST') ? $request->getPayload() : $request->query;
        $state = trim((string) $source->get('state', ''));
        $scope = trim((string) $source->get('scope', 'accounting.transactions accounting.contacts offline_access'));
        $clientId = trim((string) $source->get('client_id', $this->store->defaultClientId()));
        $responseType = trim((string) $source->get('response_type', 'code'));
        $loginHint = trim((string) $source->get('login_hint', ''));
        $email = trim((string) $source->get('email', $loginHint !== '' ? $loginHint : $source->get('username', '')));
        $password = trim((string) $source->get('password', ''));
        $redirectUri = trim((string) $source->get('redirect_uri', ''));
        $client = $this->store->findClientById($clientId);

        if ($client !== null && $redirectUri === '') {
            $redirectUri = (string) $client['RedirectURI'];
        }

        if ($responseType !== 'code') {
            return new JsonResponse([
                'error' => 'unsupported_response_type',
                'error_description' => 'Only response_type=code is supported by this mock.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($client === null) {
            return new Response($this->renderAuthorizePage(
                clientId: $clientId,
                client: null,
                redirectUri: $redirectUri,
                state: $state,
                scope: $scope,
                email: $email !== '' ? $email : $loginHint,
                error: 'Unknown OAuth client. Register it first in /admin.',
            ), Response::HTTP_BAD_REQUEST);
        }

        $registeredRedirectUri = trim((string) $client['RedirectURI']);
        if ($registeredRedirectUri !== '' && $redirectUri !== $registeredRedirectUri) {
            return new Response($this->renderAuthorizePage(
                clientId: $clientId,
                client: $client,
                redirectUri: $redirectUri,
                state: $state,
                scope: $scope,
                email: $email !== '' ? $email : $loginHint,
                error: 'redirect_uri does not match the registered client redirect URI.',
            ), Response::HTTP_BAD_REQUEST);
        }

        if ($email === '' || $password === '') {
            return new Response($this->renderAuthorizePage(
                clientId: $clientId,
                client: $client,
                redirectUri: $redirectUri,
                state: $state,
                scope: $scope,
                email: $email !== '' ? $email : $loginHint,
            ));
        }

        $user = $this->store->verifyMockUserCredentials($email, $password);
        if ($user === null) {
            return new Response($this->renderAuthorizePage(
                clientId: $clientId,
                client: $client,
                redirectUri: $redirectUri,
                state: $state,
                scope: $scope,
                email: $email,
                error: 'Invalid mock user credentials.',
            ), Response::HTTP_UNAUTHORIZED);
        }

        $code = $this->store->createAuthorizationCode(
            userId: (string) $user['UserID'],
            userEmail: (string) $user['Email'],
            clientId: $clientId,
            redirectUri: $redirectUri,
            scope: $scope,
        );

        if ($redirectUri !== '') {
            $query = http_build_query(array_filter([
                'code' => $code,
                'scope' => $scope,
                'state' => $state !== '' ? $state : null,
                'session_state' => 'mock-session-state',
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse($redirectUri.$separator.$query);
        }

        return new JsonResponse([
            'code' => $code,
            'scope' => $scope,
            'state' => $state,
            'client' => [
                'clientId' => $client['ClientID'],
                'name' => $client['Name'],
            ],
            'user' => [
                'email' => $user['Email'],
                'firstName' => $user['FirstName'],
                'lastName' => $user['LastName'],
            ],
        ]);
    }

    #[Route('/connect/token', name: 'xero_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $payload = $request->getPayload();
        $grantType = (string) $payload->get('grant_type', 'client_credentials');
        $scope = trim((string) $payload->get('scope', ''));
        if ($scope === '' && $grantType !== 'refresh_token') {
            $scope = 'accounting.transactions accounting.contacts offline_access';
        }

        $clientCredentials = $this->clientCredentials($request);
        $clientId = $clientCredentials['client_id'];
        $clientSecret = $clientCredentials['client_secret'];
        $client = $this->store->findClientById($clientId);

        if ($client === null) {
            return $this->invalidClient('Unknown client_id.');
        }

        if ($clientSecret !== '' && $clientSecret !== (string) $client['ClientSecret']) {
            return $this->invalidClient('Invalid client_secret.');
        }

        if ($grantType === 'authorization_code') {
            $authorization = $this->store->consumeAuthorizationCode(
                code: trim((string) $payload->get('code', '')),
                clientId: $clientId,
                redirectUri: trim((string) $payload->get('redirect_uri', '')),
            );
            if ($authorization === null) {
                return $this->invalidGrant('Invalid, expired or already-consumed authorization code.');
            }

            $scope = (string) $authorization['scope'];
            $token = $this->store->createToken(
                grantType: $grantType,
                scope: $scope,
                userId: (string) $authorization['user_id'],
                userEmail: (string) $authorization['user_email'],
                clientId: $clientId,
            );
        } elseif ($grantType === 'refresh_token') {
            $token = $this->store->refreshToken(
                refreshToken: trim((string) $payload->get('refresh_token', '')),
                scope: $scope !== '' ? $scope : null,
            );
            if ($token === null) {
                return $this->invalidGrant('Invalid refresh token.');
            }
        } else {
            $token = $this->store->createToken(
                grantType: $grantType,
                scope: $scope,
                clientId: $clientId,
            );
        }

        return new JsonResponse([
            'access_token' => $token['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => 1800,
            'refresh_token' => $token['refresh_token'],
            'scope' => $token['scope'],
        ]);
    }

    #[Route('/connect/revocation', name: 'xero_revocation', methods: ['POST'])]
    public function revocation(Request $request): Response
    {
        $token = trim((string) $request->getPayload()->get('token', ''));
        if ($token !== '') {
            $this->store->revokeToken($token);
        }

        return new Response('', Response::HTTP_OK);
    }

    #[Route('/connections', name: 'xero_connections', methods: ['GET'])]
    public function connections(): JsonResponse
    {
        return new JsonResponse([
            [
                'id' => 'mock-connection-0001',
                'tenantId' => $this->store->tenantId(),
                'tenantType' => 'ORGANISATION',
                'tenantName' => 'Xero Mock Organisation',
                'createdDateUtc' => '2026-01-01T00:00:00.0000000',
                'updatedDateUtc' => gmdate('Y-m-d\TH:i:s.0000000'),
            ],
        ]);
    }

    private function invalidGrant(string $description): JsonResponse
    {
        return new JsonResponse([
            'error' => 'invalid_grant',
            'error_description' => $description,
        ], Response::HTTP_BAD_REQUEST);
    }

    private function invalidClient(string $description): JsonResponse
    {
        return new JsonResponse([
            'error' => 'invalid_client',
            'error_description' => $description,
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function clientCredentials(Request $request): array
    {
        $payload = $request->getPayload();
        $clientId = trim((string) $payload->get('client_id', ''));
        $clientSecret = trim((string) $payload->get('client_secret', ''));
        $authorization = trim((string) $request->headers->get('Authorization', ''));

        if (str_starts_with(strtolower($authorization), 'basic ')) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$basicClientId, $basicClientSecret] = explode(':', $decoded, 2);
                if (trim($basicClientId) !== '') {
                    $clientId = trim($basicClientId);
                }
                $clientSecret = trim($basicClientSecret);
            }
        }

        if ($clientId === '') {
            $clientId = $this->store->defaultClientId();
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * @param array<string, mixed>|null $client
     */
    private function renderAuthorizePage(
        string $clientId,
        ?array $client,
        string $redirectUri,
        string $state,
        string $scope,
        string $email,
        ?string $error = null,
    ): string {
        $clientName = $client !== null
            ? $this->escape((string) $client['Name'])
            : 'Unknown client';
        $clientIdLabel = $this->escape($clientId);
        $redirectUriLabel = $this->escape($redirectUri !== '' ? $redirectUri : 'No redirect URI provided');
        $clientDescription = $client !== null && trim((string) $client['Description']) !== ''
            ? $this->escape((string) $client['Description'])
            : 'Authorize a registered OAuth client against the local Xero mock organisation.';
        $homepageUrl = $client !== null ? trim((string) ($client['HomepageURL'] ?? '')) : '';
        $homepageMarkup = $homepageUrl !== ''
            ? sprintf(
                '<a class="link" href="%s" target="_blank" rel="noreferrer">%s</a>',
                $this->escape($homepageUrl),
                $this->escape($homepageUrl),
            )
            : '<span class="muted">No homepage configured</span>';
        $scopeMarkup = $this->renderScopePills($scope);
        $emailValue = $this->escape($email);
        $redirectUriValue = $this->escape($redirectUri);
        $stateValue = $this->escape($state);
        $stateLabel = $this->escape($state !== '' ? $state : 'Not provided');
        $scopeValue = $this->escape($scope);
        $requestedScopeCount = $this->escape((string) count($this->scopeParts($scope)));
        $errorMarkup = $error !== null
            ? sprintf('<div class="error">%s</div>', $this->escape($error))
            : '';
        $formMarkup = $client !== null
            ? <<<HTML
                <form method="post" action="/identity/connect/authorize" class="auth-form">
                    <input type="hidden" name="client_id" value="{$clientIdLabel}">
                    <input type="hidden" name="redirect_uri" value="{$redirectUriValue}">
                    <input type="hidden" name="state" value="{$stateValue}">
                    <input type="hidden" name="scope" value="{$scopeValue}">
                    <input type="hidden" name="response_type" value="code">
                    <div class="field">
                        <label for="email">Mock User Email</label>
                        <input id="email" name="email" value="{$emailValue}" autocomplete="username">
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" autocomplete="current-password">
                    </div>
                    <button type="submit">Sign in and allow access</button>
                </form>
            HTML
            : <<<HTML
                <div class="error">
                    This client is not registered yet. Add it from <a href="/admin">/admin</a> and retry the authorize flow.
                </div>
            HTML;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xero Mock Authorize</title>
    <style>
        :root {
            --ink: #102a43;
            --ink-soft: #486581;
            --line: rgba(16, 42, 67, 0.12);
            --brand: #13b5ea;
            --brand-deep: #0077c8;
            --brand-soft: rgba(19, 181, 234, 0.12);
            --paper: rgba(255, 255, 255, 0.9);
            --surface: rgba(255, 255, 255, 0.76);
            --error: #b42318;
            --error-soft: rgba(180, 35, 24, 0.1);
            --shadow: 0 28px 80px rgba(0, 41, 78, 0.18);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font-family: "Avenir Next", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(19, 181, 234, 0.28), transparent 28%),
                radial-gradient(circle at bottom right, rgba(0, 119, 200, 0.18), transparent 24%),
                linear-gradient(180deg, #eef8fd, #d7ecf8 58%, #f6fbfe);
        }

        .shell {
            width: min(1160px, calc(100vw - 32px));
            min-height: 100vh;
            margin: 0 auto;
            padding: 28px 0;
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
            gap: 20px;
            align-items: center;
        }

        .panel {
            border-radius: 30px;
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .brand-panel {
            min-height: 720px;
            padding: 36px;
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.22), transparent 34%),
                linear-gradient(160deg, #0ea5e9, #0b7fcc 62%, #065f9f);
            color: #f4fbff;
            display: grid;
            align-content: space-between;
            gap: 24px;
        }

        .eyebrow {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            opacity: 0.9;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        h1 {
            font-size: clamp(38px, 5.6vw, 64px);
            line-height: 0.94;
            max-width: 10ch;
        }

        .lead {
            max-width: 48ch;
            line-height: 1.65;
            color: rgba(244, 251, 255, 0.84);
        }

        .tile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .tile {
            padding: 18px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
        }

        .tile .label,
        .label {
            display: block;
            margin-bottom: 8px;
            font-size: 11px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .auth-panel {
            background: var(--paper);
            padding: 34px;
            display: grid;
            gap: 20px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--brand), var(--brand-deep));
            color: #fff;
            font-size: 20px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.34);
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .meta-card,
        .scope-card {
            padding: 16px 18px;
            border-radius: 20px;
            background: var(--surface);
            border: 1px solid var(--line);
        }

        .muted {
            color: var(--ink-soft);
        }

        .link {
            color: var(--brand-deep);
            text-decoration: none;
        }

        .link:hover {
            text-decoration: underline;
        }

        .scope-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .scope-pill {
            padding: 9px 12px;
            border-radius: 999px;
            background: var(--brand-soft);
            border: 1px solid rgba(19, 181, 234, 0.16);
            font-size: 13px;
            color: var(--ink);
        }

        .auth-form {
            display: grid;
            gap: 14px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--ink-soft);
        }

        input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.94);
            font: inherit;
            color: var(--ink);
        }

        button {
            border: none;
            border-radius: 18px;
            padding: 15px 18px;
            font: 700 15px/1 "Avenir Next", "Segoe UI", sans-serif;
            color: #fff;
            background: linear-gradient(135deg, var(--brand), var(--brand-deep));
            cursor: pointer;
            box-shadow: 0 16px 32px rgba(11, 127, 204, 0.24);
        }

        .note {
            padding: 15px 16px;
            border-radius: 18px;
            background: rgba(16, 42, 67, 0.05);
            border: 1px solid var(--line);
            line-height: 1.55;
        }

        .error {
            padding: 15px 16px;
            border-radius: 18px;
            background: var(--error-soft);
            border: 1px solid rgba(180, 35, 24, 0.12);
            color: var(--error);
            line-height: 1.5;
        }

        @media (max-width: 920px) {
            .shell {
                width: min(100vw - 20px, 1160px);
                padding: 20px 0;
                grid-template-columns: 1fr;
                align-items: stretch;
            }

            .brand-panel {
                min-height: auto;
            }

            .tile-grid,
            .meta-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel brand-panel">
            <div>
                <div class="eyebrow">xero mock identity</div>
                <h1>Authorize {$clientName} for your sandbox org</h1>
            </div>
            <p class="lead">This screen mirrors the Xero-style authorize flow, but it uses your local mock users and registered OAuth clients from the built-in dashboard.</p>
            <div class="tile-grid">
                <article class="tile">
                    <span class="label">Tenant</span>
                    <strong>Xero Mock Organisation</strong>
                </article>
                <article class="tile">
                    <span class="label">Requested Scopes</span>
                    <strong>{$requestedScopeCount}</strong>
                </article>
                <article class="tile">
                    <span class="label">Client ID</span>
                    <strong>{$clientIdLabel}</strong>
                </article>
                <article class="tile">
                    <span class="label">Homepage</span>
                    {$homepageMarkup}
                </article>
            </div>
        </section>

        <section class="panel auth-panel">
            <div class="brand">
                <span class="brand-mark">X</span>
                <span>xero mock</span>
            </div>
            <div>
                <div class="eyebrow muted">Connect App</div>
                <h2>{$clientName}</h2>
                <p class="muted">{$clientDescription}</p>
            </div>
            <div class="meta-grid">
                <article class="meta-card">
                    <span class="label">Redirect URI</span>
                    <strong>{$redirectUriLabel}</strong>
                </article>
                <article class="meta-card">
                    <span class="label">State</span>
                    <strong>{$stateLabel}</strong>
                </article>
            </div>
            <article class="scope-card">
                <span class="label">Requested Permissions</span>
                <div class="scope-list">
                    {$scopeMarkup}
                </div>
            </article>
            {$errorMarkup}
            {$formMarkup}
            <div class="note">
                Default mock users: <strong>alice@example.test / alice-pass</strong> and <strong>bob@example.test / bob-pass</strong>.<br>
                Add more credentials with <code>docker compose exec -T app php bin/console app:mock-user:add</code>.
            </div>
        </section>
    </main>
</body>
</html>
HTML;
    }

    private function renderScopePills(string $scope): string
    {
        $items = [];
        foreach ($this->scopeParts($scope) as $part) {
            $items[] = sprintf('<span class="scope-pill">%s</span>', $this->escape($part));
        }

        return implode("\n", $items);
    }

    /**
     * @return list<string>
     */
    private function scopeParts(string $scope): array
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
