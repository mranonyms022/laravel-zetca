<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum ArtifactKind: string
{
    case SignedXml = 'signed_xml';
    case ClearedXml = 'cleared_xml';
    case QrPng = 'qr_png';
    case PreviewXml = 'preview_xml';
}
