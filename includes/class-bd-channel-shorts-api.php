<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The app's Shorts tab is driven directly from the @BDTV-NG YouTube channel's own Shorts shelf,
 * replacing the old editorial workflow (a WordPress post in the `video` format with a pasted
 * youtube.com/shorts/ link — see class-bd-videos-api.php) which sat mostly unused. There is no
 * public YouTube Data API endpoint that lists a channel's Shorts specifically (channels.list /
 * playlistItems.list only expose regular uploads), so this scrapes the same `ytInitialData` JSON
 * blob the channel page itself renders from — exactly what a browser loading
 * https://www.youtube.com/@BDTV-NG/shorts parses client-side. Confirmed live (2026-09-25): the
 * page's Shorts tab defaults to the "Latest" chip selected, so the shelf order is already newest
 * first — no separate date field is even exposed per item to sort by.
 *
 * This is inherently coupled to YouTube's current page structure (`shortsLockupViewModel`) and can
 * break silently if YouTube changes it. Mitigated two ways: heavily cached (TTL below) so a break
 * is caught well before it's expensive, and a failed/empty scrape never overwrites the last good
 * result (see `bd_channel_shorts_last_good`) — the tab keeps showing the last real list instead of
 * going blank the moment YouTube's markup shifts.
 */
class BD_Channel_Shorts_Api
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';
    const TTL = 900; // a scrape, not a real API — no reason to hit YouTube more often than this
    const CHANNEL_HANDLE = '@BDTV-NG';
    const MAX_ITEMS = 30;
    const LAST_GOOD_OPTION = 'bd_channel_shorts_last_good';

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
        register_rest_route(self::NAMESPACE, '/channel-shorts', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cacheKey = 'channel_shorts';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $items = self::fetchFromYoutube();
            if ($items === null) {
                $stale = get_option(self::LAST_GOOD_OPTION);
                $cached = ['items' => is_array($stale) ? $stale : []];
                // Deliberately not re-cached via BD_App_Cache — a short TTL-free retry next
                // request costs nothing (still serves the stale list either way) and recovers
                // sooner once YouTube is reachable/parseable again.
            } else {
                $cached = ['items' => $items];
                update_option(self::LAST_GOOD_OPTION, $items, false);
                BD_App_Cache::set($cacheKey, $cached, self::TTL);
            }
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }

    /** @return array<int, array{videoId:string,title:string,thumbnailUrl:?string}>|null */
    private static function fetchFromYoutube(): ?array
    {
        // `gl=US&hl=en` plus both cookies below are needed to reliably skip YouTube's EU
        // cookie-consent interstitial ("Before you continue to YouTube") — confirmed live: this
        // host's outbound IP got served that interstitial instead of the channel page with just
        // CONSENT=YES+1 (which was enough from a non-EU/residential IP when this was first built
        // and tested). SOCS is the second cookie Google's own consent flow itself sets once a
        // visitor accepts; sending it pre-emptively is what actually satisfies the check.
        $url = 'https://www.youtube.com/' . self::CHANNEL_HANDLE . '/shorts?gl=US&hl=en&persist_gl=1';
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cookie' => 'CONSENT=YES+1; SOCS=CAI',
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!preg_match('#var ytInitialData = (\{.*?\});</script>#s', $body, $m)) {
            return null;
        }

        $data = json_decode($m[1], true);
        if (!is_array($data)) {
            return null;
        }

        $tabs = $data['contents']['twoColumnBrowseResultsRenderer']['tabs'] ?? null;
        if (!is_array($tabs)) {
            return null;
        }

        $shortsTab = null;
        foreach ($tabs as $tab) {
            if (($tab['tabRenderer']['title'] ?? null) === 'Shorts') {
                $shortsTab = $tab['tabRenderer'];
                break;
            }
        }
        if ($shortsTab === null) {
            return null;
        }

        $contents = $shortsTab['content']['richGridRenderer']['contents'] ?? null;
        if (!is_array($contents)) {
            return null;
        }

        $items = [];
        foreach ($contents as $entry) {
            $model = $entry['richItemRenderer']['content']['shortsLockupViewModel'] ?? null;
            if (!is_array($model)) {
                continue; // the trailing continuation-token entry, not a real Short
            }

            $videoId = $model['onTap']['innertubeCommand']['reelWatchEndpoint']['videoId'] ?? null;
            $title = $model['overlayMetadata']['primaryText']['content'] ?? null;
            if (!is_string($videoId) || $videoId === '' || !is_string($title)) {
                continue;
            }

            $thumbSources = $model['thumbnailViewModel']['thumbnailViewModel']['image']['sources'] ?? null;
            $thumbnailUrl = is_array($thumbSources) && !empty($thumbSources[0]['url'])
                ? $thumbSources[0]['url']
                : "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg";

            $items[] = [
                'videoId' => $videoId,
                'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                'thumbnailUrl' => $thumbnailUrl,
            ];

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items === [] ? null : $items;
    }
}
