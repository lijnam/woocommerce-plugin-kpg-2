<?php
// Run with: php tests/run.php. These stubs exercise the real gateway code without WordPress.
define('ABSPATH', __DIR__);
define('WC_KHALTI_PLUGIN_FILE', dirname(__DIR__) . '/woocommerce-khalti.php');

$GLOBALS['currency'] = 'NPR';
$GLOBALS['options'] = array(
    'merchant_secret' => 'secret', 'sandbox_merchant_secret' => 'sandbox',
    'send_amount_breakdown' => 'yes', 'send_customer_info' => 'yes'
);
$GLOBALS['hooks'] = array();
$GLOBALS['orders'] = array();
$GLOBALS['notices'] = array();
$GLOBALS['api_response'] = null;
$GLOBALS['last_request'] = null;
$GLOBALS['mail'] = array();

class WP_Error
{
    public function get_error_message() { return 'Network unavailable'; }
}

class WC_Payment_Gateway
{
    public $enabled = 'yes';
    public function init_settings() { $this->enabled = 'yes'; }
    public function is_available() { return $this->enabled === 'yes'; }
    public function admin_options() { $GLOBALS['admin_options_rendered'] = true; }
    public function get_option($name, $default = '') {
        return isset($GLOBALS['options'][$name]) ? $GLOBALS['options'][$name] : $default;
    }
}

class FakeCart
{
    public $emptied = false;
    public function empty_cart() { $this->emptied = true; }
}

class FakeWoo
{
    public $cart;
    public function __construct() { $this->cart = new FakeCart(); }
    public function api_request_url($name) { return 'https://shop.example/wc-api/' . $name; }
}

class FakeOrder
{
    public $id;
    public $key;
    public $total;
    public $currency = 'NPR';
    public $method = 'khalti';
    public $meta = array();
    public $saved = 0;
    public $status = 'pending';
    public $completed_with = null;
    public $complete_calls = 0;
    public $notes = array();
    public $discount = '0';
    public $shipping = '0';
    public $tax = '0';
    public $items = array();

    public function __construct($id, $key, $total) {
        $this->id = $id;
        $this->key = $key;
        $this->total = $total;
        $GLOBALS['orders'][$id] = $this;
    }
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_order_key() { return $this->key; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function get_payment_method() { return $this->method; }
    public function get_meta($name, $single = true) {
        return isset($this->meta[$name]) ? $this->meta[$name] : '';
    }
    public function update_meta_data($name, $value) { $this->meta[$name] = $value; }
    public function save() { $this->saved++; }
    public function get_items() { return $this->items; }
    public function get_discount_total() { return $this->discount; }
    public function get_shipping_total() { return $this->shipping; }
    public function get_total_tax() { return $this->tax; }
    public function get_billing_first_name() { return 'Test'; }
    public function get_billing_last_name() { return 'Buyer'; }
    public function get_billing_email() { return 'buyer@example.test'; }
    public function get_billing_phone() { return '9800000000'; }
    public function has_status($statuses) { return in_array($this->status, (array) $statuses, true); }
    public function update_status($status, $note = '', $manual = false) { $this->status = $status; }
    public function add_order_note($note) { $this->notes[] = $note; }
    public function payment_complete($txn_id = '') {
        if (in_array($this->status, array('pending', 'failed', 'on-hold', 'cancelled'), true)) {
            $this->completed_with = $txn_id;
            $this->complete_calls++;
            $this->status = 'processing';
        }
    }
}

class FakeProduct
{
    public function get_id() { return 25; }
    public function get_name() { return 'Item'; }
    public function is_virtual($value = '') { return false; }
}

class FakeItem
{
    public function get_product() { return new FakeProduct(); }
    public function get_quantity() { return 1; }
    public function get_total() { return '19.99'; }
}

class FakeDeletedItem extends FakeItem
{
    public function get_product() { return false; }
    public function get_product_id() { return 26; }
    public function get_name() { return 'Deleted item'; }
}

class FakeZeroItem extends FakeDeletedItem
{
    public function get_quantity() { return 0; }
}

function add_action($name, $callback) { $GLOBALS['hooks'][$name][] = $callback; }
function apply_filters($name, $value) { return $value; }
function get_woocommerce_currency() { return $GLOBALS['currency']; }
function plugins_url($path, $file) { return 'https://shop.example/' . $path; }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return $text; }
function wp_upload_dir() { return array('basedir' => '/tmp'); }
function admin_url($path) { return 'https://shop.example/' . $path; }
function get_site_url() { return 'https://shop.example'; }
function WC() { return $GLOBALS['woo']; }
function wc_print_r($value, $return = false) { return print_r($value, true); }
function wc_clean($value) { return trim((string) $value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_post($url, $args) {
    $GLOBALS['last_request'] = array('url' => $url, 'args' => $args);
    return $GLOBALS['api_response'];
}
function wp_remote_retrieve_body($response) {
    return is_array($response) ? $response['body'] : '';
}
function wp_remote_retrieve_response_code($response) {
    return is_array($response) ? $response['code'] : 0;
}
function wc_add_notice($message, $type) { $GLOBALS['notices'][] = array($type, $message); }
function wp_strip_all_tags($message) { return strip_tags($message); }
function current_time($format) { return '2026-10-25 12:00:00'; }
function get_option($name) { return $name === 'admin_email' ? 'admin@example.test' : 'UTC'; }
function wp_mail($to, $subject, $message) {
    $GLOBALS['mail'][] = array($to, $subject, $message);
    return true;
}
function wc_get_order_id_by_order_key($key) {
    foreach ($GLOBALS['orders'] as $order) {
        if ($order->key === $key) { return $order->id; }
    }
    return 0;
}
function wc_get_order($id) { return isset($GLOBALS['orders'][$id]) ? $GLOBALS['orders'][$id] : false; }
function wc_get_is_paid_statuses() { return array('processing', 'completed'); }
function sanitize_text_field($value) { return trim((string) $value); }

require dirname(__DIR__) . '/includes/class-wc-gateway-khalti.php';
require dirname(__DIR__) . '/includes/gateways/class-wc-gateway-khalti-request.php';
require dirname(__DIR__) . '/includes/gateways/class-wc-gateway-khalti-ipn-handler.php';

$GLOBALS['woo'] = new FakeWoo();
$failures = array();
$checks = 0;
function check($condition, $description) {
    global $failures, $checks;
    $checks++;
    if (!$condition) { $failures[] = $description; }
}
function api_reply($body, $code = 200) {
    $GLOBALS['api_response'] = array('code' => $code, 'body' => json_encode($body));
}

// On a USD store, a cookieless Khalti return must still have a callback handler.
$GLOBALS['currency'] = 'USD';
$usd_gateway = new WC_Gateway_Khalti();
check($usd_gateway->enabled === 'yes', 'Khalti stays enabled on a USD store');
check($usd_gateway->is_valid_for_use(), 'Khalti configuration valid on a USD store');
check(!$usd_gateway->is_available(), 'Khalti hidden for USD checkout');
ob_start();
$usd_gateway->admin_options();
$admin_options = ob_get_clean();
check(!empty($GLOBALS['admin_options_rendered']), 'Khalti settings render on a USD store');
check(strpos($admin_options, 'only when the order currency is NPR') !== false, 'USD store guidance shown');
check(!empty($GLOBALS['hooks']['woocommerce_api_wc_gateway_khalti']), 'USD callback remains registered');
$GLOBALS['currency'] = 'NPR';
$gateway = new WC_Gateway_Khalti();
check($gateway->enabled === 'yes', 'Khalti shown for NPR checkout');
check($gateway->is_valid_for_use(), 'Khalti configuration valid for NPR checkout');
check($gateway->is_available(), 'Khalti available for NPR checkout');
check($gateway->payment_request_api_endpoint === 'https://khalti.com/api/v2/epayment/initiate/', 'live endpoint');
$GLOBALS['options']['testmode'] = 'yes';
$test_gateway = new WC_Gateway_Khalti();
check($test_gateway->payment_request_api_endpoint === 'https://dev.khalti.com/api/v2/epayment/initiate/', 'documented sandbox endpoint');
unset($GLOBALS['options']['testmode']);

$order = new FakeOrder(1, 'order-key-1', '19.99');
$order->discount = '1.01';
$order->shipping = '1.23';
$order->tax = '0.45';
$order->items = array(new FakeItem());
$request = new WC_Gateway_Khalti_Request($gateway);
api_reply(array('pidx' => 'paid-pidx', 'payment_url' => 'https://pay.khalti.com/checkout'));
$result = $request->get_request_url($order);
$body = json_decode($GLOBALS['last_request']['args']['body'], true);
check($result['result'] === 'success', 'initiation succeeds');
check($body['amount'] === 1999, 'decimal NPR total converts to integer paisa');
check($order->get_meta('_khalti_pending_payments')['paid-pidx'] === 1999, 'initiated pidx and amount saved');
check($order->saved > 0, 'payment attempt persisted before redirect');
check(array_sum(array_column($body['amount_breakdown'], 'amount')) === 1999, 'amount breakdown sums exactly');
check($body['product_details'][0]['unit_price'] === 1999, 'unit price uses integer paisa');
check($body['product_details'][0]['total_price'] === 1999, 'item total uses integer paisa');
check($body['customer_info']['name'] === 'Test Buyer', 'optional customer information included');

$deleted_order = new FakeOrder(3, 'order-key-3', '19.99');
$deleted_order->items = array(new FakeDeletedItem(), new FakeZeroItem());
$GLOBALS['options']['send_amount_breakdown'] = 'no';
$GLOBALS['options']['send_customer_info'] = 'no';
$minimal_gateway = new WC_Gateway_Khalti();
$minimal_request = new WC_Gateway_Khalti_Request($minimal_gateway);
api_reply(array('pidx' => 'deleted-item-pidx', 'payment_url' => 'https://pay.khalti.com/deleted'));
check($minimal_request->get_request_url($deleted_order)['result'] === 'success', 'deleted product does not stop initiation');
$minimal_body = json_decode($GLOBALS['last_request']['args']['body'], true);
check($minimal_body['product_details'][0]['identity'] === 26, 'deleted product uses order item identity');
check(count($minimal_body['product_details']) === 1, 'zero-quantity item skipped');
check(!isset($minimal_body['amount_breakdown']), 'optional breakdown can be disabled');
check(!isset($minimal_body['customer_info']), 'optional customer information can be disabled');
$GLOBALS['options']['send_amount_breakdown'] = 'yes';
$GLOBALS['options']['send_customer_info'] = 'yes';

$handler = new WC_Gateway_Khalti_IPN_Handler($gateway);
$callback = array(
    'pidx' => 'paid-pidx', 'amount' => '1999',
    'purchase_order_id' => $order->key, 'purchase_order_name' => $order->key
);
$completed = array(
    'pidx' => 'paid-pidx', 'total_amount' => 1999,
    'status' => 'Completed', 'transaction_id' => 'txn-1', 'refunded' => false
);
api_reply($completed);
check(is_array($handler->validate_payment($callback)), 'matching NPR payment verifies');
check($GLOBALS['last_request']['url'] === $gateway->payment_lookup_api_endpoint, 'lookup uses configured endpoint');

$cases = array(
    'different callback pidx' => array(array('pidx' => 'other-pidx'), array()),
    'different lookup pidx' => array(array(), array('pidx' => 'other-pidx')),
    'underpayment' => array(array(), array('total_amount' => 1000)),
    'overpayment' => array(array(), array('total_amount' => 2000)),
    'tampered callback amount' => array(array('amount' => '1000'), array()),
    'wrong purchase order id' => array(array('purchase_order_id' => 'other'), array()),
    'pending payment' => array(array(), array('status' => 'Pending')),
    'refunded payment' => array(array(), array('refunded' => true)),
    'missing lookup amount' => array(array(), array('total_amount' => null)),
    'fractional lookup amount' => array(array(), array('total_amount' => 1999.5)),
    'missing transaction id' => array(array(), array('transaction_id' => null)),
    'blank transaction id' => array(array(), array('transaction_id' => '')),
);
foreach ($cases as $name => $changes) {
    api_reply(array_merge($completed, $changes[1]));
    check($handler->validate_payment(array_merge($callback, $changes[0])) === false, $name . ' rejected');
}
$order->total = '20.00';
api_reply($completed);
check($handler->validate_payment($callback) === false, 'changed order total rejected');
$order->total = '19.99';
$order->currency = 'USD';
check($handler->validate_payment($callback) === false, 'USD order rejected');
$order->currency = 'NPR';
$order->method = 'cod';
check($handler->validate_payment($callback) === false, 'other gateway order rejected');
$order->method = 'khalti';
$order->status = 'cancelled';
api_reply($completed);
check(is_array($handler->validate_payment($callback)), 'verified payment for previously cancelled order remains valid');
$order->status = 'pending';
api_reply($completed, 500);
check($handler->validate_payment($callback) === false, 'lookup HTTP error rejected');
$GLOBALS['api_response'] = new WP_Error();
check($handler->validate_payment($callback) === false, 'lookup network error rejected');
api_reply(array('status' => 'Completed'));
check($handler->validate_payment($callback) === false, 'malformed lookup rejected');
$other_order = new FakeOrder(2, 'order-key-2', '19.99');
api_reply($completed);
$other_callback = array_merge($callback, array(
    'purchase_order_id' => $other_order->key,
    'purchase_order_name' => $other_order->key
));
check($handler->validate_payment($other_callback) === false, 'completed pidx cannot pay another order');

// A second initiation keeps the first valid because the customer may pay either tab.
api_reply(array('pidx' => 'second-pidx', 'payment_url' => 'https://pay.khalti.com/second'));
$request->get_request_url($order);
api_reply($completed);
check(is_array($handler->validate_payment($callback)), 'earlier completed attempt remains valid');
check(count($order->get_meta('_khalti_pending_payments')) === 2, 'both attempts recorded');

$GLOBALS['notices'] = array();
api_reply(array('detail' => 'Invalid token.'), 401);
$failure = $request->get_request_url($order);
check($failure['result'] === 'failure', 'initiation HTTP error returns failure');
check(count($GLOBALS['notices']) === 1, 'initiation error displays checkout notice');
check(count($order->get_meta('_khalti_pending_payments')) === 2, 'failed retry preserves prior attempts');
$GLOBALS['notices'] = array();
$GLOBALS['api_response'] = new WP_Error();
check($request->get_request_url($order)['result'] === 'failure', 'initiation network error returns failure');
check(count($GLOBALS['notices']) === 1, 'network error displays notice');
api_reply(array('pidx' => 'missing-url'));
check($request->get_request_url($order)['result'] === 'failure', 'malformed initiation rejected');
api_reply(array('pidx' => array('bad'), 'payment_url' => 'https://pay.khalti.com/bad'));
check($request->get_request_url($order)['result'] === 'failure', 'non-string pidx rejected');
api_reply(array('pidx' => 'bad-url', 'payment_url' => 'http://pay.khalti.com/bad'));
check($request->get_request_url($order)['result'] === 'failure', 'insecure payment URL rejected');

// Completing the verified payment records the identifiers and is safe to repeat.
$complete = new ReflectionMethod($handler, 'payment_status_completed');
$complete->invoke($handler, $order, $completed);
check($order->completed_with === 'txn-1', 'verified payment completes order');
check($order->get_meta('_khalti_pidx') === 'paid-pidx', 'paid pidx recorded');
check($order->get_meta('_khalti_txn_id') === 'txn-1', 'transaction id recorded');
$saved_after_completion = $order->saved;
$complete->invoke($handler, $order, $completed);
check($order->saved === $saved_after_completion, 'same callback is idempotent');
check($order->complete_calls === 1, 'Woo payment_complete called once');
$second_payment = array_merge($completed, array('pidx' => 'second-pidx', 'transaction_id' => 'txn-2'));
$complete->invoke($handler, $order, $second_payment);
check($order->complete_calls === 1, 'second charge does not complete order twice');
check($order->get_meta('_khalti_additional_payments')['second-pidx'] === 'txn-2', 'second charge recorded for review');
check(count($GLOBALS['mail']) > 0, 'second charge alerts administrator');
$mail_count = count($GLOBALS['mail']);
$complete->invoke($handler, $order, $second_payment);
check(count($GLOBALS['mail']) === $mail_count, 'duplicate callback for second charge alerts once');
api_reply($completed);
$order->status = 'refunded';
check($handler->validate_payment($callback) === false, 'old payment cannot reopen refunded order');
$order->status = 'processing';

$GLOBALS['last_request'] = null;
$order->currency = 'USD';
$GLOBALS['notices'] = array();
check($request->get_request_url($order)['result'] === 'failure', 'USD order cannot initiate Khalti');
check($GLOBALS['last_request'] === null, 'USD order sends no Khalti API request');
check(count($GLOBALS['notices']) === 1, 'USD order shows checkout error');
$order->currency = 'NPR';

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo $checks . " checks passed\n";
