<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

final class TelnyxCallStateService
{
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function storeInbound(
        string $callSessionId,
        string $callControlId,
        string $callLegId,
        string $connectionId,
    ): void {
        $item = $this->cache->getItem($this->cacheKey($callSessionId));
        $item->set([
            'call_control_id' => $callControlId,
            'call_leg_id' => $callLegId,
            'connection_id' => $connectionId,
        ]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    /**
     * @return array{call_control_id: string, call_leg_id: string, connection_id: string}|null
     */
    public function getInbound(string $callSessionId): ?array
    {
        $item = $this->cache->getItem($this->cacheKey($callSessionId));
        if (!$item->isHit()) {
            return null;
        }

        $state = $item->get();
        if (
            !is_array($state)
            || !isset($state['call_control_id'], $state['call_leg_id'], $state['connection_id'])
            || !is_string($state['call_control_id'])
            || !is_string($state['call_leg_id'])
            || !is_string($state['connection_id'])
        ) {
            return null;
        }

        return $state;
    }

    private function cacheKey(string $callSessionId): string
    {
        return 'telnyx.call.'.hash('sha256', $callSessionId);
    }
}
