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
        return $this->xeroResponse('Organisations', [
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
        ]);
    }

    #[Route('/api.xro/2.0/Accounts', name: 'xero_accounts_list', methods: ['GET'])]
    public function accounts(): JsonResponse
    {
        return $this->xeroResponse('Accounts', $this->store->accounts());
    }

    #[Route('/api.xro/2.0/Contacts', name: 'xero_contacts_list', methods: ['GET'])]
    public function contacts(): JsonResponse
    {
        return $this->xeroResponse('Contacts', $this->store->contacts());
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

        return $this->xeroResponse('Contacts', $created, 201);
    }

    #[Route('/api.xro/2.0/Invoices', name: 'xero_invoices_list', methods: ['GET'])]
    public function invoices(): JsonResponse
    {
        return $this->xeroResponse('Invoices', $this->store->invoices());
    }

    #[Route('/api.xro/2.0/Invoices', name: 'xero_invoices_upsert', methods: ['POST', 'PUT'])]
    #[Route('/api.xro/2.0/Invoices/{invoiceId}', name: 'xero_invoices_upsert_by_id', methods: ['POST', 'PUT'])]
    public function upsertInvoice(Request $request, ?string $invoiceId = null): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $invoices = $payload['Invoices'] ?? [$payload];
        $statusCode = $request->isMethod('POST') && $invoiceId === null ? 201 : 200;

        $created = [];
        foreach ($invoices as $index => $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            if ($invoiceId !== null && $index === 0) {
                $invoice['InvoiceID'] = $invoiceId;
            }
            $created[] = $this->store->createInvoice($invoice);
        }

        return $this->xeroResponse('Invoices', $created, $statusCode);
    }

    #[Route('/api.xro/2.0/Payments', name: 'xero_payments_upsert', methods: ['POST', 'PUT'])]
    #[Route('/api.xro/2.0/Payments/{paymentId}', name: 'xero_payments_upsert_by_id', methods: ['POST', 'PUT'])]
    public function upsertPayment(Request $request, ?string $paymentId = null): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $payments = $payload['Payments'] ?? [$payload];
        $statusCode = $request->isMethod('POST') && $paymentId === null ? 201 : 200;

        $saved = [];
        foreach ($payments as $index => $payment) {
            if (!is_array($payment)) {
                continue;
            }
            if ($paymentId !== null && $index === 0) {
                $payment['PaymentID'] = $paymentId;
            }
            $saved[] = $this->store->createPayment($payment);
        }

        return $this->xeroResponse('Payments', $saved, $statusCode);
    }

    #[Route('/api.xro/2.0/TaxRates', name: 'xero_tax_rates_list', methods: ['GET'])]
    public function taxRates(): JsonResponse
    {
        return $this->xeroResponse('TaxRates', $this->store->taxRates());
    }

    #[Route('/api.xro/2.0/TrackingCategories', name: 'xero_tracking_categories_list', methods: ['GET'])]
    public function trackingCategories(): JsonResponse
    {
        return $this->xeroResponse('TrackingCategories', $this->store->trackingCategories());
    }

    #[Route('/api.xro/2.0/ManualJournals', name: 'xero_manual_journals_upsert', methods: ['PUT'])]
    public function upsertManualJournals(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $manualJournals = $payload['ManualJournals'] ?? [$payload];

        $saved = [];
        foreach ($manualJournals as $manualJournal) {
            if (!is_array($manualJournal)) {
                continue;
            }
            $saved[] = $this->store->createManualJournal($manualJournal);
        }

        return $this->xeroResponse('ManualJournals', $saved);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function xeroResponse(string $resourceName, array $records, int $statusCode = 200): JsonResponse
    {
        return new JsonResponse([
            'Id' => $this->store->requestId(),
            'Status' => 'OK',
            'ProviderName' => 'xeroloc',
            $resourceName => $records,
        ], $statusCode);
    }
}
