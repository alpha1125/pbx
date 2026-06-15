<?php

declare(strict_types=1);

namespace App\Service;

final class CrmInputNormalizer
{
    public function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    public function normalizePhoneOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if (null === $value) {
            return null;
        }

        $digits = preg_replace('/[^0-9+]/', '', $value) ?? '';
        if ('' === $digits) {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (11 === strlen($digits) && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        if (10 === strlen($digits)) {
            return '+1'.$digits;
        }

        return $digits;
    }

    public function normalizeEmailOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return null !== $value ? mb_strtolower($value) : null;
    }

    public function normalizePostalCodeOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if (null === $value) {
            return null;
        }

        return strtoupper(str_replace(' ', '', $value));
    }

    public function normalizeProvinceOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if (null === $value) {
            return null;
        }

        return strtoupper($value);
    }

    public function normalizeCountryOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if (null === $value) {
            return null;
        }

        return strtoupper($value);
    }
}
