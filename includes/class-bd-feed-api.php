<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cached, non-personalized listing endpoints — the reason this plugin exists. Every one of
 * these endpoints returns the exact same JSON to every reader (no auth/device-id input, no
 * `Vary` needed), rebuilt on a timer rather than per-request. That's the whole fix for the load
 * problem: without this, showing a 20-item list would mean 20 live entitlement checks (DB read +
 * outbound HTTP call each, per AeroPaywall's own docs) on every single screen render, for every
 * reader. With this, a full feed page costs that same 20-call batch once every TTL window,
 * amortized across all traffic.
 *
 * Real per-article entitlement is still checked live, directly against AeroPaywall, exactly
 * once — when a reader actually opens a specific article. That's proportional to real usage and
 * is intentionally NOT cached or proxied here (see class-bd-aeropaywall-bridge.php's docblock).
 */
class BD_App_Feed_Api
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';
    const FEED_TTL = 180; // 3 min — balances freshness against rebuild cost
    const PER_PAGE = 20;
    const WORDS_PER_MINUTE = 250; // configured average adult reading speed used for readTimeMinutes

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
        register_rest_route(self::NAMESPACE, '/feed/home', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleHome'],
        ]);

        register_rest_route(self::NAMESPACE, '/feed/latest', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleLatest'],
            // Param is `pg`, not `page` — a bare `page=` query parameter reliably trips a
            // Cloudflare WAF rule on the live site (returns a JS challenge page instead of JSON,
            // which no native HTTP client can ever solve). Confirmed live: `?page=1` -> 403
            // `cf-mitigated: challenge` on every route in this file, `?pg=1` -> 200.
            'args' => ['pg' => ['default' => 1, 'sanitize_callback' => 'absint']],
        ]);

        // Mirrors core/homepage/data.php's 'most_popular' exactly (orderby comment_count, 5 posts)
        // — the "Editor's Pick & Most Read" / "Most Popular" sidebar content. Not part of
        // themeSectionMap()/getConfiguredSections() since it isn't one of the toggleable homepage
        // sections in Bday_Section_Registry — a fixed, always-on route instead, same shape as
        // /feed/latest above.
        register_rest_route(self::NAMESPACE, '/feed/most-popular', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleMostPopular'],
        ]);

        register_rest_route(self::NAMESPACE, '/feed/section/(?P<slug>[a-z0-9-]+)', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleSection'],
            'args' => ['pg' => ['default' => 1, 'sanitize_callback' => 'absint']],
        ]);

        // Tag-scoped feed — mirrors handleSection but keyed on a tag slug rather than a category.
        // Exists specifically because the real site's own editorial convention uses tags, not
        // categories, for cross-cutting collections like "recent" (`bdrecent`) and "top stories"
        // (`bdlead`) — a category source can't express that, so a plain date-ordered feed would
        // silently diverge from what an editor actually curated on the website.
        register_rest_route(self::NAMESPACE, '/feed/tag/(?P<slug>[a-z0-9-]+)', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleTag'],
            'args' => ['pg' => ['default' => 1, 'sanitize_callback' => 'absint']],
        ]);

        // Deliberately uncached — see class docblock. Still returns the same lean view model so
        // the app doesn't need special-case parsing for search results.
        register_rest_route(self::NAMESPACE, '/search', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleSearch'],
            'args' => ['q' => ['required' => true]],
        ]);

        // Single-post lookup by id — needed so a cold-started app (e.g. opened via a shared
        // article's Universal Link, where all we have is a permalink, not an already-registered
        // Article) can fetch that one article's metadata directly, without having to wait for it
        // to show up in a listing feed first.
        register_rest_route(self::NAMESPACE, '/article/(?P<id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleArticle'],
        ]);

        // Mirrors template-parts/single-default.php's two related-content blocks exactly: "Related
        // News" (relatedByTag, shared tags) and "You Might Also Like" (relatedByCategory, shared
        // primary category) — same 3-post count, same post__not_in the article itself. This
        // endpoint is anonymous/cached like every other feed route here, so it can't replicate the
        // website's "skip Related News when the post is gated" check itself (gating is a live,
        // per-reader thing the separate aeropaywall plugin computes, not something this cached
        // route has visibility into) — the app applies that same rule client-side instead, from
        // the entitlement fetch it already makes.
        register_rest_route(self::NAMESPACE, '/article/(?P<id>\d+)/related', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleRelated'],
        ]);

        // Author archive — same data single-default.php's own author-bio block reads
        // (get_the_author_meta 'description', get_avatar_url), plus that author's own posts, so
        // ColumnistPageScreen.tsx (opened from an article's byline) doesn't have to be a stub.
        register_rest_route(self::NAMESPACE, '/author/(?P<id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleAuthor'],
            'args' => ['pg' => ['default' => 1, 'sanitize_callback' => 'absint']],
        ]);

        // Resolves a live WordPress permalink to its post ID — the other half of the Universal
        // Link flow above: iOS/Android hand the app the tapped URL, not an ID.
        register_rest_route(self::NAMESPACE, '/resolve', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleResolve'],
            'args' => ['url' => ['required' => true]],
        ]);
    }

    public function handleArticle(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return new WP_REST_Response(['message' => 'Not found'], 404);
        }
        $items = $this->buildItems([$post]);
        return new WP_REST_Response(['item' => $items[0]], 200);
    }

    public function handleRelated(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return new WP_REST_Response(['message' => 'Not found'], 404);
        }

        $cacheKey = 'related_' . $id;
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $tags = wp_get_post_tags($id);
            $byTag = [];
            if (!empty($tags)) {
                $byTag = (new WP_Query([
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'tag__in' => wp_list_pluck($tags, 'term_id'),
                    'post__not_in' => [$id],
                    'posts_per_page' => 3,
                    'no_found_rows' => true,
                ]))->posts;
            }

            $categories = get_the_category($id);
            $byCategory = [];
            if (!empty($categories)) {
                $byCategory = (new WP_Query([
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'category_name' => $categories[0]->slug,
                    'post__not_in' => [$id],
                    'posts_per_page' => 3,
                    'no_found_rows' => true,
                ]))->posts;
            }

            $cached = [
                'relatedByTag' => $this->buildItems($byTag),
                'relatedByCategory' => $this->buildItems($byCategory),
            ];
            BD_App_Cache::set($cacheKey, $cached, self::FEED_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::FEED_TTL);
        return $response;
    }

    public function handleAuthor(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $userData = get_userdata($id);
        if (!$userData) {
            return new WP_REST_Response(['message' => 'Not found'], 404);
        }

        $page = max(1, (int) $request->get_param('pg'));
        $cacheKey = 'author_' . $id . '_p' . $page;
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'author' => $id,
                'posts_per_page' => self::PER_PAGE,
                'paged' => $page,
            ]);

            $cached = [
                'author' => [
                    'id' => $id,
                    'name' => $userData->display_name,
                    'avatarUrl' => get_avatar_url($id, ['size' => 200]),
                    'bio' => (string) get_the_author_meta('description', $id),
                ],
                'items' => $this->buildItems($query->posts),
                'page' => $page,
                'hasMore' => $page < (int) $query->max_num_pages,
            ];
            BD_App_Cache::set($cacheKey, $cached, self::FEED_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::FEED_TTL);
        return $response;
    }

    public function handleResolve(WP_REST_Request $request): WP_REST_Response
    {
        $url = (string) $request->get_param('url');
        $postId = url_to_postid($url);
        if ($postId <= 0) {
            return new WP_REST_Response(['message' => 'Not found'], 404);
        }
        return new WP_REST_Response(['id' => $postId], 200);
    }

    // Theme homepage section slug -> one or more app sections, sourced from the exact same
    // category/tag vocabulary core/homepage/data.php's bday_get_homepage_data() uses (read
    // directly from that file, not guessed) — an editor who reorders/toggles sections on
    // Appearance -> BusinessDay Theme -> Homepage Sections (bday_homepage_sections option) sees
    // the same change reflected here, with no separate mobile-only curation step. Sections with
    // no clean single taxonomy source (composite/admin-configurable ones like `your-news`, or
    // ones with no mobile surface yet like `market-pulse`/`newsletter`/`watch-listen`, or
    // purely visual ones like `toon`/`events`/`partner-content`) are simply absent from this map
    // and skipped — same as if disabled.
    //
    // Verified directly against the live, current theme (core/homepage/class-section-registry.php
    // + homepage-sections/*.php file headers) — every slug below matches a real section file, and
    // `economy`/`law` below match the theme's own bday_get_homepage_data() exactly (core/homepage/
    // data.php's topic_economy/topic_law), not a guess. Those two categories don't exist yet on
    // the live site (verified against a live GET /wp-json/wp/v2/categories — all 223), so both the
    // website's own homepage rail and this map's tiles are silently empty until an editor creates
    // them — that's a content gap to close on the WordPress side, not a code bug here.
    //
    // @return array<int, array<string, array{label: string, sourceType: string, sourceValue: string, count: int, displayType: string}>>
    private static function themeSectionMap(): array
    {
        return [
            'hero' => [['label' => 'Top News', 'sourceType' => 'tag', 'sourceValue' => 'bdlead', 'count' => 5, 'displayType' => 'hero']],
            'in-other-news' => [['label' => 'In Other News', 'sourceType' => 'tag', 'sourceValue' => 'bdothernews', 'count' => 9, 'displayType' => 'mixed']],
            'latest-stories' => [['label' => 'Latest Stories', 'sourceType' => 'tag', 'sourceValue' => 'bdrecent', 'count' => 10, 'displayType' => 'textList']],
            'editor-pick' => [['label' => "Editor's Pick", 'sourceType' => 'category', 'sourceValue' => 'editorial', 'count' => 4, 'displayType' => 'mixed']],
            'columnists' => [['label' => 'Columnists', 'sourceType' => 'category', 'sourceValue' => 'Columnist', 'count' => 6, 'displayType' => 'briefRail']],
            'opinion' => [['label' => 'Opinion', 'sourceType' => 'category', 'sourceValue' => 'opinion', 'count' => 5, 'displayType' => 'textList']],
            'topic-triple' => [
                ['label' => 'Economy', 'sourceType' => 'category', 'sourceValue' => 'economy', 'count' => 5, 'displayType' => 'tileGrid'],
                ['label' => 'World', 'sourceType' => 'category', 'sourceValue' => 'world', 'count' => 5, 'displayType' => 'tileGrid'],
                ['label' => 'Law', 'sourceType' => 'category', 'sourceValue' => 'law', 'count' => 5, 'displayType' => 'tileGrid'],
            ],
            // 'reports' was wrong — doesn't match any homepage "BD Investigations" content
            // (returned E-edition/Property Digest posts). Live site's actual Investigation
            // section uses category slug 'investigation' (id 6735, verified against the
            // homepage's own "Investigation" kicker links) — corrected 2026-10-12.
            'investigates' => [['label' => 'BD Investigations', 'sourceType' => 'category', 'sourceValue' => 'investigation', 'count' => 3, 'displayType' => 'mixed']],
        ];
    }

    // Ordered, enabled theme section slugs -> flattened app sections, in the editor's own order.
    // Falls back to the legacy mobile-only option (BD_App_Settings) only if the theme's registry
    // class isn't available (e.g. this plugin running on a different theme) or genuinely has
    // nothing configured yet — never a hard failure.
    private function getConfiguredSections(): array
    {
        if (class_exists('Bday_Section_Registry')) {
            $activeSlugs = Bday_Section_Registry::ordered_active();
            $map = self::themeSectionMap();
            $sections = [];
            foreach ($activeSlugs as $slug) {
                if (!isset($map[$slug])) {
                    continue; // no mobile surface for this section yet
                }
                foreach ($map[$slug] as $i => $section) {
                    $sections[] = array_merge($section, ['id' => $slug . ($i > 0 ? '-' . $i : '')]);
                }
            }
            if (!empty($sections)) {
                return $sections;
            }
        }

        return BD_App_Settings::getActiveHomeSections();
    }

    // Home is a set of editor-defined sections — each section is its own label + taxonomy
    // source + count/offset. If nothing's configured yet (fresh install, or the theme's own
    // homepage-sections option is empty), falls back to one synthesized "Latest" section so the
    // endpoint is never emptily broken before an editor touches it.
    public function handleHome(WP_REST_Request $request): WP_REST_Response
    {
        $cacheKey = 'feed_home';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $configured = $this->getConfiguredSections();

            if (empty($configured)) {
                $query = new WP_Query([
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'posts_per_page' => self::PER_PAGE,
                    'no_found_rows' => true,
                ]);
                $sections = [['id' => 'latest', 'label' => 'Latest', 'displayType' => 'mixed', 'items' => $this->buildItems($query->posts)]];
            } else {
                $sections = [];
                foreach ($configured as $section) {
                    // 'hero' (Top News) is special: the theme's own Lead Story Lock feature
                    // (core/homepage/hero-lead-lock.php) lets an editor pin a specific post as the
                    // homepage lead, overriding "newest bdlead-tagged post." A plain tag query here
                    // would ignore that pin entirely and show the app a different lead than the
                    // website — this mirrors core/homepage/redesign-data.php's own lead + rd_top_news
                    // (post__not_in the lead) split so slot 0 is always the website's real lead.
                    if ('hero' === $section['id'] && function_exists('bday_get_hero_lead')) {
                        $posts = $this->buildHeroPosts($section);
                    } else {
                        $args = [
                            'post_type' => 'post',
                            'post_status' => 'publish',
                            'posts_per_page' => max(1, (int) ($section['count'] ?? 6)),
                            'offset' => max(0, (int) ($section['offset'] ?? 0)),
                            'no_found_rows' => true,
                        ];
                        if (($section['sourceType'] ?? '') === 'tag') {
                            $args['tag'] = $section['sourceValue'];
                        } else {
                            $args['category_name'] = $section['sourceValue'];
                        }
                        $posts = (new WP_Query($args))->posts;
                    }
                    if (empty($posts)) {
                        continue; // don't show an empty section for a slug with no posts yet
                    }
                    $sections[] = [
                        'id' => $section['id'],
                        'label' => $section['label'],
                        'displayType' => $section['displayType'] ?? 'mixed',
                        // Exposed so the app's "See all" action can hit the matching archive
                        // endpoint (feed/section/{category} vs feed/tag/{tag}) instead of always
                        // guessing a category slug from the display label — "Latest Stories" and
                        // "In Other News" are tag-sourced (bdrecent/bdothernews), and slugifying
                        // their labels into a category query that doesn't exist returned nothing.
                        'sourceType' => $section['sourceType'] ?? 'category',
                        'sourceValue' => $section['sourceValue'] ?? '',
                        'items' => $this->buildItems($posts),
                    ];
                }
            }

            $cached = ['sections' => $sections, 'page' => 1, 'hasMore' => false];
            BD_App_Cache::set($cacheKey, $cached, self::FEED_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::FEED_TTL);
        return $response;
    }

    public function handleLatest(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('pg'));
        $cacheKey = 'feed_latest_p' . $page;
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => self::PER_PAGE,
                'paged' => $page,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            $cached = [
                'items' => $this->buildItems($query->posts),
                'page' => $page,
                'hasMore' => $page < (int) $query->max_num_pages,
            ];
            BD_App_Cache::set($cacheKey, $cached, self::FEED_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::FEED_TTL);
        return $response;
    }

    public function handleSection(WP_REST_Request $request): WP_REST_Response
    {
        $slug = sanitize_title($request->get_param('slug'));
        $page = max(1, (int) $request->get_param('pg'));
        $cacheKey = 'feed_section_' . $slug . '_p' . $page;
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'category_name' => $slug,
                'posts_per_page' => self::PER_PAGE,
                'paged' => $page,
            ]);
            $cached = [
                'items' => $this->buildItems($query->posts),
                'page' => $page,
                'hasMore' => $page < (int) $query->max_num_pages,
            ];
            BD_App_Cache::set($cacheKey, $cached, self::FEED_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::FEED_TTL);
        return $response;
    }

    public function handleMostPopular(WP_REST_Request $request): WP_REST_Response
    {
        $cacheKey = 'feed_most_popular';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 5,
                'orderby' => 'comment_count',
                'no_found_rows' => true,
            ]);
            $cached = ['items' => $this->buildItems($query->posts)];
            BD_App_Cache::set($cacheKey, $cached, self::FEED_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::FEED_TTL);
        return $response;
    }

    public function handleTag(WP_REST_Request $request): WP_REST_Response
    {
        $slug = sanitize_title($request->get_param('slug'));
        $page = max(1, (int) $request->get_param('pg'));
        $cacheKey = 'feed_tag_' . $slug . '_p' . $page;
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'tag' => $slug,
                'posts_per_page' => self::PER_PAGE,
                'paged' => $page,
            ]);
            $cached = [
                'items' => $this->buildItems($query->posts),
                'page' => $page,
                'hasMore' => $page < (int) $query->max_num_pages,
            ];
            BD_App_Cache::set($cacheKey, $cached, self::FEED_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::FEED_TTL);
        return $response;
    }

    public function handleSearch(WP_REST_Request $request): WP_REST_Response
    {
        $term = sanitize_text_field((string) $request->get_param('q'));
        if (trim($term) === '') {
            return new WP_REST_Response(['items' => [], 'page' => 1, 'hasMore' => false], 200);
        }

        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            's' => $term,
            'posts_per_page' => self::PER_PAGE,
            'no_found_rows' => true,
        ]);

        $response = new WP_REST_Response([
            'items' => $this->buildItems($query->posts),
            'page' => 1,
            'hasMore' => false,
        ], 200);
        // Short, private (per-request-shape, not shared) cache — lets the OS HTTP cache dedupe a
        // reader re-issuing the exact same search within a few seconds, without WordPress ever
        // storing anything server-side for it.
        $response->header('Cache-Control', 'private, max-age=20');
        return $response;
    }

    /**
     * Slot 0 is always bday_get_hero_lead()'s result (the locked post if one's pinned, else the
     * newest 'bdlead'-tagged post) — the rest of the section's count is filled from the same tag
     * query the theme's rd_top_news uses, with the lead's own post excluded so it never appears
     * twice. Same shape as WP_Query::$posts either way, so buildItems() needs no special case.
     *
     * @return WP_Post[]
     */
    private function buildHeroPosts(array $section): array
    {
        $lead = bday_get_hero_lead();
        $leadId = $lead[0]->ID ?? 0;

        $restCount = max(0, (int) ($section['count'] ?? 6) - count($lead));
        $rest = [];
        if ($restCount > 0) {
            $rest = (new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'tag' => $section['sourceValue'],
                'posts_per_page' => $restCount,
                'offset' => max(0, (int) ($section['offset'] ?? 0)),
                'post__not_in' => $leadId ? [$leadId] : [],
                'no_found_rows' => true,
            ]))->posts;
        }

        return array_merge($lead, $rest);
    }

    /**
     * @param WP_Post[] $posts
     * @return array<int, array<string, mixed>>
     */
    // Public (not private) so other endpoints needing the same Article/FeedItem shape — e.g.
    // BD_Your_News_Api — reuse this instead of duplicating the thumbnail/excerpt/entitlement
    // logic a second time and risking drift.
    public function buildItems(array $posts): array
    {
        $items = [];
        foreach ($posts as $post) {
            $meta = BD_AeroPaywall_Bridge::getPostMeta($post->ID);
            $categories = get_the_category($post->ID);
            $primary = $categories[0] ?? null;
            $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
            $thumbnailId = get_post_thumbnail_id($post->ID);
            $imageUrl = $thumbnailId ? wp_get_attachment_image_url($thumbnailId, 'large') : null;
            $author = get_the_author_meta('display_name', $post->post_author);
            $wordCount = str_word_count(wp_strip_all_tags($post->post_content));

            // get_the_title() runs through the 'the_title' filter chain, which includes
            // wptexturize — that converts straight quotes/ampersands into literal HTML entity
            // text (e.g. "Bank's" -> "Bank&#8217;s"), not real Unicode characters. WP themes
            // render that through an HTML parser so it looks fine; a bare RN <Text> has no HTML
            // parser and shows the raw entity text. Category term names have the same issue for
            // '&'. Decode both here so the app never has to.
            $items[] = [
                'id' => $post->ID,
                'headline' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'dek' => html_entity_decode(
                    $meta['excerpt'] ?? wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 30),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                'section' => $primary ? html_entity_decode($primary->name, ENT_QUOTES, 'UTF-8') : 'News',
                'sectionSlug' => $primary ? $primary->slug : 'news',
                'author' => ['id' => (int) $post->post_author, 'name' => $author ?: 'BusinessDay'],
                'publishedAt' => get_post_time('c', true, $post),
                'isPremium' => $meta['isPremium'] ?? false,
                'imageUrl' => $imageUrl ?: null,
                // Editorial-meta metabox (core/editorial-meta.php on the theme) lets an editor set
                // a YouTube video as an article's featured media, via either field — the card-facade
                // add-on's dedicated field takes precedence over the single-post embed field when
                // both are set. Previously never exposed here, so the app had nothing to consume
                // even though the theme was saving it correctly.
                'featuredVideoId' => get_post_meta($post->ID, '_featured_video_id', true)
                    ?: (get_post_meta($post->ID, '_youtube_id', true) ?: null),
                'link' => get_permalink($post),
                'tags' => $tags ?: [],
                'commentCount' => (int) $post->comment_count,
                'readTimeMinutes' => max(1, (int) ceil($wordCount / self::WORDS_PER_MINUTE)),
            ];
        }
        return $items;
    }
}
