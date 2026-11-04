<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Toon of the Day — the real `cartoons` CPT (addons/cartoons/includes/cpt.php). The website has
 * no "set today's cartoon" toggle at all — homepage-sections/toon.php just shows the single
 * latest-published `cartoons` post (core/homepage/redesign-data.php's `rd_toon`). This route
 * mirrors that exactly: items[0] is "today's" cartoon, the rest is the same archive
 * archive-cartoons.php already shows.
 */
class BD_Toons_Api
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
        register_rest_route(self::NAMESPACE, '/toons', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cacheKey = 'toons';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $items = [];

            if (post_type_exists('cartoons')) {
                $query = new WP_Query([
                    'post_type' => 'cartoons',
                    'post_status' => 'publish',
                    'posts_per_page' => self::PER_PAGE,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'no_found_rows' => true,
                ]);

                foreach ($query->posts as $post) {
                    $thumbnailId = get_post_thumbnail_id($post->ID);
                    $items[] = [
                        'id' => $post->ID,
                        'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
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
