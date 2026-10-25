<?php

/**
 * Handles responses from Khalti IPN.
 *
 * @package WooCommerce_Khalti\Classes\Payment
 */

defined('ABSPATH') || exit;

require_once dirname(__FILE__) . '/class-wc-gateway-khalti-response.php';

/**
 * WC_Gateway_Khalti_IPN_Handler class.
 */
#[AllowDynamicProperties]
class WC_Gateway_Khalti_IPN_Handler extends WC_Gateway_Khalti_Response
{
    /**
     * Constructor.
     *
     * @param WC_Gateway_Khalti $gateway Gateway class.
     */
    public function __construct($gateway)
    {
        add_action(
            'woocommerce_api_wc_gateway_khalti',
            array($this, 'check_response')
        );
        add_action(
            'valid_khalti_standard_ipn_request',
            array($this, 'valid_response')
        );

        $this->gateway = $gateway;
    }

    /**
     * Check for Khalti IPN Response.
     */
    public function check_response()
    {
        $required_params = ['pidx', 'amount', 'mobile', 'purchase_order_id', 'purchase_order_name'];
        $missing_params = [];
        $requested = [];

        array_push(
            $required_params,
            isset($_REQUEST['transaction_id']) ? 'transaction_id' : 'idx'
        );

        foreach ($required_params as $param) {
            if (!isset($_REQUEST[$param])) {
                array_push($missing_params, $param);
            } else {
                $requested[$param] = sanitize_text_field(
                    wp_unslash($_REQUEST[$param])
                );
            }
        }

        if (count($missing_params) == 0) {
            WC_Gateway_Khalti::log('IPN Response: ' . wc_print_r($_REQUEST, true));

            if ($verification_response = $this->validate_payment($requested)) {
                do_action(
                    'valid_khalti_standard_ipn_request',
                    $verification_response
                );

                exit;
            }

            wp_die(
                'Khalti Payment Verification Failure',
                'Khalti IPN',
                array('response' => 500)
            );
        }

        WC_Gateway_Khalti::log(
            'Parameter ' . implode(', ', $missing_params) . ' missing in response'
        );

        wp_die(
            'Khalti Request Failure',
            'Khalti IPN',
            array('response' => 500)
        );
    }

    /**
     * Check Khalti response validity.
     */
    public function validate_payment($requested)
    {
        WC_Gateway_Khalti::log('Validating IPN response');

        $order = $this->get_khalti_order($requested['purchase_order_name']);

        if (
            !$order
            || $order->get_payment_method() !== 'khalti'
            || $order->get_currency() !== 'NPR'
            || $requested['purchase_order_id'] !== $order->get_order_key()
        ) {
            return false;
        }

        // A refunded or reopened order must not be paid again with its old pidx.
        if (
            $order->get_meta('_khalti_pidx', true)
            && !$order->has_status(wc_get_is_paid_statuses())
        ) {
            return false;
        }

        $pending_payments = $order->get_meta('_khalti_pending_payments', true);
        $expected_pidx = $requested['pidx'];

        if (
            !is_array($pending_payments)
            || !array_key_exists($expected_pidx, $pending_payments)
        ) {
            return false;
        }

        $expected_amount = $pending_payments[$expected_pidx];
        if (!ctype_digit((string) $expected_amount)) {
            return false;
        }

        $response = wp_remote_post(
            $this->gateway->payment_lookup_api_endpoint,
            [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Key ' . $this->gateway->merchant_secret
                ],
                'body' => json_encode(['pidx' => $requested['pidx']])
            ]
        );

        if (is_wp_error($response)) {
            WC_Gateway_Khalti::log(
                'Khalti Payment Verification Error: ' . $response->get_error_message(),
                'error'
            );

            return false;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            WC_Gateway_Khalti::log('Khalti Payment Verification HTTP error', 'error');
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body, true);

        if (
            is_array($response_body)
            && isset($response_body['status'], $response_body['pidx'], $response_body['total_amount'], $response_body['transaction_id'])
            && $response_body['status'] === 'Completed'
            && $response_body['pidx'] === $expected_pidx
            && is_string($response_body['transaction_id'])
            && $response_body['transaction_id'] !== ''
            && ctype_digit((string) $response_body['total_amount'])
            && (string) $response_body['total_amount'] === (string) $expected_amount
            && ctype_digit((string) $requested['amount'])
            && (string) $requested['amount'] === (string) $expected_amount
            && (int) $expected_amount === (int) round((float) $order->get_total() * 100)
            && (!isset($response_body['refunded']) || $response_body['refunded'] === false)
        ) {
            WC_Gateway_Khalti::log('Payment Verification: Successful');

            $response_body['order_key'] = $requested['purchase_order_name'];

            return $response_body;
        }

        WC_Gateway_Khalti::log('Payment Verification: Failed');

        return false;
    }

    /**
     * There was a valid response.
     *
     * @param array    $response 	Verification response data
     */
    public function valid_response($response)
    {
        $order = $this->get_khalti_order($response['order_key']);

        if ($order) {
            WC_Gateway_Khalti::log('Found order #' . $order->get_id());

            // Lowercase returned variables.
            $payment_status = strtolower($response['status']);
            $payment_status =  in_array(
                $payment_status,
                ['completed', 'failed', 'cancelled', 'refunded']
            ) ? $payment_status : 'failed';

            WC_Gateway_Khalti::log('Payment status: ' . $payment_status);

            if (method_exists($this, "payment_status_$payment_status")) {
                call_user_func(
                    array($this, "payment_status_$payment_status"),
                    $order,
                    $response
                );

                wp_safe_redirect(
                    esc_url_raw(
                        add_query_arg(
                            'utm_nooverride',
                            '1',
                            $this->gateway->get_return_url($order)
                        )
                    )
                );

                exit;
            }
        }
    }

    /**
     * Handle a completed payment.
     *
     * @param WC_Order $order     	Order object.
     * @param array    $response 	Verification response data.
     */
    protected function payment_status_completed($order, $response)
    {
        if ($order->has_status(wc_get_is_paid_statuses())) {
            if ($order->get_meta('_khalti_pidx', true) === $response['pidx']) {
                return;
            }
            $additional = $order->get_meta('_khalti_additional_payments', true);
            if (!is_array($additional)) {
                $additional = array();
            }
            if (!isset($additional[$response['pidx']])) {
                $additional[$response['pidx']] = wc_clean($response['transaction_id']);
                $order->update_meta_data('_khalti_additional_payments', $additional);
                $order->add_order_note(
                    sprintf(
                        __('Additional Khalti payment received: transaction %s, pidx %s. Review and refund if needed.', 'woocommerce-khalti'),
                        $additional[$response['pidx']],
                        wc_clean($response['pidx'])
                    )
                );
                $order->save();
                wp_mail(
                    get_option('admin_email'),
                    sprintf(__('Additional Khalti payment for order #%s', 'woocommerce-khalti'), $order->get_id()),
                    sprintf(__('Review order #%s and Khalti transaction %s for a possible refund.', 'woocommerce-khalti'), $order->get_id(), $additional[$response['pidx']])
                );
            }
            return;
        }

        if ($order->has_status('cancelled')) {
            $this->payment_status_paid_cancelled_order($order);
        }

        $txn_id = wc_clean($response['transaction_id']);

        $order->update_meta_data('_khalti_pidx', wc_clean($response['pidx']));
        $order->update_meta_data('_khalti_txn_id', $txn_id);
        $order->save();

        $this->payment_complete(
            $order,
            $txn_id,
            __('IPN payment completed', 'woocommerce-khalti')
        );

    }

    /**
     * Handle a failed payment.
     *
     * @param WC_Order $order     	Order object.
     * @param array    $response 	Verification response data.
     */
    protected function payment_status_failed($order, $response)
    {
        $order->update_status(
            'failed',
            /* translators: %s: payment status */
            sprintf(__('Payment %s via IPN.', 'woocommerce-khalti'), 'failed')
        );
    }

    /**
     * Handle a refunded payment.
     *
     * @param WC_Order $order     	Order object.
     * @param array    $response 	Verification response data.
     */
    protected function payment_status_refunded($order, $response)
    {
        /* translators: %s: payment status */
        $order->update_status(
            'refunded',
            sprintf(__('Payment %s via IPN.', 'woocommerce-khalti'), 'refunded')
        );
    }

    /**
     * When a user cancelled order is marked paid.
     *
     * @param WC_Order $order 	Order object.
     */
    protected function payment_status_paid_cancelled_order($order)
    {
        if (version_compare(WC_VERSION, '4.5', '>')) {
            $this->send_ipn_email_notification(
                sprintf(
                    /* translators: %s: order ID. */
                    __(
                        'Payment for cancelled order %s received',
                        'woocommerce-khalti'
                    ),
                    $order->get_order_number()
                ),
                sprintf(
                    /* translators: %s: order link. */
                    __(
                        'Order #%s has been marked paid by Khalti IPN, but was previously cancelled. Admin handling required.',
                        'woocommerce-khalti'
                    ),
                    '<a class="link" href="' . esc_url($order->get_edit_order_url()) . '">' . $order->get_order_number() . '</a>'
                )
            );
        }
    }

    /**
     * Send a notification to the user handling orders.
     *
     * @param string $subject Email subject.
     * @param string $message Email message.
     */
    protected function send_ipn_email_notification($subject, $message)
    {
        $new_order_settings = get_option('woocommerce_new_order_settings', array());
        $mailer = WC()->mailer();
        $message = $mailer->wrap_message($subject, $message);
        $woocommerce_khalti_settings = get_option('woocommerce_khalti_settings');

        if (
            !empty($woocommerce_khalti_settings['ipn_notification'])
            && 'no' === $woocommerce_khalti_settings['ipn_notification']
        ) {
            return;
        }

        $mailer->send(
            !empty($new_order_settings['recipient'])
                ? $new_order_settings['recipient']
                : get_option('admin_email'),
            strip_tags($subject),
            $message
        );
    }

}
