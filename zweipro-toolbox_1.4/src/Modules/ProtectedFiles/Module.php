<?php
namespace Zweipro\Toolbox\Modules\ProtectedFiles;

use Zweipro\Toolbox\Core\ModuleInterface;

class Module implements ModuleInterface
{
    const OPTION_KEY  = 'zweipro_toolbox_protected_files';
    const STORAGE_DIR = 'protectedfiles';

    public function get_id(): string
    {
        return 'protected_files';
    }

    public function get_title(): string
    {
        return __('Geschützte Dateien', 'zweipro-toolbox');
    }

    public function get_description(): string
    {
        return __('Lagert Dateien geschützt ab & erstellt sichere Download-Links.', 'zweipro-toolbox');
    }

    public function init(): void
    {
        // REST Meta immer registrieren
        add_action('init', [$this, 'register_rest_meta']);

        // Geschützte Datei ausliefern
        add_action('template_redirect', [$this, 'process_request']);

        // AJAX Upload
        add_action('wp_ajax_zweipro_upload_files', [$this, 'ajax_upload_files']);

        // Sicherheit: Direkte URLs überschreiben
        add_filter('wp_get_attachment_url', [$this, 'filter_protected_attachment_url'], 99, 2);



// ✅ Geschützte Bilder: keine direkten File-URLs über srcset leaken
add_filter('wp_calculate_image_srcset', [$this, 'disable_srcset_for_protected'], 10, 5);

// ✅ Geschützte Bilder: im Frontend Bild-URL auf Token-URL zwingen
add_filter('wp_get_attachment_image_src', [$this, 'force_secure_image_src'], 10, 4);

add_action('pre_get_posts', [$this, 'hide_protected_media_for_authors'], 20);


        if (is_admin()) {
            // Mediathek Listenansicht
            add_filter('manage_upload_columns', [$this, 'add_media_protected_column']);
            add_action('manage_media_custom_column', [$this, 'render_media_protected_column'], 10, 2);

            // Keine unnötigen Image-Sizes
            add_filter('intermediate_image_sizes_advanced', [$this, 'disable_image_sizes_for_protected'], 10, 3);

            // Attachment-Detail Felder
            add_filter('attachment_fields_to_edit', [$this, 'attachment_fields_protected'], 10, 2);

            // CSS für Badges
            add_action('admin_head-upload.php', [$this, 'output_media_badge_css']);
            add_action('admin_head-post.php', [$this, 'output_media_badge_css']);

            // Performantes Grid-Badge-Script
            // add_action('admin_print_scripts-upload.php', [$this, 'enqueue_media_grid_badge_script']);
            // add_action('admin_print_scripts-media-new.php', [$this, 'enqueue_media_grid_badge_script']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_media_grid_badge_script']);

            // PDFs auch in MetaBox "image_advanced" / Media-Modal sichtbar machen
            add_filter('ajax_query_attachments_args', [$this, 'allow_pdfs_in_media_modal'], 20, 1);


            // Löschung synchronisieren
            add_action('delete_attachment', [$this, 'on_media_delete']);

            // Cache invalidieren
            add_action('updated_post_meta', [$this, 'maybe_flush_cache_on_meta_update'], 10, 4);
        }
    }







private function get_blocked_tokens_for_current_user(): array
{
    $user  = wp_get_current_user();
    $roles = (array) $user->roles;

    $settings = get_option(self::OPTION_KEY, []);
    $files    = $settings['files'] ?? [];

    $blocked = [];
    foreach ($files as $f) {
        if (empty($f['token'])) continue;
        $ex = $f['exclude_roles'] ?? [];
        if (!is_array($ex) || empty($ex)) continue;

        if (array_intersect($roles, $ex)) {
            $blocked[] = (string) $f['token'];
        }
    }
    return array_values(array_unique($blocked));
}



    /* ====================== PERFORMANCE: GRID BADGE ====================== */

   public function enqueue_media_grid_badge_script(): void
{
    // Auf allen Admin-Seiten laufen lassen, sobald das Media-Modal verfügbar ist
// WICHTIG: In AJAX nichts tun → verhindert Fehler bei Uploads
    if (wp_doing_ajax()) {
        return;
    }

    // Nur fortfahren, wenn media-views geladen ist
    if (!wp_script_is('media-views', 'enqueued')) {
        return;
    }

    // Geschützte IDs aus Transient holen (mit Cache)
    $protected_ids = get_transient('zweipro_protected_media_ids');
    if ($protected_ids === false) {
        $protected_ids = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_zweipro_protected',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ]);
        $protected_ids = array_map('intval', $protected_ids);
        set_transient('zweipro_protected_media_ids', $protected_ids, 10 * MINUTE_IN_SECONDS);
    }

    // Variablen ins JS geben
    wp_add_inline_script(
        'media-views',
        'window.ZWEIPRO_PROTECTED_IDS = ' . wp_json_encode($protected_ids) . ';',
        'before'
    );

    // Hauptlogik – Styling jetzt aus CSS (keine Inline-Styles mehr!)
    wp_add_inline_script('media-views', '
        jQuery(function($) {
            if (typeof window.ZWEIPRO_PROTECTED_IDS === "undefined" || !window.ZWEIPRO_PROTECTED_IDS.length) {
                return;
            }
            const protectedSet = new Set(window.ZWEIPRO_PROTECTED_IDS);

            function markProtected() {
                $(".attachment").each(function() {
                    const $item = $(this);
                    const id = parseInt($item.data("id"), 10);
                    if (!id || !protectedSet.has(id) || $item.find(".zweipro-prot-flag").length) {
                        return;
                    }
                    $item.css("position", "relative");
                    $item.append("<div class=\"zweipro-prot-flag\">PROT</div>");
                });
            }

            markProtected();
            $(document).on("ajaxComplete", markProtected);
        });
    ', 'after');
}

// Cache leeren bei Änderungen
private function flush_protected_media_cache(): void
{
    delete_transient('zweipro_protected_media_ids');
}

public function maybe_flush_cache_on_meta_update(int $meta_id, int $post_id, string $meta_key, $meta_value): void
{
    if ($meta_key === '_zweipro_protected' && get_post_type($post_id) === 'attachment') {
        $this->flush_protected_media_cache();
    }
}



    /* ====================== Rechte fur Authoren ====================== */



public function hide_protected_media_for_authors(\WP_Query $query): void
{
    if (!is_admin() || !$query->is_main_query()) return;

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'upload') return;

    // Admin/Redakteur nicht einschränken
    if (current_user_can('manage_options') || current_user_can('editor')) return;

    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    if (empty($roles)) return;

    $settings = get_option(self::OPTION_KEY, []);
    $files    = $settings['files'] ?? [];
    if (empty($files)) return;

    // Tokens sammeln, die für die Rolle ausgeschlossen sind
    $blocked_tokens = [];
    foreach ($files as $f) {
        if (empty($f['token'])) continue;
        $ex = $f['exclude_roles'] ?? [];
        if (!is_array($ex) || empty($ex)) continue;
        if (array_intersect($roles, $ex)) {
            $blocked_tokens[] = (string) $f['token'];
        }
    }
    $blocked_tokens = array_values(array_unique($blocked_tokens));
    if (empty($blocked_tokens)) return;

    // Mediathek: alles anzeigen, außer Attachments mit blocked token
    $query->set('meta_query', [
        'relation' => 'OR',
        [
            'key'     => '_zweipro_protected_token',
            'compare' => 'NOT EXISTS',
        ],
        [
            'key'     => '_zweipro_protected_token',
            'value'   => $blocked_tokens,
            'compare' => 'NOT IN',
        ],
    ]);
}



    /* ====================== SICHERHEIT: URL ÜBERSCHREIBEN ====================== */

    public function filter_protected_attachment_url(string $url, int $post_id): string
{
    if (!get_post_meta($post_id, '_zweipro_protected', true)) {
        return $url;
    }



    // WICHTIG: Im Admin und bei AJAX-Anfragen die originale URL zurückgeben
    // → damit Thumbnails und Media-Modal funktionieren
    if (is_admin() || wp_doing_ajax()) {
        return $url;
    }

    // Nur auf dem Frontend die sichere Token-URL verwenden
    $secure_url = get_post_meta($post_id, '_zweipro_protected_secure_url', true);
    return $secure_url ?: $url;
}




public function disable_srcset_for_protected($sources, $size_array, $image_src, $image_meta, $attachment_id)
{
    // Admin/Modal/AJAX/REST nicht beeinflussen – sonst verschwinden Thumbnails im Media-Modal
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $sources;
    }

    if (get_post_meta((int) $attachment_id, '_zweipro_protected', true)) {
        return false; // Frontend: kein srcset leaken
    }

    return $sources;
}

public function force_secure_image_src($image, $attachment_id, $size, $icon)
{
    if (!$image || !get_post_meta((int) $attachment_id, '_zweipro_protected', true)) {
        return $image;
    }

    // Admin/AJAX/REST nicht ändern -> Mediathek bleibt ok
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $image;
    }

    $secure_url = (string) get_post_meta((int) $attachment_id, '_zweipro_protected_secure_url', true);
    if ($secure_url !== '') {
        $image[0] = $secure_url;
    }

    return $image;
}





    /* ====================== REST META ====================== */

    public function register_rest_meta(): void
    {
        register_post_meta('attachment', '_zweipro_protected', [
            'show_in_rest' => true,
            'type'         => 'boolean',
            'single'       => true,
            'auth_callback' => fn() => current_user_can('upload_files'),
        ]);

        register_post_meta('attachment', '_zweipro_protected_token', [
            'show_in_rest' => true,
            'type'         => 'string',
            'single'       => true,
            'auth_callback' => fn() => current_user_can('upload_files'),
        ]);

        register_post_meta('attachment', '_zweipro_protected_secure_url', [
            'show_in_rest' => true,
            'type'         => 'string',
            'single'       => true,
            'auth_callback' => fn() => current_user_can('upload_files'),
        ]);
    }

    /* ====================== DOWNLOAD HANDLER ====================== */

    public function process_request(): void
    {
        if (empty($_GET['zweipro_protected']) || empty($_GET['file'])) {
            return;
        }

        $path = $this->get_storage_path();
        if (!file_exists($path)) {
            wp_die('Protected directory missing.', 404);
        }

        require __DIR__ . '/handler.php';
        exit;
    }

    private function get_storage_path(): string
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . self::STORAGE_DIR;
    }

    private function ensure_storage_dir(): void
    {
        $path = $this->get_storage_path();
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }

        $htaccess_path = $path . '/.htaccess';
        $settings      = get_option(self::OPTION_KEY, []);
        $redirect_id   = $settings['redirect_page_id'] ?? 0;
        $redirect_url  = $redirect_id ? get_permalink($redirect_id) : home_url('/');

        $new_htaccess = sprintf(
            "<IfModule mod_rewrite.c>\nRewriteEngine On\n\nRewriteCond %%{HTTP_COOKIE} wordpress_logged_in_ [NC]\nRewriteRule .* - [L]\n\nRewriteCond %%{HTTP_COOKIE} wp-postpass_ [NC]\nRewriteRule .* - [L]\n\nRewriteRule ^.*$ %s [R=302,L]\n</IfModule>\n",
            esc_url($redirect_url)
        );

        if (!file_exists($htaccess_path) || file_get_contents($htaccess_path) !== $new_htaccess) {
            @file_put_contents($htaccess_path, $new_htaccess);
        }

        if (!file_exists($path . '/index.php')) {
            @file_put_contents($path . '/index.php', "<?php // Silence is golden\n");
        }
    }

    /* ====================== ADMIN PAGE ====================== */

    public function register_admin_page(string $parent_slug): void
    {
        add_submenu_page(
            $parent_slug,
            $this->get_title(),
            $this->get_title(),
            'edit_pages',
            'zweipro-toolbox-protected-files',
            [$this, 'render_admin_page']
        );

        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets'], 99);
    }

    public function register_settings(): void
    {
        register_setting('zweipro_toolbox_protected_files_group', self::OPTION_KEY);
    }

    public function enqueue_admin_assets(string $hook): void
{
    $allowed = [
        'toplevel_page_zweipro-toolbox',
        'zweipro-toolbox_page_zweipro-toolbox-protected-files'
    ];
    if (!in_array($hook, $allowed, true)) {
        return;
    }

    // admin.css liegt im gleichen Ordner wie Module.php
    $css_rel = 'admin.css';

    $css_path = plugin_dir_path(__FILE__) . $css_rel;
    $css_url  = plugins_url($css_rel, __FILE__);
    $ver      = file_exists($css_path) ? filemtime($css_path) : time();

    wp_enqueue_style(
        'zweipro_protected_files_admin',
        $css_url,
        [],
        $ver
    );
}


    public function output_media_badge_css(): void
    {
        echo '<style>
            .zweipro-prot-badge { display:inline-block; padding:2px 6px; font-size:11px; font-weight:600; color:#fff; background:#d32f2f; border-radius:3px; text-transform:uppercase; }
            .zweipro-prot-flag { position:absolute; top:15%; left:5px; background:black; color:#fff; padding:2px 6px; font-size:10px; font-weight:600; border-radius:0 3px 3px 0; z-index:9999; pointer-events:none; }
        </style>';
    }

    /* ====================== MEDIATHEK ====================== */

    public function add_media_protected_column(array $columns): array
    {
        $columns['zweipro_protected'] = __('Geschützt', 'zweipro-toolbox');
        return $columns;
    }

    public function render_media_protected_column(string $column_name, int $post_id): void
    {
        if ($column_name !== 'zweipro_protected') {
            return;
        }
        echo get_post_meta($post_id, '_zweipro_protected', true)
            ? '<span class="zweipro-prot-badge">PROT</span>'
            : '—';
    }

    public function disable_image_sizes_for_protected(array $sizes, $metadata, int $attachment_id): array
{
    if (!get_post_meta($attachment_id, '_zweipro_protected', true)) {
        return $sizes;
    }

    // Nur bei Nicht-Bildern (z. B. PDF) alle Sizes deaktivieren
    $mime = get_post_mime_type($attachment_id);
    if ($mime && strpos($mime, 'image/') !== 0) {
        return [];
    }

    // Bei geschützten Bildern normale Sizes generieren (für Thumbnails im Media-Modal wichtig!)
    return $sizes;
}
    
    public function allow_pdfs_in_media_modal(array $args): array
    {
        // ✅ Nur ausblenden, wenn aktuelle Rolle in exclude_roles steht (Token basiert)
if (!current_user_can('manage_options') && !current_user_can('editor')) {
    $blocked_tokens = $this->get_blocked_tokens_for_current_user();

    if (!empty($blocked_tokens)) {
        $args['meta_query'] = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'relation' => 'OR',
            [
                'key'     => '_zweipro_protected_token',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_zweipro_protected_token',
                'value'   => $blocked_tokens,
                'compare' => 'NOT IN',
            ],
        ];
    }
}






// Viele Felder (z.B. MetaBox image_advanced) filtern hart auf "image" → PDFs würden verschwinden.
        if (!isset($args['post_mime_type'])) {
            return $args;
        }

        $mt = $args['post_mime_type'];

        // String-Fall
        if (is_string($mt) && $mt === 'image') {
            $args['post_mime_type'] = ['image', 'application/pdf'];
            return $args;
        }

        // Array-Fall
        if (is_array($mt) && in_array('image', $mt, true) && !in_array('application/pdf', $mt, true)) {
            $mt[] = 'application/pdf';
            $args['post_mime_type'] = $mt;
        }

        return $args;
    }

public function attachment_fields_protected(array $fields, \WP_Post $post): array
    {
        $is_protected = get_post_meta($post->ID, '_zweipro_protected', true);
        $secure_url   = get_post_meta($post->ID, '_zweipro_protected_secure_url', true);

        if (!$is_protected || !$secure_url) {
            return $fields;
        }

        if (isset($fields['url'])) {
            $fields['url']['value'] = $secure_url;
        }

        $fields['zweipro_secure_url'] = [
            'label' => __('Geschützter Link', 'zweipro-toolbox'),
            'input' => 'html',
            'html'  => '<input type="text" class="widefat" readonly value="' . esc_attr($secure_url) . '" />'
                     . '<p class="description">' . __('Dieser Link nutzt den geschützten Download-Handler (Token-URL).', 'zweipro-toolbox') . '</p>',
        ];

        return $fields;
    }

    /* ====================== UPLOAD & ATTACHMENT ====================== */

    public function ajax_upload_files(): void
    {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        if (empty($_POST['zweipro_upload_nonce']) || !wp_verify_nonce($_POST['zweipro_upload_nonce'], 'zweipro_upload_files')) {
            wp_send_json_error(['message' => 'Ungültige Anfrage (Nonce).']);
        }

        if (empty($_FILES['secure_file']['name'][0])) {
            wp_send_json_error(['message' => 'Keine Dateien übergeben.']);
        }

        $this->ensure_storage_dir();
        $path     = $this->get_storage_path();
        $settings = get_option(self::OPTION_KEY, []);
        $settings['files'] = $settings['files'] ?? [];

        $roles_excluded = isset($_POST['secure_roles_exclude'])
            ? array_map('sanitize_text_field', (array) $_POST['secure_roles_exclude'])
            : [];

        foreach ($_FILES['secure_file']['name'] as $index => $name) {
            if (empty($_FILES['secure_file']['tmp_name'][$index])) {
                continue;
            }

            $token    = bin2hex(random_bytes(16));
            $filename = basename($name);
            $new_name = $token . '-' . $filename;
            $fullpath = $path . '/' . $new_name;

            if (!move_uploaded_file($_FILES['secure_file']['tmp_name'][$index], $fullpath)) {
                continue;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $thumb_path = $path . '/' . $token . '.png';
                $this->generate_pdf_thumbnail($fullpath, $thumb_path);
            }

            array_unshift($settings['files'], [
                'token'         => $token,
                'file'          => $filename,
                'exclude_roles' => $roles_excluded,
                'uploaded_at'   => current_time('mysql'),
            ]);

            $this->create_media_attachment_for_protected_file($fullpath, $filename, $token);
        }

        update_option(self::OPTION_KEY, $settings);
        $this->flush_protected_media_cache();

        wp_send_json_success(['message' => 'Upload erfolgreich.']);
    }






   private function create_media_attachment_for_protected_file(string $fullpath, string $filename, string $token): void
{
    $relative_path = self::STORAGE_DIR . '/' . $token . '-' . $filename;

    $checked = wp_check_filetype_and_ext($fullpath, $filename);
    $mime    = $checked['type'] ?: 'application/octet-stream';

    $upload_dir = wp_upload_dir();
    $file_url   = trailingslashit($upload_dir['baseurl']) . $relative_path;

    $attachment = [
        'post_mime_type' => $mime,
        'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'guid'           => $file_url,
    ];

    // wichtig: absoluter Pfad
    $attach_id = wp_insert_attachment($attachment, $fullpath);
    if (is_wp_error($attach_id) || !$attach_id) {
        return;
    }

    // wichtig: _wp_attached_file setzen
    update_attached_file($attach_id, $relative_path);

    $secure_url = add_query_arg(['zweipro_protected' => '1', 'file' => $token], home_url('/'));

    update_post_meta($attach_id, '_zweipro_protected', 1);
    update_post_meta($attach_id, '_zweipro_protected_token', $token);
    update_post_meta($attach_id, '_zweipro_protected_secure_url', $secure_url);

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata($attach_id, $fullpath);
    if (!empty($metadata) && !is_wp_error($metadata)) {
        wp_update_attachment_metadata($attach_id, $metadata);
    }
}



    /* ====================== LÖSCHUNG ====================== */

    public function on_media_delete(int $attachment_id): void
    {
        $token = get_post_meta($attachment_id, '_zweipro_protected_token', true);
        if (!$token) {
            return;
        }

        $settings = get_option(self::OPTION_KEY, []);
        $path     = $this->get_storage_path();

        foreach ($settings['files'] as $index => $file) {
            if ($file['token'] !== $token) {
                continue;
            }

            $stored_file = $path . '/' . $token . '-' . $file['file'];
            if (file_exists($stored_file)) {
                @unlink($stored_file);
            }

            $thumb = $path . '/' . $token . '.png';
            if (file_exists($thumb)) {
                @unlink($thumb);
            }

            unset($settings['files'][$index]);
            $settings['files'] = array_values($settings['files']);
            update_option(self::OPTION_KEY, $settings);
            $this->flush_protected_media_cache();
            break;
        }
    }


    /* ====================== THUMBNAIL ====================== */



    private function generate_pdf_thumbnail(string $source, string $target, int $width = 300): bool
    {
        if (!extension_loaded('imagick')) {
            return false;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($source . '[0]');
            $imagick->setImageFormat('png');
            $imagick->scaleImage($width, 0);
            $imagick->writeImage($target);
            $imagick->clear();
            $imagick->destroy();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function get_file_thumbnail_html(string $filepath, string $filename): string
    {
        $upload_dir = wp_upload_dir();
        $base_url   = trailingslashit($upload_dir['baseurl']) . self::STORAGE_DIR;
        $plugin_url = plugin_dir_url(__FILE__);
        $basename   = basename($filepath);
        $token      = explode('-', $basename)[0] ?? '';
        $ext        = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $img_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (in_array($ext, $img_exts, true)) {
            return '<img src="' . esc_url($base_url . '/' . $basename) . '" style="width:50px;border-radius:4px;border:1px solid #ddd;">';
        }

        if ($ext === 'pdf') {
            $thumb_url = $base_url . '/' . $token . '.png';
            if (file_exists(dirname($filepath) . '/' . $token . '.png')) {
                return '<img src="' . esc_url($thumb_url) . '" style="width:50px;border-radius:4px;border:1px solid #ddd;">';
            }
            return '<img src="' . esc_url($plugin_url . 'assets/pdf-icon.png') . '" style="width:50px;">';
        }

        return '<img src="' . esc_url($plugin_url . 'assets/file-icon.png') . '" style="width:50px;">';
    }


    /* ====================== FIND USING LINKS ====================== */


    private function find_posts_using_link(string $token, string $filename = ''): array
{
    global $wpdb;

    $token = trim($token);
    if ($token === '') {
        return [];
    }

    // Nur öffentliche Post Types (Pages/Posts/CPTs)
    $public_types = get_post_types(['public' => true], 'names');
    unset($public_types['attachment']);

    if (empty($public_types)) {
        return [];
    }

    $post_types_sql = "'" . implode("','", array_map('esc_sql', $public_types)) . "'";

    // Attachment-ID zur Datei über Token holen (wichtig für ACF/MetaBox, die nur IDs speichern)
    $attachment_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value = %s
             LIMIT 1",
            '_zweipro_protected_token',
            $token
        )
    );

    $conditions = [];
    $params     = [];

    // 1) Token-Link im Content / Builder-JSON (z.B. file=<token>)
    $conditions[] = "p.post_content LIKE %s";
    $params[]     = '%' . $wpdb->esc_like('file=' . $token) . '%';

    // 2) Token irgendwo in Meta (z.B. wenn secure_url gespeichert wurde)
    $conditions[] = "pm.meta_value LIKE %s";
    $params[]     = '%' . $wpdb->esc_like($token) . '%';

    // 3) Optional: Dateiname im Content/Meta (falls irgendwo gespeichert)
    if ($filename !== '') {
        $conditions[] = "p.post_content LIKE %s";
        $params[]     = '%' . $wpdb->esc_like($filename) . '%';

        $conditions[] = "pm.meta_value LIKE %s";
        $params[]     = '%' . $wpdb->esc_like($filename) . '%';
    }

    // 4) WICHTIG: Attachment-ID-Muster (ACF/MetaBox)
    if ($attachment_id > 0) {
        // wp-image-123 im Content (Editor)
        $conditions[] = "p.post_content LIKE %s";
        $params[]     = '%' . $wpdb->esc_like('wp-image-' . $attachment_id) . '%';

        // Meta exakt "123"
        $conditions[] = "pm.meta_value = %s";
        $params[]     = (string) $attachment_id;

        // Meta serialisiert: i:123;
        $conditions[] = "pm.meta_value LIKE %s";
        $params[]     = '%i:' . $attachment_id . ';%';

        // Meta/JSON/serialisiert als "123"
        $conditions[] = "pm.meta_value LIKE %s";
        $params[]     = '%"' . $attachment_id . '"%';
    }

    $sql = "
        SELECT DISTINCT p.ID, p.post_title, p.post_type
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
        WHERE p.post_status = 'publish'
          AND p.post_type IN ($post_types_sql)
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY p.post_type, p.post_title
    ";

    $prepared = $wpdb->prepare($sql, $params);
    return $wpdb->get_results($prepared);
}

    /* ====================== ADMIN PAGE (DEIN ORIGINAL) ====================== */

    public function render_admin_page(): void
    {
        $settings = get_option(self::OPTION_KEY, []);
        $this->ensure_storage_dir();
        $path = $this->get_storage_path();
        if (empty($settings['files']) || !is_array($settings['files'])) {
            $settings['files'] = [];
        }



if (
    !empty($_POST['zweipro_pf_action']) &&
    $_POST['zweipro_pf_action'] === 'bulk_delete' &&
    !empty($_POST['zweipro_bulk_tokens']) &&
    check_admin_referer('zweipro_bulk_delete')
) {
    foreach ((array) $_POST['zweipro_bulk_tokens'] as $token) {
        $token = sanitize_text_field($token);

        foreach ($settings['files'] as $index => $file) {
            if ($file['token'] !== $token) {
                continue;
            }

            // Datei löschen
            $stored_file = $path . '/' . $file['token'] . '-' . $file['file'];
            if (file_exists($stored_file)) {
                @unlink($stored_file);
            }

            // Attachment über Token-Meta finden (robust!)
            $ids = get_posts([
                'post_type'      => 'attachment',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'   => '_zweipro_protected_token',
                        'value' => $file['token'],
                    ],
                ],
            ]);

            if (!empty($ids[0])) {
                wp_delete_attachment((int)$ids[0], true);
            }

            unset($settings['files'][$index]);
            break;
        }
    }

    $settings['files'] = array_values($settings['files']);
    update_option(self::OPTION_KEY, $settings);
    $this->flush_protected_media_cache();

    echo '<div class="updated"><p>Ausgewählte Dateien gelöscht ✔</p></div>';
}






        if (!empty($_POST['zweipro_pf_action']) && $_POST['zweipro_pf_action'] === 'save_settings') {
            check_admin_referer('zweipro_pf_save_settings');
            $settings['redirect_page_id'] = isset($_POST['redirect_page_id']) ? intval($_POST['redirect_page_id']) : 0;
            update_option(self::OPTION_KEY, $settings);
            echo '<div class="updated"><p>Einstellungen gespeichert ✔</p></div>';
        }

        if (!current_user_can('edit_pages')) {
            wp_die('Keine Berechtigung.', 'Fehler', 403);
        }

        if (!empty($_GET['zweipro_delete']) && !empty($_GET['_wpnonce'])) {
            $token = sanitize_text_field($_GET['zweipro_delete']);
            if (wp_verify_nonce($_GET['_wpnonce'], 'zweipro_delete_' . $token)) {
                foreach ($settings['files'] as $index => $file) {
                    if ($file['token'] === $token) {
                        $stored_file = $path . '/' . $file['token'] . '-' . $file['file'];
                        if (file_exists($stored_file)) {
                            @unlink($stored_file);
                        }
                        // ✅ Attachment sicher über Token-Meta finden (funktioniert für Bilder + PDFs)
$attachment_id = 0;

// 1) Primär: Meta-Lookup über _zweipro_protected_token
$ids = get_posts([
    'post_type'      => 'attachment',
    'posts_per_page' => 1,
    'fields'         => 'ids',
    'meta_query'     => [
        [
            'key'   => '_zweipro_protected_token',
            'value' => $file['token'],
        ],
    ],
]);








if (!empty($ids[0])) {
    $attachment_id = (int) $ids[0];
}

// 2) Fallback: alter URL-Weg (optional)
if (!$attachment_id) {
    $attachment_url = wp_upload_dir()['baseurl'] . '/protectedfiles/' . $file['token'] . '-' . $file['file'];
    $attachment_id  = (int) attachment_url_to_postid($attachment_url);
}

// 3) Löschen (force)
if ($attachment_id > 0) {
    wp_delete_attachment($attachment_id, true);
}
                        unset($settings['files'][$index]);
                        $settings['files'] = array_values($settings['files']);
                        update_option(self::OPTION_KEY, $settings);
                        echo '<div class="updated"><p>Datei gelöscht ✔</p></div>';
                        break;
                    }
                }
            }
        }

        $plugin_url = plugin_dir_url(__FILE__);
        $copy_icon = $plugin_url . 'assets/copy.png';
        ?>
        <div class="wrap">
            <h1><?= esc_html($this->get_title()) ?></h1>

            <div class="zweipro-card">
                <h2>Datei hochladen</h2>
                <form id="zweipro-upload-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('zweipro_upload_files', 'zweipro_upload_nonce'); ?>
                    <div class="zweipro-upload-grid">
                        <div id="zweipro-dropzone" class="zweipro-dropzone">
                            <p>Dateien hier ablegen oder klicken</p>
                            <input type="file" id="zweipro-file-input" name="secure_file[]" multiple hidden>
                        </div>
                        <div class="zweipro-role-box">
                            <strong>Diese Datei(en) ausblenden für Rollen:</strong><br>
                            <?php
                            foreach (wp_roles()->roles as $role_key => $role_obj) {
    if ($role_key === 'administrator') continue;
    if ($role_key === 'editor') continue; // ✅ Redakteur nicht auswählbar

    $label = function_exists('translate_user_role')
        ? translate_user_role($role_obj['name'])
        : $role_obj['name'];

    echo '<label style="display:block;margin:3px 0;">';
    echo '<input type="checkbox" name="secure_roles_exclude[]" value="' . esc_attr($role_key) . '"> ';
    echo esc_html($label);
    echo '</label>';
}
                            ?>
                        </div>
                    </div>
                    <button type="submit" class="zweipro-btn-main">Hochladen</button>
                    <div id="zweipro-upload-list"></div>
                </form>
            </div>

            <div class="zweipro-card">
                <h2>Einstellungen</h2>
                <p style="max-width:800px;">
                    Geschützte Dokumente sind nur für eingeloggte Benutzer sichtbar
                    oder für Nutzer, die über einen Link von einer passwortgeschützten Seite kommen.
                    Ein direkter Zugriff über die Adresszeile ist nicht möglich.
                    Falls jemand ohne Berechtigung auf eine Datei zugreifen möchte,
                    wird stattdessen die folgende Seite angezeigt:
                </p>
                <form method="post">
                    <?php wp_nonce_field('zweipro_pf_save_settings'); ?>
                    <input type="hidden" name="zweipro_pf_action" value="save_settings">
                    <table class="form-table">
                        <tr>
                            <th>Seite bei fehlenden Rechten</th>
                            <td>
                                <?php
                                $redirect_page_id = $settings['redirect_page_id'] ?? 0;
                                wp_dropdown_pages([
                                    'name'              => 'redirect_page_id',
                                    'show_option_none'  => 'Standard-Fehlermeldung anzeigen',
                                    'option_none_value' => 0,
                                    'selected'          => $redirect_page_id,
                                ]);
                                ?>
                                <p class="description">
                                    Wenn ein Benutzer keine Berechtigung hat, wird er hierhin weitergeleitet.
                                </p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="zweipro-btn-main">Einstellungen speichern</button>
                </form>
            </div>

            <div class="zweipro-card">
                <h2>Gespeicherte Dateien</h2>
                <?php if (!empty($settings['files'])): ?>
                    
<form method="post" style="margin-bottom:10px;">
    <?php wp_nonce_field('zweipro_bulk_delete'); ?>
    <input type="hidden" name="zweipro_pf_action" value="bulk_delete">

    <button type="submit"
        id="zweipro-bulk-delete-btn"
        class="zweipro-btn zweipro-btn-delete"
        style="opacity:0.25;pointer-events:none;"
        onclick="return confirm('Ausgewählte Dateien wirklich löschen?');">
    <img src="<?= esc_url($plugin_url . 'assets/trash.png'); ?>" alt="">
    Ausgewählte löschen
</button>

    <table class="widefat" style="margin-top:12px;">

        <thead>
<tr>
    <th class="check-column zweipro-check-col" style="padding: 20px 0 22px 7px">
    <input type="checkbox" id="zweipro-bulk-select-all">
</th>
    <th>Vorschau</th>
    <th>Dateiname</th>
    <th>Sicherer Link</th>
    <th>Aktionen</th>
	<th>Verwendet in</th>
    <th>Rollen</th>
    <th>Upl. Date</th>
</tr>
</thead>

        <tbody>
        <?php foreach ($settings['files'] as $file): ?>
            <?php
            $token = $file['token'];
            $filename = $file['file'];
            $fullpath = $path . '/' . $token . '-' . $filename;
            $secure_link = site_url('/?zweipro_protected=1&file=' . $token);
            $delete_url = wp_nonce_url(
                add_query_arg(['page' => 'zweipro-toolbox-protected-files', 'zweipro_delete' => $token], admin_url('admin.php')),
                'zweipro_delete_' . $token
            );
            $roles_excluded = $file['exclude_roles'] ?? [];
            ?>
            <tr>
                <td class="check-column" style="width:10px;max-width:10px;padding:0px 15px 15px 15px;vertical-align:middle;">
    <input
        type="checkbox"
        class="zweipro-bulk-item"
        name="zweipro_bulk_tokens[]"
        value="<?= esc_attr($token); ?>"
    >
</td>

                <td><?= $this->get_file_thumbnail_html($fullpath, $filename); ?></td>
                <td><?= esc_html($filename); ?></td>
                <td><code><?= esc_html($secure_link); ?></code></td>



<td>
                    <button type="button" class="zweipro-btn zweipro-btn-copy zweipro-copy-link" data-link="<?= esc_attr($secure_link); ?>">
                        <img src="<?= esc_url($plugin_url . 'assets/copy.png'); ?>" alt=""> Kopieren
                    </button>
                    <a class="zweipro-btn zweipro-btn-delete zweipro-delete-btn" href="<?= esc_url($delete_url); ?>" onclick="return confirm('Datei wirklich löschen?');">
                        <img src="<?= esc_url($plugin_url . 'assets/trash.png'); ?>" alt=""> Löschen
                    </a>
                </td>


                <td>
                    <?php
                    $used_in = $this->find_posts_using_link($token, $filename);
                    if (empty($used_in)) {
                        echo '<em>Keine Verwendung</em>';
                    } else {
                        echo '<ul class="zweipro-used-list">';
                        foreach ($used_in as $p) {
    $edit_link = get_edit_post_link($p->ID);
    if (!$edit_link) {
        continue;
    }

    echo '<li><span class="zweipro-used-page">';
    echo '<a href="' . esc_url($edit_link) . '">' . esc_html($p->post_title) . '</a> ';
    echo '<a href="' . esc_url($edit_link) . '" class="zweipro-edit-link">'
        . '<img src="' . esc_url($plugin_url . 'assets/edit.png') . '" class="zweipro-edit-icon" alt="Bearbeiten">'
        . '</a>';
    echo '</span></li>';
}                        echo '</ul>';
                    }
                    ?>
                </td>

                <td>
                    <?php
                    if (empty($roles_excluded)) {
                        echo '<em>Alle Rollen erlaubt</em>';
                    } else {
                        echo '<strong>Ausgeschlossen:</strong><br>';
                        foreach ($roles_excluded as $role_key) {
                            if (isset(wp_roles()->roles[$role_key])) {
                                echo esc_html(wp_roles()->roles[$role_key]['name']) . '<br>';
                            }
                        }
                    }
                    ?>
                </td>

                

                <td>
                    <?php echo !empty($file['uploaded_at']) ? date_i18n('d.m.Y H:i', strtotime($file['uploaded_at'])) : '<em>nicht gespeichert</em>'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</form>                <?php else: ?>
                    <p>Noch keine Dateien hochgeladen.</p>
                <?php endif; ?>
            </div>
        </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('zweipro-bulk-delete-btn');
    if (!btn) return;

    const checkboxes = document.querySelectorAll('input[name="zweipro_bulk_tokens[]"]');

    function updateState() {
        const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
        btn.style.opacity = anyChecked ? '1' : '0.25';
        btn.style.pointerEvents = anyChecked ? 'auto' : 'none';
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateState));
});
</script>

        <script>
        document.addEventListener("click", function(e) {
            const btn = e.target.closest(".zweipro-copy-link");
            if (!btn) return;
            e.preventDefault();
            const link = btn.dataset.link;
            if (!link) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(() => showCopied(btn)).catch(() => fallbackCopy(btn, link));
            } else {
                fallbackCopy(btn, link);
            }
        });
        function fallbackCopy(btn, text) {
            const temp = document.createElement("input");
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand("copy");
            document.body.removeChild(temp);
            showCopied(btn);
        }
        function showCopied(btn) {
            const original = btn.innerHTML;
            btn.innerHTML = "✔ Kopiert";
            btn.classList.add("zweipro-btn-success");
            setTimeout(() => {
                btn.innerHTML = original;
                btn.classList.remove("zweipro-btn-success");
            }, 1200);
        }
        </script>

        <script>
        document.addEventListener("DOMContentLoaded", function () {
            const form = document.getElementById("zweipro-upload-form");
            const dropzone = document.getElementById("zweipro-dropzone");
            const fileInput = document.getElementById("zweipro-file-input");
            const uploadList = document.getElementById("zweipro-upload-list");
            const ajaxUrl = "<?= admin_url('admin-ajax.php'); ?>";
            if (!form || !dropzone || !fileInput || !uploadList) return;

            ["dragenter", "dragover", "dragleave", "drop"].forEach(eventName => {
                dropzone.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); });
            });
            dropzone.addEventListener("dragover", () => dropzone.classList.add("is-dragover"));
            dropzone.addEventListener("dragleave", () => dropzone.classList.remove("is-dragover"));
            dropzone.addEventListener("drop", e => {
                dropzone.classList.remove("is-dragover");
                const files = e.dataTransfer.files;
                if (files.length) {
                    const dt = new DataTransfer();
                    for (let i = 0; i < files.length; i++) dt.items.add(files[i]);
                    fileInput.files = dt.files;
                    uploadList.innerHTML = `<strong>${files.length} Datei(en) bereit zum Hochladen</strong>`;
                }
            });
            dropzone.addEventListener("click", () => fileInput.click());
            fileInput.addEventListener("change", () => {
                uploadList.innerHTML = fileInput.files.length ? `<strong>${fileInput.files.length} Datei(en) ausgewählt</strong>` : "";
            });
            form.addEventListener("submit", e => {
                e.preventDefault();
                if (!fileInput.files.length) return alert("Bitte mindestens eine Datei auswählen.");
                uploadList.innerHTML = "<em>Upload läuft...</em>";
                const formData = new FormData();
                for (let i = 0; i < fileInput.files.length; i++) {
                    formData.append("secure_file[]", fileInput.files[i]);
                }
                document.querySelectorAll('input[name="secure_roles_exclude[]"]:checked').forEach(cb => {
                    formData.append("secure_roles_exclude[]", cb.value);
                });
                formData.append("action", "zweipro_upload_files");
                const nonceField = document.querySelector('input[name="zweipro_upload_nonce"]');
                if (nonceField) formData.append("zweipro_upload_nonce", nonceField.value);

                fetch(ajaxUrl, { method: "POST", body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        uploadList.innerHTML = '<span style="color:green;">Upload erfolgreich ✔</span>';
                        setTimeout(() => location.reload(), 800);
                    } else {
                        uploadList.innerHTML = '<span style="color:red;">' + (data.data?.message || "Fehler") + '</span>';
                    }
                })
                .catch(() => uploadList.innerHTML = '<span style="color:red;">Ajax-Fehler.</span>');
            });
        });
        </script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('zweipro-bulk-delete-btn');
    const selectAll = document.getElementById('zweipro-bulk-select-all');

    function getItems() {
        return Array.from(document.querySelectorAll('input.zweipro-bulk-item'));
    }

    function updateButton() {
        const anyChecked = getItems().some(cb => cb.checked);
        btn.style.opacity = anyChecked ? '1' : '0.25';
        btn.style.pointerEvents = anyChecked ? 'auto' : 'none';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            getItems().forEach(cb => cb.checked = selectAll.checked);
            updateButton();
        });
    }

    document.addEventListener('change', function (e) {
        if (!e.target.matches('input.zweipro-bulk-item')) return;

        if (selectAll) {
            const items = getItems();
            const checkedCount = items.filter(cb => cb.checked).length;
            selectAll.checked = checkedCount === items.length && items.length > 0;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < items.length;
        }

        updateButton();
    });

    updateButton();
});
</script>


        <?php
    }
}