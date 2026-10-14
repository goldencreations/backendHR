<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived caching for expensive aggregate endpoints (dashboards,
 * monthly reports) with instant invalidation on write.
 *
 * Keys are namespaced by a global epoch: any mutation bumps the epoch,
 * which retires every cached aggregate at once. Reads stay fast (a
 * database-cache lookup) while a decision is visible on the very next
 * request — there is no stale window to reason about.
 */
final class DashboardCache
{
    public static function remember(string $key, int $ttlSeconds, Closure $build): mixed
    {
        $epoch = (int) Cache::get('dash:epoch', 1);

        return Cache::remember("dash:v{$epoch}:{$key}", $ttlSeconds, $build);
    }

    public static function bust(): void
    {
        // Increment() is unreliable on some cache stores when the key does
        // not exist yet, so read-modify-write explicitly. Forever, because
        // the epoch must never expire while aggregates reference it.
        $epoch = (int) Cache::get('dash:epoch', 1);
        Cache::forever('dash:epoch', $epoch + 1);
    }
}
