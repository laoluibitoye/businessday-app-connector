# BusinessDay App Connector

A companion WordPress plugin for the BusinessDay mobile app. It does **not** touch or replace
AeroPaywall's `connector-plugin` — the two run side by side, and this plugin never reads or
writes AeroPaywall's data. It exists to solve one problem AeroPaywall's mobile endpoint doesn't
try to solve: **serving high-volume, non-personalized reads (content listings, app config)
without a live database hit and a live entitlement check on every single request.**

## Why this exists

AeroPaywall's `GET /wp-json/aeropaywall/v1/articles/{id}` is, by design, always live — every call
does a DB read plus (for non-subscribers) an outbound HTTP call to `subscription-service`'s meter
endpoint. That's correct for the one thing it does (deciding whether *this specific reader* can
see *this specific article*, right now) — but it means naively calling it once per article, per
list, per render would multiply load by (readers × screens × items-per-screen) for no reason,
since a list screen doesn't need per-reader gating, just "is this premium" and a preview.

This plugin adds a second, purpose-built layer in front of that problem:

- **Feed endpoints** (`/wp-json/businessday-app/v1/feed/*`) return cached, non-personalized
  listings — the exact same JSON to every reader, rebuilt on a timer (3 min) or on post save, not
  per request. Each item's `isPremium` flag is read via one **in-process** call to AeroPaywall's
  own REST route per post (no network hop — see `includes/class-bd-aeropaywall-bridge.php`), but
  that only happens once per post per cache window, not once per reader per view.
- **The actual reading gate stays untouched.** When a reader opens a specific article, the app
  calls AeroPaywall's real endpoint directly, live, exactly as AeroPaywall intends — this plugin
  never proxies, caches, or duplicates that call. Load stays proportional to real article opens,
  which is the correct place for it to scale with usage.
- **App config** (`/wp-json/businessday-app/v1/config`) — banners, ad-slot config, and
  app-specific paywall copy, all editable from wp-admin, all cached the same way.

None of this requires a persistent object cache (Redis/Memcached) to be useful — every cache
entry here is shared across *all* readers, not per-reader, so it doesn't grow with traffic the
way a per-user cache would. If a CDN (Cloudflare, etc.) sits in front of the site, the
`Cache-Control: public, s-maxage=...` headers on these endpoints let it absorb almost all of this
traffic before it reaches WordPress at all.

## Requirements

- WordPress with AeroPaywall's `connector-plugin` active (this plugin degrades gracefully —
  everything is treated as non-premium/open if AeroPaywall isn't active — but it's meant to run
  alongside it).
- PHP 8.0+.

## Install

1. Copy this directory into `wp-content/plugins/businessday-app-connector`.
2. Activate it from **Plugins** in wp-admin.
3. A new **BusinessDay App** menu appears in the sidebar with three editorial pages (Banners,
   Ads, Paywall Copy) and a Cache page with a manual purge button.

## Endpoints

| Endpoint | Cached? | Notes |
|---|---|---|
| `GET /wp-json/businessday-app/v1/feed/home` | Yes, 3 min | Home feed |
| `GET /wp-json/businessday-app/v1/feed/latest?page=1` | Yes, 3 min | Reverse-chronological, paginated |
| `GET /wp-json/businessday-app/v1/feed/section/{slug}?page=1` | Yes, 3 min | By category slug |
| `GET /wp-json/businessday-app/v1/search?q=...` | No (private, 20s client-side only) | Live `WP_Query` search |
| `GET /wp-json/businessday-app/v1/config` | Yes, 5 min | Banners, ad slots, paywall copy |

Full article content and entitlement are **not** served here — the app calls AeroPaywall's own
`GET /wp-json/aeropaywall/v1/articles/{id}` directly for that, per-article, on open.

## Configuring the mobile app

Point the app's `EXPO_PUBLIC_WP_BASE_URL` env var at this WordPress site's base URL (the same
site the AeroPaywall `connector-plugin` is installed on — both plugins' REST routes live under
one host, just different namespaces: `aeropaywall/v1` vs `businessday-app/v1`).
