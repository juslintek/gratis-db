<?php declare(strict_types=1);
namespace Gratis\DB\CLI;

use Gratis\DB\CompatibilityScanner;
use Gratis\DB\DropinManager;
use Gratis\DB\Migrator;

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
     * Show the real MySQL → SQLite migration plan (tables, row counts, blockers).
     *
     * ## EXAMPLES
     *     wp gratis-db plan
     *
     * @when after_wp_load
     */
    public function plan($args, $assoc_args): void {
        unset($args, $assoc_args);
        $plan = (new Migrator())->plan();
        $this->print_plan($plan);

        if ($plan['already_sqlite']) {
            return;
        }
        if (empty($plan['blockers'])) {
            \WP_CLI::success("No blockers. Preview with 'wp gratis-db migrate --dry-run', then run 'wp gratis-db migrate'.");
        } else {
            \WP_CLI::warning('Migration is blocked. Resolve the issues above first.');
        }
    }

    /**
     * Migrate the MySQL database to SQLite.
     *
     * Runs readiness + compatibility checks, takes a pre-migration MySQL backup,
     * copies every base table row-by-row into a new SQLite database (preserving IDs
     * and serialized data), installs the SQLite db.php drop-in, then verifies row
     * counts. The MySQL database is never modified — roll back with
     * `wp gratis-db rollback`.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report the plan and readiness without writing anything.
     *
     * [--yes]
     * : Skip the confirmation prompt. The switch is destructive: it installs the
     *   SQLite drop-in so the next page load uses SQLite.
     *
     * [--skip-backup]
     * : Skip the pre-migration MySQL logical backup (not recommended).
     *
     * ## EXAMPLES
     *     wp gratis-db migrate --dry-run
     *     wp gratis-db migrate --yes
     *
     * @when after_wp_load
     */
    public function migrate($args, $assoc_args): void {
        unset($args);
        $migrator    = new Migrator();
        $dry_run     = isset($assoc_args['dry-run']);
        $skip_backup = isset($assoc_args['skip-backup']);

        if ($dry_run) {
            $plan = $migrator->migrate(true);
            $this->print_plan($plan);
            \WP_CLI::log('');
            \WP_CLI::success('Dry run complete — no changes were written.');
            return;
        }

        $plan = $migrator->plan();
        $this->print_plan($plan);

        if ($plan['already_sqlite']) {
            \WP_CLI::error('This site already runs on SQLite — nothing to migrate.');
        }
        if (!empty($plan['blockers'])) {
            \WP_CLI::error('Migration is blocked. Resolve the issues above first.');
        }

        \WP_CLI::confirm(
            'This installs the SQLite drop-in and switches the database backend on the next page load. Continue?',
            $assoc_args
        );

        try {
            $result = $migrator->migrate(false, !$skip_backup);
        } catch (\Throwable $e) {
            \WP_CLI::error('Migration failed: ' . $e->getMessage());
            return;
        }

        if (!empty($result['backup'])) {
            \WP_CLI::log('MySQL backup written to: ' . $result['backup']);
        }
        \WP_CLI::log('SQLite database: ' . $result['target_sqlite']);
        \WP_CLI::log('Tables copied:   ' . count($result['tables_copied']));

        $mismatches = $result['verification']['mismatches'] ?? [];
        if (!empty($mismatches)) {
            \WP_CLI::log('');
            \WP_CLI::warning('Row-count verification found mismatches:');
            foreach ($mismatches as $table => $counts) {
                \WP_CLI::log("  ✗ {$table}: MySQL {$counts['mysql']} vs SQLite {$counts['sqlite']}");
            }
            \WP_CLI::error('Verification failed. MySQL is untouched — run `wp gratis-db rollback` to remove the drop-in.');
            return;
        }

        \WP_CLI::success('Migration complete and verified. Reload the site; if anything is wrong, run `wp gratis-db rollback`.');
    }

    /**
     * Roll back the SQLite switch by removing the Gratis-managed db.php drop-in.
     *
     * WordPress returns to the untouched MySQL database on the next page load.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *     wp gratis-db rollback
     *
     * @when after_wp_load
     */
    public function rollback($args, $assoc_args): void {
        unset($args);
        \WP_CLI::confirm('Remove the SQLite db.php drop-in and return to MySQL on the next page load?', $assoc_args);

        $result = (new DropinManager())->uninstall();
        if (!empty($result['error'])) {
            \WP_CLI::error($result['error']);
            return;
        }

        $suffix = !empty($result['restored']) ? ' A previously backed-up db.php was restored.' : '';
        \WP_CLI::success('Drop-in removed. WordPress will use MySQL on the next page load.' . $suffix);
    }

    /** Pretty-print a migration plan array (shared by plan + migrate --dry-run). */
    private function print_plan(array $plan): void {
        \WP_CLI::log('=== MySQL → SQLite Migration Plan ===');

        if (!empty($plan['already_sqlite'])) {
            \WP_CLI::warning('This site already runs on SQLite — there is no MySQL source to migrate.');
            return;
        }

        \WP_CLI::log('Target SQLite database: ' . ($plan['target_sqlite'] ?? ''));
        \WP_CLI::log('db.php drop-in path:    ' . ($plan['dropin_path'] ?? ''));
        \WP_CLI::log('SQLite integration:     ' . ($plan['sqlite_plugin'] ?? 'NOT FOUND'));
        \WP_CLI::log('');
        \WP_CLI::log(
            sprintf(
                'Tables: %d · total rows: %s',
                count($plan['tables'] ?? []),
                number_format_i18n((int) ($plan['total_rows'] ?? 0))
            )
        );

        if (!empty($plan['tables'])) {
            $items = array_map(
                static fn($t) => ['table' => $t['name'], 'rows' => $t['rows']],
                $plan['tables']
            );
            \WP_CLI\Utils\format_items('table', $items, ['table', 'rows']);
        }

        if (!empty($plan['skipped'])) {
            \WP_CLI::log('Skipped (views / unsafe names): ' . implode(', ', $plan['skipped']));
        }

        if (!empty($plan['blockers'])) {
            \WP_CLI::log('');
            \WP_CLI::log('Blockers:');
            foreach ($plan['blockers'] as $blocker) {
                \WP_CLI::log('  ✗ ' . $blocker);
            }
        }
        if (!empty($plan['warnings'])) {
            \WP_CLI::log('');
            \WP_CLI::log('Warnings:');
            foreach ($plan['warnings'] as $warning) {
                \WP_CLI::log('  ⚠ ' . $warning);
            }
        }
    }
}
