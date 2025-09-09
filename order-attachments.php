<?php
/**
 * Plugin Name: STB – Order Attachments (secure)
 * Description: Bezpieczne załączniki do zamówień WooCommerce + REST API + widok w panelu klienta i admina.
 * Author: Adrian Ciołek | STB Tech
 * Version: 1.0.3
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: stb-order-attachments
 */


if (!defined('ABSPATH')) {
    exit;
}

define('STB_OA_FILE', __FILE__);
define('STB_OA_DIR',  plugin_dir_path(__FILE__));
define('STB_OA_URL',  plugin_dir_url(__FILE__));
define('STB_OA_VER',  '1.1.0');


$__stb_oa_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($__stb_oa_autoload)) {
    require_once $__stb_oa_autoload;
} else {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>STB – Order Attachments:</strong> brak autoloadera Composera. Uruchom <code>composer install</code> w katalogu wtyczki lub zainstaluj przez Composera.</p></div>';
    });
    return;
}

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>STB – Order Attachments:</strong> ta wtyczka wymaga aktywnego WooCommerce.</p></div>';
        });
        return;
    }

    if (!class_exists(\STB\OrderAttachments\Plugin::class)) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>STB – Order Attachments:</strong> nie można załadować klas wtyczki.</p></div>';
        });
        return;
    }

    \STB\OrderAttachments\Plugin::boot(__FILE__);
}, 20);

if (file_exists($__stb_oa_autoload)) {
    register_activation_hook(__FILE__, function() {
        if (class_exists(\STB\OrderAttachments\Installer::class)) {
            \STB\OrderAttachments\Installer::activate();
        }
    });
}


register_deactivation_hook(__FILE__, function () {
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules();
    }
});
