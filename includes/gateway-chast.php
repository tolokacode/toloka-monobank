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

    const FAIL_REASONS = [
        'CLIENT_NOT_FOUND'                => 'клієнта не знайдено в monobank',
        'EXCEEDED_SUM_LIMIT'              => 'недостатній ліміт на Покупку частинами',
        'EXISTS_OTHER_OPEN_ORDER'         => 'у клієнта є інша незавершена заявка',
        'NOT_ENOUGH_MONEY_FOR_INIT_DEBIT' => 'недостатньо коштів для першого платежу',
        'REJECTED_BY_CLIENT'              => 'клієнт відхилив заявку',
        'PAY_PARTS_ARE_NOT_ACCEPTABLE'    => 'неприйнятна кількість платежів',
        'FRAUD_REJECTED'                  => 'відхилено антифрод-системою банку',
        'RESTRICTED_BY_RISKS'             => 'обмеження ризик-менеджменту банку',
        'CLIENT_PUSH_TIMEOUT'             => 'клієнт не підтвердив за 15 хвилин',
        'REJECTED_BY_STORE'               => 'скасовано магазином',
        'FAIL'                            => 'внутрішня помилка банку',
    ];

    public function __construct() {
        $this->id                 = 'toloka_chast';
        $this->method_title       = 'Покупка частинами monobank';
        $this->method_description = 'Оплата частинами через API monobank. Статуси: «На утриманні» → «В обробці» → «Виконано» (після відправки товару).';
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
            'enabled'         => ['title' => 'Увімкнути', 'type' => 'checkbox', 'label' => 'Увімкнути Покупку частинами', 'default' => 'no'],
            'title'           => ['title' => 'Назва', 'type' => 'text', 'default' => 'Покупка частинами monobank'],
            'description'     => ['title' => 'Опис', 'type' => 'textarea', 'default' => 'Без переплат. Після оформлення підтвердіть покупку в застосунку monobank.'],
            'environment'     => [
                'title'       => 'Середовище',
                'type'        => 'select',
                'default'     => 'sandbox',
                'options'     => ['sandbox' => 'Пісочниця (тест)', 'stage' => 'Stage (тест з реальним застосунком)', 'production' => 'Продакшн'],
                'description' => 'Пісочниця: Store ID <code>test_store_with_confirm</code>, ключ <code>secret_98765432--123-123</code>. Телефон, що закінчується на 4, — схвалено.',
            ],
            'store_id'        => ['title' => 'Store ID', 'type' => 'text'],
            'store_secret'    => ['title' => 'Секретний ключ', 'type' => 'password'],
            'parts'           => ['title' => 'Кількість платежів', 'type' => 'text', 'default' => '3,4,6', 'description' => 'Через кому, від 3 до 25. Мають відповідати договору з банком.'],
            'min_total'       => ['title' => 'Мінімальна сума, грн', 'type' => 'number', 'default' => '500'],
            'max_total'       => ['title' => 'Максимальна сума, грн', 'type' => 'number', 'default' => '', 'description' => 'Порожньо — без обмеження.'],
            'show_on_product' => ['title' => 'Сторінка товару', 'type' => 'checkbox', 'label' => 'Показувати «N платежів по X ₴» під ціною', 'default' => 'yes'],
        ];
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
        $word = ($parts % 10 >= 2 && $parts % 10 <= 4 && ($parts % 100 < 12 || $parts % 100 > 14)) ? 'платежі' : 'платежів';
        return $sum > 0 ? sprintf('%d %s по ~%s', $parts, $word, wp_strip_all_tags(wc_price($sum / $parts))) : sprintf('%d %s', $parts, $word);
    }

    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        echo '<p class="form-row form-row-wide"><label for="toloka_chast_parts">Кількість платежів</label><select name="toloka_chast_parts" id="toloka_chast_parts">';
        foreach ($this->get_parts() as $p) {
            printf('<option value="%d">%s</option>', $p, esc_html(self::parts_label($p, $this->get_order_total())));
        }
        echo '</select></p>';
    }

    public function validate_fields() {
        if (!in_array((int) ($_POST['toloka_chast_parts'] ?? 0), $this->get_parts(), true)) {
            wc_add_notice('Оберіть кількість платежів.', 'error');
            return false;
        }
        if (!self::normalize_phone(wc_clean(wp_unslash($_POST['billing_phone'] ?? '')))) {
            wc_add_notice('Для Покупки частинами вкажіть український номер телефону, прив\'язаний до monobank.', 'error');
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
            $order->add_order_note(sprintf('Покупка частинами: не вдалося створити заявку (%d) %s [trace %s]', $result['code'], $result['error'], $result['trace']));
            wc_add_notice('Не вдалося створити заявку на Покупку частинами. ' . ($result['error'] ?: 'Спробуйте пізніше або оберіть інший спосіб оплати.'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data(self::META_ID, $result['data']['order_id']);
        $order->update_meta_data(self::META_PARTS, $parts);
        $order->set_transaction_id($result['data']['order_id']);
        $order->update_status('on-hold', sprintf('Покупка частинами: заявку %s створено (%s), чекаємо підтвердження клієнта в застосунку.', $result['data']['order_id'], self::parts_label($parts, 0)));
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
            $products[] = ['name' => 'Доставка', 'count' => 1, 'sum' => $shipping];
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
            $products = [['name' => 'Замовлення №' . $order->get_order_number(), 'count' => 1, 'sum' => round((float) $order->get_total(), 2)]];
        }
        return $products;
    }

    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        if ($order && $order->has_status('on-hold')) {
            echo '<p><strong>Підтвердіть Покупку частинами в застосунку monobank протягом 15 хвилин.</strong> Ми отримаємо сповіщення автоматично.</p>';
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
                $order->add_order_note('Покупка частинами: клієнт підтвердив. Відправте товар і переведіть замовлення у «Виконано» — тоді банк підтвердить покупку.');
                $order->payment_complete($order->get_meta(self::META_ID));
            }
            return;
        }

        if ($state === 'FAIL') {
            $note = 'Покупка частинами: відмова — ' . (self::FAIL_REASONS[$sub_state] ?? $sub_state) . '.';
            if ($order->has_status(['on-hold', 'pending'])) {
                $order->update_status('cancelled', $note);
            } else {
                $order->add_order_note($note);
            }
            return;
        }

        $labels = [
            'IN_PROCESS/WAITING_FOR_CLIENT' => 'чекаємо підтвердження клієнта',
            'SUCCESS/ACTIVE'                => 'покупку підтверджено, розстрочка активна',
            'SUCCESS/DONE'                  => 'клієнт повністю сплатив',
            'SUCCESS/RETURNED'              => 'товар повернено, кошти повернуто клієнту',
        ];
        $order->add_order_note('Покупка частинами: ' . ($labels[$full] ?? $full) . '.');
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
                $order->add_order_note('Покупка частинами: УВАГА — замовлення у «Виконано», але заявка в статусі ' . ($state ?: 'невідомо') . ', банк не підтверджено.');
            }
            return;
        }
        $result = $this->api()->confirm($id);
        if (!$result['ok']) {
            $order->add_order_note(sprintf('Покупка частинами: ПОМИЛКА підтвердження (%d) %s [trace %s]. Перевірте заявку в кабінеті monobank.', $result['code'], $result['error'], $result['trace']));
            return;
        }
        $order->add_order_note('Покупка частинами: відправку товару підтверджено в monobank.');
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
            ? 'Покупка частинами: заявку скасовано в monobank.'
            : sprintf('Покупка частинами: ПОМИЛКА скасування (%d) %s [trace %s].', $result['code'], $result['error'], $result['trace']));
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $id    = $order ? $order->get_meta(self::META_ID) : '';
        if (!$id || !$amount) {
            return new WP_Error('toloka_chast', 'Немає заявки Покупки частинами або суми повернення.');
        }
        if (strpos((string) $order->get_meta(self::META_STATE), 'SUCCESS/') !== 0) {
            $this->sync_order($order);
        }
        if (strpos((string) $order->get_meta(self::META_STATE), 'SUCCESS/') !== 0) {
            return new WP_Error('toloka_chast', 'Повернення можливе лише після «Виконано». Якщо товар ще не відправлено — скасуйте замовлення.');
        }
        $return_id = $order->get_id() . '-R' . time();
        $result    = $this->api()->return_order($id, $return_id, $amount);
        if (!$result['ok']) {
            return new WP_Error('toloka_chast', sprintf('monobank: %s [trace %s]', $result['error'] ?: $result['code'], $result['trace']));
        }
        $order->add_order_note(sprintf('Покупка частинами: повернення %s (%s) відправлено в monobank. %s', wc_price($amount), $return_id, $reason));
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
            printf('<p class="toloka-chast-product">Покупка частинами monobank: %s</p>', esc_html(self::parts_label(end($parts), $price)));
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
