<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Rfq;
use App\Entity\Tenant;
use App\Repository\TenantRepository;

final class RfqVendorTargetingService
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    /**
     * @param iterable<Tenant>|null $tenants
     *
     * @return list<Tenant>
     */
    public function findEligibleTenants(Rfq $rfq, ?iterable $tenants = null): array
    {
        $tenantList = null !== $tenants ? iterator_to_array($tenants, false) : $this->tenantRepository->findAll();

        $eligible = [];
        foreach ($tenantList as $tenant) {
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $score = $this->scoreTenant($tenant, $rfq);
            if ($score < 0) {
                continue;
            }

            $eligible[] = [
                'tenant' => $tenant,
                'score' => $score,
                'name' => mb_strtolower($tenant->getName()),
                'id' => $tenant->getId() ?? PHP_INT_MAX,
            ];
        }

        usort($eligible, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            if ($left['name'] !== $right['name']) {
                return $left['name'] <=> $right['name'];
            }

            return $left['id'] <=> $right['id'];
        });

        return array_values(array_map(static fn (array $row): Tenant => $row['tenant'], $eligible));
    }

    public function isEligibleTenant(Rfq $rfq, Tenant $tenant): bool
    {
        return $this->scoreTenant($tenant, $rfq) >= 0;
    }

    public function scoreTenant(Tenant $tenant, Rfq $rfq): int
    {
        if (!$tenant->isRfqVendorEnabled()) {
            return -1;
        }

        $score = 0;
        $rfqCountry = $this->normalizeCountry($rfq->getCountry());
        $rfqProvince = $this->normalizeProvince($rfq->getProvince());
        $rfqCity = $this->normalizeCity($rfq->getCity());
        $rfqPostalPrefix = $this->normalizePostalPrefix($rfq->getPostalCode());

        if ([] !== $tenant->getRfqServiceAreaCountries() && !in_array($rfqCountry, $tenant->getRfqServiceAreaCountries(), true)) {
            return -1;
        }

        if ([] !== $tenant->getRfqServiceAreaCountries()) {
            $score += 10;
        }

        if ([] !== $tenant->getRfqServiceAreaProvinces()) {
            if (!in_array($rfqProvince, $tenant->getRfqServiceAreaProvinces(), true)) {
                return -1;
            }

            $score += 25;
        }

        if ([] !== $tenant->getRfqServiceAreaCities()) {
            if (!in_array($rfqCity, $tenant->getRfqServiceAreaCities(), true)) {
                return -1;
            }

            $score += 50;
        }

        if ([] !== $tenant->getRfqServiceAreaPostalPrefixes()) {
            if (!in_array($rfqPostalPrefix, $tenant->getRfqServiceAreaPostalPrefixes(), true)) {
                return -1;
            }

            $score += 100;
        }

        return $score;
    }

    private function normalizeCountry(string $country): string
    {
        return mb_strtoupper(trim($country));
    }

    private function normalizeProvince(string $province): string
    {
        return mb_strtoupper(trim($province));
    }

    private function normalizeCity(string $city): string
    {
        return mb_strtolower(trim($city));
    }

    private function normalizePostalPrefix(string $postalCode): string
    {
        return mb_strtoupper(substr(preg_replace('/\s+/', '', trim($postalCode)) ?? '', 0, 3));
    }
}
