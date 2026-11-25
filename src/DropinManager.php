<?php declare(strict_types=1);
namespace Gratis\DB;

defined('ABSPATH') || exit;
/** Manages the db.php drop-in for SQLite integration. */
final class DropinManager {

    private string $dropinPath;

    public function __construct() {
        $this->dropinPath = WP_CONTENT_DIR . '/db.php';
    }

    public function isInstalled(): bool {
        if (!file_exists($this->dropinPath)) {
            return false;
        }
        // Reading the local wp-content/db.php drop-in, not a remote URL.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return str_contains((string) @file_get_contents($this->dropinPath), 'gratis-db');
    }

    public function currentDropin(): string {
        if (!file_exists($this->dropinPath)) {
            return 'none';
        }
        // Reading the local wp-content/db.php drop-in, not a remote URL.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = (string) @file_get_contents($this->dropinPath);
        if (str_contains($content, 'gratis-db')) {
            return 'gratis-db';
        }
        if (str_contains($content, 'sqlite')) {
            return 'sqlite-unknown';
        }
        return 'third-party';
    }

    public function canInstall(): array {
        $issues = [];
        if (!extension_loaded('sqlite3')) {
            $issues[] = 'ext-sqlite3 is not available';
        }
        if (file_exists($this->dropinPath)) {
            // Reading the local wp-content/db.php drop-in, not a remote URL.
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $existing = (string) @file_get_contents($this->dropinPath);
            if (!str_contains($existing, 'gratis-db')) {
                $issues[] = 'Another db.php drop-in is already installed';
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        if (!is_writable(WP_CONTENT_DIR)) {
            $issues[] = 'wp-content directory is not writable';
        }
        return $issues;
    }

    /** Absolute path of the db.php drop-in this manager controls. */
    public function dropinPath(): string {
        return $this->dropinPath;
    }

    /** Directory where Gratis DB stores backups (drop-in + MySQL dumps). */
    public function backupDir(): string {
        return WP_CONTENT_DIR . '/gratis-db-backups';
    }

    /**
     * Locate the sqlite-database-integration plugin (the source of the drop-in
     * template and the runtime translator). Returns null when it is not usable.
     */
    public function pluginPath(): ?string {
        $base = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
        $path = $base . '/sqlite-database-integration';
        if (
            is_dir($path)
            && file_exists($path . '/db.copy')
            && file_exists($path . '/wp-includes/sqlite/db.php')
        ) {
            return $path;
        }
        return null;
    }

    /**
     * Compute the SQLite database file path exactly as the runtime drop-in does
     * (mirrors the integration's constants.php precedence).
     */
    public function targetSqlitePath(): string {
        if (defined('FQDB')) {
            return (string) constant('FQDB');
        }
        if (defined('DB_DIR')) {
            $dir = trailingslashit((string) constant('DB_DIR'));
        } else {
            $dir = WP_CONTENT_DIR . '/database/';
        }
        $fileName = defined('DB_FILE') ? (string) constant('DB_FILE') : '.ht.sqlite';
        return $dir . $fileName;
    }

    /**
     * Install the SQLite db.php drop-in by copying the integration's db.copy
     * (substituting its placeholders) and tagging it as Gratis-managed. Any
     * pre-existing db.php is backed up first so the change is reversible.
     *
     * @return array{installed: bool, error: ?string, backup: ?string}
     */
    public function install(): array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        if (!is_writable(WP_CONTENT_DIR)) {
            return ['installed' => false, 'error' => 'wp-content directory is not writable', 'backup' => null];
        }
        if (file_exists($this->dropinPath) && 'gratis-db' !== $this->currentDropin()) {
            return ['installed' => false, 'error' => 'A non-Gratis db.php drop-in is already installed', 'backup' => null];
        }

        $plugin = $this->pluginPath();
        if (null === $plugin) {
            return ['installed' => false, 'error' => 'sqlite-database-integration plugin not found', 'backup' => null];
        }

        // Reading the local plugin db.copy template, not a remote URL.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $template = (string) @file_get_contents($plugin . '/db.copy');
        if ('' === $template) {
            return ['installed' => false, 'error' => 'Could not read db.copy template', 'backup' => null];
        }

        $contents = strtr(
            $template,
            [
                '{SQLITE_IMPLEMENTATION_FOLDER_PATH}' => $plugin,
                '{SQLITE_PLUGIN}'                     => 'sqlite-database-integration/load.php',
            ]
        );

        // Tag the drop-in so currentDropin()/isInstalled() recognise our install
        // and so rollback knows it is safe to remove.
        $marker   = "/* gratis-db: managed SQLite drop-in installed by Gratis DB. Delete this file to roll back to MySQL. */\n";
        $contents = preg_replace('/^<\?php\s*\n?/', "<?php\n" . $marker, $contents, 1);

        // Back up an existing (Gratis) drop-in before overwriting.
        $backup = null;
        if (file_exists($this->dropinPath)) {
            if (!is_dir($this->backupDir())) {
                wp_mkdir_p($this->backupDir());
            }
            $backup = $this->backupDir() . '/db.php.' . gmdate('Ymd-His') . '.bak';
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
            @copy($this->dropinPath, $backup);
        }

        // Writing the local wp-content/db.php drop-in.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        $written = @file_put_contents($this->dropinPath, $contents);
        if (false === $written) {
            return ['installed' => false, 'error' => 'Could not write the db.php drop-in', 'backup' => $backup];
        }

        return ['installed' => true, 'error' => null, 'backup' => $backup];
    }

    /**
     * Roll back by removing the Gratis-managed drop-in (restoring the most recent
     * backup when one exists). Refuses to touch a drop-in we did not install.
     *
     * @return array{removed: bool, restored: bool, error: ?string}
     */
    public function uninstall(): array {
        if (!file_exists($this->dropinPath)) {
            return ['removed' => false, 'restored' => false, 'error' => 'No db.php drop-in present.'];
        }
        if ('gratis-db' !== $this->currentDropin()) {
            return ['removed' => false, 'restored' => false, 'error' => 'db.php is not managed by Gratis DB; refusing to remove it.'];
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink($this->dropinPath);

        // Restore the most recent backed-up drop-in, if any.
        $restored = false;
        $backups  = glob($this->backupDir() . '/db.php.*.bak');
        if (is_array($backups) && !empty($backups)) {
            sort($backups);
            $latest = (string) end($backups);
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
            $restored = @copy($latest, $this->dropinPath);
        }

        return ['removed' => true, 'restored' => (bool) $restored, 'error' => null];
    }
}
