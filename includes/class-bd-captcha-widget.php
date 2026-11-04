<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serves a bare HTML page that renders a Cloudflare Turnstile / reCAPTCHA widget, for the mobile
 * app's WebView to load as a REAL navigation to this site's own domain.
 *
 * Why this exists: the widget's site key is domain-restricted in the provider's dashboard to this
 * site's hostname. A WebView loading inline HTML with a spoofed origin (react-native-webview's
 * `baseUrl` option) gives the provider's script a plausible `window.location`, but not a genuine
 * browsing context (real network navigation, Referer/Sec-Fetch headers) — Cloudflare's managed
 * challenge hung indefinitely under that setup before eventually failing with error 300031.
 * Serving the exact same widget markup from a real URL on this domain gives it a legitimate
 * context to validate against, matching what already works for browser-based signup.
 *
 * `provider`/`siteKey` are passed through from the client rather than looked up here — both are
 * public values already fetched by every caller from subscription-service's own
 * `/api/v1/public/paywall-config` endpoint (see sdk/src/captcha.ts and
 * BDay_Mobile_2026/app/src/lib/api/publicConfig.ts), never secret. Escaped on output regardless.
 */
class BD_Captcha_Widget
{
    private static ?self $instance = null;
    const NAMESPACE = 'businessday-app/v1';

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
        register_rest_route(self::NAMESPACE, '/captcha-widget', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleCaptchaWidget'],
        ]);
    }

    // Echoes raw HTML and exits rather than returning a WP_REST_Response — the response must be
    // real text/html for a WebView to navigate to, not the REST API's default JSON envelope.
    public function handleCaptchaWidget(WP_REST_Request $request): void
    {
        $provider = $request->get_param('provider') === 'recaptcha' ? 'recaptcha' : 'turnstile';
        $siteKey = sanitize_text_field((string) $request->get_param('siteKey'));

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $this->buildHtml($provider, $siteKey);
        exit;
    }

    private function buildHtml(string $provider, string $siteKey): string
    {
        $siteKeyJs = esc_js($siteKey);
        $scriptSrc = $provider === 'recaptcha'
            ? 'https://www.google.com/recaptcha/api.js?render=explicit'
            : 'https://challenges.cloudflare.com/turnstile/v0/api.js';
        $renderCall = $provider === 'recaptcha'
            ? "window.grecaptcha.render('captcha', { sitekey: '{$siteKeyJs}', callback: onToken, 'expired-callback': onExpire })"
            : "window.turnstile.render('#captcha', { sitekey: '{$siteKeyJs}', callback: onToken, 'expired-callback': onExpire, 'error-callback': onError })";
        $readyCheck = $provider === 'recaptcha' ? 'window.grecaptcha && window.grecaptcha.render' : 'window.turnstile && window.turnstile.render';

        return <<<HTML
<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>body{margin:0;display:flex;justify-content:center;background:transparent;}</style>
<script src="{$scriptSrc}" async defer></script>
</head><body>
<div id="captcha"></div>
<script>
  function onToken(token) { window.ReactNativeWebView.postMessage(token); }
  function onExpire() { window.ReactNativeWebView.postMessage('__EXPIRED__'); }
  function onError(code) { window.ReactNativeWebView.postMessage('__ERROR__' + code); }
  window.onload = function poll() {
    if ({$readyCheck}) {
      {$renderCall};
    } else {
      setTimeout(poll, 100);
    }
  };
</script>
</body></html>
HTML;
    }
}
