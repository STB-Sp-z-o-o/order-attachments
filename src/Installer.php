<?php
namespace STB\OrderAttachments;

final class Installer
{
    public const QV_DOWNLOAD = 'stb_order_attachment_download';

    public static function activate(): void
    {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'order-attachments';
        
        // Debug: loguj informacje o tworzeniu katalogu
        error_log('STB Order Attachments: Activation - Upload dir: ' . print_r($upload, true));
        error_log('STB Order Attachments: Activation - Target dir: ' . $dir);
        
        $created = wp_mkdir_p($dir);
        error_log('STB Order Attachments: Activation - Directory created: ' . ($created ? 'yes' : 'no'));
        error_log('STB Order Attachments: Activation - Directory exists: ' . (is_dir($dir) ? 'yes' : 'no'));
        error_log('STB Order Attachments: Activation - Directory writable: ' . (is_writable(dirname($dir)) ? 'yes' : 'no'));

        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            $htaccess_created = file_put_contents($ht, "Order allow,deny\nDeny from all\n");
            error_log('STB Order Attachments: Activation - .htaccess created: ' . ($htaccess_created !== false ? 'yes' : 'no'));
        }

        self::registerRewrite();
        flush_rewrite_rules();
        
        error_log('STB Order Attachments: Activation completed');
    }

    public static function registerRewrite(): void
    {
        add_rewrite_tag('%'.self::QV_DOWNLOAD.'%', '1');
        add_rewrite_tag('%order_id%', '([0-9]+)');
        add_rewrite_tag('%att_id%', '([A-Za-z0-9\-]+)');

        add_rewrite_rule(
            '^secure-download/order/([0-9]+)/att/([A-Za-z0-9\-]+)/?',
            'index.php?'.self::QV_DOWNLOAD.'=1&order_id=$matches[1]&att_id=$matches[2]',
            'top'
        );
    }
}
