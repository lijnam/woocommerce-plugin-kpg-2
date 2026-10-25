<?php
// Run with KHALTI_E2E_RESULT set to the JSON emitted by e2e.py.
$result = json_decode(getenv('KHALTI_E2E_RESULT'), true);
if (!is_array($result)) {
    throw new RuntimeException('Missing E2E result');
}

$cheap_id = wc_get_order_id_by_order_key($result['cheap']['order_key']);
$expensive_id = wc_get_order_id_by_order_key($result['expensive']['order_key']);
$cheap = wc_get_order($cheap_id);
$expensive = wc_get_order($expensive_id);
if (!$cheap || !$expensive) {
    throw new RuntimeException('Checkout order missing');
}

$payments = get_option('khalti_e2e_payments', array());
$cheap_pidx = $result['cheap']['pidx'];
$expensive_pidx = $result['expensive']['pidx'];
$actual = array(
    'cheap_status' => $cheap->get_status(),
    'expensive_status' => $expensive->get_status(),
    'cheap_currency' => $cheap->get_currency(),
    'expensive_currency' => $expensive->get_currency(),
    'cheap_total' => wc_format_decimal($cheap->get_total(), 2),
    'expensive_total' => wc_format_decimal($expensive->get_total(), 2),
    'expensive_transaction_id' => $expensive->get_transaction_id(),
    'expensive_pidx' => $expensive->get_meta('_khalti_pidx', true),
);

if ('USD' !== get_option('woocommerce_currency')
    || 'pending' !== $actual['cheap_status']
    || !in_array($actual['expensive_status'], wc_get_is_paid_statuses(), true)
    || 'NPR' !== $actual['cheap_currency']
    || 'NPR' !== $actual['expensive_currency']
    || '1300.00' !== $actual['cheap_total']
    || '2600.00' !== $actual['expensive_total']
    || 'khalti' !== $cheap->get_payment_method()
    || 'khalti' !== $expensive->get_payment_method()
    || $payments[$cheap_pidx]['amount'] !== 130000
    || $payments[$expensive_pidx]['amount'] !== 260000
    || $actual['expensive_transaction_id'] !== $payments[$expensive_pidx]['transaction_id']
    || $actual['expensive_pidx'] !== $expensive_pidx
    || $expensive->get_meta('_khalti_txn_id', true) !== $actual['expensive_transaction_id']
) {
    throw new RuntimeException('Order assertion failed: ' . wp_json_encode($actual));
}

echo wp_json_encode($actual) . "\n";
