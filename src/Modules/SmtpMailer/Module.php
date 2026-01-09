<?php

namespace Zweipro\Toolbox\Modules\SmtpMailer;

use Zweipro\Toolbox\Core\ModuleInterface;

class Module implements ModuleInterface
{
    const OPTION_KEY = 'zweipro_toolbox_smtp';

    public function get_id(): string     { return 'smtp_mailer'; }
    public function get_title(): string  { return __('SMTP Mailer', 'zweipro-toolbox'); }
    public function get_description(): string { return __('Sendet Mails per SMTP statt PHP mail().', 'zweipro-toolbox'); }

    public function init(): void
    {
        $settings = get_option(self::OPTION_KEY, []);

        // nur aktiv wenn Host gesetzt ist
        if (!empty($settings['host'])) {
            add_action('phpmailer_init', function ($phpmailer) use ($settings) {
                $phpmailer->isSMTP();
                $phpmailer->Host       = $settings['host'];
                $phpmailer->Port       = intval($settings['port'] ?? 587);
                $phpmailer->SMTPAuth   = !empty($settings['auth']);
                $phpmailer->Username   = $settings['username'] ?? '';
                $phpmailer->Password   = $settings['password'] ?? '';
                $phpmailer->SMTPSecure = $settings['encryption'] ?? 'tls';

                if (!empty($settings['from_email'])) {
                    $phpmailer->setFrom($settings['from_email'], $settings['from_name'] ?? '');
                }
            });
        }
    }

    public function register_admin_page(string $parent_slug): void
    {
        add_submenu_page(
            $parent_slug,
            $this->get_title(),
            $this->get_title(),
            'manage_options',
            'zweipro-toolbox-smtp',
            [$this, 'render_admin_page']
        );

        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings(): void
    {
        register_setting(
            'zweipro_toolbox_smtp_group',
            self::OPTION_KEY,
            [ 'type' => 'array', 'default' => [] ]
        );
    }

    public function render_admin_page(): void
    {
        $settings = get_option(self::OPTION_KEY, [
            'host'       => '',
            'port'       => '587',
            'encryption' => 'tls',
            'auth'       => 1,
            'username'   => '',
            'password'   => '',
            'from_email' => '',
            'from_name'  => '',
        ]);

        ?>
        <div class="wrap">
            <h1><?= esc_html($this->get_title()) ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('zweipro_toolbox_smtp_group'); ?>

                <table class="form-table">

                    <tr>
                        <th>SMTP Host</th>
                        <td>
                            <input type="text" name="<?= self::OPTION_KEY ?>[host]" value="<?= esc_attr($settings['host']) ?>" style="width:300px;">
                        </td>
                    </tr>

                    <tr>
                        <th>Port</th>
                        <td>
                            <input type="text" name="<?= self::OPTION_KEY ?>[port]" value="<?= esc_attr($settings['port']) ?>" style="width:80px;">
                        </td>
                    </tr>

                    <tr>
                        <th>Verschlüsselung</th>
                        <td>
                            <select name="<?= self::OPTION_KEY ?>[encryption]">
                                <option value="none" <?= $settings['encryption']=='none'?'selected':'' ?>>Keine</option>
                                <option value="tls"  <?= $settings['encryption']=='tls'?'selected':'' ?>>TLS</option>
                                <option value="ssl"  <?= $settings['encryption']=='ssl'?'selected':'' ?>>SSL</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>SMTP Auth aktiv?</th>
                        <td>
                            <input type="checkbox" name="<?= self::OPTION_KEY ?>[auth]" value="1"
                                <?= !empty($settings['auth']) ? 'checked' : '' ?>>
                        </td>
                    </tr>

                    <tr>
                        <th>Benutzername</th>
                        <td><input type="text" name="<?= self::OPTION_KEY ?>[username]" value="<?= esc_attr($settings['username']) ?>" style="width:300px;"></td>
                    </tr>

                    <tr>
                        <th>Passwort</th>
                        <td><input type="password" name="<?= self::OPTION_KEY ?>[password]" value="<?= esc_attr($settings['password']) ?>" style="width:300px;"></td>
                    </tr>

                    <tr>
                        <th>Absender-Mail</th>
                        <td><input type="email" name="<?= self::OPTION_KEY ?>[from_email]" value="<?= esc_attr($settings['from_email']) ?>" style="width:300px;"></td>
                    </tr>

                    <tr>
                        <th>Absender-Name</th>
                        <td><input type="text" name="<?= self::OPTION_KEY ?>[from_name]" value="<?= esc_attr($settings['from_name']) ?>" style="width:300px;"></td>
                    </tr>

                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2>Testmail senden</h2>
            <form method="post">
                <input type="email" name="test_email" placeholder="Ziel-E-Mail" required style="width:300px;">
                <button class="button button-primary" name="send_test">Senden</button>
            </form>

            <?php
            if (!empty($_POST['send_test']) && !empty($_POST['test_email'])) {
                $sent = wp_mail($_POST['test_email'], 'SMTP Test', 'Die SMTP Verbindung funktioniert!');
                echo $sent ? "<p style='color:green;'>Testmail gesendet ✔️</p>"
                           : "<p style='color:red;'>Senden fehlgeschlagen ❌</p>";
            }
            ?>

        </div>
        <?php
    }
}