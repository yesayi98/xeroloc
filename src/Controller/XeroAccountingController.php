<?php

namespace App\Controller;

use App\Service\XeroMockStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class XeroAccountingController
{
    public function __construct(private XeroMockStore $store)
    {
    }

    #[Route('/api.xro/2.0/Organisation', name: 'xero_organisation', methods: ['GET'])]
    public function organisation(): JsonResponse
    {
        return new JsonResponse([
            'Id' => $this->store->requestId(),
            'Status' => 'OK',
            'ProviderName' => 'xeroloc',
            'Organisations' => [
                [
                    'OrganisationID' => $this->store->tenantId(),
                    'Name' => 'Xero Mock Organisation',
                    'LegalName' => 'Xero Mock Organisation LLC',
                    'PaysTax' => true,
                    'Version' => 'US',
                    'OrganisationType' => 'COMPANY',
                    'BaseCurrency' => 'USD',
                    'CountryCode' => 'US',
                ],
            ],
        ]);
    }

    #[Route('/api.xro/2.0/Contacts', name: 'xero_contacts_list', methods: ['GET'])]
    public function contacts(): JsonResponse
    {
        return new JsonResponse([
            'Id' => $this->store->requestId(),
            'Status' => 'OK',
            'ProviderName' => 'xeroloc',
            'Contacts' => $this->store->contacts(),
        ]);
    }

    #[Route('/api.xro/2.0/Contacts', name: 'xero_contacts_create', methods: ['POST'])]
    public function createContact(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $contacts = $payload['Contacts'] ?? [$payload];

        $created = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $created[] = $this->store->createContact($contact);
        }

        return new JsonResponse([
            'Id' => $this->store->requestId(),
            'Status' => 'OK',
            'ProviderName' => 'xeroloc',
            'Contacts' => $created,
        ], 201);
    }

    #[Route('/api.xro/2.0/Invoices', name: 'xero_invoices_list', methods: ['GET'])]
    public function invoices(): JsonResponse
    {
        return new JsonResponse([
            'Id' => $this->store->requestId(),
            'Status' => 'OK',
            'ProviderName' => 'xeroloc',
            'Invoices' => $this->store->invoices(),
        ]);
    }

    #[Route('/api.xro/2.0/Invoices', name: 'xero_invoices_create', methods: ['POST'])]
    public function createInvoice(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $invoices = $payload['Invoices'] ?? [$payload];

        $created = [];
        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            $created[] = $this->store->createInvoice($invoice);
        }

        return new JsonResponse([
            'Id' => $this->store->requestId(),
            'Status' => 'OK',
            'ProviderName' => 'xeroloc',
            'Invoices' => $created,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
