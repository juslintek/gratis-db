<?php declare(strict_types=1);
namespace Gratis\DB;

/** Scans active plugins/themes for MySQL-specific features incompatible with SQLite. */
final class CompatibilityScanner
{
    private const INCOMPATIBLE_PATTERNS = [
        'FULLTEXT'           => 'FULLTEXT indexes (not supported in SQLite)',
        'SPATIAL'            => 'Spatial indexes',
        'ON DUPLICATE KEY'   => 'ON DUPLICATE KEY UPDATE (use INSERT OR REPLACE)',
        'FOUND_ROWS'         => 'SQL_CALC_FOUND_ROWS / FOUND_ROWS()',
        'GROUP_CONCAT'       => 'GROUP_CONCAT (limited SQLite support)',
        'REGEXP'             => 'REGEXP operator (requires extension)',
        'REPLACE INTO'       => 'REPLACE INTO (supported but different semantics)',
    ];

    /** @return array{compatible: bool, issues: array, scanned: int} */
    public function scan(): array
    {
        $issues = [];
        $scanned = 0;

        // Check ext-sqlite3
        if (!extension_loaded('sqlite3')) {
            $issues[] = ['severity' => 'critical', 'source' => 'system', 'detail' => 'ext-sqlite3 not available'];
            return ['compatible' => false, 'issues' => $issues, 'scanned' => 0];
        }

        // Scan active plugins
        $plugins = get_option('active_plugins', []);
        foreach ($plugins as $plugin) {
            if (str_starts_with($plugin, 'gratis-db/')) continue; // Don't scan ourselves
            $dir = WP_PLUGIN_DIR . '/' . dirname($plugin);
            if (!is_dir($dir)) continue;
            $found = $this->scanDirectory($dir);
            $scanned++;
            foreach ($found as $issue) {
                $issues[] = array_merge($issue, ['source' => 'plugin:' . dirname($plugin)]);
            }
        }

        // Scan active theme
        $themeDir = get_stylesheet_directory();
        if (is_dir($themeDir)) {
            $found = $this->scanDirectory($themeDir);
            $scanned++;
            foreach ($found as $issue) {
                $issues[] = array_merge($issue, ['source' => 'theme:' . get_stylesheet()]);
            }
        }

        $critical = array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'critical');
        return [
            'compatible' => empty($critical),
            'issues'     => $issues,
            'scanned'    => $scanned,
        ];
    }

    private function scanDirectory(string $dir): array
    {
        $issues = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;

            foreach (self::INCOMPATIBLE_PATTERNS as $pattern => $description) {
                if (stripos($content, $pattern) !== false) {
                    $issues[] = [
                        'severity' => $pattern === 'FULLTEXT' ? 'critical' : 'warning',
                        'pattern'  => $pattern,
                        'detail'   => $description,
                        'file'     => str_replace(ABSPATH, '', $file->getPathname()),
                    ];
                }
            }
        }
        return $issues;
    }
}
