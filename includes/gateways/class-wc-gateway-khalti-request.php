<?php

/**
 * Generates requests to send to Khalti
 *
 * @package WooCommerce_Khalti\Classes\Payment
 */

defined('ABSPATH') || exit;

/**
 * WC_Gateway_Khalti_Request class.
 */
#[AllowDynamicProperties]
class WC_Gateway_Khalti_Request
{

    /**
     * Pointer to gateway making the request.
     *
     * @var WC_Gateway_Khalti
     */
    protected $gateway;

    /**
     * Endpoint for requests from Khalti.
     *
     * @var string
     */
    protected $notify_url;

    /**
     * Constructor.
     *
     * @param WC_Gateway_Khalti $gateway Gateway class.
     */
    public function __construct($gateway)
    {
        $this->gateway    = $gateway;
        $this->notify_url = WC()->api_request_url('WC_Gateway_Khalti');
    }

    /**
     * Get the Khalti request URL for an order.
     *
     * @param  WC_Order $order   Order object.
     * @return string
     */
    public function get_request_url($order)
    {
        if ($order->get_currency() !== 'NPR') {
            wc_add_notice(
                __('Khalti payments require an NPR checkout. Please select NPR and try again.', 'woocommerce-khalti'),
                'error'
            );
            return array('result' => 'failure');
        }

        $args = $this->get_khalti_args($order);
        $order_id = $order->get_order_number();

        WC_Gateway_Khalti::log(
            "Khalti Request Args for order : $order_id" . wc_print_r($args, true)
        );

        $response = wp_remote_post(
            $this->gateway->payment_request_api_endpoint,
            [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Key ' . $this->gateway->merchant_secret
                ],
                'body' => json_encode($args)
            ]
        );


// echo '<pre>';
// print_r($response);
// echo '</pre>';
// exit;

        $response_body = is_wp_error($response)
            ? null
            : json_decode(wp_remote_retrieve_body($response), true);

        if (
            $this->api_response_is_ok($order_id, $response)
            && is_array($response_body)
            && isset($response_body['pidx'], $response_body['payment_url'])
            && is_string($response_body['pidx'])
            && $response_body['pidx'] !== ''
            && is_string($response_body['payment_url'])
            && strpos($response_body['payment_url'], 'https://') === 0
        ) {
            $pending_payments = $order->get_meta('_khalti_pending_payments', true);
            if (!is_array($pending_payments)) {
                $pending_payments = array();
            }
            $pending_payments[wc_clean($response_body['pidx'])] = $args['amount'];
            $order->update_meta_data('_khalti_pending_payments', $pending_payments);
            $order->save();

            return array(
                'result' => 'success',
                'redirect' => $response_body['payment_url']
            );
        } else {
            wc_add_notice(
                wp_strip_all_tags($this->collect_error_messages_from_api_response($response_body)),
                'error'
            );

            return array(
                'result' => 'failure'
            );
        }
    }

    /**
     * Get Khalti Args for passing to Khalti.
     *
     * @param  WC_Order $order Order object.
     * @return array
     */
    protected function get_khalti_args($order)
    {
        WC_Gateway_Khalti::log(
            'Generating payment form for order '
                . $order->get_order_number()
                . '. Notify URL: '
                . $this->notify_url
        );

        $args = [
            'return_url' => $this->notify_url,
            'website_url' => get_site_url(),
            'amount' => $this->to_paisa($order->get_total()),
            'purchase_order_id' => $order->get_order_key(),
            'purchase_order_name' => $order->get_order_key(),
            'product_details' => $this->get_products_info($order)
        ];

        if ($this->gateway->send_customer_info) {
            $args['customer_info'] = $this->get_customer_info($order);
        }

        if ($this->gateway->send_amount_breakdown) {
            $args['amount_breakdown'] = $this->get_amount_breakdown($order);
        }

        return $args;
    }

    /**
     * Get customer information
     *
     * @param  $billing array.
     * @return array
     */
    private function get_customer_info($order)
    {
        return array(
            "name" => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            "email" => $order->get_billing_email(),
            "phone" => $order->get_billing_phone()
        );
    }

    /**
     * Get order amount breakdown information
     *
     * @param  WC_ORDER $order object.
     * @return array
     */
    private function get_amount_breakdown($order)
    {
        $total = $this->to_paisa($order->get_total());
        $discount_total = $this->to_paisa($order->get_discount_total());
        $shipping_total = $this->to_paisa($order->get_shipping_total());
        $tax_total = $this->to_paisa($order->get_total_tax());

        $sub_total = $total + $discount_total - $shipping_total - $tax_total;

        $amount_breakdown = [
            [
                "label" => "Mark Price",
                "amount" => $sub_total
            ],
            [
                "label" => "Shipping Charge",
                "amount" => $shipping_total
            ],
            [
                "label" => "VAT",
                "amount" => $tax_total
            ]
        ];

        if ($discount_total > 0) {
            $amount_breakdown[] = [
                "label" => "Discount",
                "amount" => -$discount_total
            ];
        }

        return $amount_breakdown;
    }

    private function to_paisa($amount)
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Get each product information in an order
     *
     * @param  WC_ORDER $order object.
     * @return array
     */
    private function get_products_info($order)
    {
        $product_info = array();
        $bundle_ids = array();
        $skipped_product_ids = array();

        foreach ($order->get_items() as $item) {
            if ($item->get_quantity() <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                $product_info[] = $this->get_product_details($item, null);
                continue;
            }
            $product_id = $product->get_id();

            if (function_exists('wc_pb_get_bundled_product_map')) {
                $product_id = $product->get_id();

                if ($product->is_type('bundle')) {
                    array_push($bundle_ids, $product_id);

                    $product_info[] = $this->get_product_details($item, $product);
                } elseif ($this->product_belongs_to_bundle_in_cart(
                    $bundle_ids,
                    $skipped_product_ids,
                    $product
                )) {
                    array_push($skipped_product_ids, $product_id);
                } else {
                    $product_info[] = $this->get_product_details($item, $product);
                }
            } else {
                $product_info[] = $this->get_product_details($item, $product);
            }
        }

        return $product_info;
    }

    /*
    *Checks if an item belongs to bundle item which is in the cart already
    *This is because even bundle items are treated as individual item in order loop
    */
    private function product_belongs_to_bundle_in_cart(
        $bundle_ids,
        $skipped_product_ids,
        $product
    ) {
        $skipped_count = array_count_values($skipped_product_ids);
        $product_id = $product->get_id();

        if ($product_bundle_ids = wc_pb_get_bundled_product_map($product)) {
            foreach ($product_bundle_ids as $bundle_id) {
                if (
                    in_array($bundle_id, $bundle_ids)
                    && ($skipped_count[$product_id] < count($product_bundle_ids))
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get product detail of an item.
     *
     * @param  $item object.
     * @param  $prodcut object.
     * @return array
     */
    private function get_product_details($item, $product)
    {
        $total_price = $this->to_paisa($item->get_total());

        return array(
            'identity' => $product ? $product->get_id() : $item->get_product_id(),
            'name' => $product ? $product->get_name() : $item->get_name(),
            'quantity' => $item->get_quantity(),
            'unit_price' => (int) round($total_price / $item->get_quantity()),
            'total_price' => $total_price
        );
    }

    /**
     * Check if api response is ok or notify on fail.
     *
     * @param  $order_id id Order object.
     * @param  $response array|WP_Error object.
     * @return boolean
     */
    private function api_response_is_ok($order_id, $response)
    {
        $date = current_time('mysql');
        $timezone = 'Timezone: ' . get_option('timezone_string');
        $subject = "Khalti API Request Error for order id: $order_id at $date $timezone";

        if (is_wp_error($response)) {
            WC_Gateway_Khalti::log(
                'Khalti Request Error: ' . $response->get_error_message(),
                'error'
            );

            wp_mail(
                get_option('admin_email'),
                $subject,
                $response->get_error_message()
            );

            return false;
        }

        if (wp_remote_retrieve_response_code($response) != 200) {
            WC_Gateway_Khalti::log(
                'Khalti Request Error: ' . wp_remote_retrieve_body($response),
                'error'
            );

            wp_mail(
                get_option('admin_email'),
                $subject,
                wp_remote_retrieve_body($response)
            );

            return false;
        }

        return true;
    }

    /**
     * Collect error messages from responde body of api response
     *
     * @param  $response_body arrayobject.
     * @return string
     */
    private function collect_error_messages_from_api_response($response_body)
    {
        if (
            array_key_exists('error_key', (array) $response_body)
            && 'validation_error' == $response_body['error_key']
        ) {
            unset($response_body['error_key']);

            $errors = [];

            foreach ($response_body as $key_1 => $response_l1) {
                if (is_array($response_l1)) {
                    foreach ($response_l1 as $key_2 => $response_l2) {
                        if (is_array($response_l2)) {
                            foreach ($response_l2 as $key_3 => $response_l3) {
                                array_push(
                                    $errors,
                                    (is_numeric($key_3) ? $key_2 : $key_3) . ' - ' . $response_l3
                                );
                            }
                        } else {
                            array_push(
                                $errors,
                                (is_numeric($key_2) ? $key_1 : $key_2) . ' - ' .  $response_l2
                            );
                        }
                    }
                } else {
                    array_push(
                        $errors,
                        $key_1 . ' - ' . $response_l1
                    );
                }
            }

            $message =  implode('<br/>', $errors);
        } else {
            $message = 'Integration error: ' . @$response_body['detail'];
        }

        return "<div class='woocommerce-error'>$message</div>";
    }
}
