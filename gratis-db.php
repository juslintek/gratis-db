<?php
/**
 * Plugin Name: Gratis DB
 * Plugin URI: https://github.com/juslintek/gratis-db
 * Description: Optional SQLite WordPress database module. Compatibility scanner, migration tools, and safety-first db.php drop-in management.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-db
 */

defined('ABSPATH') || exit;

define('GRATIS_DB_VERSION', '1.0.0');
define('GRATIS_DB_FILE', __FILE__);
define('GRATIS_DB_DIR', plugin_dir_path(__FILE__));
define('GRATIS_DB_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/autoload.php';

add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-db', \Gratis\DB\CLI\DBCommand::class);
    }

    // Slow query capture (only active when enabled in settings).
    \Gratis\DB\SlowQueryLog::init();
});

// Admin settings page + table-optimize / clear-log actions.
add_action('admin_menu', [\Gratis\DB\Settings::class, 'register_menu']);
add_action('admin_init', [\Gratis\DB\Settings::class, 'register_settings']);
\Gratis\DB\Settings::register_actions();
