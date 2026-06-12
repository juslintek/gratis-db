<?php declare(strict_types=1);

namespace Gratis\DB;

defined('ABSPATH') || exit;

/**
 * Slow query logger.
 *
 * When enabled, captures queries that exceed a configurable threshold using
 * the data WordPress collects in {@see $wpdb->queries} (requires the
 * SAVEQUERIES constant). Slow queries are stored in a capped option so they
 * can be reviewed on the settings screen without an external APM tool.
 */
final class SlowQueryLog {

    private const OPTION      = 'gratis_db_slow_queries';
    private const MAX_ENTRIES = 100;

    /** Wire the shutdown capture hook when logging is enabled. */
    public static function init(): void {
        if (!self::is_enabled()) {
            return;
        }
        // Ensure WordPress records query timings so we have data to inspect.
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        add_action('shutdown', [__CLASS__, 'capture'], 0);
    }

    /** Whether slow query logging is enabled. */
    public static function is_enabled(): bool {
        return (bool) get_option('gratis_db_slow_log_enabled', false);
    }

    /** The slow query threshold in seconds. */
    public static function threshold(): float {
        $value = (float) get_option('gratis_db_slow_threshold', 0.05);
        return $value > 0 ? $value : 0.05;
    }

    /** Inspect the request's queries and persist any that exceed the threshold. */
    public static function capture(): void {
        global $wpdb;

        if (!isset($wpdb->queries) || !is_array($wpdb->queries) || empty($wpdb->queries)) {
            return;
        }

        $threshold = self::threshold();
        $slow      = [];
        foreach ($wpdb->queries as $query) {
            $sql      = (string) ($query[0] ?? '');
            $duration = (float) ($query[1] ?? 0);
            if ($duration < $threshold) {
                continue;
            }
            $slow[] = [
                'sql'      => mb_substr(trim($sql), 0, 500),
                'duration' => round($duration, 4),
                'caller'   => (string) ($query[2] ?? ''),
                'time'     => gmdate('Y-m-d H:i:s'),
            ];
        }

        if (empty($slow)) {
            return;
        }

        $log = get_option(self::OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }
        // Newest first, capped.
        $log = array_slice(array_merge(array_reverse($slow), $log), 0, self::MAX_ENTRIES);
        update_option(self::OPTION, $log, false);
    }

    /**
     * Return the logged slow queries (newest first).
     *
     * @return array<int, array{sql: string, duration: float, caller: string, time: string}>
     */
    public static function get_log(): array {
        $log = get_option(self::OPTION, []);
        return is_array($log) ? $log : [];
    }

    /** Clear the slow query log. */
    public static function clear(): void {
        update_option(self::OPTION, [], false);
    }
}
