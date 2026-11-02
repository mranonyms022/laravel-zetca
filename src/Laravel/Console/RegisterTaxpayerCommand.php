<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Devices\DeviceRegistrar;
use Illuminate\Console\Command;

final class RegisterTaxpayerCommand extends Command
{
    protected $signature = 'zatca:taxpayer:register
        {--name= : Company name (English)}
        {--name-ar= : Company name (Arabic)}
        {--legal-name= : Taxpayer legal name (CSR organization name)}
        {--vat= : 15-digit VAT number}
        {--group-tin= : 10-digit member TIN (VAT groups only)}
        {--crn= : Commercial registration number}
        {--branch-code= : Optional first branch code}
        {--branch-name= : Optional first branch name (CSR organization unit)}';

    protected $description = 'Register a taxpayer (company + VAT registration) and optionally its first branch';

    public function handle(DeviceRegistrar $registrar): int
    {
        try {
            $registration = $registrar->registerTaxpayer([
                'name_en' => (string) ($this->option('name') ?: $this->ask('Company name (English)')),
                'name_ar' => $this->option('name-ar') ?: null,
                'legal_name' => (string) ($this->option('legal-name') ?: $this->ask('Legal name')),
                'vat_number' => (string) ($this->option('vat') ?: $this->ask('VAT number (15 digits)')),
                'group_member_tin' => $this->option('group-tin') ?: null,
                'crn' => $this->option('crn') ?: null,
            ]);

            $this->info("Taxpayer registered: company #{$registration->company_id}, VAT registration #{$registration->id}.");

            if ($this->option('branch-code') && $this->option('branch-name')) {
                $branch = $registrar->registerBranch((int) $registration->company_id, (string) $this->option('branch-code'), (string) $this->option('branch-name'));
                $this->info("Branch registered: #{$branch->id}.");
            }
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
