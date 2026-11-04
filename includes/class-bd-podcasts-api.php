<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Podcasts — the real `podcast` CPT (addons/podcasts/includes/cpt.php), same episodes the
 * website's podcast-carousel and single-podcast template show. Meta keys read verbatim from
 * addons/podcasts/includes/metabox.php: _podcast_spotify_url, _podcast_audio_url,
 * _podcast_show_name, _podcast_duration.
 */
class BD_Podcasts_Api
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
        register_rest_route(self::NAMESPACE, '/podcasts', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cacheKey = 'podcasts';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $query = new WP_Query([
                'post_type' => 'podcast',
                'post_status' => 'publish',
                'posts_per_page' => self::PER_PAGE,
                'no_found_rows' => true,
            ]);

            $items = [];
            foreach ($query->posts as $post) {
                $spotifyUrl = (string) get_post_meta($post->ID, '_podcast_spotify_url', true);
                $audioUrl = (string) get_post_meta($post->ID, '_podcast_audio_url', true);
                $thumbnailId = get_post_thumbnail_id($post->ID);

                $items[] = [
                    'id' => $post->ID,
                    'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                    'showName' => (string) get_post_meta($post->ID, '_podcast_show_name', true),
                    'duration' => (string) get_post_meta($post->ID, '_podcast_duration', true),
                    'spotifyUrl' => $spotifyUrl ?: null,
                    'audioUrl' => $audioUrl ?: null,
                    'embedUrl' => ($spotifyUrl && function_exists('bday_podcast_spotify_embed_url'))
                        ? bday_podcast_spotify_embed_url($spotifyUrl)
                        : null,
                    'imageUrl' => $thumbnailId ? wp_get_attachment_image_url($thumbnailId, 'large') : null,
                    'notes' => html_entity_decode(wp_trim_words(wp_strip_all_tags($post->post_content), 40), ENT_QUOTES, 'UTF-8'),
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
}
