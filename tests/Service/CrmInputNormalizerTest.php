<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CrmInputNormalizer;
use PHPUnit\Framework\TestCase;

final class CrmInputNormalizerTest extends TestCase
{
    public function testStringOrNullTrimsAndNullsBlankValues(): void
    {
        $normalizer = new CrmInputNormalizer();

        self::assertSame('FirstFire', $normalizer->stringOrNull('  FirstFire  '));
        self::assertNull($normalizer->stringOrNull('   '));
        self::assertNull($normalizer->stringOrNull(null));
    }

    public function testNormalizePhoneOrNullConvertsNorthAmericanNumbers(): void
    {
        $normalizer = new CrmInputNormalizer();

        self::assertSame('+14168880123', $normalizer->normalizePhoneOrNull('(416) 888-0123'));
        self::assertSame('+14168880123', $normalizer->normalizePhoneOrNull('14168880123'));
        self::assertSame('+14168880123', $normalizer->normalizePhoneOrNull('+14168880123'));
        self::assertNull($normalizer->normalizePhoneOrNull(''));
    }

    public function testNormalizeEmailPostalProvinceAndCountry(): void
    {
        $normalizer = new CrmInputNormalizer();

        self::assertSame('demo@firstfire.example', $normalizer->normalizeEmailOrNull(' Demo@FirstFire.Example '));
        self::assertSame('M5V2T6', $normalizer->normalizePostalCodeOrNull('m5v 2t6'));
        self::assertSame('ON', $normalizer->normalizeProvinceOrNull('on'));
        self::assertSame('CA', $normalizer->normalizeCountryOrNull('ca'));
    }
}
