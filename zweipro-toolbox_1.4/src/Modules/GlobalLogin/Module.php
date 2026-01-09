<?php

namespace Zweipro\Toolbox\Modules\GlobalLogin;

use Zweipro\Toolbox\Core\ModuleInterface;

class Module implements ModuleInterface
{
    const OPTION_KEY = 'zweipro_toolbox_global_login';

    public function get_id(): string     { return 'global_login'; }
    public function get_title(): string  { return __('Globaler Seitenschutz', 'zweipro-toolbox'); }
    public function get_description(): string { return __('Schützt die gesamte Website mit einem Login.', 'zweipro-toolbox'); }

    public function init(): void
    {
        // Admin Backend überspringen
        if (is_admin()) return;

        $settings = get_option(self::OPTION_KEY, [
            'enabled'       => 0,
            'login_pass'    => '',
            'whitelist'     => '',
            'ip_whitelist'  => '',
        ]);

        // Modul deaktiviert?
        if (empty($settings['enabled'])) {
            return;
        }

        // Admins dürfen immer rein
        if (current_user_can('manage_options')) {
            return;
        }

        // IP Freigabe
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $allowed_ips = array_filter(array_map('trim', explode("\n", $settings['ip_whitelist'])));
        if (in_array($user_ip, $allowed_ips)) {
            return;
        }

        // Sollseite ist Whitelist?
        $allowed_paths = array_filter(array_map('trim', explode("\n", $settings['whitelist'])));
        $current_path = strtok($_SERVER['REQUEST_URI'], '?');

        if (in_array($current_path, $allowed_paths)) {
            return;
        }

        // Session ok?
        if (!empty($_COOKIE['zweipro_login_ok']) && $_COOKIE['zweipro_login_ok'] === sha1($settings['login_pass'])) {
            return;
        }

        // Login Handling
        add_action('template_redirect', function () use ($settings) {
            $this->render_login_form($settings);
            exit;
        });
    }

    private function render_login_form(array $settings): void
    {
        $error = '';

        // POST Verarbeitung
        if (!empty($_POST['zweipro_login_pass'])) {

            if (trim($_POST['zweipro_login_pass']) === $settings['login_pass']) {
                // Cookie setzen 24 Std.
                setcookie('zweipro_login_ok', sha1($settings['login_pass']), time() + 86400, "/");
                wp_safe_redirect($_SERVER['REQUEST_URI']);
                exit;
            } else {
                $error = "Falsches Passwort!";
            }
        }

        // Minimaler Login HTML Output
        status_header(403);
        nocache_headers();

        ?>
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="robots" content="noindex,nofollow">
            <title>Login geschützt</title>
           <style>
    body {
        background: #f0f2f5;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .wrapper {
        background: #fff;
        padding: 40px 35px;
        border-radius: 12px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.10);
        width: 380px;
        text-align: center;
    }
    .wrapper h2 {
        font-size: 20px;
        margin-bottom: 25px;
        color: #111;
        font-weight: 600;
    }
    input[type=password] {
        width: 200px;
        padding: 15px;
        margin-bottom: 18px;
        border: 0px solid #cdcdcd;
        border-radius: 7px;
        font-size: 16px;
        background: #f7f7f7;
        transition: all 0.2s ease;
    }
    input[type=password]:focus {
        border-color: #005cff;
        background: #f0f0f0;
        color:black;
        font-size:22px;
        outline: none;
        box-shadow: none;

    }
    button {
        padding: 17px 20px;
        width: 230px;
        background: #000;
        color: white;
        font-size: 16px;
        border: none;
        border-radius: 7px;
        cursor: pointer;
        font-weight: 600;
        transition: background 0.2s ease;
    }
    button:hover {
        background: #333;
    }
    .error {
        color: #d00;
        margin-bottom: 12px;
        font-size: 14px;
        font-weight: 500;
    }
</style>
        </head>
        <body>

       <div class="wrapper">
    
    <img src="https://zweipro.de/wp-content/uploads/2025/06/favicon.png"
         alt="Logo"
         style="width:80px;display:block;margin:0 auto 15px;">

    <img src="https://zweipro.de/wp-content/uploads/2025/12/block.svg"
         alt="Shield"
         style="width:20px;display:block;margin:0 auto 25px;">

   

            <?php if ($error): ?>
                <div class="error"><?= esc_html($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="password" name="zweipro_login_pass" placeholder="Passwort eingeben" autofocus>
                <button type="submit">Login</button>
            </form>

        </div>

        </body>
        </html>
        <?php
    }

    public function register_admin_page(string $parent_slug): void
    {
        add_submenu_page(
            $parent_slug,
            $this->get_title(),
            $this->get_title(),
            'manage_options',
            'zweipro-toolbox-global-login',
            [$this, 'render_admin_page']
        );

        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings(): void
    {
        register_setting(
            'zweipro_toolbox_global_login_group',
            self::OPTION_KEY,
            [ 'type' => 'array', 'default' => [] ]
        );
    }

    public function render_admin_page(): void
    {
        $settings = get_option(self::OPTION_KEY, [
            'enabled'       => 0,
            'login_pass'    => '',
            'whitelist'     => '',
            'ip_whitelist'  => '',
        ]);

        ?>
        <div class="wrap">
        <h1><?= esc_html($this->get_title()) ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields('zweipro_toolbox_global_login_group'); ?>

            <table class="form-table">

                <tr>
                    <th>Seitenschutz aktivieren?</th>
                    <td>
                        <input type="checkbox" name="<?= self::OPTION_KEY ?>[enabled]" value="1"
                            <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                    </td>
                </tr>

                <tr>
                    <th>Login Passwort</th>
                    <td><input type="text" name="<?= self::OPTION_KEY ?>[login_pass]" value="<?= esc_attr($settings['login_pass']) ?>" style="width:300px;"></td>
                </tr>

                <tr>
                    <th>URL Whitelist (eine pro Zeile)</th>
                    <td><textarea name="<?= self::OPTION_KEY ?>[whitelist]" rows="5" style="width:300px;"><?= esc_textarea($settings['whitelist']) ?></textarea></td>
                </tr>

                <tr>
                    <th>IP Whitelist (eine pro Zeile)</th>
                    <td><textarea name="<?= self::OPTION_KEY ?>[ip_whitelist]" rows="5" style="width:300px;"><?= esc_textarea($settings['ip_whitelist']) ?></textarea></td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
        </div>
        <?php
    }
}