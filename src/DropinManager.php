<?php declare(strict_types=1);
namespace Gratis\DB;

/** Manages the db.php drop-in for SQLite integration. */
final class DropinManager
{
    private string $dropinPath;

    public function __construct()
    {
        $this->dropinPath = WP_CONTENT_DIR . '/db.php';
    }

    public function isInstalled(): bool
    {
        return file_exists($this->dropinPath) && str_contains(
            (string) @file_get_contents($this->dropinPath),
            'gratis-db'
        );
    }

    public function currentDropin(): string
    {
        if (!file_exists($this->dropinPath)) return 'none';
        $content = @file_get_contents($this->dropinPath);
        if (str_contains($content, 'gratis-db')) return 'gratis-db';
        if (str_contains($content, 'sqlite')) return 'sqlite-unknown';
        return 'third-party';
    }

    public function canInstall(): array
    {
        $issues = [];
        if (!extension_loaded('sqlite3')) {
            $issues[] = 'ext-sqlite3 is not available';
        }
        if (file_exists($this->dropinPath) && !str_contains((string) @file_get_contents($this->dropinPath), 'gratis-db')) {
            $issues[] = 'Another db.php drop-in is already installed';
        }
        if (!is_writable(WP_CONTENT_DIR)) {
            $issues[] = 'wp-content directory is not writable';
        }
        return $issues;
    }
}
