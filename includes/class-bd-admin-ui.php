<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plain wp-admin PHP pages — deliberately not a React/Vite admin app (IMPLEMENTATION_PLAN.md
 * §16.2's explicit choice for this piece: "plain PHP admin page + one REST route, no separate
 * React admin app"). Three editorial surfaces plus a manual cache-purge button.
 */
class BD_App_Admin_Ui
{
    private static ?self $instance = null;
    const CAPABILITY = 'manage_options';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerHooks();
        }
        return self::$instance;
    }

    private function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_bd_app_save_banners', [$this, 'handleSaveBanners']);
        add_action('admin_post_bd_app_save_ads', [$this, 'handleSaveAds']);
        add_action('admin_post_bd_app_save_paywall_copy', [$this, 'handleSavePaywallCopy']);
        add_action('admin_post_bd_app_save_home_sections', [$this, 'handleSaveHomeSections']);
        add_action('admin_post_bd_app_save_home_tabs', [$this, 'handleSaveHomeTabs']);
        add_action('admin_post_bd_app_save_todays_paper', [$this, 'handleSaveTodaysPaper']);
        add_action('admin_post_bd_app_save_features', [$this, 'handleSaveFeatures']);
        add_action('admin_post_bd_app_purge_cache', [$this, 'handlePurgeCache']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'BusinessDay App',
            'BusinessDay App',
            self::CAPABILITY,
            'bd-app-banners',
            [$this, 'renderBanners'],
            'dashicons-smartphone',
            58
        );
        add_submenu_page('bd-app-banners', 'Banners', 'Banners', self::CAPABILITY, 'bd-app-banners', [$this, 'renderBanners']);
        add_submenu_page('bd-app-banners', 'Home Sections', 'Home Sections', self::CAPABILITY, 'bd-app-home-sections', [$this, 'renderHomeSections']);
        add_submenu_page('bd-app-banners', 'Home Tabs', 'Home Tabs', self::CAPABILITY, 'bd-app-home-tabs', [$this, 'renderHomeTabs']);
        add_submenu_page('bd-app-banners', "Today's Paper", "Today's Paper", self::CAPABILITY, 'bd-app-todays-paper', [$this, 'renderTodaysPaper']);
        add_submenu_page('bd-app-banners', 'Ads', 'Ads', self::CAPABILITY, 'bd-app-ads', [$this, 'renderAds']);
        add_submenu_page('bd-app-banners', 'Paywall Copy', 'Paywall Copy', self::CAPABILITY, 'bd-app-paywall-copy', [$this, 'renderPaywallCopy']);
        add_submenu_page('bd-app-banners', 'Features', 'Features', self::CAPABILITY, 'bd-app-features', [$this, 'renderFeatures']);
        add_submenu_page('bd-app-banners', 'Cache', 'Cache', self::CAPABILITY, 'bd-app-cache', [$this, 'renderCache']);
    }

    // ---- Home Sections ----

    public function renderHomeSections(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $sections = BD_App_Settings::getHomeSections();
        ?>
        <div class="wrap">
            <h1>Home Sections</h1>
            <p><strong>Fallback only.</strong> The app's Home tab now reads section order/visibility directly from the theme's own Homepage Sections settings (Appearance → BusinessDay Theme → Homepage Sections) — the same screen that controls the website's homepage, so an editor only configures it once. Anything saved below is only used if that theme setting is ever empty (e.g. a very old install).</p>
            <p>Each row is one section on the app's Home tab, in this order. Source is a category or tag slug — use the real slugs editors already tag with (e.g. <code>weekender</code>, <code>Columnist</code>, <code>opinion</code>, or a tag like <code>bdlead</code>). A section with no matching posts is skipped rather than shown empty. Display type controls how that section's articles render in the app — "Mixed (magazine)" cycles hero/rail/grid/list layouts the way the rest of Home does; the other options force every article in the section into that one layout.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_save_home_sections" />
                <?php wp_nonce_field('bd_app_save_home_sections'); ?>
                <table class="widefat" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th>Active</th><th>Label</th><th>Source type</th><th>Source (slug)</th>
                            <th>Count</th><th>Offset</th><th>Display type</th><th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections as $i => $section): ?>
                            <tr>
                                <td><input type="checkbox" name="sections[<?php echo $i; ?>][active]" <?php checked(!empty($section['active'])); ?> /></td>
                                <td><input type="text" name="sections[<?php echo $i; ?>][label]" value="<?php echo esc_attr($section['label'] ?? ''); ?>" /></td>
                                <td>
                                    <select name="sections[<?php echo $i; ?>][sourceType]">
                                        <?php foreach (BD_App_Settings::SOURCE_TYPES as $t): ?>
                                            <option value="<?php echo esc_attr($t); ?>" <?php selected($section['sourceType'] ?? '', $t); ?>><?php echo esc_html($t); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="sections[<?php echo $i; ?>][sourceValue]" value="<?php echo esc_attr($section['sourceValue'] ?? ''); ?>" /></td>
                                <td><input type="number" min="1" max="20" name="sections[<?php echo $i; ?>][count]" value="<?php echo esc_attr($section['count'] ?? 6); ?>" style="width:60px;" /></td>
                                <td><input type="number" min="0" name="sections[<?php echo $i; ?>][offset]" value="<?php echo esc_attr($section['offset'] ?? 0); ?>" style="width:60px;" /></td>
                                <td>
                                    <select name="sections[<?php echo $i; ?>][displayType]">
                                        <?php foreach (BD_App_Settings::DISPLAY_TYPES as $dt): ?>
                                            <option value="<?php echo esc_attr($dt); ?>" <?php selected($section['displayType'] ?? 'mixed', $dt); ?>><?php echo esc_html($this->displayTypeLabel($dt)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="checkbox" name="sections[<?php echo $i; ?>][delete]" /></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><input type="checkbox" name="new_section[active]" checked /></td>
                            <td><input type="text" name="new_section[label]" placeholder="e.g. Markets" /></td>
                            <td>
                                <select name="new_section[sourceType]">
                                    <?php foreach (BD_App_Settings::SOURCE_TYPES as $t): ?>
                                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="new_section[sourceValue]" placeholder="e.g. markets" /></td>
                            <td><input type="number" min="1" max="20" name="new_section[count]" value="6" style="width:60px;" /></td>
                            <td><input type="number" min="0" name="new_section[offset]" value="0" style="width:60px;" /></td>
                            <td>
                                <select name="new_section[displayType]">
                                    <?php foreach (BD_App_Settings::DISPLAY_TYPES as $dt): ?>
                                        <option value="<?php echo esc_attr($dt); ?>"><?php echo esc_html($this->displayTypeLabel($dt)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>—</td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Save home sections'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSaveHomeSections(): void
    {
        $this->assertNonce('bd_app_save_home_sections');

        $existing = BD_App_Settings::getHomeSections();
        $submitted = $_POST['sections'] ?? [];
        $result = [];

        foreach ($existing as $i => $section) {
            $row = $submitted[$i] ?? null;
            if ($row === null || !empty($row['delete'])) {
                continue;
            }
            $result[] = $this->sanitizeSection($row, $section['id']);
        }

        $new = $_POST['new_section'] ?? [];
        if (!empty($new['label']) && !empty($new['sourceValue'])) {
            $result[] = $this->sanitizeSection($new, wp_generate_uuid4());
        }

        BD_App_Settings::saveHomeSections($result);
        do_action('bd_app_settings_saved');
        $this->redirectBack('bd-app-home-sections');
    }

    private function sanitizeSection(array $row, string $id): array
    {
        return [
            'id' => $id,
            'active' => !empty($row['active']),
            'label' => sanitize_text_field($row['label'] ?? ''),
            'sourceType' => in_array($row['sourceType'] ?? '', BD_App_Settings::SOURCE_TYPES, true) ? $row['sourceType'] : 'category',
            'sourceValue' => sanitize_title($row['sourceValue'] ?? ''),
            'count' => max(1, min(20, (int) ($row['count'] ?? 6))),
            'offset' => max(0, (int) ($row['offset'] ?? 0)),
            'displayType' => in_array($row['displayType'] ?? '', BD_App_Settings::DISPLAY_TYPES, true) ? $row['displayType'] : 'mixed',
        ];
    }

    private function displayTypeLabel(string $type): string
    {
        $labels = [
            'mixed' => 'Mixed (magazine)',
            'hero' => 'Hero cards',
            'briefRail' => 'Horizontal rail',
            'tileGrid' => 'Tile grid',
            'textList' => 'Text list',
            'cardList' => 'Card list',
        ];
        return $labels[$type] ?? $type;
    }

    // ---- Home Tabs ----

    public function renderHomeTabs(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $tabs = BD_App_Settings::getHomeTabs();
        ?>
        <div class="wrap">
            <h1>Home Tabs</h1>
            <p>The category strip on the app's Home tab, right under the header — "Today" is always the fixed first tab (the app's own curated feed, configured on the Home Sections screen); everything below is additional tabs a reader can switch to, each one a category or tag archive. Source is a real category or tag slug, same vocabulary as Home Sections (e.g. <code>markets</code>, <code>banking</code>, <code>opinion</code>). Order here is the order shown in the app. Leave this list empty to fall back to the app's built-in default tab set.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_save_home_tabs" />
                <?php wp_nonce_field('bd_app_save_home_tabs'); ?>
                <table class="widefat" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th>Label</th><th>Source type</th><th>Source (slug)</th><th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tabs as $i => $tab): ?>
                            <tr>
                                <td><input type="text" name="tabs[<?php echo $i; ?>][label]" value="<?php echo esc_attr($tab['label'] ?? ''); ?>" /></td>
                                <td>
                                    <select name="tabs[<?php echo $i; ?>][sourceType]">
                                        <?php foreach (BD_App_Settings::SOURCE_TYPES as $t): ?>
                                            <option value="<?php echo esc_attr($t); ?>" <?php selected($tab['sourceType'] ?? '', $t); ?>><?php echo esc_html($t); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="tabs[<?php echo $i; ?>][sourceValue]" value="<?php echo esc_attr($tab['sourceValue'] ?? ''); ?>" /></td>
                                <td><input type="checkbox" name="tabs[<?php echo $i; ?>][delete]" /></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><input type="text" name="new_tab[label]" placeholder="e.g. Markets" /></td>
                            <td>
                                <select name="new_tab[sourceType]">
                                    <?php foreach (BD_App_Settings::SOURCE_TYPES as $t): ?>
                                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="new_tab[sourceValue]" placeholder="e.g. markets" /></td>
                            <td>—</td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Save home tabs'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSaveHomeTabs(): void
    {
        $this->assertNonce('bd_app_save_home_tabs');

        $existing = BD_App_Settings::getHomeTabs();
        $submitted = $_POST['tabs'] ?? [];
        $result = [];

        foreach ($existing as $i => $tab) {
            $row = $submitted[$i] ?? null;
            if ($row === null || !empty($row['delete'])) {
                continue;
            }
            $result[] = $this->sanitizeHomeTab($row);
        }

        $new = $_POST['new_tab'] ?? [];
        if (!empty($new['label']) && !empty($new['sourceValue'])) {
            $result[] = $this->sanitizeHomeTab($new);
        }

        BD_App_Settings::saveHomeTabs($result);
        do_action('bd_app_settings_saved');
        $this->redirectBack('bd-app-home-tabs');
    }

    private function sanitizeHomeTab(array $row): array
    {
        return [
            'label' => sanitize_text_field($row['label'] ?? ''),
            'sourceType' => in_array($row['sourceType'] ?? '', BD_App_Settings::SOURCE_TYPES, true) ? $row['sourceType'] : 'category',
            'sourceValue' => sanitize_title($row['sourceValue'] ?? ''),
        ];
    }

    // ---- Today's Paper ----

    public function renderTodaysPaper(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $paper = BD_App_Settings::getTodaysPaper();
        ?>
        <div class="wrap">
            <h1>Today's Paper</h1>
            <p><strong>No longer used.</strong> The app's Today's Paper now reads the exact same curation an editor already does once for the website: any post with "Feature in Today's Paper" checked (on the post editor screen) plus the latest E-Edition cover. Nothing below this notice is read by the app anymore — kept only so old data isn't silently deleted.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_save_todays_paper" />
                <?php wp_nonce_field('bd_app_save_todays_paper'); ?>
                <table class="form-table">
                    <tr>
                        <th><label>Edition date</label></th>
                        <td><input type="date" name="date" value="<?php echo esc_attr($paper['date'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label>PDF URL</label></th>
                        <td><input type="url" name="pdfUrl" value="<?php echo esc_attr($paper['pdfUrl'] ?? ''); ?>" style="width:400px;" /></td>
                    </tr>
                    <tr>
                        <th><label>Article IDs</label></th>
                        <td>
                            <input type="text" name="postIds" value="<?php echo esc_attr(implode(', ', $paper['postIds'] ?? [])); ?>" style="width:400px;" placeholder="e.g. 17, 18, 19" />
                            <p class="description">Comma-separated post IDs, in the order you want them grouped/shown (grouping within the app is by each post's own category).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button("Save today's paper"); ?>
            </form>
        </div>
        <?php
    }

    public function handleSaveTodaysPaper(): void
    {
        $this->assertNonce('bd_app_save_todays_paper');

        $postIds = array_filter(array_map('intval', explode(',', (string) ($_POST['postIds'] ?? ''))));

        BD_App_Settings::saveTodaysPaper([
            'date' => sanitize_text_field($_POST['date'] ?? ''),
            'pdfUrl' => esc_url_raw($_POST['pdfUrl'] ?? ''),
            'postIds' => array_values($postIds),
        ]);
        do_action('bd_app_settings_saved');
        $this->redirectBack('bd-app-todays-paper');
    }

    // ---- Banners ----

    public function renderBanners(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $banners = BD_App_Settings::getBanners();
        ?>
        <div class="wrap">
            <h1>App Banners</h1>
            <p>Promotional banners shown in the mobile app. Placement controls where in the app each one appears.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_save_banners" />
                <?php wp_nonce_field('bd_app_save_banners'); ?>
                <table class="widefat" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th>Active</th><th>Title</th><th>Image URL</th><th>Link URL</th>
                            <th>Placement</th><th>Starts</th><th>Ends</th><th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($banners as $i => $banner): ?>
                            <tr>
                                <td><input type="checkbox" name="banners[<?php echo $i; ?>][active]" <?php checked(!empty($banner['active'])); ?> /></td>
                                <td><input type="text" name="banners[<?php echo $i; ?>][title]" value="<?php echo esc_attr($banner['title'] ?? ''); ?>" /></td>
                                <td><input type="url" name="banners[<?php echo $i; ?>][imageUrl]" value="<?php echo esc_attr($banner['imageUrl'] ?? ''); ?>" style="width:220px;" /></td>
                                <td><input type="url" name="banners[<?php echo $i; ?>][linkUrl]" value="<?php echo esc_attr($banner['linkUrl'] ?? ''); ?>" style="width:180px;" /></td>
                                <td>
                                    <select name="banners[<?php echo $i; ?>][placement]">
                                        <?php foreach (BD_App_Settings::PLACEMENTS as $p): ?>
                                            <option value="<?php echo esc_attr($p); ?>" <?php selected($banner['placement'] ?? '', $p); ?>><?php echo esc_html($p); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="date" name="banners[<?php echo $i; ?>][startsAt]" value="<?php echo esc_attr($banner['startsAt'] ?? ''); ?>" /></td>
                                <td><input type="date" name="banners[<?php echo $i; ?>][endsAt]" value="<?php echo esc_attr($banner['endsAt'] ?? ''); ?>" /></td>
                                <td><input type="checkbox" name="banners[<?php echo $i; ?>][delete]" /></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><input type="checkbox" name="new_banner[active]" checked /></td>
                            <td><input type="text" name="new_banner[title]" placeholder="New banner title" /></td>
                            <td><input type="url" name="new_banner[imageUrl]" placeholder="https://..." style="width:220px;" /></td>
                            <td><input type="url" name="new_banner[linkUrl]" placeholder="https://... (optional)" style="width:180px;" /></td>
                            <td>
                                <select name="new_banner[placement]">
                                    <?php foreach (BD_App_Settings::PLACEMENTS as $p): ?>
                                        <option value="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="date" name="new_banner[startsAt]" /></td>
                            <td><input type="date" name="new_banner[endsAt]" /></td>
                            <td>—</td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Save banners'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSaveBanners(): void
    {
        $this->assertNonce('bd_app_save_banners');

        $existing = BD_App_Settings::getBanners();
        $submitted = $_POST['banners'] ?? [];
        $result = [];

        foreach ($existing as $i => $banner) {
            $row = $submitted[$i] ?? null;
            if ($row === null || !empty($row['delete'])) {
                continue;
            }
            $result[] = $this->sanitizeBanner($row, $banner['id']);
        }

        $new = $_POST['new_banner'] ?? [];
        if (!empty($new['title']) && !empty($new['imageUrl'])) {
            $result[] = $this->sanitizeBanner($new, wp_generate_uuid4());
        }

        BD_App_Settings::saveBanners($result);
        do_action('bd_app_settings_saved');
        $this->redirectBack('bd-app-banners');
    }

    private function sanitizeBanner(array $row, string $id): array
    {
        return [
            'id' => $id,
            'active' => !empty($row['active']),
            'title' => sanitize_text_field($row['title'] ?? ''),
            'imageUrl' => esc_url_raw($row['imageUrl'] ?? ''),
            'linkUrl' => esc_url_raw($row['linkUrl'] ?? ''),
            'placement' => in_array($row['placement'] ?? '', BD_App_Settings::PLACEMENTS, true)
                ? $row['placement']
                : BD_App_Settings::PLACEMENTS[0],
            'startsAt' => sanitize_text_field($row['startsAt'] ?? ''),
            'endsAt' => sanitize_text_field($row['endsAt'] ?? ''),
        ];
    }

    // ---- Ads ----

    public function renderAds(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $slots = BD_App_Settings::getAdSlots();
        ?>
        <div class="wrap">
            <h1>Ad Slots</h1>
            <p>Ad-unit config per placement. If a placement is disabled but has a house-ad image set, the app falls back to showing that image instead of a network ad.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_save_ads" />
                <?php wp_nonce_field('bd_app_save_ads'); ?>
                <table class="widefat">
                    <thead><tr><th>Placement</th><th>Enabled</th><th>Ad Unit ID</th><th>House ad image</th><th>House ad link</th></tr></thead>
                    <tbody>
                        <?php foreach ($slots as $placement => $slot): ?>
                            <tr>
                                <td><code><?php echo esc_html($placement); ?></code></td>
                                <td><input type="checkbox" name="ads[<?php echo esc_attr($placement); ?>][enabled]" <?php checked(!empty($slot['enabled'])); ?> /></td>
                                <td><input type="text" name="ads[<?php echo esc_attr($placement); ?>][adUnitId]" value="<?php echo esc_attr($slot['adUnitId'] ?? ''); ?>" style="width:220px;" /></td>
                                <td><input type="url" name="ads[<?php echo esc_attr($placement); ?>][houseAdImageUrl]" value="<?php echo esc_attr($slot['houseAdImageUrl'] ?? ''); ?>" style="width:220px;" /></td>
                                <td><input type="url" name="ads[<?php echo esc_attr($placement); ?>][houseAdLinkUrl]" value="<?php echo esc_attr($slot['houseAdLinkUrl'] ?? ''); ?>" style="width:180px;" /></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Save ad slots'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSaveAds(): void
    {
        $this->assertNonce('bd_app_save_ads');

        $submitted = $_POST['ads'] ?? [];
        $result = [];
        foreach (BD_App_Settings::AD_PLACEMENTS as $placement) {
            $row = $submitted[$placement] ?? [];
            $result[$placement] = [
                'placement' => $placement,
                'enabled' => !empty($row['enabled']),
                'adUnitId' => sanitize_text_field($row['adUnitId'] ?? ''),
                'houseAdImageUrl' => esc_url_raw($row['houseAdImageUrl'] ?? ''),
                'houseAdLinkUrl' => esc_url_raw($row['houseAdLinkUrl'] ?? ''),
            ];
        }

        BD_App_Settings::saveAdSlots($result);
        do_action('bd_app_settings_saved');
        $this->redirectBack('bd-app-ads');
    }

    // ---- Features ----

    public function renderFeatures(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $features = BD_App_Settings::getFeatures();
        ?>
        <div class="wrap">
            <h1>Features</h1>
            <p>Toggle whole features on or off for the app without a release. Off by default while a feature is reworked.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_save_features" />
                <?php wp_nonce_field('bd_app_save_features'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Follow &amp; interests</th>
                        <td>
                            <label>
                                <input type="checkbox" name="features[followEnabled]" <?php checked(!empty($features['followEnabled'])); ?> />
                                Show the follow/interests feature (post-signup Interest Picker, and Settings' "Your interests"/"Feed settings")
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save features'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSaveFeatures(): void
    {
        $this->assertNonce('bd_app_save_features');

        $submitted = $_POST['features'] ?? [];
        BD_App_Settings::saveFeatures([
            'followEnabled' => !empty($submitted['followEnabled']),
        ]);
        do_action('bd_app_settings_saved');
        $this->redirectBack('bd-app-features');
    }

    // ---- Paywall copy ----

    public function renderPaywallCopy(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $copy = BD_App_Settings::getPaywallCopy();
        ?>
        <div class="wrap">
            <h1>Paywall Copy</h1>
            <p>App-side messaging shown for each AeroPaywall entitlement stage. This only changes wording shown in the app — it never changes who is gated or when; that decision always comes live from AeroPaywall.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_save_paywall_copy" />
                <?php wp_nonce_field('bd_app_save_paywall_copy'); ?>
                <?php foreach (BD_App_Settings::PAYWALL_STAGES as $stage): $stageCopy = $copy[$stage]; ?>
                    <h2><?php echo esc_html($stage); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label>Headline</label></th>
                            <td><input type="text" name="paywall[<?php echo esc_attr($stage); ?>][headline]" value="<?php echo esc_attr($stageCopy['headline']); ?>" style="width:400px;" /></td>
                        </tr>
                        <tr>
                            <th><label>Body</label></th>
                            <td><textarea name="paywall[<?php echo esc_attr($stage); ?>][body]" rows="2" style="width:400px;"><?php echo esc_textarea($stageCopy['body']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Button label</label></th>
                            <td><input type="text" name="paywall[<?php echo esc_attr($stage); ?>][buttonLabel]" value="<?php echo esc_attr($stageCopy['buttonLabel']); ?>" style="width:250px;" /></td>
                        </tr>
                        <tr>
                            <th><label>Offer badge</label></th>
                            <td>
                                <input type="text" name="paywall[<?php echo esc_attr($stage); ?>][offerBadge]" value="<?php echo esc_attr($stageCopy['offerBadge'] ?? ''); ?>" style="width:250px;" placeholder="e.g. 50% off your first month" />
                                <p class="description">Optional. Leave blank to show no badge for this stage.</p>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>
                <?php submit_button('Save paywall copy'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSavePaywallCopy(): void
    {
        $this->assertNonce('bd_app_save_paywall_copy');

        $submitted = $_POST['paywall'] ?? [];
        $result = [];
        foreach (BD_App_Settings::PAYWALL_STAGES as $stage) {
            $row = $submitted[$stage] ?? [];
            $result[$stage] = [
                'headline' => sanitize_text_field($row['headline'] ?? ''),
                'body' => sanitize_textarea_field($row['body'] ?? ''),
                'buttonLabel' => sanitize_text_field($row['buttonLabel'] ?? ''),
                'offerBadge' => sanitize_text_field($row['offerBadge'] ?? ''),
            ];
        }

        BD_App_Settings::savePaywallCopy($result);
        do_action('bd_app_settings_saved');
        $this->redirectBack('bd-app-paywall-copy');
    }

    // ---- Cache ----

    public function renderCache(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Feed Cache</h1>
            <p>Feeds and app-config rebuild automatically every few minutes, and on every post save. Use this if you need a change to show up in the app immediately.</p>
            <?php $this->maybeShowNotice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bd_app_purge_cache" />
                <?php wp_nonce_field('bd_app_purge_cache'); ?>
                <?php submit_button('Purge cache now'); ?>
            </form>
        </div>
        <?php
    }

    public function handlePurgeCache(): void
    {
        $this->assertNonce('bd_app_purge_cache');
        BD_App_Cache::purgeAll();
        $this->redirectBack('bd-app-cache');
    }

    // ---- shared ----

    private function assertNonce(string $action): void
    {
        if (!current_user_can(self::CAPABILITY) || !check_admin_referer($action)) {
            wp_die('Unauthorized', 403);
        }
    }

    private function redirectBack(string $page): void
    {
        wp_safe_redirect(add_query_arg(['page' => $page, 'bd_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    private function maybeShowNotice(): void
    {
        if (isset($_GET['bd_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
        }
    }
}
