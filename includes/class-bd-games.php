<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * A `bd_game` custom post type so editors can push new daily games (quizzes today; crossword
 * entries are listable but the app doesn't have a real interactive crossword engine yet — see
 * IMPLEMENTATION_PLAN.md §18, this is scoped honestly rather than faking one) from wp-admin.
 * Shaped after the theme's own Cartoons add-on (addons/cartoons/), the theme's documented
 * "reference" pattern for a simple CPT + meta box + thin query, per the §18 audit.
 *
 * Play results (streaks/wins/badges) are intentionally NOT stored here or anywhere server-side —
 * the app keeps that entirely local (AsyncStorage). This CPT only ever carries game *content*.
 */
class BD_App_Games
{
    private static ?self $instance = null;
    const META_KIND = '_bd_game_kind';
    const META_ICON = '_bd_game_icon';
    const META_QUESTIONS = '_bd_game_questions_raw';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerHooks();
        }
        return self::$instance;
    }

    const NAMESPACE = 'businessday-app/v1';
    const TTL = 300;

    private function registerHooks(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post_bd_game', [$this, 'saveMetaBox']);
        add_action('save_post_bd_game', [BD_App_Cache::class, 'purgeAll']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/games', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handleList'],
        ]);
    }

    public function handleList(): WP_REST_Response
    {
        $cached = BD_App_Cache::get('games');
        if ($cached === false) {
            $cached = ['items' => self::getPublished()];
            BD_App_Cache::set('games', $cached, self::TTL);
        }
        $response = new WP_REST_Response($cached, 200);
        BD_App_Cache::applyPublicCacheHeaders($response, self::TTL);
        return $response;
    }

    public function registerPostType(): void
    {
        register_post_type('bd_game', [
            'label' => 'Games',
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => 'bd-app-banners',
            'has_archive' => false,
            'show_in_rest' => false, // served through our own /games endpoint instead
            'supports' => ['title'],
            'menu_icon' => 'dashicons-games',
            'labels' => [
                'name' => 'Games',
                'singular_name' => 'Game',
                'add_new_item' => 'Add New Game',
                'edit_item' => 'Edit Game',
            ],
        ]);
    }

    public function addMetaBox(): void
    {
        add_meta_box('bd_game_details', 'Game Details', [$this, 'renderMetaBox'], 'bd_game', 'normal', 'high');
    }

    public function renderMetaBox(WP_Post $post): void
    {
        wp_nonce_field('bd_game_save', 'bd_game_nonce');
        $kind = get_post_meta($post->ID, self::META_KIND, true) ?: 'quiz';
        $icon = get_post_meta($post->ID, self::META_ICON, true) ?: 'zap';
        $questions = get_post_meta($post->ID, self::META_QUESTIONS, true) ?: '';
        ?>
        <p>
            <label><strong>Kind</strong></label><br />
            <select name="bd_game_kind">
                <option value="quiz" <?php selected($kind, 'quiz'); ?>>Quiz</option>
                <option value="crossword" <?php selected($kind, 'crossword'); ?>>Crossword (preview only — no interactive solver yet)</option>
            </select>
        </p>
        <p>
            <label><strong>Icon</strong> (Feather icon name, e.g. <code>zap</code>, <code>hash</code>)</label><br />
            <input type="text" name="bd_game_icon" value="<?php echo esc_attr($icon); ?>" />
        </p>
        <p>
            <label><strong>Quiz questions</strong> (one per line, ignored for Crossword games): <code>prompt|option A|option B|option C|option D|correct index (0-3)</code></label><br />
            <textarea name="bd_game_questions" rows="8" style="width:100%;font-family:monospace;"><?php echo esc_textarea($questions); ?></textarea>
        </p>
        <?php
    }

    public function saveMetaBox(int $postId): void
    {
        if (!isset($_POST['bd_game_nonce']) || !wp_verify_nonce($_POST['bd_game_nonce'], 'bd_game_save')) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        update_post_meta($postId, self::META_KIND, in_array($_POST['bd_game_kind'] ?? '', ['quiz', 'crossword'], true) ? $_POST['bd_game_kind'] : 'quiz');
        update_post_meta($postId, self::META_ICON, sanitize_text_field($_POST['bd_game_icon'] ?? 'zap'));
        update_post_meta($postId, self::META_QUESTIONS, sanitize_textarea_field($_POST['bd_game_questions'] ?? ''));
    }

    private static function parseQuestions(string $raw): array
    {
        $questions = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 6) {
                continue;
            }
            [$prompt, $a, $b, $c, $d, $correct] = $parts;
            $questions[] = [
                'prompt' => $prompt,
                'options' => [$a, $b, $c, $d],
                'correctIndex' => max(0, min(3, (int) $correct)),
            ];
        }
        return $questions;
    }

    /** @return array<int, array<string, mixed>> */
    public static function getPublished(): array
    {
        $posts = get_posts([
            'post_type' => 'bd_game',
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return array_map(function (WP_Post $post) {
            $kind = get_post_meta($post->ID, self::META_KIND, true) ?: 'quiz';
            return [
                'id' => (string) $post->ID,
                'title' => get_the_title($post),
                'kind' => $kind,
                'icon' => get_post_meta($post->ID, self::META_ICON, true) ?: 'zap',
                'questions' => $kind === 'quiz'
                    ? self::parseQuestions((string) get_post_meta($post->ID, self::META_QUESTIONS, true))
                    : [],
            ];
        }, $posts);
    }
}
