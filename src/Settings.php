<?php declare(strict_types=1);

namespace Gratis\DB;

defined('ABSPATH') || exit;

/**
 * Admin screen for Gratis DB.
 *
 * Shows SQLite migration readiness, a table-optimization tool, and the slow
 * query log with an enable toggle + threshold.
 */
final class Settings {

    private const GROUP = 'gratis_db_settings_group';
    private const PAGE  = 'gratis-db';

    /** Register the Tools submenu page. */
    public static function register_menu(): void {
        add_management_page(
            __('Gratis DB', 'gratis-db'),
            __('Gratis DB', 'gratis-db'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    /** Register the slow query logging options. */
    public static function register_settings(): void {
        register_setting(self::GROUP, 'gratis_db_slow_log_enabled', ['type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => false]);
        register_setting(self::GROUP, 'gratis_db_slow_threshold', ['type' => 'number', 'sanitize_callback' => [__CLASS__, 'sanitize_threshold'], 'default' => 0.05]);
    }

    /** Coerce a checkbox to a boolean. */
    public static function sanitize_bool($value): bool {
        return (bool) $value;
    }

    /** Clamp the slow query threshold to a sensible range (seconds). */
    public static function sanitize_threshold($value): float {
        $value = (float) $value;
        if ($value < 0.001) {
            $value = 0.001;
        }
        if ($value > 10) {
            $value = 10;
        }
        return round($value, 4);
    }

    /** Register the admin-post handlers (optimize + clear log). */
    public static function register_actions(): void {
        add_action('admin_post_gratis_db_optimize', [__CLASS__, 'handle_optimize']);
        add_action('admin_post_gratis_db_clear_log', [__CLASS__, 'handle_clear_log']);
    }

    /** Optimize all tables (nonce + capability gated). */
    public static function handle_optimize(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to optimize tables.', 'gratis-db'));
        }
        check_admin_referer('gratis_db_optimize');

        $result = TableOptimizer::optimize();
        $redirect = add_query_arg(
            [
                'page'               => self::PAGE,
                'gratis_db_optimized' => absint($result['optimized']),
            ],
            admin_url('tools.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /** Clear the slow query log (nonce + capability gated). */
    public static function handle_clear_log(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'gratis-db'));
        }
        check_admin_referer('gratis_db_clear_log');

        SlowQueryLog::clear();
        wp_safe_redirect(add_query_arg(['page' => self::PAGE], admin_url('tools.php')));
        exit;
    }

    /** Render the admin screen. */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gratis-db'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Gratis DB', 'gratis-db') . '</h1>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
        if (isset($_GET['gratis_db_optimized'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
            $count = absint(wp_unslash($_GET['gratis_db_optimized']));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(/* translators: %d: table count. */ _n('Optimized %d table.', 'Optimized %d tables.', $count, 'gratis-db'), $count)) . '</p></div>';
        }

        self::render_status();
        self::render_optimizer();
        self::render_slow_log();

        echo '</div>';
    }

    /** SQLite migration readiness summary. */
    private static function render_status(): void {
        $dropin = new DropinManager();
        echo '<h2>' . esc_html__('SQLite module', 'gratis-db') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('ext-sqlite3', 'gratis-db') . '</th><td>' . (extension_loaded('sqlite3') ? esc_html__('Available', 'gratis-db') : esc_html__('Not available', 'gratis-db')) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('db.php drop-in', 'gratis-db') . '</th><td><code>' . esc_html($dropin->currentDropin()) . '</code></td></tr>';
        echo '</tbody></table>';
    }

    /** Table list + optimize button. */
    private static function render_optimizer(): void {
        $tables = TableOptimizer::tables();
        $total_overhead = array_sum(array_map(static fn($t) => $t['overhead'], $tables));

        echo '<h2>' . esc_html__('Table optimization', 'gratis-db') . '</h2>';
        echo '<p>' . esc_html(sprintf(/* translators: 1: table count, 2: reclaimable size in KB. */ __('%1$d tables · ~%2$s KB reclaimable overhead', 'gratis-db'), count($tables), number_format_i18n($total_overhead / 1024, 1))) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('gratis_db_optimize');
        echo '<input type="hidden" name="action" value="gratis_db_optimize">';
        submit_button(__('Optimize all tables', 'gratis-db'), 'secondary', 'submit', false);
        echo '</form>';

        if (empty($tables)) {
            return;
        }

        echo '<table class="widefat striped" style="margin-top:1em;"><thead><tr>';
        echo '<th>' . esc_html__('Table', 'gratis-db') . '</th>';
        echo '<th>' . esc_html__('Engine', 'gratis-db') . '</th>';
        echo '<th>' . esc_html__('Rows', 'gratis-db') . '</th>';
        echo '<th>' . esc_html__('Size (KB)', 'gratis-db') . '</th>';
        echo '<th>' . esc_html__('Overhead (KB)', 'gratis-db') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($tables as $t) {
            echo '<tr>';
            echo '<td><code>' . esc_html($t['name']) . '</code></td>';
            echo '<td>' . esc_html($t['engine']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($t['rows'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($t['size'] / 1024, 1)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($t['overhead'] / 1024, 1)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /** Slow query log controls + recent entries. */
    private static function render_slow_log(): void {
        $enabled   = SlowQueryLog::is_enabled();
        $threshold = SlowQueryLog::threshold();

        echo '<h2>' . esc_html__('Slow query log', 'gratis-db') . '</h2>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Enable logging', 'gratis-db') . '</th><td>';
        echo '<label><input type="checkbox" name="gratis_db_slow_log_enabled" value="1"' . checked($enabled, true, false) . '> ' . esc_html__('Record queries slower than the threshold', 'gratis-db') . '</label>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="gd_threshold">' . esc_html__('Threshold (seconds)', 'gratis-db') . '</label></th><td>';
        echo '<input type="number" step="0.001" min="0.001" max="10" id="gd_threshold" class="small-text" name="gratis_db_slow_threshold" value="' . esc_attr((string) $threshold) . '">';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save log settings', 'gratis-db'));
        echo '</form>';

        $entries = SlowQueryLog::get_log();
        if (empty($entries)) {
            echo '<p>' . esc_html__('No slow queries recorded yet.', 'gratis-db') . '</p>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:1em;">';
        wp_nonce_field('gratis_db_clear_log');
        echo '<input type="hidden" name="action" value="gratis_db_clear_log">';
        submit_button(__('Clear log', 'gratis-db'), 'delete', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Duration (s)', 'gratis-db') . '</th>';
        echo '<th>' . esc_html__('Query', 'gratis-db') . '</th>';
        echo '<th>' . esc_html__('Time (UTC)', 'gratis-db') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($entries as $entry) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($entry['duration'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($entry['sql'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($entry['time'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
