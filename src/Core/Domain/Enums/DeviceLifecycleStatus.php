<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum DeviceLifecycleStatus: string
{
    case Registered = 'REGISTERED';
    case KeyGenerated = 'KEY_GENERATED';
    case ComplianceCsidIssued = 'COMPLIANCE_CSID_ISSUED';
    case ComplianceChecking = 'COMPLIANCE_CHECKING';
    case CompliancePassed = 'COMPLIANCE_PASSED';
    case OnboardingFailed = 'ONBOARDING_FAILED';
    case Active = 'ACTIVE';
    case RenewalDue = 'RENEWAL_DUE';
    case Renewing = 'RENEWING';
    case RenewalFailed = 'RENEWAL_FAILED';
    case Expired = 'EXPIRED';
    case Suspended = 'SUSPENDED';
    case Decommissioned = 'DECOMMISSIONED';

    /** Whether the device may sign new production documents (certificate still valid). */
    public function canIssue(): bool
    {
        return in_array($this, [self::Active, self::RenewalDue, self::Renewing, self::RenewalFailed], true);
    }
}
