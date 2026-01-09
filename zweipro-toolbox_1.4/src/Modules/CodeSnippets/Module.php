<?php

namespace Zweipro\Toolbox\Modules\CodeSnippets;

use Zweipro\Toolbox\Core\ModuleInterface;

class Module implements ModuleInterface
{
    const OPTION_KEY = 'zweipro_toolbox_code_snippets';

    public function get_id(): string     { return 'code_snippets'; }
    public function get_title(): string  { return __('Code Snippets', 'zweipro-toolbox'); }
    public function get_description(): string { return __('Füge individuelle PHP-, CSS- oder JS-Snippets hinzu.', 'zweipro-toolbox'); }

    /* ----------------------------------------------
     * INIT – führt Snippets im Frontend aus
     * ---------------------------------------------- */
    public function init(): void
    {
        $snippets = get_option(self::OPTION_KEY, []);

        if (!empty($snippets)) {
            foreach ($snippets as $snippet) {
                if (empty($snippet['enabled'])) continue;

                switch ($snippet['type']) {
                    case 'php':
                        try {
                            eval('?>'.$snippet['code']);
                        } catch (\Throwable $e) {
                            error_log('[ZWEIPRO Snippet PHP Error] '.$e->getMessage());
                        }
                        break;

                    case 'css':
                        add_action('wp_head', function () use ($snippet) {
                            echo "<style>{$snippet['code']}</style>";
                        });
                        break;

                    case 'js':
                        $hook = ($snippet['location'] === 'footer') ? 'wp_footer' : 'wp_head';
                        add_action($hook, function () use ($snippet) {
                            echo "<script>{$snippet['code']}</script>";
                        });
                        break;
                }
            }
        }
    }

    /* ----------------------------------------------
     * ADMIN UI REGISTRIEREN
     * ---------------------------------------------- */
    public function register_admin_page(string $parent_slug): void
    {
        add_submenu_page(
            $parent_slug,
            $this->get_title(),
            $this->get_title(),
            'manage_options',
            'zweipro-toolbox-code-snippets',
            [$this, 'render_admin_page']
        );
    }

    /* ----------------------------------------------
     * ADMIN LOGIK – LISTE + BEARBEITEN + NEU + LÖSCHEN
     * ---------------------------------------------- */
    public function render_admin_page(): void
    {
        $snippets = get_option(self::OPTION_KEY, []);

        $action = $_GET['action'] ?? '';
        $index  = intval($_GET['index'] ?? -1);

        // DELETE ACTION
        if ($action === 'delete' && $index >= 0 && isset($snippets[$index])) {
            check_admin_referer('delete_snippet_'.$index);
            unset($snippets[$index]);
            $snippets = array_values($snippets);
            update_option(self::OPTION_KEY, $snippets);
            echo '<div class="updated"><p>Snippet gelöscht.</p></div>';
        }

        // SAVE ACTION
        if ($action === 'save' && $_POST) {
            check_admin_referer('save_snippet');

            $item = [
                'enabled'   => isset($_POST['enabled']) ? 1 : 0,
                'name'      => sanitize_text_field($_POST['name']),
                'type'      => sanitize_text_field($_POST['type']),
                'location'  => sanitize_text_field($_POST['location']),
                'code'      => wp_kses_post(stripslashes($_POST['code'])),
            ];

            if ($index >= 0 && isset($snippets[$index])) {
                $snippets[$index] = $item;
            } else {
                $snippets[] = $item;
            }

            update_option(self::OPTION_KEY, $snippets);

            echo '<div class="updated"><p>Snippet gespeichert.</p></div>';
        }

        // BEARBEITEN UI
        if ($action === 'edit' || $action === 'new') {
            $item = [
                'enabled'   => 1,
                'name'      => '',
                'type'      => 'php',
                'location'  => 'header',
                'code'      => ''
            ];

            if ($index >= 0 && isset($snippets[$index])) {
                $item = $snippets[$index];
            }

            $this->render_form($item, $index);
            return;
        }

        // DEFAULT: LISTE RENDERN
        $this->render_list($snippets);
    }

    /* ----------------------------------------------
     * LIST VIEW
     * ---------------------------------------------- */
    private function render_list(array $snippets): void
    {
        ?>
        <div class="wrap">
            <h1><?= esc_html($this->get_title()) ?></h1>

            <a href="<?= admin_url('admin.php?page=zweipro-toolbox-code-snippets&action=new'); ?>"
                class="button button-primary" style="margin-bottom:10px;">
                Neues Snippet anlegen
            </a>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Typ</th>
                        <th>Position</th>
                        <th style="width:140px;">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($snippets)) : ?>
                    <tr><td colspan="5">Noch keine Snippets vorhanden.</td></tr>
                <?php else: ?>
                    <?php foreach ($snippets as $idx => $snippet) : ?>
                        <tr>
                            <td><?= !empty($snippet['enabled']) ? '✔ Aktiv' : '✖ Deaktiviert'; ?></td>
                            <td><?= esc_html($snippet['name']); ?></td>
                            <td><?= strtoupper(esc_html($snippet['type'])); ?></td>
                            <td><?= ucfirst(esc_html($snippet['location'])); ?></td>
                            <td>
                                <a href="<?= admin_url("admin.php?page=zweipro-toolbox-code-snippets&action=edit&index={$idx}") ?>">Bearbeiten</a>
                                |
                                <a href="<?= wp_nonce_url(
                                    admin_url("admin.php?page=zweipro-toolbox-code-snippets&action=delete&index={$idx}"),
                                    'delete_snippet_'.$idx
                                ); ?>" onclick="return confirm('Wirklich löschen?')">Löschen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ----------------------------------------------
     * FORM VIEW
     * ---------------------------------------------- */
    private function render_form(array $item, int $index): void
    {
        $edit_url = admin_url('admin.php?page=zweipro-toolbox-code-snippets&action=save&index='.$index);
        ?>
        <div class="wrap">
            <h1><?= $index >= 0 ? 'Snippet bearbeiten' : 'Neues Snippet erstellen' ?></h1>

            <form method="post" action="<?= esc_url($edit_url) ?>">
                <?php wp_nonce_field('save_snippet'); ?>

                <table class="form-table">

                    <tr>
                        <th scope="row">Aktiviert?</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1"
                                    <?= !empty($item['enabled']) ? 'checked' : '' ?>>
                                Dieses Snippet aktivieren
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Name</th>
                        <td>
                            <input type="text" name="name" value="<?= esc_attr($item['name']) ?>"
                                style="width: 400px;">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Typ</th>
                        <td>
                            <select name="type">
                                <option value="php"   <?= $item['type']=='php' ? 'selected':'' ?>>PHP</option>
                                <option value="css"   <?= $item['type']=='css' ? 'selected':'' ?>>CSS</option>
                                <option value="js"    <?= $item['type']=='js' ? 'selected':'' ?>>JavaScript</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Position</th>
                        <td>
                            <select name="location">
                                <option value="header" <?= $item['location']=='header'?'selected':'' ?>>Header</option>
                                <option value="footer" <?= $item['location']=='footer'?'selected':'' ?>>Footer</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Code</th>
                        <td>
                            <textarea name="code" rows="15" style="width:100%;font-family:monospace;"><?= esc_textarea($item['code']) ?></textarea>
                        </td>
                    </tr>

                </table>

                <?php submit_button('Speichern'); ?>
            </form>
        </div>
        <?php
    }
}