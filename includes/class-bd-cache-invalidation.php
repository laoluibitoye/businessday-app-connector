<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Purges the shared feed/config caches whenever content or settings actually change, so the
 * 3–5 minute TTLs in class-bd-feed-api.php / class-bd-config-api.php are a ceiling on staleness,
 * not the normal case. Deliberately coarse (purge everything on any post save) rather than
 * trying to compute exactly which feed pages a post affects — correctness over precision, and
 * still cheap since this only runs on editor actions, not reader traffic.
 */
class BD_App_Cache_Invalidation
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerHooks();
        }
        return self::$instance;
    }

    private function registerHooks(): void
    {
        add_action('save_post', [$this, 'purgeFeeds']);
        add_action('transition_post_status', [$this, 'purgeFeeds']);
        // Editors commonly add/change tags or categories on an already-published post (e.g.
        // adding `bdlead`/`bdrecent` after the fact) without re-saving the post title/content —
        // that only fires `set_object_terms`, not `save_post`/`transition_post_status`. Verified
        // live: without this hook, a tag-sourced feed (Recent, Home's tag-based sections) stayed
        // stale until the TTL expired even though the post itself showed up correctly everywhere
        // category-sourced.
        add_action('set_object_terms', [$this, 'purgeFeeds']);
        add_action('bd_app_settings_saved', [BD_App_Cache::class, 'purgeAll']);
    }

    public function purgeFeeds(): void
    {
        BD_App_Cache::purgeAll();
    }
}
