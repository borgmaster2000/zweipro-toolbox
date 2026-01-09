<?php

namespace Zweipro\Toolbox\Modules\EmailEncoder;

use Zweipro\Toolbox\Core\ModuleInterface;

class Module implements ModuleInterface
{
    const OPTION_KEY = 'zweipro_toolbox_email_encoder';

    public function get_id(): string     { return 'email_encoder'; }
    public function get_title(): string  { return __('E-Mail Encoder', 'zweipro-toolbox'); }
    public function get_description(): string { return __('Schützt E-Mails vor Spam Bots durch Verschleierung.', 'zweipro-toolbox'); }

    /* =====================================================================
     * INIT — automatische Hooks & JS Encoder
     * ===================================================================== */
    public function init(): void
    {
        $settings = get_option(self::OPTION_KEY, [
            'auto_encode'  => 1,
            'encode_style' => 'hex',
            'encode_links' => 1,
            'global_output_buffer' => 1,
        ]);

        /* Serverseitiges Content Encoding */
        if (!empty($settings['auto_encode'])) {
            add_filter('the_content', [$this, 'encode_emails']);
            add_filter('widget_text', [$this, 'encode_emails']);
            add_filter('widget_text_content', [$this, 'encode_emails']);
            add_filter('the_excerpt', [$this, 'encode_emails']);
            add_filter('acf/format_value', [$this, 'encode_emails']);
            add_filter('wp_nav_menu_items', [$this, 'encode_emails']);
        }

        /* Volle Seitenausgabe filtern */
        if (!empty($settings['global_output_buffer'])) {
            add_action('template_redirect', function () use ($settings) {
                ob_start(function ($buffer) {
                    return $this->encode_emails($buffer);
                });
            });
        }

        /* Shortcode (optional) */
        add_shortcode('email', function ($atts) use ($settings) {
            $a = shortcode_atts([
                'address' => '',
                'link'    => $settings['encode_links'],
            ], $atts);

            return $this->encode($a['address'], ($a['link'] ? true : false), $settings['encode_style']);
        });

        /* JavaScript DOM Encoder — Fix für Pagebuilder */
        add_action('wp_footer', [$this, 'inject_js_encoder']);
    }

    /* =====================================================================
     * ERKENNUNG & ERSETZUNG — Serverseitig
     * ===================================================================== */
    public function encode_emails(string $content): string
    {
        $settings = get_option(self::OPTION_KEY);

        /** 1) Links wie href="mailto:email@domain.de" */
        $content = preg_replace_callback(
            '/href=["\']mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})["\']/i',
            function ($matches) use ($settings) {

                $encoded_email = strip_tags(
                    $this->encode($matches[1], false, $settings['encode_style'])
                );

                return 'href="mailto:' . esc_attr($encoded_email) . '"';
            },
            $content
        );

        /** 2) mailto:email@domain.de als Plaintext */
        $content = preg_replace_callback(
            '/mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i',
            function ($matches) use ($settings) {

                $encoded = strip_tags(
                    $this->encode($matches[1], false, $settings['encode_style'])
                );

                return 'mailto:' . $encoded;
            },
            $content
        );

        /** 3) Normale Text-E-Mails oder Linktexte */
        return preg_replace_callback(
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/i',
            function ($matches) use ($settings) {

                return $this->encode(
                    $matches[0],
                    (bool)$settings['encode_links'],
                    $settings['encode_style']
                );
            },
            $content
        );
    }

    /* =====================================================================
     * ENCODING ENGINE — hex / js / rot13
     * ===================================================================== */
    private function encode(string $email, bool $link, string $style): string
    {
        switch ($style) {

            case 'js':
                $encoded = '';
                foreach (str_split($email) as $c) {
                    $encoded .= '&#' . ord($c) . ';';
                }
                return "<script>document.write(" . json_encode($encoded) . ");</script>";

            case 'rot13':
                $rot = str_rot13($email);
                return '<span data-rot="'.esc_attr($rot).'"></span>
                        <script>document.currentScript.previousElementSibling.innerHTML="'.esc_js($email).'";</script>';

            case 'hex':
            default:
                $encoded = '';
                for ($i = 0; $i < strlen($email); $i++) {
                    $encoded .= '&#x' . dechex(ord($email[$i])) . ';';
                }
                return $link ? '<a href="mailto:'.$encoded.'">'.$encoded.'</a>' : $encoded;
        }
    }

    /* =====================================================================
     * JAVASCRIPT DOM ENCODER — Pagebuilder Fixer
     * ===================================================================== */
    public function inject_js_encoder(): void
{
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("a[href^='mailto:']").forEach(function (link) {

            let email = link.getAttribute("href").replace("mailto:", "");

            // HEX Encode
            let encoded = "";
            for (let i = 0; i < email.length; i++) {
                encoded += email.charCodeAt(i).toString(16) + "-";
            }

            // Speichere encoded email im data-Attribut
            link.setAttribute("data-email", encoded);

            // Entferne mailto komplett, damit Bots es nicht sehen
            link.setAttribute("href", "#email-protected");

            // Click Handler: dekodiert und öffnet echtes Mailto
            link.addEventListener("click", function (e) {
                e.preventDefault();

                let encoded = this.getAttribute("data-email").split("-");
                let decoded = "";

                encoded.forEach(function (hex) {
                    if (hex.length > 0) decoded += String.fromCharCode(parseInt(hex, 16));
                });

                window.location.href = "mailto:" + decoded;
            });
        });
    });
    </script>
    <?php
}
    /* =====================================================================
     * ADMIN UI
     * ===================================================================== */
    public function register_admin_page(string $parent_slug): void
    {
        add_submenu_page(
            $parent_slug,
            $this->get_title(),
            $this->get_title(),
            'manage_options',
            'zweipro-toolbox-email-encoder',
            [$this, 'render_admin_page']
        );

        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings(): void
    {
        register_setting(
            'zweipro_toolbox_email_encoder_group',
            self::OPTION_KEY,
            [ 'type' => 'array', 'default' => [] ]
        );
    }

    public function render_admin_page(): void
    {
        $settings = get_option(self::OPTION_KEY, [
            'auto_encode'          => 1,
            'encode_style'         => 'hex',
            'encode_links'         => 1,
            'global_output_buffer' => 1,
        ]);

        ?>
        <div class="wrap">
            <h1><?= esc_html($this->get_title()) ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('zweipro_toolbox_email_encoder_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>Automatische Erkennung?</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?= self::OPTION_KEY ?>[auto_encode]" value="1"
                                    <?= !empty($settings['auto_encode']) ? 'checked' : '' ?>>
                                Ja — Mailadressen im Inhalt automatisch verschlüsseln
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th>Verschlüsselungsart</th>
                        <td>
                            <select name="<?= self::OPTION_KEY ?>[encode_style]">
                                <option value="hex"   <?= $settings['encode_style']=='hex'?'selected':'' ?>>HEX Encoding</option>
                                <option value="js"    <?= $settings['encode_style']=='js'?'selected':'' ?>>JavaScript Write</option>
                                <option value="rot13" <?= $settings['encode_style']=='rot13'?'selected':'' ?>>ROT13 Maskierung</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>Als klickbarer Link?</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?= self::OPTION_KEY ?>[encode_links]" value="1"
                                    <?= !empty($settings['encode_links']) ? 'checked' : '' ?>>
                                Ja — mailto-Link generieren
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th>Globaler Output-Filter?</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?= self::OPTION_KEY ?>[global_output_buffer]" value="1"
                                    <?= !empty($settings['global_output_buffer']) ? 'checked' : '' ?>>
                                Ja — komplette Seiten-Ausgabe filtern (maximaler Schutz)
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Test</h2>
            <p>Shortcode Beispiel (optional):  
                <code>[email address="info@domain.de"]</code></p>
        </div>
        <?php
    }
}