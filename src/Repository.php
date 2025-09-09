<?php
namespace STB\OrderAttachments;

use WC_Order;

final class Repository
{
    private const META_KEY = '_stb_order_attachments';

    public static function all(WC_Order $order): array
    {
        $raw = $order->get_meta(self::META_KEY);
        return is_array($raw) ? $raw : [];
    }

    public static function save(WC_Order $order, array $attachments): void
    {
        $order->update_meta_data(self::META_KEY, array_values($attachments));
        $order->save();
    }

    public static function handleDownload(): void
    {
        if (!get_query_var(Installer::QV_DOWNLOAD)) return;

        $orderId = absint(get_query_var('order_id'));
        $attId   = sanitize_text_field(get_query_var('att_id'));
        $hash    = sanitize_text_field($_GET['_hash'] ?? '');
        $timestamp = absint($_GET['_timestamp'] ?? 0);

        // Sprawdź czy link nie wygasł (1 godzina)
        if (!$timestamp || (time() - $timestamp) > 3600) {
            wp_die(__('Link do pobrania wygasł.', 'stb'), '', ['response' => 403]);
        }

        // Sprawdź czy zamówienie istnieje
        $order = wc_get_order($orderId);
        if (!$order) {
            wp_die(__('Nie znaleziono zamówienia.', 'stb'), '', ['response' => 404]);
        }

        // Sprawdź uprawnienia do zamówienia
        if (!self::canUserAccessOrder($order)) {
            wp_die(__('Brak uprawnień do pobrania tego załącznika.', 'stb'), '', ['response' => 403]);
        }

        // Sprawdź hash z timestamp - własny system zamiast wp_create_nonce()
        $secret = wp_salt('nonce');
        $hashData = 'stb_att_' . $orderId . '_' . $attId . '_' . $timestamp . '_' . $secret;
        $expectedHash = substr(md5($hashData), 0, 10);
        
        // Debug info
        error_log('STB Order Attachments Download Debug:');
        error_log('Order ID: ' . $orderId);
        error_log('Attachment ID: ' . $attId);
        error_log('Timestamp: ' . $timestamp);
        error_log('Hash data: ' . $hashData);
        error_log('Received hash: ' . $hash);
        error_log('Expected hash: ' . $expectedHash);
        
        if (!hash_equals($expectedHash, $hash)) {
            wp_die(__('Nieprawidłowy token bezpieczeństwa.', 'stb'), '', ['response' => 403]);
        }

        // Znajdź załącznik
        $atts = self::all($order);
        $file = null;
        foreach ($atts as $a) {
            if ($a['id'] === $attId) {
                $file = $a;
                break;
            }
        }

        if (!$file || !file_exists($file['path'])) {
            wp_die(__('Załącznik nie istnieje.', 'stb'), '', ['response' => 404]);
        }

        // Loguj dostęp do pliku
        error_log('STB Order Attachments: File downloaded - Order: ' . $orderId . ', File: ' . $file['name'] . ', User: ' . get_current_user_id());

        // Wyślij plik
        nocache_headers();
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: attachment; filename="' . basename($file['name']) . '"');
        header('Content-Length: ' . filesize($file['path']));
        readfile($file['path']);
        exit;
    }

    /**
     * Sprawdza czy aktualny użytkownik może pobrać załącznik z zamówienia
     */
    public static function canUserAccessOrder(WC_Order $order): bool
    {
        $currentUserId = get_current_user_id();
        
        // Goście nie mogą pobierać
        if (!$currentUserId) {
            return false;
        }
        
        // Administratorzy mogą pobierać wszystko
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        
        // Użytkownicy z uprawnieniami do edycji zamówień
        if (current_user_can('edit_shop_orders')) {
            return true;
        }
        
        // Właściciel zamówienia może pobrać swoje załączniki
        if ($order->get_customer_id() && $order->get_customer_id() == $currentUserId) {
            return true;
        }
        
        // Sprawdź czy użytkownik może edytować konkretne zamówienie
        if (current_user_can('edit_shop_order', $order->get_id())) {
            return true;
        }
        
        return false;
    }

    public static function downloadUrl(WC_Order $order, string $attId): string
    {
        $timestamp = time();
        
        // Własny hash zamiast wp_create_nonce() - bardziej przewidywalny
        $secret = wp_salt('nonce'); // Używa WordPress salt
        $hashData = 'stb_att_' . $order->get_id() . '_' . $attId . '_' . $timestamp . '_' . $secret;
        $hash = substr(md5($hashData), 0, 10); // 10 znaków dla bezpieczeństwa
        
        $pretty = home_url(sprintf('/secure-download/order/%d/att/%s', $order->get_id(), $attId));
        
        // Debug info
        error_log('STB Order Attachments URL Generation Debug:');
        error_log('Order ID: ' . $order->get_id());
        error_log('Attachment ID: ' . $attId);
        error_log('Timestamp: ' . $timestamp);
        error_log('Hash data: ' . $hashData);
        error_log('Generated hash: ' . $hash);
        
        return add_query_arg([
            '_hash' => $hash,
            '_timestamp' => $timestamp
        ], $pretty);
    }
}
