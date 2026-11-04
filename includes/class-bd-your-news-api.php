<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mirrors the theme's "Your News" homepage section (Redesign 2026) — an admin-configured set of
 * desks (each its own category or tag, with a custom display title), wp-admin -> BDay Aero
 * Options -> News Carousel, `bday_addon_news_carousel` option. Calls the theme's own
 * bday_get_redesign_your_news_desks() directly rather than re-reading the option and
 * re-implementing its query/fallback logic here — that function also silently falls back to the
 * busiest categories when nothing's configured yet, and duplicating that behavior would drift the
 * moment either copy changed. Any number of desks is supported (no 6-column assumption anywhere
 * in this file) — whatever the admin has configured is what both the website and the app show.
 */
class BD_Your_News_Api
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';
    const TTL = 180;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            add_action('rest_api_init', [self::$instance, 'registerRoutes']);
        }
        return self::$instance;
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/your-news', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cached = BD_App_Cache::get('your_news');
        if ($cached === false) {
            $desks = [];

            if (function_exists('bday_get_redesign_your_news_desks')) {
                foreach (bday_get_redesign_your_news_desks() as $desk) {
                    $posts = $desk['posts'] ?? [];
                    if (empty($posts)) {
                        continue; // same "skip an empty desk" posture as the website section
                    }
                    $desks[] = [
                        'label' => html_entity_decode($desk['name'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'url' => $desk['url'] ?? '',
                        'items' => BD_App_Feed_Api::instance()->buildItems($posts),
                    ];
                }
            }

            $cached = ['desks' => $desks];
            BD_App_Cache::set('your_news', $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }
}
