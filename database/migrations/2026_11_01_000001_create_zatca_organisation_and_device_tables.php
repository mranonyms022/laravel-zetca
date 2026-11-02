<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends ZatcaMigration {
    public function up(): void
    {
        $this->create('companies', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->string('name_en');
            $t->string('name_ar')->nullable();
            $t->string('crn', 64)->nullable();
            $t->json('address')->nullable();          // building no, street, district, city, postal, additional no, country
            $t->string('status', 32)->default('active');
            $t->json('settings')->nullable();
            $this->datetimes($t);
            $this->softDeletes($t);
            $t->index(['tenant_id', 'status'], Tables::index('co_tenant_status'));
        });

        $this->create('vat_registrations', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('company_id');
            $t->string('vat_number', 15);
            $t->boolean('is_group')->default(false);
            $t->string('group_member_tin', 10)->default('');   // '' when not a VAT group member
            $t->string('legal_name');
            $t->json('address')->nullable();
            $t->string('seller_id_scheme', 8)->nullable();       // BT-29-1 (CRN, MOM, MLS, 700, SAG, OTH)
            $t->string('seller_id_value', 64)->nullable();
            $t->string('status', 32)->default('active');
            $this->datetimes($t);
            $t->unique(['tenant_id', 'vat_number', 'group_member_tin'], Tables::index('vat_uk'));
            $t->index('company_id', Tables::index('vat_company'));
            Tables::foreign($t, 'company_id', 'companies', 'vat_company');
        });

        $this->create('branches', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('company_id');
            $t->string('code', 64);
            $t->string('name');
            $t->json('address_override')->nullable();
            $this->datetimes($t);
            $this->softDeletes($t);
            $t->unique(['company_id', 'code'], Tables::index('br_uk'));
            Tables::foreign($t, 'company_id', 'companies', 'br_company');
        });

        $this->create('devices', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('vat_registration_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->uuid('device_uuid');
            $t->string('name');                               // CSR CN
            $t->string('serial_number');                      // 1-..|2-..|3-..
            $t->string('invoice_type_flags', 4);              // TSCZ
            $t->string('environment', 16);
            $t->string('industry')->nullable();               // CSR businessCategory
            $t->string('location')->nullable();               // CSR registeredAddress
            $t->string('lifecycle_status', 32);
            $t->unsignedBigInteger('active_certificate_id')->nullable(); // pointer, enforced in repository
            $t->dateTime('certificate_expiry')->nullable();
            $t->dateTime('submissions_paused_until')->nullable();
            $t->dateTime('decommissioned_at')->nullable();
            $this->datetimes($t);
            $t->unique('device_uuid', Tables::index('dev_uuid_uk'));
            $t->index(['company_id', 'lifecycle_status'], Tables::index('dev_company_status'));
            $t->index('certificate_expiry', Tables::index('dev_cert_expiry'));
            $t->index(['tenant_id', 'environment'], Tables::index('dev_tenant_env'));
            Tables::foreign($t, 'company_id', 'companies', 'dev_company');
            Tables::foreign($t, 'vat_registration_id', 'vat_registrations', 'dev_vat');
            Tables::foreign($t, 'branch_id', 'branches', 'dev_branch');
        });

        $this->create('device_keys', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->string('environment', 16);
            $t->string('algorithm', 32);                      // secp256k1
            $t->text('encrypted_private_key');
            $t->text('public_key');
            $t->text('csr');                                  // public PKCS#10
            $t->string('key_fingerprint', 64);
            $t->string('vault_key_id', 64);
            $t->unsignedBigInteger('generated_by')->nullable();
            $this->datetimes($t);
            $t->index(['device_id', 'created_at'], Tables::index('dk_device'));
            Tables::foreign($t, 'device_id', 'devices', 'dk_device');
        });

        $this->create('certificates', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('device_key_id');
            $t->unsignedBigInteger('device_id');
            $t->string('environment', 16);
            $t->string('type', 16);                           // compliance | production
            $t->unsignedInteger('version');
            $t->text('certificate');                          // binarySecurityToken (public)
            $t->text('encrypted_secret');
            $t->string('vault_key_id', 64);
            $t->string('request_id', 64)->nullable();         // compliance requestID
            $t->string('serial', 128)->nullable();
            $t->string('fingerprint', 64)->nullable();
            $t->dateTime('valid_from')->nullable();
            $t->dateTime('valid_until')->nullable();
            $t->string('status', 16);
            $t->dateTime('activated_at')->nullable();
            $t->dateTime('superseded_at')->nullable();
            $this->datetimes($t);
            $t->unique(['device_id', 'environment', 'type', 'version'], Tables::index('cert_uk'));
            $t->index(['device_id', 'environment', 'type', 'status'], Tables::index('cert_active'));
            $t->index('valid_until', Tables::index('cert_valid_until'));
            Tables::foreign($t, 'device_key_id', 'device_keys', 'cert_key');
            Tables::foreign($t, 'device_id', 'devices', 'cert_device');
        });

        $this->create('device_onboardings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->string('environment', 16);
            $t->string('status', 32);
            $t->string('current_step', 64)->nullable();
            $t->string('otp_fingerprint', 128)->nullable();   // salted hash only; OTP never stored
            $t->string('compliance_request_id', 64)->nullable();
            $t->json('compliance_checks')->nullable();
            $t->unsignedBigInteger('initiated_by')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->text('error')->nullable();
            $this->datetimes($t);
            $t->index(['device_id', 'created_at'], Tables::index('onb_device'));
            Tables::foreign($t, 'device_id', 'devices', 'onb_device');
        });

        $this->create('device_renewals', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->string('environment', 16);
            $t->unsignedBigInteger('previous_certificate_id')->nullable();
            $t->unsignedBigInteger('new_certificate_id')->nullable();
            $t->string('status', 32);
            $t->string('otp_fingerprint', 128)->nullable();
            $t->unsignedBigInteger('initiated_by')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->text('error')->nullable();
            $this->datetimes($t);
            $t->index(['device_id', 'created_at'], Tables::index('ren_device'));
            Tables::foreign($t, 'device_id', 'devices', 'ren_device');
            Tables::foreign($t, 'previous_certificate_id', 'certificates', 'ren_prev_cert');
            Tables::foreign($t, 'new_certificate_id', 'certificates', 'ren_new_cert');
        });
    }

    public function down(): void
    {
        $this->drop('device_renewals', 'device_onboardings', 'certificates', 'device_keys', 'devices', 'branches', 'vat_registrations', 'companies');
    }
};
