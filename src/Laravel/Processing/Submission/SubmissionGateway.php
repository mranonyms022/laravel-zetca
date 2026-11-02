<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Submission;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Fatoora\InvoiceSubmissionResult;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;

/** Sends a signed document to ZATCA: B2B → clearance, B2C → reporting. Never the other way round. */
final class SubmissionGateway
{
    public function __construct(private readonly FatooraClientFactory $clients)
    {
    }

    /** @throws \CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException */
    public function submit(Workflow $workflow, Environment $environment, string $signedXml, string $hash, string $uuid, CsidCredentials $credentials): InvoiceSubmissionResult
    {
        $client = $this->clients->for($environment);

        return $workflow === Workflow::B2B
            ? $client->clearInvoice($signedXml, $hash, $uuid, $credentials)
            : $client->reportInvoice($signedXml, $hash, $uuid, $credentials);
    }

    public static function endpoint(Workflow $workflow): string
    {
        return $workflow === Workflow::B2B ? '/invoices/clearance/single' : '/invoices/reporting/single';
    }
}
