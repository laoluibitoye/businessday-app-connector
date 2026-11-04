<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serves the two well-known link-verification files so tapping a shared article link opens the
 * app instead of the mobile browser, on both platforms:
 *  - iOS Universal Links: `/.well-known/apple-app-site-association` (and, as a fallback some
 *    older iOS versions still check, bare `/apple-app-site-association`).
 *  - Android App Links: `/.well-known/assetlinks.json`.
 * Neither is something a normal WP page/rewrite can produce (no file extension, exact path, JSON
 * body) — this hooks `init` directly, before WordPress does any real routing, and short-circuits
 * the request.
 */
class BD_Universal_Links
{
    private static ?self $instance = null;

    // FU9T32FCB5 is the app's real Apple Developer Team ID (see eas.json's `submit.production.ios`),
    // bdnews.businessday.bdtech its bundle identifier (see app.json).
    const APP_ID = 'FU9T32FCB5.bdnews.businessday.bdtech';

    const ANDROID_PACKAGE = 'bdnews.businessday.bdtech';

    // SHA-256 of the app's release-signing certificate (the "Google Play Key Store
    // (App Sign bdnews.businessday.bdtech)/bdnewsapp.jks" keystore) — extracted via
    // `keytool -list -v -keystore bdnewsapp.jks -alias bdnewsapp`. Re-verify this against Google
    // Play Console's App Integrity page once Play App Signing is confirmed enrolled with this
    // same key — if Google re-signs with a different certificate, THAT fingerprint is the one
    // that actually needs to be here, not this one.
    const ANDROID_SHA256_FINGERPRINT = '60:C6:0D:E7:37:9D:F2:93:C0:70:20:86:B2:30:E8:CB:ED:33:B9:A9:25:4A:A0:8A:67:6F:F4:5D:BC:81:C5:5D';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            add_action('init', [self::$instance, 'maybeServe'], 0);
        }
        return self::$instance;
    }

    public function maybeServe(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        if ($uri === '/.well-known/apple-app-site-association' || $uri === '/apple-app-site-association') {
            $this->serveAppleAppSiteAssociation();
        }

        if ($uri === '/.well-known/assetlinks.json') {
            $this->serveAndroidAssetLinks();
        }
    }

    private function serveAppleAppSiteAssociation(): void
    {
        header('Content-Type: application/json');
        echo wp_json_encode([
            'applinks' => [
                'apps' => [],
                'details' => [
                    [
                        'appID' => self::APP_ID,
                        'paths' => [
                            'NOT /wp-admin/*',
                            'NOT /wp-json/*',
                            'NOT /wp-login.php',
                            '/*',
                        ],
                    ],
                ],
            ],
        ]);
        exit;
    }

    private function serveAndroidAssetLinks(): void
    {
        header('Content-Type: application/json');
        echo wp_json_encode([
            [
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => self::ANDROID_PACKAGE,
                    'sha256_cert_fingerprints' => [self::ANDROID_SHA256_FINGERPRINT],
                ],
            ],
        ]);
        exit;
    }
}
