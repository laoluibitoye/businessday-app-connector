<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Events — the real `events` CPT (addons/events/addon.php). Meta keys read verbatim from that
 * addon's metabox: _bday_event_venue, _bday_event_link, _bday_event_date, _bday_event_time.
 * `_bday_event_date` is free text (no date picker on the website either) — parsed the same
 * tolerant way homepage-sections/events.php does (strtotime()), with the raw string always kept
 * too so a value that fails to parse still displays something.
 */
class BD_Events_Api
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';
    const TTL = 180;
    const PER_PAGE = 30;

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
        register_rest_route(self::NAMESPACE, '/events', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cacheKey = 'events';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $items = [];

            if (post_type_exists('events')) {
                $query = new WP_Query([
                    'post_type' => 'events',
                    'post_status' => 'publish',
                    'posts_per_page' => self::PER_PAGE,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'no_found_rows' => true,
                ]);

                foreach ($query->posts as $post) {
                    $thumbnailId = get_post_thumbnail_id($post->ID);
                    $rawDate = (string) get_post_meta($post->ID, '_bday_event_date', true);
                    $parsed = $rawDate !== '' ? strtotime($rawDate) : false;
                    $link = (string) get_post_meta($post->ID, '_bday_event_link', true);

                    $items[] = [
                        'id' => $post->ID,
                        'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                        'venue' => (string) get_post_meta($post->ID, '_bday_event_venue', true),
                        'dateRaw' => $rawDate ?: null,
                        'dateIso' => $parsed !== false ? gmdate('c', $parsed) : null,
                        'time' => (string) get_post_meta($post->ID, '_bday_event_time', true),
                        'registerUrl' => $link !== '' ? $link : get_permalink($post),
                        'excerpt' => html_entity_decode(wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 30), ENT_QUOTES, 'UTF-8'),
                        'imageUrl' => $thumbnailId ? wp_get_attachment_image_url($thumbnailId, 'large') : null,
                        'publishedAt' => get_post_time('c', true, $post),
                        'link' => get_permalink($post),
                    ];
                }
            }

            $cached = ['items' => $items];
            BD_App_Cache::set($cacheKey, $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }
}
