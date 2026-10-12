<?php

defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', __('monobank installments report', 'toloka-monobank'), __('monobank report', 'toloka-monobank'), 'manage_woocommerce', 'toloka-chast-report', 'toloka_chast_report_page');
}, 60);

function toloka_chast_report_date() {
    $date = sanitize_text_field(wp_unslash($_GET['date'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : wp_date('Y-m-d', strtotime('-1 day'));
}

function toloka_chast_report_rows($date) {
    $gateway = toloka_gateway('toloka_chast');
    if (!$gateway || !$gateway->is_ready()) {
        return new WP_Error('toloka_chast', __('Set up monobank installments first.', 'toloka-monobank'));
    }
    $result = $gateway->api()->report($date);
    if (!$result['ok']) {
        return new WP_Error('toloka_chast', $result['error'] ?: (string) $result['code']);
    }
    return $result['data']['orders'] ?? [];
}

function toloka_chast_report_page() {
    $date = toloka_chast_report_date();
    $rows = toloka_chast_report_rows($date);
    $csv  = wp_nonce_url(admin_url('admin-post.php?action=toloka_chast_report_csv&date=' . $date), 'toloka_chast_report_csv');

    echo '<div class="wrap">';
    toloka_monobank_header();
    echo '<div class="toloka-ui"><h1>' . esc_html__('monobank installments report', 'toloka-monobank') . '</h1>';
    echo '<form method="get"><input type="hidden" name="page" value="toloka-chast-report">';
    printf('<input type="date" name="date" value="%s"> <button class="button">%s</button> <a class="button" href="%s">%s</a></form>',
        esc_attr($date), esc_html__('Show', 'toloka-monobank'), esc_url($csv), esc_html__('Download CSV', 'toloka-monobank'));

    if (is_wp_error($rows)) {
        echo '<div class="notice notice-error"><p>' . esc_html($rows->get_error_message()) . '</p></div></div></div>';
        return;
    }
    if (!$rows) {
        echo '<p>' . esc_html__('No operations on this day.', 'toloka-monobank') . '</p></div></div>';
        return;
    }

    echo '<table class="widefat striped" style="margin-top:16px"><thead><tr>';
    foreach (toloka_chast_report_columns() as $title) {
        echo '<th>' . esc_html($title) . '</th>';
    }
    echo '</tr></thead><tbody>';
    $sum = ['total_sum' => 0, 'commission' => 0, 'transferred_sum' => 0];
    foreach ($rows as $row) {
        $order = wc_get_order($row['invoice_number']);
        $label = esc_html($row['invoice_number']);
        if ($order) {
            $label = '<a href="' . esc_url($order->get_edit_order_url()) . '">' . $label . '</a>';
        }
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s%%</td><td>%s</td><td>%s</td></tr>',
            esc_html($row['order_date']), wp_kses_post($label), esc_html($row['odb_contract_number']), (int) $row['pay_parts'],
            wp_kses_post(wc_price($row['total_sum'])), esc_html($row['commission_percent']),
            wp_kses_post(wc_price($row['commission'])), wp_kses_post(wc_price($row['transferred_sum'])));
        foreach ($sum as $key => $value) {
            $sum[$key] = $value + (float) $row[$key];
        }
    }
    printf('</tbody><tfoot><tr><th colspan="4">%s</th><th>%s</th><th></th><th>%s</th><th>%s</th></tr></tfoot></table></div></div>',
        esc_html__('Total', 'toloka-monobank'), wp_kses_post(wc_price($sum['total_sum'])),
        wp_kses_post(wc_price($sum['commission'])), wp_kses_post(wc_price($sum['transferred_sum'])));
}

function toloka_chast_report_columns() {
    return [
        __('Date', 'toloka-monobank'),
        __('Order', 'toloka-monobank'),
        __('Contract', 'toloka-monobank'),
        __('Payments', 'toloka-monobank'),
        __('Amount', 'toloka-monobank'),
        __('Commission, %', 'toloka-monobank'),
        __('Commission', 'toloka-monobank'),
        __('Paid to the shop', 'toloka-monobank'),
    ];
}

add_action('admin_post_toloka_chast_report_csv', function () {
    check_admin_referer('toloka_chast_report_csv');
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Not allowed.', 'toloka-monobank'));
    }
    $date = toloka_chast_report_date();
    $rows = toloka_chast_report_rows($date);
    if (is_wp_error($rows)) {
        wp_die(esc_html($rows->get_error_message()));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="monobank-report-' . $date . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, toloka_chast_report_columns());
    foreach ($rows as $row) {
        fputcsv($out, [$row['order_date'], $row['invoice_number'], $row['odb_contract_number'], $row['pay_parts'],
            $row['total_sum'], $row['commission_percent'], $row['commission'], $row['transferred_sum']]);
    }
    exit;
});
