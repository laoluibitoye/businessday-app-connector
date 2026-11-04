<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mirrors the theme's admin-editable "Off the Clock" category list (Appearance -> BusinessDay
 * Theme -> Off the Clock, `bday_off_the_clock_categories` option — see the theme's
 * core/homepage/off-the-clock-admin.php) so the app renders the exact same tabs, in the exact
 * same order, as the website's homepage section, with zero separate mobile-only curation step.
 * Reads the theme's option directly rather than duplicating the admin UI here.
 */
class BD_Off_The_Clock_Api
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
        register_rest_route(self::NAMESPACE, '/off-the-clock', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cached = BD_App_Cache::get('off_the_clock');
        if ($cached === false) {
            $rows = get_option('bday_off_the_clock_categories', []);
            $tabs = [];

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $slug = $row['category_slug'] ?? '';
                    $label = $row['label'] ?? '';
                    if ($slug === '' || $label === '') {
                        continue;
                    }

                    $query = new WP_Query([
                        'post_type' => 'post',
                        'post_status' => 'publish',
                        'category_name' => $slug,
                        'posts_per_page' => 3,
                        'no_found_rows' => true,
                    ]);
                    if (empty($query->posts)) {
                        continue; // same "skip an empty column" posture as the website section
                    }

                    $items = [];
                    foreach ($query->posts as $post) {
                        $thumbnailId = get_post_thumbnail_id($post->ID);
                        $items[] = [
                            'id' => $post->ID,
                            'headline' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                            'imageUrl' => $thumbnailId ? wp_get_attachment_image_url($thumbnailId, 'large') : null,
                            'link' => get_permalink($post),
                        ];
                    }

                    $tabs[] = [
                        'label' => $label,
                        'categorySlug' => $slug,
                        'items' => $items,
                    ];
                }
            }

            $cached = ['tabs' => $tabs];
            BD_App_Cache::set('off_the_clock', $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }
}
