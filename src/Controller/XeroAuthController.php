<?php

namespace App\Controller;

use App\Service\XeroMockStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class XeroAuthController
{
    public function __construct(private XeroMockStore $store)
    {
    }

    #[Route('/connect/token', name: 'xero_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $grantType = $request->request->get('grant_type', 'client_credentials');
        $scope = $request->request->get('scope', 'accounting.transactions accounting.contacts offline_access');
        $token = $this->store->createToken((string) $grantType, (string) $scope);

        return new JsonResponse([
            'access_token' => $token['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => 1800,
            'refresh_token' => $token['refresh_token'],
            'scope' => $scope,
        ]);
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
