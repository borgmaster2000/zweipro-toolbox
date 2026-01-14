<?php
/**
 * Plugin Name: ZWEIPRO Toolbox
 * Description: Sammlung von Utility-Modulen (SMTP, Snippets, Captcha, Cookie Banner, etc.).
 * Version: 1.4.3
 * Author: ZWEIPRO
 * Text Domain: zweipro-toolbox
 */
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/borgmaster2000/zweipro-toolbox/',
    __FILE__,
    'zweipro-toolbox'
);

// WICHTIG: Releases verwenden (empfohlen)
$updateChecker->getVcsApi()->enableReleaseAssets();


if (!defined('ABSPATH')) {
    exit;
}

define('ZWEIPRO_TOOLBOX_VERSION', '1.4.3');
define('ZWEIPRO_TOOLBOX_PATH', plugin_dir_path(__FILE__));
define('ZWEIPRO_TOOLBOX_URL', plugin_dir_url(__FILE__));

require_once ZWEIPRO_TOOLBOX_PATH . 'src/autoload.php';

add_action('plugins_loaded', function () {
    \Zweipro\Toolbox\Core\Plugin::instance();
});


register_deactivation_hook(__FILE__, function () {
    // Autoloader sicher laden (auf Deactivate nicht garantiert)
    if (!class_exists(\Zweipro\Toolbox\Core\Plugin::class)) {
        require_once ZWEIPRO_TOOLBOX_PATH . 'src/autoload.php';
    }

    // Plugin-Instanz holen (wenn ihr Singleton habt)
    $plugin = \Zweipro\Toolbox\Core\Plugin::instance();

    // Variante 1 (ideal): Plugin hat Zugriff auf Module
    if (method_exists($plugin, 'get_module')) {
        $module = $plugin->get_module('protected_files');
        if ($module && method_exists($module, 'on_deactivate')) {
            $module->on_deactivate();
            return;
        }
    }

    // Variante 2 (Fallback): direkt instanziieren (nur wenn nötig)
    if (class_exists(\Zweipro\Toolbox\Modules\ProtectedFiles\Module::class)) {
        (new \Zweipro\Toolbox\Modules\ProtectedFiles\Module())->on_deactivate();
    }
});
