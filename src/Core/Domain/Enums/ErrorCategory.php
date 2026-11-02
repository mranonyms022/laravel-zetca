<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum ErrorCategory: string
{
    case JsonValidation = 'json_validation';
    case BusinessRule = 'business_rule';
    case XmlGeneration = 'xml_generation';
    case XmlValidation = 'xml_validation';
    case Certificate = 'certificate';
    case Signing = 'signing';
    case Authentication = 'authentication';
    case ApiConnection = 'api_connection';
    case ZatcaRejection = 'zatca_rejection';
    case Queue = 'queue';
    case Database = 'database';
    case Sequence = 'sequence';
}
