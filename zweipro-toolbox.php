<?php
/**
 * Plugin Name: ZWEIPRO Toolbox
 * Description: Sammlung von Utility-Modulen (SMTP, Snippets, Captcha, Cookie Banner, etc.).
 * Version: 1.4.0
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

define('ZWEIPRO_TOOLBOX_VERSION', '0.1.4');
define('ZWEIPRO_TOOLBOX_PATH', plugin_dir_path(__FILE__));
define('ZWEIPRO_TOOLBOX_URL', plugin_dir_url(__FILE__));

require_once ZWEIPRO_TOOLBOX_PATH . 'src/autoload.php';

add_action('plugins_loaded', function () {
    \Zweipro\Toolbox\Core\Plugin::instance();
});
