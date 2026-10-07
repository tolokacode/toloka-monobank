<?php

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Toloka_Chast_Block extends AbstractPaymentMethodType {

    protected $name = 'toloka_chast';

    public function initialize() {
        $this->settings = get_option('woocommerce_toloka_chast_settings', []);
    }

    public function is_active() {
        $gateway = toloka_gateway('toloka_chast');
        return $gateway && $gateway->is_ready();
    }

    public function get_payment_method_script_handles() {
        $file = 'assets/js/chast-block.js';
        wp_register_script(
            'toloka-chast-block',
            plugins_url($file, TOLOKA_MONOBANK_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            filemtime(plugin_dir_path(TOLOKA_MONOBANK_FILE) . $file),
            true
        );
        wp_set_script_translations('toloka-chast-block', 'toloka-monobank', plugin_dir_path(TOLOKA_MONOBANK_FILE) . 'languages');
        return ['toloka-chast-block'];
    }

    public function get_payment_method_data() {
        $gateway = toloka_gateway('toloka_chast');
        return [
            'title'       => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'parts'       => $gateway ? $gateway->get_parts() : [],
            'min'         => (float) $this->get_setting('min_total'),
            'max'         => (float) $this->get_setting('max_total'),
            'supports'    => $gateway ? array_values(array_filter($gateway->supports, [$gateway, 'supports'])) : [],
        ];
    }
}

add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
    $registry->register(new Toloka_Chast_Block());
});
