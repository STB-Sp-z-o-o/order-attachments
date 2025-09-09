<?php
/**
 * Plugin Name: STB – Order Attachments (secure)
 * Description: Bezpieczne załączniki do zamówień WooCommerce + REST API + widok w panelu klienta i admina.
 * Author: Adrian Ciołek | STB Tech
 * Version: 1.0.2
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: stb-order-attachments
 */

// Blokada bezpośredniego wywołania
if (!defined('ABSPATH')) {
    exit;
}

/**
 * (Opcjonalnie) stałe pomocnicze
 */
define('STB_OA_FILE', __FILE__);
define('STB_OA_DIR',  plugin_dir_path(__FILE__));
define('STB_OA_URL',  plugin_dir_url(__FILE__));
define('STB_OA_VER',  '1.1.0');

/**
 * Autoloader Composera – wymagany przy instalacji przez Composer/Packagist.
 * Jeśli ktoś skopiuje wtyczkę ręcznie bez `vendor/`, pokaż ostrzeżenie w panelu.
 */
$__stb_oa_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($__stb_oa_autoload)) {
    require_once $__stb_oa_autoload;
} else {
    // Delikatny komunikat w panelu admina – wtyczka nadal nie wstanie bez autoloadera.
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>STB – Order Attachments:</strong> brak autoloadera Composera. Uruchom <code>composer install</code> w katalogu wtyczki lub zainstaluj przez Composera.</p></div>';
    });
    // Nie przerywamy — pozwalamy wyświetlić notyfikację.
    return;
}

/**
 * Start wtyczki po załadowaniu pluginów i WooCommerce.
 * - weryfikujemy obecność WooCommerce,
 * - uruchamiamy nasz orchestrator \STB\OrderAttachments\Plugin::boot().
 */
add_action('plugins_loaded', function () {
    // Sprawdź WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>STB – Order Attachments:</strong> ta wtyczka wymaga aktywnego WooCommerce.</p></div>';
        });
        return;
    }

    // Sprawdź, czy nasza klasa główna jest dostępna (autoload PSR-4)
    if (!class_exists(\STB\OrderAttachments\Plugin::class)) {
        // Jeśli nie ma autoloadera, wcześniej już pokazaliśmy notice — tutaj wychodzimy.
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>STB – Order Attachments:</strong> nie można załadować klas wtyczki.</p></div>';
        });
        return;
    }

    // Uruchomienie: rejestracja hooków, REST, admin, front
    \STB\OrderAttachments\Plugin::boot(__FILE__);
}, 20); // priorytet >10, aby WooCommerce zdążył się zainicjalizować

/**
 * Hook aktywacji - rejestrujemy po załadowaniu autoloadera
 */
if (file_exists($__stb_oa_autoload)) {
    register_activation_hook(__FILE__, function() {
        // Upewnij się, że klasy są załadowane
        if (class_exists(\STB\OrderAttachments\Installer::class)) {
            \STB\OrderAttachments\Installer::activate();
        }
    });
}

/**
 * (Opcjonalnie) Deaktywacja – odświeżenie reguł rewrite, jeśli chcesz posprzątać.
 * Aktywacja i rejestracja rewrite jest w Installer::activate().
 */
register_deactivation_hook(__FILE__, function () {
    // Na dezaktywacji po prostu flush (bez kasowania plików użytkownika).
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules();
    }
});
