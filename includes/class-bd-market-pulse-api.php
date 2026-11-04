<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Market Pulse — the site's real, admin-edited market-data ticker (Appearance → BusinessDay
 * Theme → Market Pulse, option `bday_market_pulse`). The theme deliberately removed its one live
 * external feed (NGN/USD via open.er-api.com) after it caused intermittent server 502/504s from
 * a synchronous unlocked HTTP call in the page-render path — this endpoint must not reintroduce
 * that. It only ever surfaces the same admin-typed figures the website itself trusts, via the
 * theme's own normalization function (`bday_market_pulse_normalize()`,
 * addons/market-pulse/includes/admin.php, unconditionally loaded — not admin-only), so an editor
 * updating one figure updates both surfaces at once.
 */
class BD_Market_Pulse_Api
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
        register_rest_route(self::NAMESPACE, '/market-pulse', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $cached = BD_App_Cache::get('market_pulse');
        if ($cached === false) {
            $cached = function_exists('bday_market_pulse_normalize')
                ? bday_market_pulse_normalize(get_option('bday_market_pulse', []))
                : ['items' => [], 'scroll_seconds' => 30];
            BD_App_Cache::set('market_pulse', $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }
}
