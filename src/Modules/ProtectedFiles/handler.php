<?php
/**
 * Protected Files Download Handler
 * Aufruf: ?zweipro_protected=1&file=TOKEN
 */

use Zweipro\Toolbox\Modules\ProtectedFiles\Module;

// ------------------------------------------------------------------
// 1. Security Headers & frühes WordPress-Laden
// ------------------------------------------------------------------
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!defined('ABSPATH')) {
    // Pfad anpassen je nach deiner Plugin-Struktur
    // Von /modules/ProtectedFiles/handler.php aus → 4 Ebenen hoch zu wp-load.php
    require_once dirname(__DIR__, 4) . '/wp-load.php';
}

// ------------------------------------------------------------------
// 2. Parameter validieren
// ------------------------------------------------------------------
if (empty($_GET['zweipro_protected']) || empty($_GET['file'])) {
    status_header(400);
    exit('Ungültige Anfrage.');
}

$token = sanitize_text_field($_GET['file']);

// Token-Länge prüfen (wir nutzen bin2hex(random_bytes(16)) → 32 Zeichen)
if (strlen($token) !== 32) {
    status_header(400);
    exit('Ungültiger Token.');
}

// ------------------------------------------------------------------
// 3. Einstellungen laden & Datei-Eintrag finden
// ------------------------------------------------------------------
$settings = get_option(Module::OPTION_KEY, []);
if (empty($settings['files']) || !is_array($settings['files'])) {
    status_header(404);
    exit('Keine geschützten Dateien konfiguriert.');
}

$file_entry = null;
foreach ($settings['files'] as $entry) {
    if ($entry['token'] === $token) {
        $file_entry = $entry;
        break;
    }
}

if (!$file_entry) {
    status_header(404);
    exit('Datei nicht gefunden.');
}

$original_filename = $file_entry['file'];
$excluded_roles    = $file_entry['exclude_roles'] ?? [];

// ------------------------------------------------------------------
// 4. Zugriffsprüfung
// ------------------------------------------------------------------
$access_granted = false;

// 4a) Eingeloggter Benutzer?
if (is_user_logged_in()) {
    $access_granted = true;

    // 4b) Rollen-Ausschluss prüfen (nur bei eingeloggten Usern relevant)
    if (!empty($excluded_roles)) {
        $user = wp_get_current_user();
        foreach ($user->roles as $user_role) {
            if (in_array($user_role, $excluded_roles, true)) {
                $access_granted = false;
                break;
            }
        }
    }
}

// 4c) Alternativ: Passwort-geschützte Seite – aber NUR wenn das Cookie
//     zu einer Seite passt, die diese Datei tatsächlich referenziert.
if (!$access_granted) {
    $cookie_name = 'wp-postpass_' . COOKIEHASH;
    $cookie_val  = $_COOKIE[$cookie_name] ?? '';

    if (!empty($cookie_val)) {
        // Posts finden, in denen der Token/Dateiname verwendet wird
        // (wir suchen beides, damit es nicht zu breit wird)
        global $wpdb;

        $search_token = '%' . $wpdb->esc_like($token) . '%';
        $search_name  = $original_filename ? '%' . $wpdb->esc_like($original_filename) . '%' : null;

        $conds = [];
        $conds[] = $wpdb->prepare("(p.post_content LIKE %s OR pm.meta_value LIKE %s)", $search_token, $search_token);
        if ($search_name) {
            $conds[] = $wpdb->prepare("(p.post_content LIKE %s OR pm.meta_value LIKE %s)", $search_name, $search_name);
        }

        $sql = "
            SELECT DISTINCT p.ID, p.post_password
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_status = 'publish'
              AND p.post_password <> ''
              AND (" . implode(" OR ", $conds) . ")
            LIMIT 50
        ";

        $rows = $wpdb->get_results($sql);

        if (!empty($rows)) {
            foreach ($rows as $row) {
                // WordPress speichert post_password im Klartext,
                // wp-postpass Cookie ist ein Hash davon → wp_check_password passt genau dafür.
                if (wp_check_password($row->post_password, $cookie_val)) {
                    $access_granted = true;
                    break;
                }
            }
        }
    }
}


// Zugriff verweigert → Redirect oder 403
if (!$access_granted) {
    $redirect_page_id = intval($settings['redirect_page_id'] ?? 0);
    if ($redirect_page_id > 0 && get_post_status($redirect_page_id) === 'publish') {
        wp_safe_redirect(get_permalink($redirect_page_id));
        exit;
    }

    status_header(403);
    exit('Zugriff verweigert.');
}

// ------------------------------------------------------------------
// 5. Physische Datei vorbereiten
// ------------------------------------------------------------------
$upload_dir = wp_upload_dir();
$storage_dir = trailingslashit($upload_dir['basedir']) . Module::STORAGE_DIR;
$filepath = $storage_dir . '/' . $token . '-' . $original_filename;

if (!file_exists($filepath) || !is_file($filepath) || !is_readable($filepath)) {
    status_header(404);
    exit('Datei nicht gefunden oder nicht lesbar.');
}

// ------------------------------------------------------------------
// 6. MIME-Type & Disposition
// ------------------------------------------------------------------
$filetype = wp_check_filetype($original_filename);
$mime     = $filetype['type'] ?: 'application/octet-stream';

$ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));

// Inline-Anzeige für gängige Formate (PDF, Bilder), sonst Download
$disposition = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)
    ? 'inline'
    : 'attachment';

// ------------------------------------------------------------------
// 7. Datei ausliefern
// ------------------------------------------------------------------
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($original_filename) . '"');
header('Content-Length: ' . filesize($filepath));

// Output Buffering leeren für sauberes Streaming
if (ob_get_level()) {
    ob_end_clean();
}

// Große Dateien effizient streamen
readfile($filepath);
exit;