<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mirrors the theme's own "E-Editions" homepage section (addons/editions/includes/homepage.php)
 * exactly: one row per `edition_publication` taxonomy term that has at least one edition, each
 * carrying that publication's single most recent `bday_edition` post — the app renders this as a
 * horizontal carousel instead of the website's static grid, but the underlying data/rules are
 * identical (same taxonomy query, same "latest edition per publication" logic), so an editor
 * adding a new publication or publishing a new edition sees it reflected on both surfaces with no
 * separate mobile-only step.
 */
class BD_Editions_Homepage_Api
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
        register_rest_route(self::NAMESPACE, '/editions/homepage', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cached = BD_App_Cache::get('editions_homepage');
        if ($cached === false) {
            $cards = [];

            if (post_type_exists('bday_edition')) {
                $publications = get_terms(['taxonomy' => 'edition_publication', 'hide_empty' => true]);
                if (!is_wp_error($publications)) {
                    foreach ($publications as $publication) {
                        $query = new WP_Query([
                            'post_type' => 'bday_edition',
                            'post_status' => 'publish',
                            'posts_per_page' => 1,
                            'tax_query' => [
                                ['taxonomy' => 'edition_publication', 'field' => 'term_id', 'terms' => $publication->term_id],
                            ],
                        ]);
                        $edition = $query->posts[0] ?? null;
                        if (!$edition) {
                            continue;
                        }

                        $thumbnailId = get_post_thumbnail_id($edition->ID);
                        $cards[] = [
                            'publicationSlug' => $publication->slug,
                            'publicationLabel' => html_entity_decode($publication->name, ENT_QUOTES, 'UTF-8'),
                            'date' => get_post_time('Y-m-d', false, $edition),
                            'coverImageUrl' => $thumbnailId ? wp_get_attachment_image_url($thumbnailId, 'large') : null,
                        ];
                    }
                }
            }

            $cached = ['items' => $cards];
            BD_App_Cache::set('editions_homepage', $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }
}
