<?php
/**
 * Plugin Name: Toloka for monobank
 * Plugin URI: https://github.com/tolokacode/toloka-monobank
 * Description: monobank installments (Покупка частинами) and cash on delivery with online prepayment. Works alongside the official "plata by mono" plugin.
 * Version: 0.1.0
 * Author: tolokacode
 * License: EUPL-1.2
 * License URI: https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * Requires PHP: 7.4
 * Requires at least: 6.5
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Text Domain: toloka-monobank
 */

defined('ABSPATH') || exit;

const TOLOKA_CHAST_CRON = 'toloka_chast_poll';
const TOLOKA_MONOBANK_FILE = __FILE__;
const TOLOKA_MONOBANK_VERSION = '0.1.0';

require_once __DIR__ . '/toloka-ui/toloka-ui.php';

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once __DIR__ . '/includes/chast-api.php';
    require_once __DIR__ . '/includes/gateway-chast.php';
    require_once __DIR__ . '/includes/gateway-cod.php';
    if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        require_once __DIR__ . '/includes/blocks.php';
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Toloka_Gateway_Chast';
        return array_map(function ($g) {
            return $g === 'WC_Gateway_COD' ? 'Toloka_Gateway_COD' : $g;
        }, $gateways);
    }, 20);
}, 11);

function toloka_monobank_header() {
    toloka_ui_header('Toloka for monobank', TOLOKA_MONOBANK_VERSION, __('Free and open source', 'toloka-monobank'), [
        __('Docs', 'toloka-monobank')        => 'https://github.com/tolokacode/toloka-monobank#readme',
        __('Report a bug', 'toloka-monobank') => 'https://github.com/tolokacode/toloka-monobank/issues',
        'GitHub'                             => 'https://github.com/tolokacode/toloka-monobank',
    ]);
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'woocommerce_page_wc-settings') {
        toloka_ui_enqueue();
    }
});

function toloka_gateway($id) {
    $gateways = WC()->payment_gateways()->payment_gateways();
    return $gateways[$id] ?? null;
}

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled(TOLOKA_CHAST_CRON)) {
        wp_schedule_event(time() + 300, 'toloka_5min', TOLOKA_CHAST_CRON);
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(TOLOKA_CHAST_CRON);
});

add_filter('cron_schedules', function ($schedules) {
    $schedules['toloka_5min'] = ['interval' => 300, 'display' => 'Every 5 minutes (Toloka)'];
    return $schedules;
});

// Plugins like order-status-control set Completed right on the thank-you page, before the client
// has confirmed the installments or paid the prepayment. Turn that off for our orders.
add_action('woocommerce_thankyou', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !($order->get_payment_method() === 'toloka_chast' || $order->get_meta('_toloka_prepay_invoice'))) {
        return;
    }
    global $wp_filter;
    foreach ($wp_filter['woocommerce_thankyou']->callbacks[10] ?? [] as $cb) {
        if (is_array($cb['function']) && is_object($cb['function'][0]) && $cb['function'][1] === 'autoCompleteOrder') {
            remove_action('woocommerce_thankyou', $cb['function'], 10);
        }
    }
}, 1);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=');
    array_unshift($links,
        '<a href="' . esc_url($url . 'toloka_chast') . '">' . esc_html__('Installments', 'toloka-monobank') . '</a>',
        '<a href="' . esc_url($url . 'cod') . '">' . esc_html__('Cash on delivery', 'toloka-monobank') . '</a>'
    );
    return $links;
});
