<?php
/**
 * Cash on delivery with online prepayment through plata by mono.
 *
 * Replaces the standard COD gateway but keeps the "cod" id, which shipping plugins rely on.
 * Prepayment 0 = regular cash on delivery.
 * Statuses: Pending payment (prepayment not paid) → Processing (paid, ready to ship).
 */

defined('ABSPATH') || exit;

class Toloka_Gateway_COD extends WC_Gateway_COD {

    const API          = 'https://api.monobank.ua/api/merchant';
    const META_INVOICE = '_toloka_prepay_invoice';
    const META_AMOUNT  = '_toloka_prepay_amount';
    const META_PAID    = '_toloka_prepay_paid';
    const META_STATUS  = '_toloka_prepay_status';

    public function init_form_fields() {
        parent::init_form_fields();
        $this->form_fields['prepay_type'] = [
            'title'   => 'Передоплата',
            'type'    => 'select',
            'default' => 'fixed',
            'options' => ['fixed' => 'Фіксована сума, грн', 'percent' => 'Відсоток від замовлення, %'],
        ];
        $this->form_fields['prepay_amount'] = [
            'title'       => 'Розмір передоплати',
            'type'        => 'number',
            'default'     => '0',
            'description' => '0 — без передоплати (звичайний накладений платіж).',
        ];
        $this->form_fields['mono_token'] = [
            'title'       => 'Токен plata by mono',
            'type'        => 'password',
            'description' => 'Порожньо — береться токен з офіційного плагіна «plata by mono».',
        ];
    }

    public function get_token() {
        $token = (string) $this->get_option('mono_token');
        if ($token === '') {
            $mono  = get_option('woocommerce_mono_gateway_settings');
            $token = (string) ($mono['API_KEY'] ?? '');
        }
        return $token;
    }

    public function prepay_for($order) {
        $value  = (float) $this->get_option('prepay_amount');
        $total  = (float) $order->get_total();
        $amount = $this->get_option('prepay_type') === 'percent' ? $total * $value / 100 : $value;
        return round(min(max($amount, 0), $total), 2);
    }

    public function is_available() {
        if (!parent::is_available()) {
            return false;
        }
        return (float) $this->get_option('prepay_amount') <= 0 || ($this->get_token() !== '' && get_woocommerce_currency() === 'UAH');
    }

    public function process_payment($order_id) {
        $order  = wc_get_order($order_id);
        $prepay = $this->prepay_for($order);
        if ($prepay <= 0) {
            return parent::process_payment($order_id);
        }

        // Retry from the "Pay for order" page: the old invoice may already be paid.
        if ($old = $order->get_meta(self::META_INVOICE)) {
            $this->sync($order);
            if ($order->get_meta(self::META_PAID) === 'yes') {
                return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
            }
            $this->api('POST', '/invoice/remove', ['invoiceId' => $old]);
        }

        $result = $this->api('POST', '/invoice/create', [
            'amount'           => (int) round($prepay * 100),
            'ccy'              => 980,
            'merchantPaymInfo' => [
                'reference'   => (string) $order->get_id(),
                'destination' => 'Передоплата за замовлення №' . $order->get_order_number(),
            ],
            'redirectUrl'      => $this->get_return_url($order),
            'webHookUrl'       => add_query_arg('wc-api', 'toloka_prepay', home_url('/')),
            'validity'         => 3600,
        ]);

        if (empty($result['data']['invoiceId']) || empty($result['data']['pageUrl'])) {
            $order->add_order_note(sprintf('Передоплата: не вдалося створити рахунок (%d) %s', $result['code'], $result['data']['errText'] ?? ''));
            wc_add_notice('Не вдалося створити рахунок на передоплату. Спробуйте ще раз або оберіть інший спосіб оплати.', 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data(self::META_INVOICE, $result['data']['invoiceId']);
        $order->update_meta_data(self::META_AMOUNT, $prepay);
        $order->update_meta_data(self::META_PAID, 'no');
        $order->delete_meta_data(self::META_STATUS);
        $order->add_order_note(sprintf('Передоплата: рахунок %s на %s створено, чекаємо оплату.', $result['data']['invoiceId'], wc_price($prepay)));
        $order->save();

        return ['result' => 'success', 'redirect' => $result['data']['pageUrl']];
    }

    // The status always comes from the bank API, so webhook data doesn't need to be trusted.
    public function sync($order) {
        $invoice = $order->get_meta(self::META_INVOICE);
        if (!$invoice || $order->get_meta(self::META_PAID) === 'yes') {
            return;
        }
        $result = $this->api('GET', '/invoice/status?invoiceId=' . rawurlencode($invoice));
        $status = (string) ($result['data']['status'] ?? '');
        if ($status === '' || $status === $order->get_meta(self::META_STATUS)) {
            return;
        }
        $order->update_meta_data(self::META_STATUS, $status);
        $order->save();

        $expected = (int) round((float) $order->get_meta(self::META_AMOUNT) * 100);
        if ($status === 'success' && (int) ($result['data']['amount'] ?? 0) === $expected) {
            $order->update_meta_data(self::META_PAID, 'yes');
            $order->add_order_note(sprintf('Передоплату %s отримано. При отриманні (накладений платіж): %s.', wc_price($expected / 100), wc_price(self::rest_for($order))));
            $order->payment_complete($invoice);
        } elseif (in_array($status, ['failure', 'expired', 'reversed'], true)) {
            $reason = $result['data']['failureReason'] ?? '';
            $order->add_order_note('Передоплата: рахунок у статусі ' . $status . ($reason ? ' — ' . $reason : '') . '.');
        }
    }

    public static function rest_for($order) {
        return max(0, round((float) $order->get_total() - (float) $order->get_meta(self::META_AMOUNT), 2));
    }

    // Core COD sets Completed on payment_complete; after a prepayment the order must be Processing.
    public function change_payment_complete_order_status($status, $order_id = 0, $order = false) {
        return $status;
    }

    public function thankyou_page($order_id = 0) {
        $order = wc_get_order($order_id);
        if ($order && $order->get_meta(self::META_INVOICE)) {
            $this->sync($order);
            $amount = wc_price((float) $order->get_meta(self::META_AMOUNT));
            if ($order->get_meta(self::META_PAID) === 'yes') {
                printf('<p><strong>Передоплату %s отримано.</strong> При отриманні сплатите %s.</p>', $amount, wc_price(self::rest_for($order)));
            } else {
                printf('<p><strong>Передоплату ще не отримано.</strong> Замовлення буде прийнято в роботу після оплати %s.</p><p><a class="button" href="%s">Сплатити передоплату</a></p>',
                    $amount, esc_url($order->get_checkout_payment_url()));
            }
        }
        parent::thankyou_page();
    }

    private function api($method, $path, $body = null) {
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => ['X-Token' => $this->get_token(), 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $response = wp_remote_request(self::API . $path, $args);
        $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $data     = is_wp_error($response) ? ['errText' => $response->get_error_message()] : json_decode(wp_remote_retrieve_body($response), true);
        $data     = is_array($data) ? $data : [];

        wc_get_logger()->log($code >= 200 && $code < 300 ? 'info' : 'error',
            sprintf('%s %s %s → %d %s', $method, $path, $args['body'] ?? '', $code, wp_json_encode($data, JSON_UNESCAPED_UNICODE)),
            ['source' => 'toloka-prepay']);

        return ['code' => $code, 'data' => $data];
    }
}

add_action('woocommerce_api_toloka_prepay', function () {
    $data    = json_decode((string) file_get_contents('php://input'), true);
    $gateway = toloka_gateway('cod');
    if ($gateway instanceof Toloka_Gateway_COD && !empty($data['invoiceId'])) {
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => Toloka_Gateway_COD::META_INVOICE, 'meta_value' => $data['invoiceId']]);
        if ($orders) {
            $gateway->sync($orders[0]);
        }
    }
    status_header(200);
    exit;
});

// WooCommerce 9.3+ shows the COD settings on a new page with standard fields only.
// Use the classic page so the prepayment fields show up.
add_filter('experimental_woocommerce_admin_payment_reactify_render_sections', function ($sections) {
    return array_values(array_diff((array) $sections, ['cod']));
});

// Prepayment and "pay on delivery" rows on the thank-you page, in emails and in My account.
add_filter('woocommerce_get_order_item_totals', function ($rows, $order) {
    $amount = (float) $order->get_meta(Toloka_Gateway_COD::META_AMOUNT);
    if ($amount <= 0) {
        return $rows;
    }
    $paid  = $order->get_meta(Toloka_Gateway_COD::META_PAID) === 'yes';
    $extra = [
        'toloka_prepay' => ['label' => 'Передоплата онлайн:', 'value' => wc_price($amount) . ($paid ? ' (сплачено)' : ' (не сплачено)')],
        'toloka_rest'   => ['label' => 'При отриманні:', 'value' => wc_price(Toloka_Gateway_COD::rest_for($order))],
    ];
    $pos = array_search('order_total', array_keys($rows), true);
    return $pos === false ? $rows + $extra : array_slice($rows, 0, $pos + 1, true) + $extra + array_slice($rows, $pos + 1, null, true);
}, 10, 2);

// Same rows in the admin order view, so the manager knows the amount for the Nova Poshta waybill.
add_action('woocommerce_admin_order_totals_after_total', function ($order_id) {
    $order  = wc_get_order($order_id);
    $amount = $order ? (float) $order->get_meta(Toloka_Gateway_COD::META_AMOUNT) : 0;
    if ($amount <= 0) {
        return;
    }
    $paid = $order->get_meta(Toloka_Gateway_COD::META_PAID) === 'yes';
    printf('<tr><td class="label">Передоплата онлайн:</td><td width="1%%"></td><td class="total">%s %s</td></tr>', wc_price($amount), $paid ? '✅' : '❌ не сплачено');
    printf('<tr><td class="label"><strong>Накладений платіж (ТТН):</strong></td><td width="1%%"></td><td class="total"><strong>%s</strong></td></tr>', wc_price(Toloka_Gateway_COD::rest_for($order)));
});
