<?php
namespace Zweipro\Toolbox\Core;

class ModuleManager
{
    protected array $modules = [];
    protected array $active_modules = [];

    public function __construct()
    {
        $this->active_modules = get_option('zweipro_toolbox_active_modules', []);
    }

    public function register_module(ModuleInterface $module): void
    {
        $this->modules[$module->get_id()] = $module;
    }

    public function init_modules(): void
    {
        foreach ($this->modules as $id => $module) {
            if (!empty($this->active_modules[$id])) {
                $module->init();
            }
        }
    }

    public function register_admin_pages(string $parent_slug): void
    {
        // Nur aktive Module im Menü anzeigen
        foreach ($this->modules as $id => $module) {
            if (!empty($this->active_modules[$id])) {
                $module->register_admin_page($parent_slug);
            }
        }

           }


    /* ======================================================================
       MODULE SEITE – MODERNE UI VERSION
       ====================================================================== */

    public function render_admin_modules_page(): void
{
    // Logo laden
    $logo_url = plugin_dir_url(__FILE__) . '../assets/zp-logo.png';
    ?>

    <div class="zweipro-wrap">

        <!-- HEADER BEREICH -->
        <div style="
            display:flex;
            align-items:center;
            gap:20px;
            margin-bottom:25px;
        ">
            <img src="<?= esc_url($logo_url); ?>" 
                 alt="ZWEIPRO Logo"
                 style="height:44px; margin-top:15px;width:auto;">

            <h1 style="margin:0; font-size:28px; font-weight:700; margin-top:-8px;">
                ZWEIPRO-Tools
            </h1>
        </div>

        <div class="zweipro-card" style="width:100%">

            <h2 style="margin-top:0;">Module verwalten</h2>

            <table class="widefat zweipro-table zweipro-zebra" style="width:100%">
                <thead>
                    <tr>
                        <th>Modul</th>
                        <th>Beschreibung</th>
                        <th>Aktiv</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($this->modules as $id => $module): ?>
                    <tr>

                        <!-- Modulname -->
                        <td style="font-size:16px; padding:18px 10px;">
                            <strong><?= esc_html($module->get_title()) ?></strong>
                        </td>

                        <!-- Beschreibung -->
                        <td style="font-size:15px; padding:18px 10px;">
                            <?= esc_html($module->get_description()) ?>
                        </td>

                        <!-- Aktiv Checkbox -->
                        <td style="padding:18px 10px;">
    <label class="zweipro-toggle">
        <input type="checkbox"
               class="zweipro-module-toggle"
               data-module="<?= esc_attr($id) ?>"
            <?= !empty($this->active_modules[$id]) ? 'checked' : '' ?>>
        <span class="switch"></span>
    </label>
</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        </div>

    </div>

    <script>
    /**
     * Ajax Toggle für Module
     */
    document.addEventListener('change', function(e) {
        const checkbox = e.target.closest('.zweipro-module-toggle');
        if (!checkbox) return;

        const formData = new FormData();
        formData.append('action', 'zweipro_toggle_module');
        formData.append('module', checkbox.dataset.module);
        formData.append('value', checkbox.checked ? '1' : '0');

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(() => {
            location.reload(); // Modul sofort sichtbar
        });
    });
    </script>

    <?php
}
}