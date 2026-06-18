<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\BrowserCallTokenRateLimitException;
use Psr\Cache\CacheItemPoolInterface;

final class BrowserCallTokenIssueGuard
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $cooldownSeconds,
    ) {
    }

    public function assertAllowed(Tenant $tenant, Property $property, Contact $contact, User $user): void
    {
        $key = sprintf(
            'browser_call_token:%s:%s:%s:%s',
            (string) ($tenant->getId() ?? 'tenant'),
            (string) ($property->getId() ?? 'property'),
            (string) ($contact->getId() ?? 'contact'),
            (string) ($user->getId() ?? 'user'),
        );

        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            throw new BrowserCallTokenRateLimitException('Browser call token requests are rate-limited. Please wait a moment and try again.');
        }

        $item
            ->set([
                'issuedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
            ->expiresAfter(max(1, $this->cooldownSeconds));

        $this->cache->save($item);
    }
}
