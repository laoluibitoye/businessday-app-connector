<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Videos — real posts using the theme's `video` post format (core/theme-setup.php's
 * add_theme_support('post-formats', ['image','video'])), with a YouTube ID set via the editorial
 * meta key `_youtube_id` (core/editorial-meta.php, rendered as an iframe in
 * template-parts/single-default.php). Only posts that actually have a YouTube ID are returned —
 * a post can carry the video format without one, and there'd be nothing to embed.
 */
class BD_Videos_Api
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
        register_rest_route(self::NAMESPACE, '/videos', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cacheKey = 'videos';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => self::PER_PAGE,
                'tax_query' => [
                    [
                        'taxonomy' => 'post_format',
                        'field' => 'slug',
                        'terms' => 'post-format-video',
                    ],
                ],
                'meta_query' => [
                    [
                        'key' => '_youtube_id',
                        'value' => '',
                        'compare' => '!=',
                    ],
                ],
                'no_found_rows' => true,
            ]);

            $items = [];
            foreach ($query->posts as $post) {
                $raw = (string) get_post_meta($post->ID, '_youtube_id', true);
                $youtubeId = self::normalizeYoutubeId($raw);
                if ($youtubeId === null) {
                    continue; // stored value isn't a usable YouTube ID/URL — nothing to embed
                }

                $categories = get_the_category($post->ID);
                $primary = $categories[0] ?? null;
                $thumbnailId = get_post_thumbnail_id($post->ID);

                $items[] = [
                    'id' => $post->ID,
                    'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                    'youtubeId' => $youtubeId,
                    // An editor marks a clip as a Short simply by pasting its youtube.com/shorts/
                    // link (rather than the regular watch/embed link) into the same `_youtube_id`
                    // field — no separate meta field or admin UI needed. The app splits its
                    // vertical Shorts feed from the regular Videos list using this flag.
                    'isShort' => self::isShortsUrl($raw),
                    'section' => $primary ? html_entity_decode($primary->name, ENT_QUOTES, 'UTF-8') : 'News',
                    'imageUrl' => $thumbnailId ? wp_get_attachment_image_url($thumbnailId, 'large') : null,
                    'publishedAt' => get_post_time('c', true, $post),
                    'link' => get_permalink($post),
                ];
            }

            $cached = ['items' => $items];
            BD_App_Cache::set($cacheKey, $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }

    /**
     * `_youtube_id` on staging holds inconsistent formats — some rows are a bare 11-char ID, some
     * are a full watch/embed/share URL, and some carry a stray leading slash (e.g. "/mXml15K4vS0")
     * left over from a pasted URL fragment. Extracts a real embeddable ID from any of those, or
     * returns null if nothing usable is there.
     */
    private static function normalizeYoutubeId(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})#', $raw, $m)) {
            return $m[1];
        }

        $stripped = ltrim($raw, '/');
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $stripped)) {
            return $stripped;
        }

        return null;
    }

    private static function isShortsUrl(string $raw): bool
    {
        return (bool) preg_match('#youtube\.com/shorts/#i', trim($raw));
    }
}
