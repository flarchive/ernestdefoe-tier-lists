<?php

namespace ErnestDefoe\TierLists\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/** Windows that start with the first use, so no clock boundary resets them early. Admins are never limited. */
class RateLimit
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function exceeded(User $actor, string $kind, int $max, int $seconds): bool
    {
        if ($actor->isAdmin()) {
            return false;
        }

        $key = "tier-lists:rate:$kind:$actor->id";
        $this->cache->add($key, 0, $seconds);

        return $this->cache->increment($key) > $max;
    }
}
