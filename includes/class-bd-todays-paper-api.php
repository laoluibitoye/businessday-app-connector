<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Today's Paper — reads the SAME real curation an editor already does once for the website
 * (templates/template-todays-paper.php): posts flagged "Feature in Today's Paper" (post meta
 * `_bday_todays_paper`, set by addons/todays-paper/includes/metabox.php) plus the most recent
 * `bday_edition` post tagged `edition_publication` = `e-paper` for the cover. No separate
 * mobile-only curation option — an editor who flags a post or publishes today's edition on the
 * website sees it here automatically, without a second manual step.
 *
 * `pdfUrl` is deliberately not returned here — the real PDF is a short-lived signed URL fetched
 * from subscription-service at click time (GET /me/editions/{publication}/{date}/download-url,
 * same as the website's sdk/src/edition-download.ts and the mobile app's editions.ts), not a
 * static link stored anywhere in WordPress.
 */
class BD_App_Todays_Paper_Api
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';
    const TTL = 180;
    const EPAPER_PUBLICATION_SLUG = 'e-paper';

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
        register_rest_route(self::NAMESPACE, '/todays-paper', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cached = BD_App_Cache::get('todays_paper');
        if ($cached === false) {
            // Scoped to posts flagged *as of today* (see the theme's todays-paper metabox's
            // `_bday_todays_paper_date` stamp, set every time an editor (re)checks the box) —
            // without this, a post flagged on a previous day and never explicitly unflagged would
            // keep accumulating here forever instead of being a same-day curation, and would drift
            // from the website's own Today's Paper page, which enforces the same restriction.
            $query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'meta_query' => [
                    ['key' => '_bday_todays_paper', 'value' => '1'],
                    ['key' => '_bday_todays_paper_date', 'value' => current_time('Y-m-d')],
                ],
                'posts_per_page' => -1,
            ]);

            $sectionsByLabel = [];
            foreach ($query->posts as $post) {
                $categories = get_the_category($post->ID);
                // html_entity_decode: get_the_title()/category names run through WP's
                // wptexturize filter, which emits literal HTML entity text (e.g. "&#8217;")
                // rather than real Unicode — fine for an HTML-rendering theme, wrong for a
                // bare RN <Text> (see class-bd-feed-api.php's buildItems() for the same fix).
                $label = isset($categories[0]) ? html_entity_decode($categories[0]->name, ENT_QUOTES, 'UTF-8') : 'News';
                $meta = BD_AeroPaywall_Bridge::getPostMeta($post->ID);
                $sectionsByLabel[$label][] = [
                    'id' => $post->ID,
                    'headline' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                    'dek' => html_entity_decode(
                        $meta['excerpt'] ?? wp_trim_words(wp_strip_all_tags($post->post_content), 30),
                        ENT_QUOTES,
                        'UTF-8'
                    ),
                    'isPremium' => $meta['isPremium'] ?? false,
                    'link' => get_permalink($post),
                ];
            }

            $sections = [];
            foreach ($sectionsByLabel as $label => $items) {
                $sections[] = ['title' => $label, 'items' => $items];
            }

            $coverImageUrl = null;
            $editionDate = '';
            if (post_type_exists('bday_edition')) {
                $term = get_term_by('slug', self::EPAPER_PUBLICATION_SLUG, 'edition_publication');
                if ($term) {
                    $editionQuery = new WP_Query([
                        'post_type' => 'bday_edition',
                        'post_status' => 'publish',
                        'posts_per_page' => 1,
                        'tax_query' => [
                            ['taxonomy' => 'edition_publication', 'field' => 'term_id', 'terms' => $term->term_id],
                        ],
                    ]);
                    $edition = $editionQuery->posts[0] ?? null;
                    if ($edition) {
                        $thumbnailId = get_post_thumbnail_id($edition->ID);
                        $coverImageUrl = $thumbnailId ? wp_get_attachment_image_url($thumbnailId, 'large') : null;
                        $editionDate = get_post_time('Y-m-d', false, $edition);
                    }
                }
            }

            $cached = [
                'date' => $editionDate ?: gmdate('Y-m-d'),
                'publication' => self::EPAPER_PUBLICATION_SLUG,
                'coverImageUrl' => $coverImageUrl,
                'sections' => $sections,
            ];
            BD_App_Cache::set('todays_paper', $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }
}
