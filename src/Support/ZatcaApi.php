<?php

namespace delwarhossaindev\Zatca\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;

/**
 * Thin client for the ZATCA Fatoora e-invoicing APIs.
 */
class ZatcaApi
{
    public function __construct(private string $environment)
    {
        if (! config("zatca.environments.{$environment}.url")) {
            throw new ZatcaException("Unknown ZATCA environment \"$environment\".");
        }
    }

    /**
     * Onboarding step 1: request a Compliance CSID with the CSR and the OTP from the Fatoora portal.
     */
    public function complianceCsid(string $csr, string $otp): Response
    {
        return $this->request()
            ->withHeaders(['OTP' => $otp])
            ->post('/compliance', ['csr' => base64_encode($csr)]);
    }

    /**
     * Onboarding step 2: validate sample invoices against the compliance CSID.
     */
    public function complianceInvoice(string $certificate, string $secret, array $payload): Response
    {
        return $this->request()
            ->withBasicAuth($certificate, $secret)
            ->post('/compliance/invoices', $payload);
    }

    /**
     * Onboarding step 3: exchange the compliance CSID for a Production CSID.
     */
    public function productionCsid(string $certificate, string $secret, string $complianceRequestId): Response
    {
        return $this->request()
            ->withBasicAuth($certificate, $secret)
            ->post('/production/csids', ['compliance_request_id' => $complianceRequestId]);
    }

    /**
     * Renew an expiring Production CSID with a new CSR and a new OTP.
     */
    public function renewProductionCsid(string $certificate, string $secret, string $csr, string $otp): Response
    {
        return $this->request()
            ->withBasicAuth($certificate, $secret)
            ->withHeaders(['OTP' => $otp])
            ->patch('/production/csids', ['csr' => base64_encode($csr)]);
    }

    /**
     * Report a simplified (B2C) invoice within 24 hours.
     */
    public function report(string $certificate, string $secret, array $payload): Response
    {
        return $this->request()
            ->withBasicAuth($certificate, $secret)
            ->withHeaders(['Clearance-Status' => '0'])
            ->post('/invoices/reporting/single', $payload);
    }

    /**
     * Clear a standard (B2B) invoice before sharing it with the buyer.
     */
    public function clear(string $certificate, string $secret, array $payload): Response
    {
        return $this->request()
            ->withBasicAuth($certificate, $secret)
            ->withHeaders(['Clearance-Status' => '1'])
            ->post('/invoices/clearance/single', $payload);
    }

    /**
     * Reduce a ZATCA response to status, HTTP code, errors and warnings.
     *
     * @return array{status: string, http: int, errors: array, warnings: array}
     */
    public static function summarize(Response $response): array
    {
        $json = $response->json() ?? [];
        $results = $json['validationResults'] ?? [];

        $status = $json['reportingStatus']
            ?? $json['clearanceStatus']
            ?? ($results['status'] ?? null)
            ?? ($response->successful() ? 'OK' : 'ERROR');

        $messages = fn ($items) => collect($items ?? [])
            ->map(fn ($item) => trim(($item['code'] ?? '').': '.($item['message'] ?? ''), ': '))
            ->values()
            ->all();

        $errors = $messages($results['errorMessages'] ?? []);

        if (! $response->successful() && empty($errors)) {
            $errors = $messages($json['errors'] ?? []) ?: [$json['message'] ?? $response->body() ?: 'HTTP '.$response->status()];
        }

        return [
            'status' => $status,
            'http' => $response->status(),
            'errors' => $errors,
            'warnings' => $messages($results['warningMessages'] ?? []),
        ];
    }

    /**
     * Server error or rate limit: ZATCA did not validate the document, so it can be sent again.
     */
    public static function isTemporaryFailure(Response $response): bool
    {
        return $response->serverError() || $response->status() === 429;
    }

    public static function ensureSuccessful(Response $response, string $step): void
    {
        if ($response->successful()) {
            return;
        }

        $json = $response->json() ?? [];
        $message = collect($json['errors'] ?? [])->map(fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : $e)->implode('; ')
            ?: ($json['message'] ?? $json['errorMessage'] ?? $response->body());

        throw new ZatcaException("$step request failed (HTTP {$response->status()}): $message");
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config("zatca.environments.{$this->environment}.url"))
            ->timeout((int) config('zatca.timeout', 60))
            ->acceptJson()
            ->withHeaders([
                'Accept-Language' => 'en',
                'Accept-Version' => 'V2',
            ]);
    }
}
