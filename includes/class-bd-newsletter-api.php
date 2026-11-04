<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter signup — bridges the theme's existing FluentCRM connection
 * (addons/newsletter-fluentcrm/includes/client.php) rather than reimplementing it. Reuses
 * bday_newsletter_get_lists() for listing and calls the same remote fc-bridge/v1/subscribe
 * endpoint the website's own AJAX handler (includes/shortcode.php) calls, so a subscribe from
 * the app lands in the exact same FluentCRM lists as one from the website.
 */
class BD_Newsletter_Api
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';
    const TTL = 300;

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
        register_rest_route(self::NAMESPACE, '/newsletters', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleList'],
        ]);

        register_rest_route(self::NAMESPACE, '/newsletters/subscribe', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleSubscribe'],
            'args' => [
                'email' => ['required' => true],
                'lists' => ['required' => false],
                'detach_lists' => ['required' => false],
            ],
        ]);
    }

    public function handleList(): WP_REST_Response
    {
        if (!function_exists('bday_newsletter_get_lists')) {
            return new WP_REST_Response(['lists' => []], 200);
        }

        $cacheKey = 'newsletter_lists';
        $cached = BD_App_Cache::get($cacheKey);
        if ($cached === false) {
            $lists = bday_newsletter_get_lists();
            $descriptions = function_exists('bday_newsletter_setting')
                ? (array) bday_newsletter_setting('list_descriptions', [])
                : [];
            $visible = function_exists('bday_newsletter_setting')
                ? (array) bday_newsletter_setting('visible_lists', [])
                : [];

            $items = [];
            foreach ($lists as $list) {
                $id = (string) ($list['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                if (!empty($visible) && !in_array($id, $visible, true)) {
                    continue;
                }
                $items[] = [
                    'id' => $id,
                    'title' => (string) ($list['title'] ?? $list['name'] ?? 'Newsletter'),
                    'description' => (string) ($descriptions[$id] ?? ''),
                ];
            }

            $cached = ['lists' => $items];
            BD_App_Cache::set($cacheKey, $cached, self::TTL);
        }

        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }

    public function handleSubscribe(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('bday_newsletter_api_request')) {
            return new WP_REST_Response(['message' => 'Newsletter signup is not configured.'], 503);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if (!is_email($email)) {
            return new WP_REST_Response(['message' => 'Enter a valid email address.'], 400);
        }

        $lists = (array) $request->get_param('lists');
        $lists = array_values(array_filter(array_map('sanitize_text_field', $lists)));

        $detachLists = (array) $request->get_param('detach_lists');
        $detachLists = array_values(array_filter(array_map('sanitize_text_field', $detachLists)));

        if (empty($lists) && empty($detachLists)) {
            return new WP_REST_Response(['message' => 'Choose at least one newsletter.'], 400);
        }

        $firstName = sanitize_text_field((string) $request->get_param('firstName'));

        $result = bday_newsletter_api_request('subscribe', 'POST', [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => '',
            'lists' => $lists,
            'detach_lists' => $detachLists,
        ]);

        if (is_wp_error($result)) {
            return new WP_REST_Response(['message' => 'Could not complete signup. Please try again.'], 502);
        }

        return new WP_REST_Response(['subscribed' => true], 200);
    }
}
