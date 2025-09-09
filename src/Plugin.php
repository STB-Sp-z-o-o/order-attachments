<?php
namespace STB\OrderAttachments;

use STB\OrderAttachments\Installer;
use STB\OrderAttachments\Repository;
use STB\OrderAttachments\RestController;
use STB\OrderAttachments\AdminPage;
use STB\OrderAttachments\ShortCodes;

final class Plugin
{
    private static string $pluginFile;

    public static function boot(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;

        add_action('init', [Installer::class, 'registerRewrite']);

        add_action('template_redirect', [Repository::class, 'handleDownload']);
        add_action('rest_api_init', [RestController::class, 'register']);

        AdminPage::init();
        
        add_action('woocommerce_order_details_after_order_table', [ShortCodes::class, 'renderOrderView']);
        add_filter('woocommerce_my_account_my_orders_actions', [ShortCodes::class, 'addMyOrdersAction'], 10, 2);
        
        add_filter('manage_woocommerce_page_wc-orders_columns', [ShortCodes::class, 'addOrderListColumn']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [ShortCodes::class, 'renderOrderListColumn'], 10, 2);
        add_filter('manage_edit-shop_order_columns', [ShortCodes::class, 'addOrderListColumn']);
        add_action('manage_shop_order_posts_custom_column', [ShortCodes::class, 'renderOrderListColumn'], 10, 2);
        
    }
}
