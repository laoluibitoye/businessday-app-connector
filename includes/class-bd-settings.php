<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage for everything editorially-owned by the app team that isn't a gating decision:
 * promotional banners, ad-slot config, and app-specific paywall copy. All of it lives in its own
 * wp_options rows, entirely separate from AeroPaywall's settings — this plugin never reads or
 * writes AeroPaywall's option keys.
 */
class BD_App_Settings
{
    const BANNERS_OPTION = 'bd_app_banners';
    const AD_SLOTS_OPTION = 'bd_app_ad_slots';
    const PAYWALL_COPY_OPTION = 'bd_app_paywall_copy';
    const HOME_SECTIONS_OPTION = 'bd_app_home_sections';
    const HOME_TABS_OPTION = 'bd_app_home_tabs';
    const TODAYS_PAPER_OPTION = 'bd_app_todays_paper';
    const FEATURES_OPTION = 'bd_app_features';

    const PLACEMENTS = ['home_top', 'home_mid', 'explore_top', 'article_footer', 'latest_top'];
    const AD_PLACEMENTS = ['home_feed', 'article_body', 'explore_feed'];
    const PAYWALL_STAGES = ['register_prompt', 'profile_prompt', 'paid_lock'];
    const SOURCE_TYPES = ['category', 'tag'];
    // How a section's articles render on the app's Home tab. `mixed` (default) cycles the same
    // hero/brief-rail/tile-grid/text-list/card-list variety category archives use; the rest force
    // every article in the section into that one module shape (e.g. `tileGrid` = a single 2-column
    // grid for the whole section, `briefRail` = one horizontal-scroll rail).
    const DISPLAY_TYPES = ['mixed', 'hero', 'briefRail', 'tileGrid', 'textList', 'cardList'];

    public static function getBanners(): array
    {
        return get_option(self::BANNERS_OPTION, []);
    }

    public static function saveBanners(array $banners): void
    {
        update_option(self::BANNERS_OPTION, $banners, false);
    }

    /** Only banners that are active and within their optional date window. */
    public static function getActiveBanners(): array
    {
        $now = time();
        return array_values(array_filter(self::getBanners(), function ($banner) use ($now) {
            if (empty($banner['active'])) {
                return false;
            }
            if (!empty($banner['startsAt']) && strtotime($banner['startsAt']) > $now) {
                return false;
            }
            if (!empty($banner['endsAt']) && strtotime($banner['endsAt']) < $now) {
                return false;
            }
            return true;
        }));
    }

    public static function getAdSlots(): array
    {
        $stored = get_option(self::AD_SLOTS_OPTION, []);
        $defaults = [];
        foreach (self::AD_PLACEMENTS as $placement) {
            $defaults[$placement] = [
                'placement' => $placement,
                'enabled' => false,
                'adUnitId' => '',
                'houseAdImageUrl' => '',
                'houseAdLinkUrl' => '',
            ];
        }
        return array_replace($defaults, $stored);
    }

    public static function saveAdSlots(array $slots): void
    {
        update_option(self::AD_SLOTS_OPTION, $slots, false);
    }

    public static function getPaywallCopy(): array
    {
        $stored = get_option(self::PAYWALL_COPY_OPTION, []);
        $defaults = [
            'register_prompt' => [
                'headline' => 'Create a free account to keep reading',
                'body' => "You've hit your free-article limit. Sign up to continue.",
                'buttonLabel' => 'Sign up free',
                'offerBadge' => '',
            ],
            'profile_prompt' => [
                'headline' => 'Complete your profile',
                'body' => 'Tell us a bit more about you to keep reading free articles.',
                'buttonLabel' => 'Complete profile',
                'offerBadge' => '',
            ],
            'paid_lock' => [
                'headline' => 'Subscribe to keep reading',
                'body' => 'This story is for subscribers. Unlock unlimited access to BusinessDay.',
                'buttonLabel' => 'See plans',
                'offerBadge' => '',
            ],
        ];
        return array_replace_recursive($defaults, $stored);
    }

    public static function savePaywallCopy(array $copy): void
    {
        update_option(self::PAYWALL_COPY_OPTION, $copy, false);
    }

    // Home screen sections — an ordered, editor-defined list of "label + taxonomy source +
    // count/offset" slots, replacing what used to be a hardcoded module sequence in the app.
    // Verified against the actual theme (IMPLEMENTATION_PLAN.md §18 audit): editors' real
    // category/tag vocabulary includes things like `bdlead`, `weekender`, `Columnist`, `opinion`
    // — this stores whatever slugs an editor picks, doesn't hardcode a list.
    public static function getHomeSections(): array
    {
        return get_option(self::HOME_SECTIONS_OPTION, []);
    }

    public static function saveHomeSections(array $sections): void
    {
        update_option(self::HOME_SECTIONS_OPTION, $sections, false);
    }

    public static function getActiveHomeSections(): array
    {
        return array_values(array_filter(self::getHomeSections(), fn($s) => !empty($s['active'])));
    }

    // The app's Home tab strip (Today | Top Stories | Banking | ...) — an ordered, editor-defined
    // list of "label + category-or-tag source" tabs, replacing what used to be a hardcoded label
    // list in the app (BDay_Mobile_2026/app/src/data/mock.ts's `sections`). "Today" itself isn't
    // stored here — it's always the fixed first tab, backed by getActiveHomeSections() above, not
    // a taxonomy archive.
    public static function getHomeTabs(): array
    {
        return get_option(self::HOME_TABS_OPTION, []);
    }

    public static function saveHomeTabs(array $tabs): void
    {
        update_option(self::HOME_TABS_OPTION, $tabs, false);
    }

    // Today's Paper — a manually-curated set of post IDs for a given print-edition date, plus
    // the edition PDF link. Deliberately NOT a taxonomy query (the theme's own "latest post in
    // category e-paper" pattern doesn't date-match at all, and mixing manual curation with a
    // live category query would let unrelated same-category posts leak in — see §18 audit).
    public static function getTodaysPaper(): array
    {
        return get_option(self::TODAYS_PAPER_OPTION, ['date' => '', 'pdfUrl' => '', 'postIds' => []]);
    }

    public static function saveTodaysPaper(array $paper): void
    {
        update_option(self::TODAYS_PAPER_OPTION, $paper, false);
    }

    // Editor-requested (2026-09-18): the follow-a-category/tag feature (post-signup Interest
    // Picker, and Settings' "Your interests"/"Feed settings" entries) is hidden while it's
    // reworked. `followEnabled` defaults to false (hidden) — the one feature flag here that does
    // NOT default to "on", since that's the actually-desired current state, same as the website's
    // Bday_Aero_Settings::follow_feature_enabled().
    public static function getFeatures(): array
    {
        $defaults = ['followEnabled' => false];
        return array_replace($defaults, get_option(self::FEATURES_OPTION, []));
    }

    public static function saveFeatures(array $features): void
    {
        update_option(self::FEATURES_OPTION, $features, false);
    }
}
