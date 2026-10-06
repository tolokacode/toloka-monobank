<?php
// monobank installments (Покупка частинами) API client: https://monobank.ua/api-docs/chast

defined('ABSPATH') || exit;

class Toloka_Chast_Api {

    const URLS = [
        'sandbox'    => 'https://u2-demo-ext.mono.st4g3.com',
        'stage'      => 'https://u2-ext.mono.st4g3.com',
        'production' => 'https://u2.monobank.com.ua',
    ];

    private $url;
    private $store_id;
    private $secret;

    public function __construct($env, $store_id, $secret) {
        $this->url      = self::URLS[$env] ?? self::URLS['sandbox'];
        $this->store_id = $store_id;
        $this->secret   = $secret;
    }

    public static function sign($body, $secret) {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    public function verify($body, $signature) {
        return $signature !== '' && hash_equals(self::sign($body, $this->secret), $signature);
    }

    public function create(array $data) {
        return $this->request('/api/order/create', $data);
    }

    public function state($id) {
        return $this->request('/api/order/state', ['order_id' => $id]);
    }

    public function confirm($id) {
        return $this->request('/api/order/confirm', ['order_id' => $id]);
    }

    public function reject($id) {
        return $this->request('/api/order/reject', ['order_id' => $id]);
    }

    public function return_order($id, $return_id, $sum) {
        return $this->request('/api/order/return', [
            'order_id'             => $id,
            'store_return_id'      => $return_id,
            'sum'                  => round((float) $sum, 2),
            'return_money_to_card' => true,
        ]);
    }

    // Returns ['ok' => bool, 'code' => int, 'data' => array, 'error' => string, 'trace' => string].
    private function request($path, array $data) {
        $body     = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = wp_remote_post($this->url . $path, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'store-id'     => $this->store_id,
                'signature'    => self::sign($body, $this->secret),
            ],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            $result = ['ok' => false, 'code' => 0, 'data' => [], 'error' => $response->get_error_message(), 'trace' => ''];
        } else {
            $code   = (int) wp_remote_retrieve_response_code($response);
            $json   = json_decode(wp_remote_retrieve_body($response), true);
            $json   = is_array($json) ? $json : [];
            $result = [
                'ok'    => $code >= 200 && $code < 300,
                'code'  => $code,
                'data'  => $json,
                'error' => $json['message'] ?? '',
                'trace' => (string) wp_remote_retrieve_header($response, 'trace-id'),
            ];
        }

        // Mask the phone number in the log.
        $log_body = preg_replace('/("client_phone":"\+?\d{5})\d+(\d{2}")/', '$1*****$2', $body);
        wc_get_logger()->log($result['ok'] ? 'info' : 'error',
            sprintf('%s %s → %d %s [trace %s]', $path, $log_body, $result['code'], wp_json_encode($result['data'], JSON_UNESCAPED_UNICODE), $result['trace']),
            ['source' => 'toloka-chast']);

        return $result;
    }
}
