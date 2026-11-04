<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads premium status from AeroPaywall's connector-plugin — the single source of truth for
 * whether a post is gated. This plugin never re-implements that decision and never reads
 * AeroPaywall's internal data structures directly (no coupling to its meta keys/taxonomy, which
 * could change without notice); it only ever calls AeroPaywall's own public REST contract,
 * `GET /wp-json/aeropaywall/v1/articles/{id}`.
 *
 * Critically, this is dispatched IN-PROCESS via `rest_do_request()`, not over HTTP — same
 * webserver process, no network round-trip, no extra TCP/TLS cost. That's what makes it cheap
 * enough to call once per post during a feed-cache rebuild (see class-bd-feed-api.php), rather
 * than something that has to be avoided.
 *
 * The dispatched request carries no Authorization/X-Device-Id headers, so AeroPaywall always
 * resolves it down the anonymous, no-device path — per AeroPaywall's own documented behavior,
 * `isPremium`/`title`/`excerpt` are still returned in that path regardless of gating; only
 * `content`/`preview` are withheld (correctly — a feed listing has no business reading full
 * article bodies through this path, and we never ask for them here).
 */
class BD_AeroPaywall_Bridge
{
    public static function isAvailable(): bool
    {
        // Match by namespace prefix, not the exact registered pattern string — matching the
        // literal pattern is brittle (verified live: AeroPaywall registers
        // '/articles/(?P<id>\d+)', and a byte-for-byte mismatch here silently made every post
        // look non-premium, since `getPostMeta()` short-circuits to null on a false negative).
        $server = rest_get_server();
        foreach (array_keys($server->get_routes()) as $route) {
            if (str_starts_with($route, '/aeropaywall/v1/articles/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{isPremium: bool, excerpt: string}|null null if AeroPaywall isn't active/
     *   reachable — callers must treat that as "unknown, default to open" rather than fatal.
     */
    public static function getPostMeta(int $postId): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        $request = new WP_REST_Request('GET', '/aeropaywall/v1/articles/' . $postId);
        $response = rest_do_request($request);

        if ($response->is_error()) {
            return null;
        }

        $data = $response->get_data();
        if (!is_array($data) || !isset($data['isPremium'])) {
            return null;
        }

        return [
            'isPremium' => (bool) $data['isPremium'],
            'excerpt' => (string) ($data['excerpt'] ?? ''),
        ];
    }
}
