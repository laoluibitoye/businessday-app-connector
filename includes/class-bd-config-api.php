<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public, cached, non-personalized: banners, ad-slot config, and paywall copy — everything an
 * editor manages from wp-admin without an app release. Same caching rationale as the feed
 * endpoints (class-bd-feed-api.php): one shared cache entry, not one per reader.
 */
class BD_App_Config_Api
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';
    const CONFIG_TTL = 300; // 5 min

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
        register_rest_route(self::NAMESPACE, '/config', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleConfig'],
        ]);
    }

    public function handleConfig(): WP_REST_Response
    {
        $cacheKey = 'app_config';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $cached = [
                'banners' => array_map(function ($banner) {
                    return [
                        'id' => $banner['id'],
                        'placement' => $banner['placement'],
                        'title' => $banner['title'],
                        'imageUrl' => $banner['imageUrl'],
                        'linkUrl' => $banner['linkUrl'] ?? null,
                    ];
                }, BD_App_Settings::getActiveBanners()),
                'adSlots' => array_values(array_filter(
                    BD_App_Settings::getAdSlots(),
                    fn($slot) => $slot['enabled'] || ($slot['houseAdImageUrl'] ?? '') !== ''
                )),
                'paywallCopy' => BD_App_Settings::getPaywallCopy(),
                'features' => BD_App_Settings::getFeatures(),
                'homeTabs' => array_map(function ($tab) {
                    return [
                        'label' => $tab['label'],
                        'sourceType' => $tab['sourceType'],
                        'sourceValue' => $tab['sourceValue'],
                    ];
                }, BD_App_Settings::getHomeTabs()),
            ];
            BD_App_Cache::set($cacheKey, $cached, self::CONFIG_TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::CONFIG_TTL);
        return $response;
    }
}
