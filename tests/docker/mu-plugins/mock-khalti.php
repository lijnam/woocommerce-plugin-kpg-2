<?php
/**
 * Local Khalti API substitute for the Docker E2E stack only.
 * It intercepts outgoing WordPress HTTP requests before a network call.
 */
add_filter('pre_http_request', function ($preempt, $args, $url) {
    $parts = wp_parse_url($url);
    if (!is_array($parts)
        || !isset($parts['host'], $parts['path'])
        || !in_array($parts['host'], array('dev.khalti.com', 'khalti.com'), true)
    ) {
        return $preempt;
    }

    $payload = json_decode(isset($args['body']) ? $args['body'] : '', true);
    $payments = get_option('khalti_e2e_payments', array());
    if (!is_array($payments)) {
        $payments = array();
    }

    if ($parts['path'] === '/api/v2/epayment/initiate/') {
        if (!is_array($payload)
            || !isset($payload['purchase_order_id'], $payload['amount'])
            || !is_int($payload['amount'])
        ) {
            return khalti_e2e_response(array('detail' => 'Invalid initiation payload'), 400);
        }
        $pidx = 'e2e-' . substr(hash('sha256', $payload['purchase_order_id'] . microtime(true) . wp_rand()), 0, 24);
        $payments[$pidx] = array(
            'order_key' => $payload['purchase_order_id'],
            'amount' => $payload['amount'],
            'transaction_id' => 'txn-' . substr($pidx, 4),
        );
        update_option('khalti_e2e_payments', $payments, false);
        $payment_url = add_query_arg(array(
            'pidx' => $pidx,
            'purchase_order_id' => $payload['purchase_order_id'],
            'purchase_order_name' => $payload['purchase_order_name'],
            'amount' => $payload['amount'],
        ), 'https://pay.khalti.com/e2e');
        return khalti_e2e_response(array('pidx' => $pidx, 'payment_url' => $payment_url));
    }

    if ($parts['path'] === '/api/v2/epayment/lookup/') {
        $pidx = is_array($payload) && isset($payload['pidx']) ? $payload['pidx'] : '';
        if (!isset($payments[$pidx])) {
            return khalti_e2e_response(array('detail' => 'Not found'), 404);
        }
        return khalti_e2e_response(array(
            'pidx' => $pidx,
            'total_amount' => $payments[$pidx]['amount'],
            'status' => 'Completed',
            'transaction_id' => $payments[$pidx]['transaction_id'],
            'fee' => 0,
            'refunded' => false,
        ));
    }

    return $preempt;
}, 10, 3);

function khalti_e2e_response($body, $status = 200)
{
    return array(
        'headers' => array('content-type' => 'application/json'),
        'body' => wp_json_encode($body),
        'response' => array('code' => $status, 'message' => $status === 200 ? 'OK' : 'Error'),
        'cookies' => array(),
        'filename' => null,
    );
}
