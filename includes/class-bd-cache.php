<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin transient wrapper for the plugin's own caches, distinct from AeroPaywall's — nothing here
 * touches connector-plugin's storage. Every feed/config response is cached ONCE, shared across
 * every reader, regardless of traffic volume: that's what makes this safe to use even without a
 * persistent object cache (Redis/Memcached) installed. Per-reader caching would multiply rows in
 * wp_options under load instead of bounding it, which is exactly the DB-load problem this plugin
 * exists to avoid — see includes/class-bd-feed-api.php for why entitlement itself is never cached
 * here.
 *
 * Registered transient keys are tracked in one small option so a "purge everything" action (a
 * post save, or the admin's manual purge button) doesn't need to guess key names.
 */
class BD_App_Cache
{
    const REGISTRY_OPTION = 'bd_app_cache_registry';
    const PREFIX = 'bd_app_';

    public static function get(string $key)
    {
        return get_transient(self::PREFIX . $key);
    }

    public static function set(string $key, $value, int $ttlSeconds): void
    {
        set_transient(self::PREFIX . $key, $value, $ttlSeconds);
        self::registerKey($key);
    }

    public static function delete(string $key): void
    {
        delete_transient(self::PREFIX . $key);
    }

    public static function purgeAll(): void
    {
        $keys = get_option(self::REGISTRY_OPTION, []);
        foreach ($keys as $key) {
            delete_transient(self::PREFIX . $key);
        }
        update_option(self::REGISTRY_OPTION, [], false);
    }

    private static function registerKey(string $key): void
    {
        $keys = get_option(self::REGISTRY_OPTION, []);
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option(self::REGISTRY_OPTION, $keys, false);
        }
    }

    /**
     * Public, non-personalized responses only — never call this for anything that varies by
     * reader (entitlement, auth-scoped data). `s-maxage` lets a CDN in front of WordPress absorb
     * most traffic without a round-trip to the origin at all.
     */
    public static function applyPublicCacheHeaders(WP_REST_Response $response, int $maxAgeSeconds): void
    {
        $response->header(
            'Cache-Control',
            sprintf('public, max-age=%d, s-maxage=%d, stale-while-revalidate=60', $maxAgeSeconds, $maxAgeSeconds * 2)
        );
    }
}
