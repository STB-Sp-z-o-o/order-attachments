<?php
namespace STB\OrderAttachments;

use WP_Error;
use WP_REST_Request;
use WC_Order;

if (!defined('ABSPATH')) exit;

/**
 * RestController
 *
 * Rejestruje i obsługuje REST API:
 *  - GET    /stb/v1/orders/{id}/attachments
 *  - POST   /stb/v1/orders/{id}/attachments
 *  - DELETE /stb/v1/orders/{id}/attachments?att_id={UUID}
 *
 * Załączniki są przechowywane jako tablica w meta zamówienia (klucz: _stb_order_attachments),
 * a same pliki w uploads/order-attachments (poza publicznym routowaniem – pobierane przez PHP).
 */
final class RestController
{
    /** Namespace REST API */
    private const NS = 'stb/v1';

    /** Podkatalog w uploads */
    private const UPLOAD_SUBDIR = 'order-attachments';

    /**
     * Rejestracja tras.
     */
    public static function register(): void
    {
        add_filter('woocommerce_rest_is_request_to_rest_api', [RestController::class, 'filter_woocommerce_rest_api']);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/attachments', [
            // LISTA (GET)
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list'],
                'permission_callback' => [__CLASS__, 'canView'],
            ],
            // DODANIE (POST)
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'add'],
                'permission_callback' => [__CLASS__, 'canEdit'],
                'args' => [
                    'filename'       => ['required' => false, 'type' => 'string'],
                    'content_base64' => ['required' => false, 'type' => 'string'],
                ],
            ],
            // USUNIĘCIE (DELETE)
            [
                'methods'             => 'DELETE',
                'callback'            => [__CLASS__, 'delete'],
                'permission_callback' => [__CLASS__, 'canEdit'],
                'args' => [
                    'att_id' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);
    }

    public static function filter_woocommerce_rest_api( $is_wc ) {
        
        $rest_prefix = trailingslashit( rest_get_url_prefix() ); // zwykle 'wp-json/'
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw( wp_unslash($_SERVER['REQUEST_URI']) ) : '';
        // Uznaj /wp-json/stb/... także za "WooCommerce REST"
        if ( false !== strpos( $uri, $rest_prefix . 'stb/' ) ) {
            return true;
        }
        return $is_wc;
    }

    /* ============================================================
     *  PERMISSIONS
     * ============================================================ */

    /**
     * Widok: admin/shop_manager ALBO właściciel zamówienia.
     */
    public static function canView(WP_REST_Request $req): bool
    {
        return Repository::canUserAccessOrder(wc_get_order(absint($req['id'])));
    }

    /**
     * Edycja: tylko admin/shop_manager/administrator.
     */
    public static function canEdit(WP_REST_Request $req): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('shop_manager') || current_user_can('administrator');

    }

    /* ============================================================
     *  HANDLERY ENDPOINTÓW
     * ============================================================ */

    /**
     * GET: lista załączników.
     * Zwraca metadane + bezpieczne download_url (nonce powiązany z bieżącym userem).
     */
    public static function list(WP_REST_Request $req)
    {
        $order = wc_get_order(absint($req['id']));
        if (!$order) {
            return new WP_Error('not_found', 'Nie znaleziono zamówienia', ['status' => 404]);
        }

        $items = Repository::all($order);
        $out   = [];

        foreach ($items as $a) {
            $out[] = [
                'id'          => (string)($a['id'] ?? ''),
                'name'        => (string)($a['name'] ?? ''),
                'mime'        => (string)($a['mime'] ?? 'application/octet-stream'),
                'size'        => (int)($a['size'] ?? 0),
                'uploaded_at' => (string)($a['uploaded_at'] ?? ''),
                'uploaded_by' => (int)($a['uploaded_by'] ?? 0),
                'download_url'=> Repository::downloadUrl($order, (string)($a['id'] ?? '')),
            ];
        }

        return rest_ensure_response($out);
    }

    /**
     * POST: dodanie załącznika (multipart lub JSON base64).
     */
    public static function add(WP_REST_Request $req)
    {
        $order = wc_get_order(absint($req['id']));
        if (!$order) {
            return new WP_Error('not_found', 'Nie znaleziono zamówienia', ['status' => 404]);
        }

        if (!self::ensureUploadDir()) {
            return new WP_Error('storage_error', 'Nie można utworzyć katalogu docelowego', ['status' => 500]);
        }

        $bytes    = null;
        $filename = null;
        $mime     = 'application/octet-stream';

        if (!empty($_FILES['file']) && is_array($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $filename = self::sanitizeFilename($_FILES['file']['name'] ?? 'file.bin');
            $bytes    = file_get_contents($_FILES['file']['tmp_name']);
            $mime     = !empty($_FILES['file']['type']) ? (string)$_FILES['file']['type'] : $mime;
        }
        elseif ($req->get_param('content_base64')) {
            $b64      = (string)$req->get_param('content_base64');
            $filename = self::sanitizeFilename((string)$req->get_param('filename') ?: 'file.bin');
            $bytes    = base64_decode($b64, true);
            if ($bytes === false) {
                return new WP_Error('bad_request', 'content_base64 nie jest poprawnym base64', ['status' => 400]);
            }
            if (function_exists('finfo_open')) {
                $f = finfo_open(FILEINFO_MIME_TYPE);
                if ($f) {
                    $detected = finfo_buffer($f, $bytes);
                    if (is_string($detected) && $detected !== '') {
                        $mime = $detected;
                    }
                    finfo_close($f);
                }
            }
        } else {
            return new WP_Error('bad_request', 'Brak pliku (multipart "file") lub content_base64', ['status' => 400]);
        }

        $uuid      = self::uuid();
        $targetRel = $uuid . '__' . $filename;
        $targetAbs = trailingslashit(self::uploadBaseDir()) . $targetRel;

        if (@file_put_contents($targetAbs, $bytes) === false) {
            return new WP_Error('storage_error', 'Błąd zapisu pliku', ['status' => 500]);
        }

        $att = [
            'id'          => $uuid,
            'name'        => $filename,
            'path'        => $targetAbs,
            'mime'        => $mime,
            'size'        => filesize($targetAbs),
            'uploaded_at' => current_time('mysql', true), // UTC
            'uploaded_by' => get_current_user_id() ?: 0,
        ];

        $list   = Repository::all($order);
        $list[] = $att;
        Repository::save($order, $list);

        $out = $att;
        unset($out['path']);
        $out['download_url'] = Repository::downloadUrl($order, $uuid);

        return rest_ensure_response($out);
    }

    /**
     * DELETE: usuń załącznik po ID.
     */
    public static function delete(WP_REST_Request $req)
    {
        $order = wc_get_order(absint($req['id']));
        if (!$order) {
            return new WP_Error('not_found', 'Nie znaleziono zamówienia', ['status' => 404]);
        }

        $attId = sanitize_text_field((string)$req->get_param('att_id'));
        if ($attId === '') {
            return new WP_Error('bad_request', 'Brak parametru att_id', ['status' => 400]);
        }

        $list   = Repository::all($order);
        $found  = false;

        foreach ($list as $i => $a) {
            if (($a['id'] ?? '') === $attId) {
                $found = true;
                if (!empty($a['path']) && is_string($a['path']) && file_exists($a['path'])) {
                    @unlink($a['path']);
                }
                unset($list[$i]);
                break;
            }
        }

        if (!$found) {
            return new WP_Error('not_found', 'Załącznik nie istnieje', ['status' => 404]);
        }

        Repository::save($order, array_values($list));
        return rest_ensure_response(['deleted' => $attId]);
    }

    /* ============================================================
     *  POMOCNICZE
     * ============================================================ */

    /** Katalog bazowy uploads dla załączników */
    private static function uploadBaseDir(): string
    {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . self::UPLOAD_SUBDIR;
    }

    /** Zapewnia istnienie katalogu uploads/order-attachments */
    private static function ensureUploadDir(): bool
    {
        $ok = wp_mkdir_p(self::uploadBaseDir());
        $ht = trailingslashit(self::uploadBaseDir()) . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Order allow,deny\nDeny from all\n");
        }
        return $ok;
    }

    /** Generowanie UUID v4 (fallback gdy wp_generate_uuid4 nie istnieje) */
    private static function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /** Sanityzacja nazwy pliku */
    private static function sanitizeFilename(string $name): string
    {
        $name = sanitize_file_name($name);
        return $name !== '' ? $name : 'file';
    }
}
