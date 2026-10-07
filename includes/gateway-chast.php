<?php
/**
 * monobank installments (Покупка частинами) payment method.
 *
 * Statuses: On hold (client confirms in the app) → Processing (client confirmed)
 * → manager sets Completed after shipping = confirm at the bank, the bank pays the shop.
 * Cancelled before Completed = reject. Refund after Completed = return.
 */

defined('ABSPATH') || exit;

class Toloka_Gateway_Chast extends WC_Payment_Gateway {

    const META_ID      = '_toloka_chast_id';
    const META_STATE   = '_toloka_chast_state';
    const META_PARTS   = '_toloka_chast_parts';
    const META_ATTEMPT = '_toloka_chast_attempt';


    public function __construct() {
        $this->id                 = 'toloka_chast';
        $this->method_title       = __('monobank installments', 'toloka-monobank');
        $this->method_description = __('Pay in installments through the monobank API. Statuses: On hold, then Processing, then Completed (after shipping).', 'toloka-monobank');
        $this->has_fields         = true;
        $this->supports           = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();
        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'general'         => ['title' => __('General', 'toloka-monobank'), 'type' => 'title'],
            'enabled'         => ['title' => __('Enable', 'toloka-monobank'), 'type' => 'checkbox', 'label' => __('Enable monobank installments', 'toloka-monobank'), 'default' => 'no'],
            'title'           => ['title' => __('Title', 'toloka-monobank'), 'type' => 'text', 'default' => __('monobank installments', 'toloka-monobank')],
            'description'     => ['title' => __('Description', 'toloka-monobank'), 'type' => 'textarea', 'default' => __('No overpayment. After placing the order, confirm the purchase in the monobank app.', 'toloka-monobank')],
            'connection'      => ['title' => __('Connection to monobank', 'toloka-monobank'), 'type' => 'title'],
            'environment'     => [
                'title'       => __('Environment', 'toloka-monobank'),
                'type'        => 'select',
                'default'     => 'sandbox',
                'options'     => [
                    'sandbox'    => __('Sandbox (test)', 'toloka-monobank'),
                    'stage'      => __('Stage (test with the real app)', 'toloka-monobank'),
                    'production' => __('Production', 'toloka-monobank'),
                ],
                'description' => __('Sandbox and Stage are for testing. Use Production for real orders.', 'toloka-monobank'),
            ],
            'store_id'        => ['title' => __('Store ID', 'toloka-monobank'), 'type' => 'text'],
            'store_secret'    => ['title' => __('Secret key', 'toloka-monobank'), 'type' => 'password'],
            'checkout'        => ['title' => __('Installments', 'toloka-monobank'), 'type' => 'title'],
            'parts'           => ['title' => __('Number of payments', 'toloka-monobank'), 'type' => 'text', 'default' => '3,4,6', 'description' => __('Comma separated, from 3 to 25. Must match your contract with the bank.', 'toloka-monobank')],
            'min_total'       => ['title' => __('Minimum amount, UAH', 'toloka-monobank'), 'type' => 'number', 'default' => '500'],
            'max_total'       => ['title' => __('Maximum amount, UAH', 'toloka-monobank'), 'type' => 'number', 'default' => '', 'description' => __('Leave empty for no limit.', 'toloka-monobank')],
            'show_on_product' => ['title' => __('Product page', 'toloka-monobank'), 'type' => 'checkbox', 'label' => __('Show "N payments of X" under the price', 'toloka-monobank'), 'default' => 'yes'],
        ];
    }

    public function admin_options() {
        toloka_monobank_header();
        echo '<div class="toloka-ui">';
        parent::admin_options();
        echo '</div>';
    }

    public function api() {
        return new Toloka_Chast_Api($this->get_option('environment'), $this->get_option('store_id'), $this->get_option('store_secret'));
    }

    public function get_parts() {
        $parts = array_map('intval', explode(',', (string) $this->get_option('parts')));
        $parts = array_unique(array_filter($parts, function ($p) { return $p >= 3 && $p <= 25; }));
        sort($parts);
        return $parts;
    }

    public function fits_total($total) {
        $min = (float) $this->get_option('min_total');
        $max = (float) $this->get_option('max_total');
        return !($min && $total < $min) && !($max && $total > $max);
    }

    public function is_ready() {
        return $this->enabled === 'yes' && $this->get_option('store_id') && $this->get_option('store_secret')
            && $this->get_parts() && get_woocommerce_currency() === 'UAH';
    }

    public function is_available() {
        $total = $this->get_order_total();
        return parent::is_available() && $this->is_ready() && ($total <= 0 || $this->fits_total($total));
    }

    public static function parts_label($parts, $sum) {
        if ($sum <= 0) {
            /* translators: %d: number of payments */
            return sprintf(_n('%d payment', '%d payments', $parts, 'toloka-monobank'), $parts);
        }
        /* translators: 1: number of payments, 2: amount of one payment */
        return sprintf(_n('%1$d payment of ~%2$s', '%1$d payments of ~%2$s', $parts, 'toloka-monobank'), $parts, wp_strip_all_tags(wc_price($sum / $parts)));
    }

    public static function fail_reason($code) {
        $reasons = [
            'CLIENT_NOT_FOUND'                => __('the customer is not a monobank client', 'toloka-monobank'),
            'EXCEEDED_SUM_LIMIT'              => __('the customer\'s installments limit is too low', 'toloka-monobank'),
            'EXISTS_OTHER_OPEN_ORDER'         => __('the customer has another open application', 'toloka-monobank'),
            'NOT_ENOUGH_MONEY_FOR_INIT_DEBIT' => __('not enough money for the first payment', 'toloka-monobank'),
            'REJECTED_BY_CLIENT'              => __('the customer declined', 'toloka-monobank'),
            'PAY_PARTS_ARE_NOT_ACCEPTABLE'    => __('this number of payments is not allowed', 'toloka-monobank'),
            'FRAUD_REJECTED'                  => __('declined by the bank\'s fraud check', 'toloka-monobank'),
            'RESTRICTED_BY_RISKS'             => __('declined by the bank\'s risk rules', 'toloka-monobank'),
            'CLIENT_PUSH_TIMEOUT'             => __('the customer did not confirm within 15 minutes', 'toloka-monobank'),
            'REJECTED_BY_STORE'               => __('cancelled by the shop', 'toloka-monobank'),
            'FAIL'                            => __('internal bank error', 'toloka-monobank'),
        ];
        return $reasons[$code] ?? $code;
    }

    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        echo '<p class="form-row form-row-wide"><label for="toloka_chast_parts">' . esc_html__('Number of payments', 'toloka-monobank') . '</label><select name="toloka_chast_parts" id="toloka_chast_parts">';
        foreach ($this->get_parts() as $p) {
            printf('<option value="%d">%s</option>', $p, esc_html(self::parts_label($p, $this->get_order_total())));
        }
        echo '</select></p>';
    }

    public function validate_fields() {
        if (!in_array((int) ($_POST['toloka_chast_parts'] ?? 0), $this->get_parts(), true)) {
            wc_add_notice(__('Choose the number of payments.', 'toloka-monobank'), 'error');
            return false;
        }
        $phone = isset($_POST['billing_phone']) ? wc_clean(wp_unslash($_POST['billing_phone'])) : (WC()->customer ? WC()->customer->get_billing_phone() : '');
        if (!self::normalize_phone($phone)) {
            wc_add_notice(__('For monobank installments, enter a Ukrainian phone number linked to monobank.', 'toloka-monobank'), 'error');
            return false;
        }
        return true;
    }

    public static function normalize_phone($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) === 12 && strpos($digits, '380') === 0) {
            return '+' . $digits;
        }
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return '+38' . $digits;
        }
        if (strlen($digits) === 9) {
            return '+380' . $digits;
        }
        return '';
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $parts = (int) ($_POST['toloka_chast_parts'] ?? 0);
        if (!in_array($parts, $this->get_parts(), true)) {
            $parts = $this->get_parts()[0];
        }

        // The bank returns the existing application for the same store_order_id, so a retry needs a new one.
        $attempt = (int) $order->get_meta(self::META_ATTEMPT) + 1;
        $order->update_meta_data(self::META_ATTEMPT, $attempt);

        $result = $this->api()->create([
            'store_order_id'     => $order->get_id() . ($attempt > 1 ? '-' . $attempt : ''),
            'client_phone'       => self::normalize_phone($order->get_billing_phone()),
            'total_sum'          => round((float) $order->get_total(), 2),
            'invoice'            => [
                'date'   => $order->get_date_created()->date('Y-m-d'),
                'number' => (string) $order->get_order_number(),
                'source' => 'INTERNET',
            ],
            'available_programs' => [['available_parts_count' => [$parts], 'type' => 'payment_installments']],
            'products'           => $this->get_products($order),
            'result_callback'    => rest_url('toloka/v1/chast'),
        ]);

        if (!$result['ok'] || empty($result['data']['order_id'])) {
            /* translators: 1: HTTP code, 2: error from the bank, 3: bank trace id */
            $order->add_order_note(sprintf(__('Installments: could not create the application (%1$d) %2$s [trace %3$s]', 'toloka-monobank'), $result['code'], $result['error'], $result['trace']));
            wc_add_notice(__('Could not create the installments application.', 'toloka-monobank') . ' ' . ($result['error'] ?: __('Please try again later or choose another payment method.', 'toloka-monobank')), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data(self::META_ID, $result['data']['order_id']);
        $order->update_meta_data(self::META_PARTS, $parts);
        $order->set_transaction_id($result['data']['order_id']);
        /* translators: 1: bank application id, 2: number of payments, e.g. "3 payments" */
        $order->update_status('on-hold', sprintf(__('Installments: application %1$s created (%2$s), waiting for the customer to confirm in the app.', 'toloka-monobank'), $result['data']['order_id'], self::parts_label($parts, 0)));
        WC()->cart->empty_cart();

        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    // Line items must add up to total_sum. If they don't (discounts etc.), send one line for the whole order.
    private function get_products($order) {
        $products = [];
        foreach ($order->get_items() as $item) {
            $line = round((float) $item->get_total() + (float) $item->get_total_tax(), 2);
            $qty  = max(1, (int) $item->get_quantity());
            $unit = round($line / $qty, 2);
            $products[] = abs($unit * $qty - $line) < 0.005
                ? ['name' => $item->get_name(), 'count' => $qty, 'sum' => $unit]
                : ['name' => $item->get_name() . ' × ' . $qty, 'count' => 1, 'sum' => $line];
        }
        $shipping = round((float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2);
        if ($shipping > 0) {
            $products[] = ['name' => __('Shipping', 'toloka-monobank'), 'count' => 1, 'sum' => $shipping];
        }
        foreach ($order->get_fees() as $fee) {
            $products[] = ['name' => $fee->get_name(), 'count' => 1, 'sum' => round((float) $fee->get_total() + (float) $fee->get_total_tax(), 2)];
        }

        $sum = 0;
        foreach ($products as $p) {
            if ($p['sum'] <= 0) {
                $sum = -1;
                break;
            }
            $sum += $p['count'] * $p['sum'];
        }
        if (abs($sum - (float) $order->get_total()) >= 0.01) {
            /* translators: %s: order number */
            $products = [['name' => sprintf(__('Order #%s', 'toloka-monobank'), $order->get_order_number()), 'count' => 1, 'sum' => round((float) $order->get_total(), 2)]];
        }
        return $products;
    }

    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        if ($order && $order->has_status('on-hold')) {
            echo '<p><strong>' . esc_html__('Confirm the installments in the monobank app within 15 minutes.', 'toloka-monobank') . '</strong> '
                . esc_html__('We will get the notification automatically.', 'toloka-monobank') . '</p>';
        }
    }

    public function handle_state($order, $state, $sub_state) {
        $full = $state . '/' . $sub_state;
        if ($full === $order->get_meta(self::META_STATE)) {
            return;
        }
        $order->update_meta_data(self::META_STATE, $full);
        $order->save();

        if ($full === 'IN_PROCESS/WAITING_FOR_STORE_CONFIRM') {
            if (!$order->is_paid()) {
                $order->add_order_note(__('Installments: the customer confirmed. Ship the order and set it to Completed, then the bank confirms the purchase.', 'toloka-monobank'));
                $order->payment_complete($order->get_meta(self::META_ID));
            }
            return;
        }

        if ($state === 'FAIL') {
            /* translators: %s: reason, e.g. "the customer declined" */
            $note = sprintf(__('Installments: declined, %s.', 'toloka-monobank'), self::fail_reason($sub_state));
            if ($order->has_status(['on-hold', 'pending'])) {
                $order->update_status('cancelled', $note);
            } else {
                $order->add_order_note($note);
            }
            return;
        }

        $labels = [
            'IN_PROCESS/WAITING_FOR_CLIENT' => __('waiting for the customer to confirm', 'toloka-monobank'),
            'SUCCESS/ACTIVE'                => __('purchase confirmed, installments are active', 'toloka-monobank'),
            'SUCCESS/DONE'                  => __('the customer has paid in full', 'toloka-monobank'),
            'SUCCESS/RETURNED'              => __('goods returned, money sent back to the customer', 'toloka-monobank'),
        ];
        /* translators: %s: status, e.g. "the customer has paid in full" */
        $order->add_order_note(sprintf(__('Installments: %s.', 'toloka-monobank'), $labels[$full] ?? $full));
    }

    public function sync_order($order) {
        $id = $order->get_meta(self::META_ID);
        if (!$id) {
            return;
        }
        $result = $this->api()->state($id);
        if ($result['ok'] && !empty($result['data']['state'])) {
            $this->handle_state($order, $result['data']['state'], $result['data']['order_sub_state'] ?? '');
        }
    }

    public function on_completed($order) {
        $id = $order->get_meta(self::META_ID);
        if (!$id) {
            return;
        }
        $this->sync_order($order);
        $state = (string) $order->get_meta(self::META_STATE);
        if ($state !== 'IN_PROCESS/WAITING_FOR_STORE_CONFIRM') {
            if (strpos($state, 'SUCCESS/') !== 0) {
                /* translators: %s: bank application state */
                $order->add_order_note(sprintf(__('Installments: WARNING, the order is Completed but the application is in state %s, so the bank was not confirmed.', 'toloka-monobank'), $state ?: __('unknown', 'toloka-monobank')));
            }
            return;
        }
        $result = $this->api()->confirm($id);
        if (!$result['ok']) {
            /* translators: 1: HTTP code, 2: error from the bank, 3: bank trace id */
            $order->add_order_note(sprintf(__('Installments: ERROR confirming (%1$d) %2$s [trace %3$s]. Check the application in your monobank account.', 'toloka-monobank'), $result['code'], $result['error'], $result['trace']));
            return;
        }
        $order->add_order_note(__('Installments: shipping confirmed at monobank.', 'toloka-monobank'));
        if (!empty($result['data']['state'])) {
            $this->handle_state($order, $result['data']['state'], $result['data']['order_sub_state'] ?? '');
        }
    }

    public function on_cancelled($order) {
        $id    = $order->get_meta(self::META_ID);
        $state = (string) $order->get_meta(self::META_STATE);
        if (!$id || strpos($state, 'FAIL/') === 0) {
            return;
        }
        $this->sync_order($order);
        $state = (string) $order->get_meta(self::META_STATE);
        if ($state !== '' && strpos($state, 'IN_PROCESS/') !== 0) {
            return;
        }
        $result = $this->api()->reject($id);
        $order->add_order_note($result['ok']
            ? __('Installments: application cancelled at monobank.', 'toloka-monobank')
            /* translators: 1: HTTP code, 2: error from the bank, 3: bank trace id */
            : sprintf(__('Installments: ERROR cancelling (%1$d) %2$s [trace %3$s].', 'toloka-monobank'), $result['code'], $result['error'], $result['trace']));
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $id    = $order ? $order->get_meta(self::META_ID) : '';
        if (!$id || !$amount) {
            return new WP_Error('toloka_chast', __('No installments application or refund amount.', 'toloka-monobank'));
        }
        if (strpos((string) $order->get_meta(self::META_STATE), 'SUCCESS/') !== 0) {
            $this->sync_order($order);
        }
        if (strpos((string) $order->get_meta(self::META_STATE), 'SUCCESS/') !== 0) {
            return new WP_Error('toloka_chast', __('A refund is only possible after the order is Completed. If you have not shipped yet, cancel the order instead.', 'toloka-monobank'));
        }
        $return_id = $order->get_id() . '-R' . time();
        $result    = $this->api()->return_order($id, $return_id, $amount);
        if (!$result['ok']) {
            return new WP_Error('toloka_chast', sprintf('monobank: %s [trace %s]', $result['error'] ?: $result['code'], $result['trace']));
        }
        /* translators: 1: amount, 2: refund id, 3: refund reason */
        $order->add_order_note(sprintf(__('Installments: refund of %1$s (%2$s) sent to monobank. %3$s', 'toloka-monobank'), wc_price($amount), $return_id, $reason));
        return true;
    }

    public function product_line() {
        global $product;
        if ($this->get_option('show_on_product') !== 'yes' || !$product || !$this->is_ready()) {
            return;
        }
        $price = (float) wc_get_price_to_display($product);
        $parts = $this->get_parts();
        if ($price > 0 && $this->fits_total($price)) {
            /* translators: %s: e.g. "4 payments of ~672 UAH" */
            printf('<p class="toloka-chast-product">%s</p>', esc_html(sprintf(__('monobank installments: %s', 'toloka-monobank'), self::parts_label(end($parts), $price))));
        }
    }
}

// The bank sends a callback when the client confirmed (WAITING_FOR_STORE_CONFIRM) or on failure (FAIL).
add_action('rest_api_init', function () {
    register_rest_route('toloka/v1', '/chast', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            $gateway = toloka_gateway('toloka_chast');
            $body    = $request->get_body();
            if (!$gateway || !$gateway->api()->verify($body, (string) $request->get_header('signature'))) {
                return new WP_REST_Response(['error' => 'bad signature'], 401);
            }
            $data   = json_decode($body, true);
            $orders = empty($data['order_id']) ? [] : wc_get_orders([
                'limit'      => 1,
                'meta_key'   => Toloka_Gateway_Chast::META_ID,
                'meta_value' => $data['order_id'],
            ]);
            if (!$orders) {
                return new WP_REST_Response(['error' => 'order not found'], 404);
            }
            $gateway->handle_state($orders[0], (string) ($data['state'] ?? ''), (string) ($data['order_sub_state'] ?? ''));
            return new WP_REST_Response(['ok' => true], 200);
        },
    ]);
});

// Backup in case a callback is lost: every 5 minutes check orders that are On hold.
add_action(TOLOKA_CHAST_CRON, function () {
    $gateway = toloka_gateway('toloka_chast');
    if (!$gateway) {
        return;
    }
    $orders = wc_get_orders([
        'limit'          => 20,
        'status'         => ['on-hold'],
        'payment_method' => 'toloka_chast',
        'date_created'   => '>' . (time() - DAY_IN_SECONDS),
    ]);
    foreach ($orders as $order) {
        $gateway->sync_order($order);
    }
});

add_action('woocommerce_order_status_completed', function ($order_id, $order) {
    if ($order->get_payment_method() === 'toloka_chast' && ($gateway = toloka_gateway('toloka_chast'))) {
        $gateway->on_completed($order);
    }
}, 10, 2);

add_action('woocommerce_order_status_cancelled', function ($order_id, $order) {
    if ($order->get_payment_method() === 'toloka_chast' && ($gateway = toloka_gateway('toloka_chast'))) {
        $gateway->on_cancelled($order);
    }
}, 10, 2);

add_action('woocommerce_single_product_summary', function () {
    if ($gateway = toloka_gateway('toloka_chast')) {
        $gateway->product_line();
    }
}, 11);
