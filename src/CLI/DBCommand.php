<?php declare(strict_types=1);
namespace Gratis\DB\CLI;

use Gratis\DB\CompatibilityScanner;
use Gratis\DB\DropinManager;

/** WP-CLI commands: wp gratis-db <subcommand> */
final class DBCommand {

    /**
     * Show SQLite module status.
     *
     * ## EXAMPLES
     *     wp gratis-db status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args): void {
        unset($args, $assoc_args);
        $dropin = new DropinManager();
        \WP_CLI::log('=== Gratis DB Status ===');
        \WP_CLI::log('ext-sqlite3: ' . (extension_loaded('sqlite3') ? '✓ available' : '✗ missing'));
        \WP_CLI::log('Current database: MySQL (' . DB_HOST . ')');
        \WP_CLI::log('db.php drop-in: ' . $dropin->currentDropin());

        $issues = $dropin->canInstall();
        if (empty($issues)) {
            \WP_CLI::log("SQLite migration: ready (run 'wp gratis-db scan' first)");
        } else {
            \WP_CLI::log('SQLite migration: blocked');
            foreach ($issues as $issue) {
                \WP_CLI::log("  ✗ {$issue}");
            }
        }
    }

    /**
     * Scan plugins and theme for SQLite compatibility.
     *
     * ## EXAMPLES
     *     wp gratis-db scan
     *
     * @when after_wp_load
     */
    public function scan($args, $assoc_args): void {
        unset($args, $assoc_args);
        $scanner = new CompatibilityScanner();
        \WP_CLI::log('Scanning for SQLite compatibility...');
        $result = $scanner->scan();

        \WP_CLI::log("Scanned: {$result['scanned']} components");
        \WP_CLI::log('Compatible: ' . ($result['compatible'] ? '✓ yes' : '✗ no'));

        if (empty($result['issues'])) {
            \WP_CLI::success('No compatibility issues found. Safe to migrate.');
            return;
        }

        \WP_CLI::log('');
        \WP_CLI::log('Issues found:');
        foreach ($result['issues'] as $issue) {
            $icon = ($issue['severity'] ?? '') === 'critical' ? '✗' : '⚠';
            $source = $issue['source'] ?? 'unknown';
            $detail = $issue['detail'] ?? '';
            $file = isset($issue['file']) ? " ({$issue['file']})" : '';
            \WP_CLI::log("  {$icon} [{$source}] {$detail}{$file}");
        }

        if (!$result['compatible']) {
            \WP_CLI::warning('Critical issues found. Migration not recommended.');
        } else {
            \WP_CLI::log('');
            \WP_CLI::success('Warnings only — migration is possible but test thoroughly.');
        }
    }

    /**
     * Show migration plan (dry-run).
     *
     * ## EXAMPLES
     *     wp gratis-db plan
     *
     * @when after_wp_load
     */
    public function plan($args, $assoc_args): void {
        unset($args, $assoc_args);
        \WP_CLI::log('=== MySQL → SQLite Migration Plan ===');
        \WP_CLI::log('');
        \WP_CLI::log('Steps:');
        \WP_CLI::log('  1. Run compatibility scan (wp gratis-db scan)');
        \WP_CLI::log('  2. Create full database backup (wp gratis-backup run --type=database)');
        \WP_CLI::log('  3. Export MySQL tables to SQLite format');
        \WP_CLI::log('  4. Install db.php drop-in');
        \WP_CLI::log('  5. Update wp-config.php with SQLite path');
        \WP_CLI::log('  6. Verify site loads correctly');
        \WP_CLI::log('  7. If issues: wp gratis-db rollback');
        \WP_CLI::log('');
        \WP_CLI::warning('This is a preview. Migration is not yet implemented.');
        \WP_CLI::log('Use the official SQLite Database Integration plugin for now.');
    }
}
