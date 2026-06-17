<?php declare(strict_types=1);

namespace Gratis\DB;

defined('ABSPATH') || exit;

/*
 * This engine writes to the new SQLite database through the sqlite-database-integration
 * driver's own PDO handle. PDO is therefore required and correct here (it is not a
 * substitute for $wpdb against the live MySQL database, which is read with $wpdb only).
 * Disable just the "use $wpdb instead of PDO" sniff for this file.
 */
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO

/**
 * MySQL -> SQLite data migration engine (correctness-first, reversible).
 *
 * Flow:
 *  1. Readiness/compatibility checks ({@see CompatibilityScanner} + {@see DropinManager}).
 *  2. Pre-migration logical backup of the MySQL database (which is never mutated).
 *  3. Schema is replayed through the sqlite-database-integration translator so the
 *     resulting database is byte-compatible with the runtime drop-in — including the
 *     internal `_mysql_data_types_cache` table the translator relies on. Hand-rolling
 *     the SQLite schema would NOT be runtime-compatible.
 *  4. Row-by-row data copy via PDO prepared statements, preserving primary-key IDs
 *     and serialized payloads exactly.
 *  5. Per-table row-count verification with mismatch reporting.
 *
 * The MySQL database is read-only throughout. Rolling back is simply removing the
 * db.php drop-in (see {@see DropinManager::uninstall()}); WordPress then boots back
 * onto the untouched MySQL database.
 *
 * ponytail: data is paged with LIMIT/OFFSET (O(n^2) for very large tables) and the
 * backup is a logical SQL dump — both are fine for typical WordPress installs; for
 * multi-million-row tables prefer a native dump/restore.
 */
final class Migrator {

	/** Rows copied per batch to bound memory. */
	private const BATCH = 1000;

	private DropinManager $dropin;
	private CompatibilityScanner $scanner;

	public function __construct(?DropinManager $dropin = null, ?CompatibilityScanner $scanner = null) {
		$this->dropin  = $dropin ?? new DropinManager();
		$this->scanner = $scanner ?? new CompatibilityScanner();
	}

	/** True when the live database connection is already SQLite (no MySQL source to migrate). */
	public function isAlreadySqlite(): bool {
		global $wpdb;
		if (defined('DB_ENGINE') && 'sqlite' === constant('DB_ENGINE')) {
			return true;
		}
		return is_object($wpdb) && false !== stripos(get_class($wpdb), 'sqlite');
	}

	/**
	 * Collect migration readiness. Hard blockers prevent migration; warnings are advisory.
	 *
	 * @return array{blockers: string[], warnings: string[], scan: array}
	 */
	public function readiness(): array {
		$blockers = [];
		$warnings = [];

		if ($this->isAlreadySqlite()) {
			$blockers[] = __('The site is already running on SQLite; there is no MySQL source to migrate.', 'gratis-db');
		}
		if (!extension_loaded('pdo_sqlite')) {
			$blockers[] = __('PHP extension pdo_sqlite is not loaded.', 'gratis-db');
		}
		if (null === $this->dropin->pluginPath()) {
			$blockers[] = __('The sqlite-database-integration plugin (with db.copy) was not found in the plugins directory.', 'gratis-db');
		}
		foreach ($this->dropin->canInstall() as $issue) {
			$blockers[] = $issue;
		}

		$scan = $this->scanner->scan();
		foreach ($scan['issues'] as $issue) {
			$label = ($issue['detail'] ?? '') . ' [' . ($issue['source'] ?? 'unknown') . ']';
			if (($issue['severity'] ?? '') === 'critical') {
				$blockers[] = __('Compatibility', 'gratis-db') . ': ' . $label;
			} else {
				$warnings[] = __('Compatibility', 'gratis-db') . ': ' . $label;
			}
		}

		return ['blockers' => $blockers, 'warnings' => $warnings, 'scan' => $scan];
	}

	/**
	 * Enumerate base tables (skipping views) with row counts.
	 *
	 * @return array{tables: array<int, array{name: string, rows: int}>, total_rows: int, skipped: string[]}
	 */
	public function inventory(): array {
		global $wpdb;
		$tables  = [];
		$skipped = [];
		$total   = 0;

		// SHOW FULL TABLES exposes Table_type so base tables can be distinguished from views.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
		if (!is_array($rows)) {
			return ['tables' => [], 'total_rows' => 0, 'skipped' => []];
		}
		foreach ($rows as $row) {
			$name = (string) ($row[0] ?? '');
			$type = (string) ($row[1] ?? 'BASE TABLE');
			if ('BASE TABLE' !== $type) {
				$skipped[] = $name . ' (' . $type . ')';
				continue;
			}
			$safe = $this->safeIdentifier($name);
			if (null === $safe) {
				$skipped[] = $name . ' (unsafe identifier)';
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$count    = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$safe}`");
			$tables[] = ['name' => $name, 'rows' => $count];
			$total   += $count;
		}
		return ['tables' => $tables, 'total_rows' => $total, 'skipped' => $skipped];
	}

	/**
	 * Build the full migration plan: readiness + inventory + target paths.
	 *
	 * @return array{already_sqlite: bool, blockers: string[], warnings: string[], tables: array, total_rows: int, skipped: string[], target_sqlite: string, dropin_path: string, sqlite_plugin: ?string}
	 */
	public function plan(): array {
		$already   = $this->isAlreadySqlite();
		$readiness = $this->readiness();
		$inventory = $already ? ['tables' => [], 'total_rows' => 0, 'skipped' => []] : $this->inventory();

		return [
			'already_sqlite' => $already,
			'blockers'       => $readiness['blockers'],
			'warnings'       => $readiness['warnings'],
			'tables'         => $inventory['tables'],
			'total_rows'     => $inventory['total_rows'],
			'skipped'        => $inventory['skipped'],
			'target_sqlite'  => $this->dropin->targetSqlitePath(),
			'dropin_path'    => $this->dropin->dropinPath(),
			'sqlite_plugin'  => $this->dropin->pluginPath(),
		];
	}

	/**
	 * Run the migration.
	 *
	 * @param bool $dryRun     When true, only the plan is returned; nothing is written.
	 * @param bool $takeBackup When true (default), a logical MySQL backup is written first.
	 * @return array Structured result.
	 * @throws \RuntimeException On a hard failure that aborts the migration.
	 */
	public function migrate(bool $dryRun = false, bool $takeBackup = true): array {
		global $wpdb;

		$plan = $this->plan();

		if ($dryRun) {
			$plan['dry_run'] = true;
			return $plan;
		}

		if (!empty($plan['blockers'])) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: semicolon-separated list of blocking issues. */
						__('Migration blocked: %s', 'gratis-db'),
						implode('; ', $plan['blockers'])
					)
				)
			);
		}

		// 1) Pre-migration backup (MySQL is never mutated, but keep a logical snapshot).
		$backupFile = null;
		if ($takeBackup) {
			$backupFile = $this->backupMysql($plan['tables']);
		}

		// 2) Create + populate the SQLite database via the integration translator.
		$target = $plan['target_sqlite'];
		$this->ensureDir(dirname($target));

		// Refuse to clobber an existing non-empty SQLite file.
		if (file_exists($target) && filesize($target) > 0) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: SQLite database file path. */
						__('Target SQLite database already exists and is not empty: %s. Move it aside before migrating.', 'gratis-db'),
						$target
					)
				)
			);
		}
		$weCreatedTarget = !file_exists($target);

		$translator = null;
		$pdo        = null;
		$copied     = [];
		try {
			$translator = $this->newTranslator($target);
			$pdo        = $translator->get_pdo();

			foreach ($plan['tables'] as $t) {
				$name = $t['name'];
				$safe = $this->safeIdentifier($name);
				if (null === $safe) {
					continue;
				}

				// 2a) Schema via the translator (also populates _mysql_data_types_cache).
				$createSql = $this->showCreate($safe);
				if (null === $createSql || '' === $createSql) {
					throw new \RuntimeException(
						esc_html(
							sprintf(/* translators: %s: table name. */ __('Could not read the schema for table %s.', 'gratis-db'), $name)
						)
					);
				}
				$translator->query($createSql);

				// 2b) Determine the columns SQLite actually created (defensive intersection).
				$sqliteCols = $this->sqliteColumns($pdo, $name);
				if (empty($sqliteCols)) {
					throw new \RuntimeException(
						esc_html(
							sprintf(
								/* translators: 1: table name, 2: translator error message. */
								__('SQLite table %1$s was not created: %2$s', 'gratis-db'),
								$name,
								(string) $translator->get_error_message()
							)
						)
					);
				}

				// 2c) Copy rows, preserving primary-key IDs and serialized payloads.
				$this->copyRows($pdo, $safe, $name, $sqliteCols);
				$copied[] = $name;
			}
		} catch (\Throwable $e) {
			// Clean up a half-written database so a retry starts fresh.
			$pdo        = null;
			$translator = null;
			if ($weCreatedTarget && file_exists($target)) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink($target);
			}
			throw new \RuntimeException(
				esc_html(
					sprintf(/* translators: %s: error message. */ __('Migration aborted during data copy: %s', 'gratis-db'), $e->getMessage())
				),
				0,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous Throwable for chaining, not output.
				$e
			);
		}

		// 3) Install the drop-in LAST so the current request stays on MySQL.
		$install = $this->dropin->install();
		if (empty($install['installed'])) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: drop-in install error message. */
						__('Data was copied but the db.php drop-in could not be installed: %s', 'gratis-db'),
						(string) ($install['error'] ?? 'unknown')
					)
				)
			);
		}

		// 4) Verify per-table row counts.
		$verify = $this->verify($pdo, $plan['tables']);

		return [
			'dry_run'       => false,
			'backup'        => $backupFile,
			'target_sqlite' => $target,
			'tables_copied' => $copied,
			'verification'  => $verify,
			'dropin_backup' => $install['backup'] ?? null,
			'ok'            => empty($verify['mismatches']),
		];
	}

	/**
	 * Compare row counts per table between MySQL and the new SQLite database.
	 *
	 * @param \PDO  $pdo    Connection to the freshly built SQLite database.
	 * @param array $tables Planned tables (name + expected MySQL row count).
	 * @return array{per_table: array<string, array{mysql: int, sqlite: int, match: bool}>, mismatches: array<string, array{mysql: int, sqlite: int}>}
	 */
	private function verify(\PDO $pdo, array $tables): array {
		global $wpdb;
		$report     = [];
		$mismatches = [];
		foreach ($tables as $t) {
			$name = $t['name'];
			$safe = $this->safeIdentifier($name);
			if (null === $safe) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$mysql  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$safe}`");
			$stmt   = $pdo->query('SELECT COUNT(*) FROM ' . $this->quoteSqliteIdent($name));
			$sqlite = $stmt ? (int) $stmt->fetchColumn() : -1;

			$report[$name] = ['mysql' => $mysql, 'sqlite' => $sqlite, 'match' => $mysql === $sqlite];
			if ($mysql !== $sqlite) {
				$mismatches[$name] = ['mysql' => $mysql, 'sqlite' => $sqlite];
			}
		}
		return ['per_table' => $report, 'mismatches' => $mismatches];
	}

	/**
	 * Copy all rows of one table from MySQL into SQLite in batches.
	 *
	 * @param \PDO     $pdo        Target SQLite connection.
	 * @param string   $safe       Validated MySQL table identifier (source).
	 * @param string   $name       Raw table name (target, quoted for SQLite).
	 * @param string[] $sqliteCols Columns that exist in the created SQLite table.
	 */
	private function copyRows(\PDO $pdo, string $safe, string $name, array $sqliteCols): void {
		global $wpdb;

		$colList      = implode(', ', array_map([$this, 'quoteSqliteIdent'], $sqliteCols));
		$placeholders = implode(', ', array_fill(0, count($sqliteCols), '?'));
		$insertSql    = 'INSERT INTO ' . $this->quoteSqliteIdent($name) . ' (' . $colList . ') VALUES (' . $placeholders . ')';
		$insert       = $pdo->prepare($insertSql);

		$offset = 0;
		while (true) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = $wpdb->prepare("SELECT * FROM `{$safe}` LIMIT %d OFFSET %d", self::BATCH, $offset);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results($query, ARRAY_A);
			if (!is_array($rows) || empty($rows)) {
				break;
			}

			$pdo->beginTransaction();
			try {
				foreach ($rows as $row) {
					$position = 1;
					foreach ($sqliteCols as $col) {
						$value = $row[$col] ?? null;
						if (null === $value) {
							$insert->bindValue($position, null, \PDO::PARAM_NULL);
						} else {
							// Bind verbatim; SQLite type affinity preserves integer IDs and
							// stores serialized strings byte-for-byte.
							$insert->bindValue($position, (string) $value, \PDO::PARAM_STR);
						}
						++$position;
					}
					$insert->execute();
				}
				$pdo->commit();
			} catch (\Throwable $e) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
				throw new \RuntimeException(
					esc_html(
						sprintf(/* translators: 1: table name, 2: error message. */ __('Row copy failed for %1$s: %2$s', 'gratis-db'), $name, $e->getMessage())
					),
					0,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous Throwable for chaining, not output.
					$e
				);
			}

			if (count($rows) < self::BATCH) {
				break;
			}
			$offset += self::BATCH;
		}
	}

	/**
	 * Instantiate the sqlite-database-integration translator against a target file.
	 *
	 * Loads the translator's class files (they are not loaded on a MySQL site) and
	 * injects our own PDO so the translator never touches the live FQDB constants.
	 *
	 * @param string $target Absolute path to the SQLite database file to create.
	 * @return \WP_SQLite_Translator
	 * @throws \RuntimeException When the integration files are missing.
	 */
	private function newTranslator(string $target): \WP_SQLite_Translator {
		$plugin = $this->dropin->pluginPath();
		if (null === $plugin) {
			throw new \RuntimeException(esc_html__('sqlite-database-integration plugin not found.', 'gratis-db'));
		}
		$base = $plugin . '/wp-includes/sqlite';

		if (!class_exists('WP_SQLite_Translator')) {
			$files = [
				'php-polyfills.php',
				'class-wp-sqlite-lexer.php',
				'class-wp-sqlite-query-rewriter.php',
				'class-wp-sqlite-translator.php',
				'class-wp-sqlite-token.php',
				'class-wp-sqlite-pdo-user-defined-functions.php',
			];
			foreach ($files as $file) {
				$path = $base . '/' . $file;
				if (!file_exists($path)) {
					throw new \RuntimeException(
						esc_html(
							sprintf(/* translators: %s: file name. */ __('Missing SQLite integration file: %s', 'gratis-db'), $file)
						)
					);
				}
				require_once $path;
			}
		}

		$options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
		$pdo     = new \PDO('sqlite:' . $target, null, null, $options);

		return new \WP_SQLite_Translator($pdo);
	}

	/** Read a table's MySQL CREATE statement. */
	private function showCreate(string $safe): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row("SHOW CREATE TABLE `{$safe}`", ARRAY_N);
		return is_array($row) ? (string) ($row[1] ?? '') : null;
	}

	/**
	 * List the column names of a table in the SQLite database.
	 *
	 * @return string[]
	 */
	private function sqliteColumns(\PDO $pdo, string $name): array {
		$stmt = $pdo->query('PRAGMA table_info(' . $this->quoteSqliteIdent($name) . ')');
		if (!$stmt) {
			return [];
		}
		$cols = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $info) {
			if (isset($info['name'])) {
				$cols[] = (string) $info['name'];
			}
		}
		return $cols;
	}

	/**
	 * Write a logical SQL backup of the MySQL database (streamed to bound memory).
	 *
	 * @param array<int, array{name: string, rows: int}> $tables Tables to back up.
	 * @return string Absolute path to the backup file.
	 * @throws \RuntimeException When the backup file cannot be written.
	 */
	private function backupMysql(array $tables): string {
		global $wpdb;

		$dir = $this->dropin->backupDir();
		$this->ensureDir($dir);
		$file = $dir . '/mysql-' . gmdate('Ymd-His') . '.sql';

		// A logical dump is streamed with low-level handles because WP_Filesystem
		// cannot append; this writes a local backup file only.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen($file, 'wb');
		if (false === $handle) {
			throw new \RuntimeException(
				esc_html(
					sprintf(/* translators: %s: file path. */ __('Could not open backup file for writing: %s', 'gratis-db'), $file)
				)
			);
		}

		try {
			$this->write($handle, "-- Gratis DB pre-migration MySQL backup\n-- Generated: " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");

			foreach ($tables as $t) {
				$name = $t['name'];
				$safe = $this->safeIdentifier($name);
				if (null === $safe) {
					continue;
				}
				$create = $this->showCreate($safe);
				$this->write($handle, "\n-- Table: {$name}\nDROP TABLE IF EXISTS `{$name}`;\n" . ($create ?? '') . ";\n");

				$offset = 0;
				while (true) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$query = $wpdb->prepare("SELECT * FROM `{$safe}` LIMIT %d OFFSET %d", self::BATCH, $offset);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$rows = $wpdb->get_results($query, ARRAY_A);
					if (!is_array($rows) || empty($rows)) {
						break;
					}
					foreach ($rows as $row) {
						$cols   = '`' . implode('`, `', array_keys($row)) . '`';
						$values = [];
						foreach ($row as $value) {
							$values[] = null === $value ? 'NULL' : "'" . $wpdb->_real_escape((string) $value) . "'";
						}
						$this->write($handle, "INSERT INTO `{$name}` ({$cols}) VALUES (" . implode(', ', $values) . ");\n");
					}
					if (count($rows) < self::BATCH) {
						break;
					}
					$offset += self::BATCH;
				}
			}

			$this->write($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
		} finally {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@fclose($handle);
		}

		return $file;
	}

	/**
	 * Write to a stream, failing loudly so a partial backup is never trusted.
	 *
	 * @param resource $handle Open file handle.
	 * @param string   $data   Data to write.
	 * @throws \RuntimeException When the write fails.
	 */
	private function write($handle, string $data): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if (false === fwrite($handle, $data)) {
			throw new \RuntimeException(esc_html__('Failed writing the MySQL backup file.', 'gratis-db'));
		}
	}

	/** Create a directory (recursively) if it does not exist. */
	private function ensureDir(string $dir): void {
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}
	}

	/** Validate an identifier we must interpolate (identifiers cannot be bound). */
	private function safeIdentifier(string $name): ?string {
		return 1 === preg_match('/^[A-Za-z0-9_]+$/', $name) ? $name : null;
	}

	/** Quote an identifier for SQLite (double quotes, doubled to escape). */
	private function quoteSqliteIdent(string $name): string {
		return '"' . str_replace('"', '""', $name) . '"';
	}
}

// phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO
