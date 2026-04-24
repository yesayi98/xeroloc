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

    #[Route('/identity/connect/authorize', name: 'xero_authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $redirectUri = (string) $request->query->get('redirect_uri', '');
        $state = (string) $request->query->get('state', '');
        $scope = (string) $request->query->get('scope', 'accounting.transactions accounting.contacts offline_access');
        $code = 'mock_auth_code_'.bin2hex(random_bytes(8));

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
        ]);
    }

    #[Route('/connect/token', name: 'xero_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $payload = $request->getPayload();
        $grantType = $payload->get('grant_type', 'client_credentials');
        $scope = $payload->get('scope', 'accounting.transactions accounting.contacts offline_access');
        $token = $this->store->createToken((string) $grantType, (string) $scope);

        return new JsonResponse([
            'access_token' => $token['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => 1800,
            'refresh_token' => $token['refresh_token'],
            'scope' => $scope,
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
}
