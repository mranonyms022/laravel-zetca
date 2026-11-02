<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Contracts\Device\ComplianceCheckRunner;
use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use CodingTriplets\Zatca\Core\Crypto\EcPrivateKey;
use CodingTriplets\Zatca\Core\Device\ComplianceSampleFactory;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceHash;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Invoice\B2B\Generator\StandardInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\B2C\Generator\SimplifiedInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\Shared\Chain\ChainPosition;
use CodingTriplets\Zatca\Core\Invoice\Shared\Generation\SigningCredentials;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\CertificateInfo;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Invoices\SellerResolver;
use Illuminate\Support\Str;

/**
 * Generates the compliance sample documents with the device's compliance CSID and submits them to
 * ZATCA's compliance-check endpoint. Samples have their own throw-away chain (ICV 1.., PIH from genesis):
 * the device's production chain is not touched.
 */
final class FatooraComplianceCheckRunner implements ComplianceCheckRunner
{
    public function __construct(
        private readonly FatooraClientFactory $clients,
        private readonly SellerResolver $sellers = new SellerResolver(),
        private readonly ComplianceSampleFactory $samples = new ComplianceSampleFactory(),
        private readonly StandardInvoiceGenerator $standard = new StandardInvoiceGenerator(),
        private readonly SimplifiedInvoiceGenerator $simplified = new SimplifiedInvoiceGenerator(),
    ) {
    }

    public function run(array $device, CsidCredentials $compliance, #[\SensitiveParameter] string $privateKeyPem): array
    {
        $model = Device::query()->findOrFail($device['id']);
        $environment = $model->environment instanceof Environment ? $model->environment : Environment::from((string) $model->environment);
        $client = $this->clients->for($environment);

        $credentials = new SigningCredentials(
            CertificateInfo::fromCertificate($compliance->binarySecurityToken),
            EcPrivateKey::fromPem($privateKeyPem),
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Riyadh'));
        $samples = $this->samples->samples(
            InvoiceTypeFlags::fromString((string) $model->invoice_type_flags),
            $this->sellers->forDevice($model),
            $now->modify('-1 minute'),
        );

        $results = [];
        $previous = InvoiceHash::genesis();
        $counter = 0;

        foreach ($samples as $name => $payload) {
            $position = new ChainPosition(++$counter, $previous);
            $uuid = (string) Str::uuid();

            try {
                $generated = $payload['workflow'] === Workflow::B2B->value
                    ? $this->standard->generate($payload, $position, $uuid, $credentials, $now)
                    : $this->simplified->generate($payload, $position, $uuid, $credentials, $now);
            } catch (InvoiceValidationException $e) {
                $results[$name] = ['passed' => false, 'status' => 'LOCAL_VALIDATION_FAILED', 'errors' => array_map(
                    static fn (array $err) => ['code' => $err['code'], 'message' => "{$err['field']}: {$err['message']}"],
                    $e->result->errors(),
                )];

                continue;
            }
            $previous = InvoiceHash::fromBase64($generated->hash());

            try {
                $result = $client->complianceInvoiceCheck($generated->xml(), $generated->hash(), $generated->uuid, $compliance);
                $results[$name] = [
                    'passed' => $result->isAccepted(),
                    'status' => $result->reportingStatus ?? $result->clearanceStatus ?? $result->validationStatus,
                    'errors' => array_map(static fn (array $m) => ['code' => $m['code'], 'message' => $m['message']], $result->errors),
                    'warnings' => array_map(static fn (array $m) => ['code' => $m['code'], 'message' => $m['message']], $result->warnings),
                ];
            } catch (FatooraApiException $e) {
                $results[$name] = ['passed' => false, 'status' => 'API_ERROR', 'errors' => [['code' => (string) $e->httpStatus, 'message' => $e->getMessage()]]];
            }
        }

        return $results;
    }
}
