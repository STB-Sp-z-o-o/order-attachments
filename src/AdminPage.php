<?php
namespace STB\OrderAttachments;

use WC_Order;
use Exception;

if (!defined('ABSPATH')) exit;

final class AdminPage
{
    private const UPLOAD_SUBDIR = 'order-attachments';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'adminInit']);
        // Wyłączamy renderDirectly - będziemy używać tylko metabox po prawej
        // add_action('woocommerce_admin_order_data_after_order_details', [self::class, 'renderDirectly']);
        add_action('admin_head', [self::class, 'addAdminStyles']);
        add_action('wp_ajax_stb_upload_attachment', [self::class, 'ajaxUploadAttachment']);
        add_action('wp_ajax_stb_delete_attachment', [self::class, 'ajaxDeleteAttachment']);
    }

    
    public static function adminInit(): void
    {
        // Włączamy metabox po prawej stronie (sidebar)
        global $pagenow;
        
        if ($pagenow === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order') {
            add_action('add_meta_boxes_shop_order', [self::class, 'register']);
        } elseif ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit') {
            add_action('add_meta_boxes', [self::class, 'register']);
        }
    }

    public static function register(): void
    {
        // Backup registrations
        $possible_screens = ['woocommerce_page_wc-orders', 'shop_order', 'wc-orders'];
        foreach ($possible_screens as $screen) {
            add_meta_box(
                'stb_order_attachments_' . sanitize_key($screen),
                __('Załączniki', 'stb-order-attachments'),
                [__CLASS__, 'render'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function renderDirectly($order): void
    {
        if (!($order instanceof WC_Order)) {
            return;
        }
        
        echo '<div class="postbox">';
        echo '<h3><span>' . esc_html__('Załączniki (STB)', 'stb-order-attachments') . '</span></h3>';
        echo '<div class="inside">';
        self::renderContent($order, $order->get_id());
        echo '</div>';
        echo '</div>';
    }
    
    private static function renderContent(WC_Order $order, int $order_id): void
    {
        $atts = Repository::all($order);

        echo '<div style="display:flex;flex-direction:column;gap:8px;">';

        if (!empty($atts)) {
            echo '<ul class="stb-attachments-list" style="margin:0;padding-left:18px;">';
            foreach ($atts as $a) {
                $id   = esc_attr((string)($a['id'] ?? ''));
                $name = esc_html((string)($a['name'] ?? ''));
                $mime = esc_html((string)($a['mime'] ?? 'application/octet-stream'));
                $size = size_format((int)($a['size'] ?? 0));
                $url = esc_url(self::downloadUrl($order, $id));

                echo '<li style="margin-bottom:6px;">';
                echo '<strong><a href="'.$url.'">'.$name.'</a></strong><br>';
                echo '<span style="font-size:12px;color:#666;">'.$mime.', '.$size.'</span><br>';
                echo '<a href="#" class="stb-delete-attachment" data-attachment-id="'.$id.'" style="font-size:12px;color:#dc3232;text-decoration:none;">';
                echo '🗑️ ' . esc_html__('Usuń', 'stb-order-attachments');
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<div class="stb-attachments-list"><em style="color:#666;">' . esc_html__('Brak załączników.', 'stb-order-attachments') . '</em></div>';
        }

        echo '<hr style="margin:10px 0;">';
        
        // Własny formularz do uploadu załączników (AJAX) - bez tagu form
        echo '<div id="stb-attachment-upload-form-' . $order_id . '">';
        echo '<label for="stb_new_att_' . $order_id . '" style="font-weight:600;">' . esc_html__('Dodaj nowy załącznik', 'stb-order-attachments') . '</label>';
        echo '<input type="file" name="stb_new_att" id="stb_new_att_' . $order_id . '" />';
        echo '<button type="button" class="button button-primary" style="margin-top:8px;" onclick="stbUploadFile(' . $order_id . ')">' . esc_html__('Dodaj załącznik', 'stb-order-attachments') . '</button>';
        echo '</div>';
        
        // Komunikat o wyniku
        echo '<div id="stb-attachment-upload-result-' . $order_id . '" style="margin-top:8px;"></div>';
        
        echo '</div>'; // Zamknięcie głównego div
        
        // JavaScript w oddzielnej funkcji
        self::renderUploadScript($order_id);
    }
    
    /**
     * Renderuje JavaScript dla obsługi AJAX uploadu
     */
    private static function renderUploadScript(int $order_id): void
    {
        ?>
        <script>
        function stbUploadFile(orderId) {
            var container = jQuery("#stb-attachment-upload-form-" + orderId);
            var fileInput = container.find("input[type=file]");
            var result = jQuery("#stb-attachment-upload-result-" + orderId);
            var button = container.find("button");
            
            if (!fileInput[0].files[0]) {
                result.html("<div style='color:red;'>Proszę wybrać plik.</div>");
                return;
            }
            
            var formData = new FormData();
            formData.append('stb_new_att', fileInput[0].files[0]);
            formData.append('order_id', orderId);
            formData.append('action', 'stb_upload_attachment');
            formData.append('stb_upload_nonce', '<?php echo wp_create_nonce('stb_upload_att_' . $order_id); ?>');
            
            // Zablokuj przycisk podczas uploadu
            button.prop("disabled", true).text("Przesyłanie...");
            result.html("");
            
            jQuery.ajax({
                url: ajaxurl,
                type: "POST",
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        result.html("<div style='color:green;'>" + response.data + "</div>");
                        // Wyczyść pole pliku
                        fileInput.val('');
                        // Odśwież stronę po 2 sekundach
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        result.html("<div style='color:red;'>Błąd: " + response.data + "</div>");
                    }
                },
                error: function() {
                    result.html("<div style='color:red;'>Błąd połączenia z serwerem.</div>");
                },
                complete: function() {
                    // Odblokuj przycisk
                    button.prop("disabled", false).text("<?php echo esc_js(__('Dodaj załącznik', 'stb-order-attachments')); ?>");
                }
            });
        }
        
        jQuery(document).ready(function($) {
            // Obsługa usuwania załączników
            $(".stb-delete-attachment").on("click", function(e) {
                e.preventDefault();
                
                if (!confirm("Czy na pewno chcesz usunąć ten załącznik?")) {
                    return;
                }
                
                var attachmentId = $(this).data("attachment-id");
                var orderId = <?php echo $order_id; ?>;
                var listItem = $(this).closest("li");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "stb_delete_attachment",
                        order_id: orderId,
                        attachment_id: attachmentId,
                        _wpnonce: "<?php echo wp_create_nonce('stb_delete_att_' . $order_id); ?>"
                    },
                    success: function(response) {
                        if (response.success) {
                            listItem.fadeOut(500, function() {
                                $(this).remove();
                                // Sprawdź czy lista jest pusta
                                if ($(".stb-attachments-list li").length === 0) {
                                    $(".stb-attachments-list").html("<em style='color:#666;'><?php echo esc_js(__('Brak załączników.', 'stb-order-attachments')); ?></em>");
                                }
                            });
                        } else {
                            alert("Błąd: " + response.data);
                        }
                    },
                    error: function() {
                        alert("Błąd połączenia z serwerem.");
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public static function render($post_or_order): void
    {
        if ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
            $order_id = $order->get_id();
        } else {
            $order = wc_get_order($post_or_order->ID);
            $order_id = $post_or_order->ID;
        }
        
        if ($order) {
            self::renderContent($order, $order_id);
        } else {
            echo '<p>Nie udało się załadować zamówienia.</p>';
        }
    }

    public static function save($postId): void
    {
        // Debug
        error_log('STB Order Attachments: save() called for order ID: ' . $postId);
        error_log('STB Order Attachments: POST nonce: ' . ($_POST['stb_att_nonce'] ?? 'NOT SET'));
        error_log('STB Order Attachments: FILES: ' . print_r($_FILES, true));
        
        // Zabezpieczenia
        if (!isset($_POST['stb_att_nonce'])) {
            error_log('STB Order Attachments: No nonce found');
            return;
        }
        
        if (!wp_verify_nonce($_POST['stb_att_nonce'], 'stb_save_att_' . $postId)) {
            error_log('STB Order Attachments: Nonce verification failed');
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            error_log('STB Order Attachments: Skipping autosave');
            return;
        }
        
        if (!current_user_can('edit_shop_order', $postId)) {
            error_log('STB Order Attachments: User cannot edit shop orders');
            return;
        }

        $order = wc_get_order($postId);
        if (!$order) {
            error_log('STB Order Attachments: Order not found');
            return;
        }
        
        error_log('STB Order Attachments: All checks passed, proceeding with save');

        $list = Repository::all($order);

        // Usuwanie zaznaczonych
        if (!empty($_POST['stb_delete_att']) && is_array($_POST['stb_delete_att'])) {
            $toDelete = array_map('sanitize_text_field', $_POST['stb_delete_att']);
            foreach ($list as $i => $a) {
                $attId = (string)($a['id'] ?? '');
                if ($attId !== '' && in_array($attId, $toDelete, true)) {
                    $path = isset($a['path']) && is_string($a['path']) ? $a['path'] : null;
                    if ($path && file_exists($path)) {
                        @unlink($path);
                    }
                    unset($list[$i]);
                }
            }
            $list = array_values($list);
        }

        // Dodanie nowego pliku
        if (!empty($_FILES['stb_new_att']) && is_uploaded_file($_FILES['stb_new_att']['tmp_name'])) {
            error_log('STB Order Attachments: Processing file upload');
            
            if (self::ensureUploadDir()) {
                $origName = (string)($_FILES['stb_new_att']['name'] ?? 'file.bin');
                $filename = self::sanitizeFilename($origName);
                $bytes    = file_get_contents($_FILES['stb_new_att']['tmp_name']);

                $uuid      = self::uuid();
                $targetRel = $uuid . '__' . $filename;
                $targetAbs = trailingslashit(self::uploadBaseDir()) . $targetRel;

                if (@file_put_contents($targetAbs, $bytes) !== false) {
                    $list[] = [
                        'id'          => $uuid,
                        'name'        => $filename,
                        'path'        => $targetAbs,
                        'mime'        => (string)($_FILES['stb_new_att']['type'] ?? 'application/octet-stream'),
                        'size'        => filesize($targetAbs),
                        'uploaded_at' => current_time('mysql', true),
                        'uploaded_by' => get_current_user_id() ?: 0,
                    ];
                    error_log('STB Order Attachments: File saved successfully: ' . $targetAbs);
                } else {
                    error_log('STB Order Attachments: Failed to save file: ' . $targetAbs);
                }
            }
        }

        Repository::save($order, $list);
    }

    /**
     * AJAX handler for uploading attachment
     */
    public static function ajaxUploadAttachment(): void
    {
        // Sprawdź nonce
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!wp_verify_nonce($_POST['stb_upload_nonce'] ?? '', 'stb_upload_att_' . $order_id)) {
            wp_send_json_error('Nieprawidłowy token bezpieczeństwa.');
        }

        // Sprawdź uprawnienia
        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error('Brak uprawnień do edycji zamówienia.');
        }

        // Sprawdź plik
        if (empty($_FILES['stb_new_att']) || !is_uploaded_file($_FILES['stb_new_att']['tmp_name'])) {
            wp_send_json_error('Nie wybrano pliku lub błąd podczas przesyłania.');
        }

        // Pobierz zamówienie
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Nie znaleziono zamówienia.');
        }

        // Sprawdź katalog docelowy
        if (!self::ensureUploadDir()) {
            wp_send_json_error('Nie można utworzyć katalogu docelowego.');
        }

        try {
            $origName = sanitize_file_name($_FILES['stb_new_att']['name']);
            $filename = self::sanitizeFilename($origName);
            $fileSize = $_FILES['stb_new_att']['size'];
            $mimeType = $_FILES['stb_new_att']['type'];

            // Sprawdź rozmiar pliku (max 10MB)
            if ($fileSize > 10 * 1024 * 1024) {
                wp_send_json_error('Plik jest za duży. Maksymalny rozmiar to 10MB.');
            }

            // Generuj unikalne ID i ścieżkę
            $uuid = self::uuid();
            $targetRel = $uuid . '__' . $filename;
            $targetAbs = trailingslashit(self::uploadBaseDir()) . $targetRel;

            // Przenieś plik
            if (!move_uploaded_file($_FILES['stb_new_att']['tmp_name'], $targetAbs)) {
                wp_send_json_error('Nie udało się zapisać pliku na serwerze.');
            }

            // Dodaj do listy załączników
            $list = Repository::all($order);
            $list[] = [
                'id'          => $uuid,
                'name'        => $filename,
                'path'        => $targetAbs,
                'mime'        => $mimeType,
                'size'        => filesize($targetAbs),
                'uploaded_at' => current_time('mysql', true),
                'uploaded_by' => get_current_user_id() ?: 0,
            ];

            Repository::save($order, $list);

            wp_send_json_success('Załącznik "' . $filename . '" został dodany pomyślnie.');

        } catch (Exception $e) {
            wp_send_json_error('Błąd podczas przetwarzania pliku: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for deleting attachment
     */
    public static function ajaxDeleteAttachment(): void
    {
        // Sprawdź nonce
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'stb_delete_att_' . $order_id)) {
            wp_send_json_error('Nieprawidłowy token bezpieczeństwa.');
        }

        // Sprawdź uprawnienia
        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error('Brak uprawnień do edycji zamówienia.');
        }

        $attachment_id = sanitize_text_field($_POST['attachment_id'] ?? '');
        if (empty($attachment_id)) {
            wp_send_json_error('Nieprawidłowy ID załącznika.');
        }

        // Pobierz zamówienie
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Nie znaleziono zamówienia.');
        }

        try {
            $list = Repository::all($order);
            $found = false;

            foreach ($list as $i => $attachment) {
                if (($attachment['id'] ?? '') === $attachment_id) {
                    // Usuń plik z dysku
                    $path = $attachment['path'] ?? '';
                    if ($path && file_exists($path)) {
                        @unlink($path);
                    }

                    // Usuń z listy
                    unset($list[$i]);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                wp_send_json_error('Nie znaleziono załącznika do usunięcia.');
            }

            // Zapisz zaktualizowaną listę
            Repository::save($order, array_values($list));

            wp_send_json_success('Załącznik został usunięty pomyślnie.');

        } catch (Exception $e) {
            wp_send_json_error('Błąd podczas usuwania załącznika: ' . $e->getMessage());
        }
    }

    private static function downloadUrl(WC_Order $order, string $attId): string
    {
        $timestamp = time();
        
        $secret = wp_salt('nonce');
        $hashData = 'stb_att_' . $order->get_id() . '_' . $attId . '_' . $timestamp . '_' . $secret;
        $hash = substr(md5($hashData), 0, 10);
        
        $pretty = home_url(sprintf('/secure-download/order/%d/att/%s', $order->get_id(), $attId));
        
        return add_query_arg([
            '_hash' => $hash,
            '_timestamp' => $timestamp
        ], $pretty);
    }

    private static function uploadBaseDir(): string
    {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . self::UPLOAD_SUBDIR;
    }

    private static function ensureUploadDir(): bool
    {
        $dir = self::uploadBaseDir();
        $ok  = wp_mkdir_p($dir);

        $ht = trailingslashit($dir) . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Order allow,deny\nDeny from all\n");
        }
        return $ok;
    }

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

    private static function sanitizeFilename(string $name): string
    {
        $name = sanitize_file_name($name);
        return $name !== '' ? $name : 'file';
    }

    public static function addAdminStyles(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'edit-shop_order'], true)) {
            return;
        }
        ?>
        <style>
            .stb-attachments-column {
                width: 120px;
            }
            .stb-attachments-preview {
                max-width: 120px;
            }
            .stb-attachments-preview a:hover {
                color: #0073aa !important;
                text-decoration: underline !important;
            }
            .column-stb_attachments {
                width: 130px !important;
            }
            @media screen and (max-width: 782px) {
                .column-stb_attachments {
                    display: none;
                }
            }
        </style>
        <?php
    }
}
