<?php

namespace Zweipro\Toolbox\Core;

use Zweipro\Toolbox\Modules\CodeSnippets\Module as CodeSnippetsModule;
use Zweipro\Toolbox\Modules\EmailEncoder\Module as EmailEncoderModule;
use Zweipro\Toolbox\Modules\GlobalLogin\Module as GlobalLoginModule;
use Zweipro\Toolbox\Modules\SmtpMailer\Module as SmtpModule;
use Zweipro\Toolbox\Modules\ProtectedFiles\Module as ProtectedFilesModule;

class Plugin
{
    private static ?Plugin $instance = null;

    protected ModuleManager $module_manager;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->module_manager = new ModuleManager();

        $this->register_modules();
        $this->hooks();
    }

    /* ======================================================
       Module registrieren
       ====================================================== */
    protected function register_modules(): void
    {
        $this->module_manager->register_module(new CodeSnippetsModule());
        $this->module_manager->register_module(new EmailEncoderModule());
        $this->module_manager->register_module(new GlobalLoginModule());
        $this->module_manager->register_module(new SmtpModule());
        $this->module_manager->register_module(new ProtectedFilesModule());
    }

    /* ======================================================
       Hooks initialisieren
       ====================================================== */
    protected function hooks(): void
    {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX Handler für Modul-Toggles
        add_action('wp_ajax_zweipro_toggle_module', [$this, 'ajax_toggle_module']);
    }

    public function init(): void
    {
        $this->module_manager->init_modules();
    }

    /* ======================================================
       Admin Menü
       ====================================================== */
    public function register_admin_menu(): void
{
    $parent_slug = 'zweipro-tools';

    // Haupteintrag
    add_menu_page(
        __('ZWEIPRO-Tools', 'zweipro-toolbox'),
        __('ZWEIPRO-Tools', 'zweipro-toolbox'),
        'edit_pages', // Editor & Admin
        $parent_slug,
        [$this, 'render_modules_overview'], // Dashboard = Modulverwaltung
        'dashicons-admin-tools',
        58
    );

    // Erster Unterpunkt: Module verwalten
    add_submenu_page(
        $parent_slug,
        __('Module verwalten', 'zweipro-toolbox'),
        __('Module verwalten', 'zweipro-toolbox'),
        'edit_pages',
        $parent_slug, // zeigt auf die gleiche Seite
        [$this, 'render_modules_overview']
    );

    // Jetzt alle anderen Module als Unterpunkte
    $this->module_manager->register_admin_pages($parent_slug);
}


   public function render_dashboard(): void
{
    // Direkt die Modulverwaltung laden
    $this->module_manager->render_admin_modules_page();
}

    public function render_modules_overview(): void
    {
        $this->module_manager->render_admin_modules_page();
    }


    /* ======================================================
       Admin CSS einbinden
       ====================================================== */
    public function enqueue_admin_assets($hook)
    {
        // Lade CSS NUR im ZWEIPRO Bereich
        if (strpos($hook, 'zweipro') === false) {
            return;
        }

        wp_enqueue_style(
            'zweipro_admin',
            plugin_dir_url(__FILE__) . '../assets/admin.css',
            [],
            time()
        );
    }


    /* ======================================================
       AJAX: Module toggeln
       ====================================================== */
    public function ajax_toggle_module()
    {
        if (empty($_POST['module'])) {
            wp_send_json_error('Missing module');
        }

        $module = sanitize_text_field($_POST['module']);
        $value  = ($_POST['value'] ?? '0') === '1';

        $modules = get_option('zweipro_toolbox_active_modules', []);
        $modules[$module] = $value;

        update_option('zweipro_toolbox_active_modules', $modules);

        wp_send_json_success();
    }
}