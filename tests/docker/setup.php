<?php
// Run with: wp eval-file wp-content/plugins/integrate-khalti-in-wc-store/tests/docker/setup.php
update_option('woocommerce_currency', 'USD');
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_ship_to_countries', 'disabled');
update_option('woocommerce_onboarding_profile', array('completed' => true));
update_option('khalti_e2e_payments', array());

update_option('woo_multi_currency_params', array(
    'enable' => 1,
    'currency_default' => 'USD',
    'currency' => array('USD', 'NPR'),
    'currency_rate' => array(1, 130),
    'currency_rate_fee' => array(0, 0),
    'currency_hidden' => array(0, 0),
    'currency_decimals' => array(2, 2),
    'currency_custom' => array('', ''),
    'currency_pos' => array('left', 'left'),
    'auto_detect' => 1,
    'geo_api' => 2,
    'enable_multi_payment' => 1,
));

update_option('woocommerce_khalti_settings', array(
    'enabled' => 'yes',
    'title' => 'Khalti',
    'description' => 'Docker test payment',
    'testmode' => 'yes',
    'sandbox_merchant_secret' => 'local-test-secret',
    'send_customer_info' => 'yes',
    'send_amount_breakdown' => 'yes',
    'debug' => 'no',
    'ipn_notification' => 'no',
));

// The USD store must still be able to enable and configure the gateway in
// WooCommerce admin; currency filtering belongs at checkout only.
$admin_gateway = new WC_Gateway_Khalti();
if ('yes' !== $admin_gateway->enabled || !$admin_gateway->is_valid_for_use()
    || $admin_gateway->is_available() || $admin_gateway->needs_setup()) {
    throw new RuntimeException('Khalti admin state is incorrect for a configured USD store');
}
ob_start();
$admin_gateway->admin_options();
$admin_html = ob_get_clean();
if (false === strpos($admin_html, 'woocommerce_khalti_enabled')) {
    throw new RuntimeException('Khalti settings cannot be edited on a USD store');
}

$checkout_page_id = wp_insert_post(array(
    'post_title' => 'Checkout',
    'post_name' => 'checkout',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => '[woocommerce_checkout]',
));
update_option('woocommerce_checkout_page_id', $checkout_page_id);

$cart_page_id = wp_insert_post(array(
    'post_title' => 'Cart',
    'post_name' => 'cart',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => '[woocommerce_cart]',
));
update_option('woocommerce_cart_page_id', $cart_page_id);

$products = array();
foreach (array('cheap' => '10.00', 'expensive' => '20.00') as $name => $price) {
    $product = new WC_Product_Simple();
    $product->set_name('Docker ' . $name . ' USD product');
    $product->set_regular_price($price);
    $product->set_virtual(true);
    $product->set_status('publish');
    $products[$name] = $product->save();
}

echo wp_json_encode(array(
    'cheap_product_id' => $products['cheap'],
    'expensive_product_id' => $products['expensive'],
    'checkout_page_id' => $checkout_page_id,
)) . "\n";
