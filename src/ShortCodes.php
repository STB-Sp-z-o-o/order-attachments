<?php
namespace STB\OrderAttachments;

use WC_Order;

if (!defined('ABSPATH')) exit;

final class ShortCodes
{
    public static function renderOrderView($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $attachments = Repository::all($order);
        
        if (empty($attachments)) {
            return;
        }

        echo '<div class="woocommerce-order-attachments" id="attachments" style="margin-top: 20px;">';
        echo '<h2>' . __('Załączniki do zamówienia', 'stb-order-attachments') . '</h2>';
        echo '<div class="order-attachments-list">';
        
        foreach ($attachments as $attachment) {
            $downloadUrl = Repository::downloadUrl($order, $attachment['id']);
            $fileSize = self::formatFileSize($attachment['size'] ?? 0);
            $uploadDate = date_i18n(get_option('date_format'), time()); // fallback to current time
            
            echo '<div class="attachment-item" style="display: flex; align-items: center; padding: 15px; border: 1px solid #ddd; margin-bottom: 10px; border-radius: 4px; background: #fff;">';
            echo '<div class="attachment-icon" style="margin-right: 15px;">';
            echo self::getFileIcon($attachment['name']);
            echo '</div>';
            echo '<div class="attachment-info" style="flex: 1;">';
            echo '<div class="attachment-name" style="font-weight: bold; margin-bottom: 5px; color: #333;">' . esc_html($attachment['name']) . '</div>';
            echo '<div class="attachment-meta" style="font-size: 12px; color: #666;">';
            echo sprintf(__('Rozmiar: %s | Dodano: %s', 'stb-order-attachments'), $fileSize, $uploadDate);
            echo '</div>';
            echo '</div>';
            echo '<div class="attachment-actions">';
            echo '<a href="' . esc_url($downloadUrl) . '" class="button button-primary attachment-download-btn" style="text-decoration: none; display: inline-flex; align-items: center;" target="_blank" rel="noopener">';
            echo '<span class="dashicons dashicons-download" style="margin-right: 5px; line-height: 1;"></span>';
            echo __('Pobierz', 'stb-order-attachments');
            echo '</a>';
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '</div>';
        
        // Add some CSS for better styling
        echo '<style>
            .woocommerce-order-attachments {
                border: 1px solid #e1e1e1;
                padding: 20px;
                margin-top: 20px;
                border-radius: 4px;
                background: #fafafa;
            }
            .woocommerce-order-attachments h2 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #333;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
            .attachment-item:hover {
                background-color: #f9f9f9 !important;
                border-color: #0073aa !important;
            }
            .attachment-download-btn {
                padding: 8px 16px !important;
                border-radius: 3px !important;
                border: none !important;
                cursor: pointer !important;
                transition: all 0.2s ease !important;
                font-size: 13px !important;
            }
            .attachment-download-btn:hover {
                background-color: #005177 !important;
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            @media (max-width: 768px) {
                .attachment-item {
                    flex-direction: column !important;
                    align-items: flex-start !important;
                }
                .attachment-icon {
                    margin-bottom: 10px !important;
                    margin-right: 0 !important;
                }
                .attachment-actions {
                    margin-top: 10px !important;
                    width: 100% !important;
                }
                .attachment-download-btn {
                    width: 100% !important;
                    justify-content: center !important;
                }
            }
        </style>';
    }

    public static function addMyOrdersAction($actions, $order): array
    {
        if (!$order instanceof WC_Order) {
            return $actions;
        }

        $attachments = Repository::all($order);
        
        if (!empty($attachments)) {
            $actions['view_attachments'] = [
                'url' => $order->get_view_order_url() . '#attachments',
                'name' => sprintf(__('Załączniki (%d)', 'stb-order-attachments'), count($attachments)),
                'class' => 'button view-attachments'
            ];
        }

        return $actions;
    }

    public static function addOrderListColumn($columns): array
    {
        // Insert attachments column before actions
        $new_columns = [];
        foreach ($columns as $key => $value) {
            if ($key === 'wc_actions' || $key === 'order_actions') {
                $new_columns['stb_attachments'] = __('Załączniki', 'stb-order-attachments');
            }
            $new_columns[$key] = $value;
        }
        
        // If no actions column found, add at the end
        if (!isset($new_columns['stb_attachments'])) {
            $new_columns['stb_attachments'] = __('Załączniki', 'stb-order-attachments');
        }
        
        return $new_columns;
    }

    public static function renderOrderListColumn($column, $order_id): void
    {
        if ($column !== 'stb_attachments') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $attachments = Repository::all($order);
        
        if (empty($attachments)) {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        $count = count($attachments);
        echo '<div class="stb-attachments-column">';
        echo '<span class="dashicons dashicons-paperclip" style="color: #0073aa; font-size: 16px;"></span>';
        echo '<span style="margin-left: 5px; font-weight: bold; color: #0073aa;">' . $count . '</span>';
        
        if ($count > 0) {
            echo '<div class="stb-attachments-preview" style="margin-top: 5px;">';
            foreach (array_slice($attachments, 0, 3) as $attachment) {
                $downloadUrl = Repository::downloadUrl($order, $attachment['id']);
                echo '<div style="font-size: 11px; margin-bottom: 2px;">';
                echo '<a href="' . esc_url($downloadUrl) . '" title="' . esc_attr($attachment['name']) . '" style="text-decoration: none; color: #666;" target="_blank" rel="noopener">';
                echo esc_html(strlen($attachment['name']) > 25 ? substr($attachment['name'], 0, 25) . '...' : $attachment['name']);
                echo '</a>';
                echo '</div>';
            }
            if ($count > 3) {
                echo '<div style="font-size: 11px; color: #999; font-style: italic;">';
                echo sprintf(__('...i %d więcej', 'stb-order-attachments'), $count - 3);
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    }

    private static function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }

    private static function getFileIcon(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $iconMap = [
            'pdf' => 'dashicons-pdf',
            'doc' => 'dashicons-media-document',
            'docx' => 'dashicons-media-document',
            'xls' => 'dashicons-media-spreadsheet',
            'xlsx' => 'dashicons-media-spreadsheet',
            'txt' => 'dashicons-media-text',
            'jpg' => 'dashicons-format-image',
            'jpeg' => 'dashicons-format-image',
            'png' => 'dashicons-format-image',
            'gif' => 'dashicons-format-image',
            'zip' => 'dashicons-media-archive',
            'rar' => 'dashicons-media-archive',
        ];

        $iconClass = $iconMap[$extension] ?? 'dashicons-media-default';
        
        return '<span class="dashicons ' . $iconClass . '" style="font-size: 32px; color: #666;"></span>';
    }
}