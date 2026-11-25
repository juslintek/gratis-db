<?php declare(strict_types=1);

namespace Gratis\DB;

defined('ABSPATH') || exit;

/**
 * Lists database tables with their size and reclaimable overhead, and runs
 * OPTIMIZE TABLE to reclaim space and defragment indexes.
 */
final class TableOptimizer {

    /**
     * Return size/overhead information for every table in the WordPress database.
     *
     * @return array<int, array{name: string, engine: string, rows: int, size: int, overhead: int}>
     */
    public static function tables(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $tables = [];
        foreach ($rows as $row) {
            $tables[] = [
                'name'     => (string) ($row['Name'] ?? ''),
                'engine'   => (string) ($row['Engine'] ?? ''),
                'rows'     => (int) ($row['Rows'] ?? 0),
                'size'     => (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0),
                'overhead' => (int) ($row['Data_free'] ?? 0),
            ];
        }
        return $tables;
    }

    /**
     * Optimize the given tables (or all WordPress tables when none are given).
     *
     * @param array<int, string> $names Optional explicit table names.
     * @return array{optimized: int, reclaimed_estimate: int}
     */
    public static function optimize(array $names = []): array {
        global $wpdb;

        if (empty($names)) {
            $names = array_map(static fn($t) => $t['name'], self::tables());
        }

        $reclaim   = 0;
        $optimized = 0;
        foreach ($names as $name) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if ('' === $name) {
                continue;
            }
            // Capture the overhead before optimizing for a reclaim estimate.
            foreach (self::tables() as $t) {
                if ($t['name'] === $name) {
                    $reclaim += $t['overhead'];
                    break;
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- explicit admin table optimization.
            $result = $wpdb->query($wpdb->prepare('OPTIMIZE TABLE %i', $name));
            if (false !== $result) {
                ++$optimized;
            }
        }

        return ['optimized' => $optimized, 'reclaimed_estimate' => $reclaim];
    }
}
