<?php

/**
 * @package payment-gateway-wc-maib-checkout
 */

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

defined('ABSPATH') || exit;

use Maib\MaibCheckout\MaibCheckoutClient;

class WC_Gateway_MAIB_Checkout extends WC_Payment_Gateway_Base
{
    //region Constants
    const MOD_ID          = 'maib_checkout';
    const MOD_TEXT_DOMAIN = 'payment-gateway-wc-maib-checkout';
    const MOD_PREFIX      = 'maib_checkout_';
    const MOD_TITLE       = 'maib e-Commerce Checkout';
    const MOD_VERSION     = '1.0.2';
    const MOD_PLUGIN_FILE = MAIB_CHECKOUT_MOD_PLUGIN_FILE;

    const SUPPORTED_CURRENCIES = array('MDL');
    const ORDER_TEMPLATE       = 'Order #%1$s';

    const MOD_ACTION_CHECK_PAYMENT = self::MOD_PREFIX . 'check_payment';

    const MOD_CHECKOUT_ID     = self::MOD_PREFIX . 'checkout_id';
    const MOD_CHECKOUT_URL    = self::MOD_PREFIX . 'checkout_url';
    const MOD_PAYMENT_ID      = self::MOD_PREFIX . 'payment_id';
    const MOD_PAYMENT_RECEIPT = self::MOD_PREFIX . 'payment_receipt';

    /**
     * Default API request timeout (seconds).
     */
    const DEFAULT_TIMEOUT = 30;

    /**
     * Default transaction validity (minutes).
     */
    const DEFAULT_VALIDITY = 30;
    //endregion

    protected $maib_checkout_base_url, $maib_checkout_callback_url, $maib_checkout_client_id, $maib_checkout_client_secret, $maib_checkout_signature_key;

    public function __construct()
    {
        $this->id                 = self::MOD_ID;
        $this->method_title       = self::MOD_TITLE;
        $this->method_description = __('Accept maib e-Commerce Checkout payments.', 'payment-gateway-wc-maib-checkout');
        $this->has_fields         = false;
        $this->supports           = array('products', 'refunds');

        //region Initialize settings
        $this->init_form_fields();
        $this->init_settings();

        parent::__construct();

        $this->icon = plugins_url('assets/img/maib.svg', self::MOD_PLUGIN_FILE);

        // https://github.com/alexminza/maib-checkout-sdk-php/blob/main/src/MaibCheckout/MaibCheckoutClient.php
        $this->maib_checkout_base_url      = $this->testmode ? MaibCheckoutClient::SANDBOX_BASE_URL : MaibCheckoutClient::DEFAULT_BASE_URL;
        $this->maib_checkout_callback_url  = $this->get_option('maib_checkout_callback_url', $this->get_callback_url());
        $this->maib_checkout_client_id     = $this->get_option('maib_checkout_client_id');
        $this->maib_checkout_client_secret = $this->get_option('maib_checkout_client_secret');
        $this->maib_checkout_signature_key = $this->get_option('maib_checkout_signature_key');

        if (is_admin()) {
            add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));

            add_filter('woocommerce_order_actions', array($this, 'order_actions'), 10, 2);
            add_action('woocommerce_order_action_' . self::MOD_ACTION_CHECK_PAYMENT, array($this, 'action_check_payment'));
        }
        //endregion

        add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
    }

    public function init_form_fields()
    {
        $callback_url = $this->get_callback_url();

        $this->form_fields = array(
            'enabled'         => array(
                'title'       => __('Enable/Disable', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Enable this gateway', 'payment-gateway-wc-maib-checkout'),
                'default'     => 'yes',
            ),
            'title'           => array(
                'title'       => __('Title', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'text',
                'description' => __('Payment method title that the customer will see during checkout.', 'payment-gateway-wc-maib-checkout'),
                'desc_tip'    => true,
                'default'     => $this->get_method_title(),
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'description'     => array(
                'title'       => __('Description', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see during checkout.', 'payment-gateway-wc-maib-checkout'),
                'desc_tip'    => true,
                'default'     => __('Visa, Mastercard, Apple Pay, Google Pay, MIA Instant Payments.', 'payment-gateway-wc-maib-checkout'),
            ),

            'testmode'        => array(
                'title'       => __('Test mode', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Enabled', 'payment-gateway-wc-maib-checkout'),
                'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'payment-gateway-wc-maib-checkout'),
                'desc_tip'    => true,
                'default'     => 'no',
            ),
            'debug'           => array(
                'title'       => __('Debug mode', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'payment-gateway-wc-maib-checkout'),
                'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'payment-gateway-wc-maib-checkout'), esc_url(self::get_logs_url())),
                'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'payment-gateway-wc-maib-checkout'),
                'default'     => 'no',
            ),

            'order_template'  => array(
                'title'       => __('Order description', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'text',
                /* translators: 1: Example placeholder shown to user, represents Order ID */
                'description' => __('Format: <code>%1$s</code> - Order ID', 'payment-gateway-wc-maib-checkout'),
                'desc_tip'    => __('Order description that the customer will see in the app during payment.', 'payment-gateway-wc-maib-checkout'),
                'default'     => self::ORDER_TEMPLATE,
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),

            'connection_settings' => array(
                'title'       => __('Connection Settings', 'payment-gateway-wc-maib-checkout'),
                'description' => __('Payment gateway connection credentials are provided by the bank.', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'title',
            ),
            'maib_checkout_client_id' => array(
                'title'       => __('Client ID', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'text',
                'desc_tip'    => 'Client ID',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'maib_checkout_client_secret' => array(
                'title'       => __('Client Secret', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'password',
                'desc_tip'    => 'Client Secret',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'maib_checkout_signature_key' => array(
                'title'       => __('Signature Key', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'password',
                'desc_tip'    => 'Signature Key',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),

            'payment_notification' => array(
                'title'       => __('Payment Notification', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'title',
            ),
            'maib_checkout_callback_url' => array(
                'title'       => __('Callback URL', 'payment-gateway-wc-maib-checkout'),
                'type'        => 'text',
                'description' => sprintf('<code>%1$s</code>', esc_url($callback_url)),
                'desc_tip'    => 'Callback URL',
                'default'     => $callback_url,
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
        );
    }

    //region Settings validation
    protected function check_settings()
    {
        return parent::check_settings()
            && !empty($this->maib_checkout_client_id)
            && !empty($this->maib_checkout_client_secret)
            && !empty($this->maib_checkout_signature_key)
            && !empty($this->maib_checkout_callback_url);
    }

    public function validate_order_template_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_maib_checkout_client_id_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_maib_checkout_client_secret_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_maib_checkout_signature_key_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_maib_checkout_callback_url_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }
    //endregion

    //region maib e-Commerce Checkout
    /**
     * @link https://github.com/alexminza/maib-checkout-sdk-php/blob/main/README.md#getting-started
     */
    protected function init_maib_checkout_client()
    {
        $options = array(
            'base_uri' => $this->maib_checkout_base_url,
            'timeout'  => self::DEFAULT_TIMEOUT,
        );

        if ($this->debug) {
            $log_name = "{$this->id}_guzzle";
            $log_file_name = \WC_Log_Handler_File::get_log_file_path($log_name);

            $log = new \Monolog\Logger($log_name);
            $log->pushHandler(new \Monolog\Handler\StreamHandler($log_file_name, \Monolog\Logger::DEBUG));

            $stack = \GuzzleHttp\HandlerStack::create();
            $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

            $options['handler'] = $stack;
        }

        $guzzle_client = new \GuzzleHttp\Client($options);
        $client = new MaibCheckoutClient($guzzle_client);

        return $client;
    }

    private function maib_checkout_get_response_result(?\GuzzleHttp\Command\Result $response)
    {
        if (!empty($response)) {
            $response_ok = boolval($response['ok']);
            if ($response_ok) {
                $response_result = (array) $response['result'];
                return $response_result;
            }
        }

        return null;
    }

    /**
     * @link https://github.com/alexminza/maib-checkout-sdk-php/blob/main/README.md#get-access-token-with-client-id-and-client-secret
     * @link https://docs.maibmerchants.md/checkout/api-reference/endpoints/authentication/obtain-authentication-token
     */
    private function maib_checkout_generate_token(MaibCheckoutClient $client)
    {
        $get_token_response = $client->getToken($this->maib_checkout_client_id, $this->maib_checkout_client_secret);
        $get_token_result = $this->maib_checkout_get_response_result($get_token_response);

        if (empty($get_token_result) || !isset($get_token_result['accessToken'])) {
            throw new \Exception('Failed to obtain maib e-Commerce Checkout API access token');
        }

        $access_token = strval($get_token_result['accessToken']);
        return $access_token;
    }

    /**
     * @link https://github.com/alexminza/maib-checkout-sdk-php/blob/main/README.md#register-a-new-hosted-checkout-session
     * @link https://docs.maibmerchants.md/checkout/api-reference/endpoints/register-a-new-hosted-checkout-session
     */
    private function maib_checkout_register(MaibCheckoutClient $client, string $auth_token, \WC_Order $order)
    {
        $order_total    = floatval($order->get_total());
        $order_currency = $order->get_currency();
        $order_date     = $order->get_date_created() ?? new \WC_DateTime();

        // $shipping_total  = floatval($order->get_shipping_total());
        // $shipping_tax    = floatval($order->get_shipping_tax());
        // $delivery_amount = wc_format_decimal($shipping_total + $shipping_tax, 2);

        $order_items = array();
        foreach ($order->get_items() as $item) {
            $product     = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            $product_sku = !empty($product) ? $product->get_sku() : '';
            // $product_id  = !empty($product) ? $product->get_id() : 0;
            // $external_id = $item->get_variation_id() ?? $item->get_product_id();
            // $line_total  = $order->get_line_total($item, true, true);

            $order_items[] = array(
                'externalId' => $product_sku,
                'title'      => $item->get_name(),
                'amount'     => $order->get_item_total($item, true, true),
                'currency'   => $order_currency,
                'quantity'   => $item->get_quantity(),
            );
        }

        $checkout_data = array(
            'amount'    => $order_total,
            'currency'  => $order_currency,
            'orderInfo' => array(
                'id'               => strval($order->get_id()),
                'description'      => $this->get_order_description($order),
                'date'             => $order_date->format('c'),
                // 'orderAmount'      => wc_format_decimal($order_total - floatval($delivery_amount), 2);,
                // 'orderCurrency'    => $order_currency,
                // 'deliveryAmount'   => $delivery_amount,
                // 'deliveryCurrency' => $order_currency,
                'items'            => $order_items,
            ),
            'payerInfo' => array(
                'name'      => $order->get_formatted_billing_full_name(),
                'email'     => $order->get_billing_email(),
                'phone'     => $order->get_billing_phone(),
                'ip'        => \WC_Geolocation::get_ip_address(),
                'userAgent' => $order->get_customer_user_agent(),
            ),
            'language'    => substr(get_user_locale(), 0, 2),
            'callbackUrl' => $this->maib_checkout_callback_url,
            'successUrl'  => $this->get_redirect_url($order),
            'failUrl'     => $order->get_checkout_payment_url(), // $order->get_cancel_order_url()
        );

        return $client->checkoutRegister($checkout_data, $auth_token);
    }

    /**
     * @link https://docs.maibmerchants.md/checkout/api-reference/endpoints/get-checkout-details
     */
    private function maib_checkout_session_active(MaibCheckoutClient $client, string $auth_token, string $checkout_id)
    {
        $checkout_details_response = $client->checkoutDetails($checkout_id, $auth_token);
        $checkout_details_result = $this->maib_checkout_get_response_result($checkout_details_response);

        if (!empty($checkout_details_result)) {
            $checkout_details_status = strval($checkout_details_result['status']);

            if (in_array(strtolower($checkout_details_status), array('waitingforinit', 'initialized', 'paymentmethodselected'), true)) {
                $checkout_details_expires_at = strval($checkout_details_result['expiresAt']);

                $now = new \DateTime();
                $expires_at = new \DateTime($checkout_details_expires_at);

                if ($expires_at > $now) {
                    $min_validity_seconds = self::DEFAULT_VALIDITY * 60 / 2;
                    $remaining_seconds = $expires_at->getTimestamp() - $now->getTimestamp();

                    return $remaining_seconds >= $min_validity_seconds;
                }
            }
        }

        return false;
    }

    /**
     * @link https://docs.maibmerchants.md/checkout/api-reference/endpoints/retrieve-all-payments-by-filter
     */
    private function maib_checkout_payment(MaibCheckoutClient $client, string $auth_token, string $order_id)
    {
        $payment_list_data = array(
            'orderId' => $order_id,
            'status' => 'Executed',
            'count' => 10,
        );

        $payment_list_response = $client->paymentList($payment_list_data, $auth_token);
        $payment_list_result = $this->maib_checkout_get_response_result($payment_list_response);

        if (!empty($payment_list_result)) {
            $payment_list_result_count = intval($payment_list_result['totalCount']);

            if (1 === $payment_list_result_count) {
                $payment_list_result_items = (array) $payment_list_result['items'];
                return (array) $payment_list_result_items[0];
            } elseif ($payment_list_result_count > 1) {
                $this->log(
                    sprintf('Multiple order %1$s payments', $order_id),
                    \WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'payment_list_response' => $payment_list_response->toArray(),
                    )
                );
            }
        }

        return null;
    }

    /**
     * @link https://docs.maibmerchants.md/checkout/api-reference/endpoints/refund-a-payment
     */
    private function maib_payment_refund(MaibCheckoutClient $client, string $auth_token, string $payment_id, float $amount, string $reason)
    {
        $refund_data = array(
            'amount' => $amount,
            'reason' => $reason,
            'callbackUrl' => $this->maib_checkout_callback_url,
        );

        return $client->paymentRefund($payment_id, $refund_data, $auth_token);
    }
    //endregion

    //region Payment
    /**
     * @param int $order_id
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $register_checkout_response = null;

        try {
            $client = $this->init_maib_checkout_client();
            $auth_token = $this->maib_checkout_generate_token($client);

            //region Existing Checkout Session
            try {
                $checkout_id = strval($order->get_meta(self::MOD_CHECKOUT_ID, true));
                $checkout_url = strval($order->get_meta(self::MOD_CHECKOUT_URL, true));

                if (!empty($checkout_id) && !empty($checkout_url)) {
                    if ($this->maib_checkout_session_active($client, $auth_token, $checkout_id)) {
                        return array(
                            'result'   => 'success',
                            'redirect' => $checkout_url,
                        );
                    }
                }
            } catch (\Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    \WC_Log_Levels::ERROR,
                    array(
                        'response' => self::get_guzzle_error_response_body($ex),
                        'order_id' => $order_id,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }
            //endregion

            $register_checkout_response = $this->maib_checkout_register($client, $auth_token, $order);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'response' => self::get_guzzle_error_response_body($ex),
                    'order_id' => $order_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        $register_checkout_result = $this->maib_checkout_get_response_result($register_checkout_response);
        if (!empty($register_checkout_result)) {
            $checkout_id = strval($register_checkout_result['checkoutId']);
            $checkout_url = strval($register_checkout_result['checkoutUrl']);

            //region Update order payment transaction metadata
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#apis-for-gettingsetting-posts-and-postmeta
            $order->update_meta_data(self::MOD_CHECKOUT_ID, $checkout_id);
            $order->update_meta_data(self::MOD_CHECKOUT_URL, $checkout_url);
            $order->save();
            //endregion

            /* translators: 1: Order ID, 2: Payment method title, 3: API response details */
            $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s: %3$s', 'payment-gateway-wc-maib-checkout'), $order_id, $this->get_method_title(), $checkout_id));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'register_checkout_response' => $register_checkout_response->toArray(),
                )
            );

            $order->add_order_note($message);

            return array(
                'result'   => 'success',
                'redirect' => $checkout_url,
            );
        }

        /* translators: 1: Order ID, 2: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'payment-gateway-wc-maib-checkout'), $order_id, $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'register_checkout_response' => $register_checkout_response ? $register_checkout_response->toArray() : null,
            )
        );

        $order->add_order_note($message);

        wc_add_notice($message, 'error');
        $this->logs_admin_website_notice();

        // https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
        // https://github.com/woocommerce/woocommerce/pull/53671
        return array(
            'result'  => 'failure',
            'message' => $message,
        );
    }

    /**
     * @link https://docs.maibmerchants.md/checkout/api-reference/callback-notifications
     */
    public function check_response()
    {
        $this->log_request(__FUNCTION__);

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ('GET' === $request_method) {
            /* translators: 1: Payment method title */
            $message = sprintf(__('%1$s Callback URL', 'payment-gateway-wc-maib-checkout'), $this->get_method_title());
            return self::return_response(\WP_Http::OK, $message);
        } elseif ('POST' !== $request_method) {
            return self::return_response(\WP_Http::METHOD_NOT_ALLOWED);
        }

        //region Validate callback
        $signature_header = null;
        $signature_timestamp = null;
        $callback_body = null;
        $callback_data = null;
        $validation_result = false;

        try {
            $callback_body = file_get_contents('php://input');
            if (empty($callback_body)) {
                throw new \Exception('Empty callback body');
            }

            /** @var array */
            $callback_data = wc_clean(json_decode($callback_body, true));
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception(json_last_error_msg());
            }
            if (empty($callback_data) || !is_array($callback_data)) {
                throw new \Exception('Invalid callback data');
            }

            // Validate signature headers
            $signature_header = isset($_SERVER['HTTP_X_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SIGNATURE'])) : '';
            $signature_timestamp = isset($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'])) : '';
            if (empty($signature_header) || empty($signature_timestamp)) {
                throw new \Exception('Empty signature/timestamp headers');
            }

            $validation_result = MaibCheckoutClient::validateCallbackSignature($callback_body, $signature_header, $signature_timestamp, $this->maib_checkout_signature_key);
            $this->log(
                sprintf(__('Payment notification callback', 'payment-gateway-wc-maib-checkout')),
                \WC_Log_Levels::INFO,
                array(
                    'validation_result' => $validation_result,
                    'signature_header' => $signature_header,
                    'signature_timestamp' => $signature_timestamp,
                    // 'callback_body' => $callback_body,
                    'callback_data' => $callback_data,
                )
            );
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'signature_header' => $signature_header,
                    'signature_timestamp' => $signature_timestamp,
                    'callback_body' => $callback_body,
                    'callback_data' => $callback_data,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );

            return self::return_response(\WP_Http::INTERNAL_SERVER_ERROR);
        }

        if (!$validation_result) {
            $message = esc_html(__('Callback signature validation failed.', 'payment-gateway-wc-maib-checkout'));
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'validation_result' => $validation_result,
                    'signature_header' => $signature_header,
                    'signature_timestamp' => $signature_timestamp,
                    'callback_data' => $callback_data,
                )
            );

            return self::return_response(\WP_Http::UNAUTHORIZED, 'Invalid callback signature');
        }
        //endregion

        //region Validate payment status
        $callback_payment_status = strval($callback_data['paymentStatus']);
        if (strtolower($callback_payment_status) !== 'executed') {
            return self::return_response(\WP_Http::ACCEPTED);
        }
        //endregion

        //region Validate order ID
        $callback_order_id = absint($callback_data['orderId']);
        $order = wc_get_order($callback_order_id);

        if (empty($order)) {
            /* translators: 1: Order ID, 2: Payment method title */
            $message = sprintf(__('Order not found by Order ID: %1$d received from %2$s.', 'payment-gateway-wc-maib-checkout'), $callback_order_id, $this->get_method_title());
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'callback_data' => $callback_data,
                )
            );

            return self::return_response(\WP_Http::UNPROCESSABLE_ENTITY, 'Order not found');
        }
        //endregion

        $confirm_payment_result = $this->confirm_payment($order, $callback_data, $callback_data);

        if (is_wp_error($confirm_payment_result)) {
            return self::return_response($confirm_payment_result->get_error_code(), $confirm_payment_result->get_error_message());
        }

        return self::return_response(\WP_Http::OK);
    }

    public function check_payment(\WC_Order $order)
    {
        $order_id = strval($order->get_id());
        $checkout_payment = null;
        $checkout_details = null;

        $checkout_id = strval($order->get_meta(self::MOD_CHECKOUT_ID, true));
        if (empty($checkout_id)) {
            /* translators: 1: Order ID, 2: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'payment-gateway-wc-maib-checkout'), $order_id, self::MOD_CHECKOUT_ID));
            \WC_Admin_Meta_Boxes::add_error($message);
            return;
        }

        try {
            $client = $this->init_maib_checkout_client();
            $auth_token = $this->maib_checkout_generate_token($client);

            $checkout_payment = $this->maib_checkout_payment($client, $auth_token, $order_id);

            if (empty($checkout_payment)) {
                $checkout_details_response = $client->checkoutDetails($checkout_id, $auth_token);
                $checkout_details = $this->maib_checkout_get_response_result($checkout_details_response);
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'response' => self::get_guzzle_error_response_body($ex),
                    'order_id' => $order_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        $payment_status = null;
        if (!empty($checkout_payment)) {
            $payment_status = strval($checkout_payment['status']);
        } elseif (!empty($checkout_details)) {
            $payment_status = strval($checkout_details['status']);
        }

        if (!empty($payment_status)) {
            /* translators: 1: Order ID, 2: Payment method title, 3: Payment status */
            $message = esc_html(sprintf(__('Order #%1$s %2$s payment status: %3$s', 'payment-gateway-wc-maib-checkout'), $order_id, $this->get_method_title(), $payment_status));
            $message = $this->get_test_message($message);
            \WC_Admin_Meta_Boxes::add_error($message);

            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'checkout_payment' => $checkout_payment,
                    'checkout_details' => $checkout_details,
                )
            );
        } else {
            /* translators: 1: Order ID */
            $message = esc_html(sprintf(__('Order #%1$s payment check failed.', 'payment-gateway-wc-maib-checkout'), $order_id));
            \WC_Admin_Meta_Boxes::add_error($message);

            return;
        }

        if (!empty($checkout_payment)) {
            if (strtolower($payment_status) === 'executed') {
                $confirm_payment_result = $this->confirm_payment($order, $checkout_payment, $checkout_payment);

                if (is_wp_error($confirm_payment_result)) {
                    \WC_Admin_Meta_Boxes::add_error($confirm_payment_result->get_error_message());
                }
            }
        }
    }

    protected function confirm_payment(\WC_Order $order, array $payment_data, array $payment_receipt_data)
    {
        //region Check order data
        $payment_data_order_id = intval($payment_data['orderId']);
        $payment_data_amount = floatval($payment_data['paymentAmount']);
        $payment_data_currency = strval($payment_data['paymentCurrency']);

        $order_id = $order->get_id();
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();

        $order_price = $this->format_price($order_total, $order_currency);
        $payment_data_price = $this->format_price($payment_data_amount, $payment_data_currency);

        if ($order_id !== $payment_data_order_id || $order_price !== $payment_data_price) {
            /* translators: 1: Payment data order ID, 2: Payment data price, 3: Order ID, 4: Order total price */
            $message = sprintf(__('Order payment data mismatch: Payment: #%1$s %2$s, Order: #%3$s %4$s.', 'payment-gateway-wc-maib-checkout'), $payment_data_order_id, $payment_data_price, $order_id, $order_price);
            $message = $this->get_test_message($message);
            $this->log($message, \WC_Log_Levels::ERROR);

            return new \WP_Error(\WP_Http::UNPROCESSABLE_ENTITY, 'Order payment data mismatch');
        }

        if ($order->is_paid()) {
            /* translators: 1: Order ID */
            $message = sprintf(__('Order #%1$s already fully paid.', 'payment-gateway-wc-maib-checkout'), $order_id);
            $message = $this->get_test_message($message);
            $this->log($message, \WC_Log_Levels::WARNING);

            return new \WP_Error(\WP_Http::ACCEPTED, 'Order already fully paid');
        }
        //endregion

        //region Complete order payment
        $payment_data_payment_id = strval($payment_data['paymentId']);
        $payment_data_reference = strval($payment_data['retrievalReferenceNumber']);

        $order->update_meta_data(self::MOD_PAYMENT_RECEIPT, wp_json_encode($payment_receipt_data));
        $order->update_meta_data(self::MOD_PAYMENT_ID, $payment_data_payment_id);
        $order->save();

        $order->payment_complete($payment_data_reference);
        //endregion

        /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
        $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'payment-gateway-wc-maib-checkout'), $order_id, $this->get_method_title(), $payment_data_reference));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::INFO,
            array(
                'payment_receipt_data' => $payment_receipt_data,
            )
        );

        $order->add_order_note($message);
        return true;
    }

    /**
     * @param  int    $order_id
     * @param  float  $amount
     * @param  string $reason
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if (!$this->check_settings()) {
            $message = wp_strip_all_tags($this->get_settings_admin_message());
            return new \WP_Error('check_settings', $message);
        }

        $order = wc_get_order($order_id);
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();
        $amount = isset($amount) ? floatval($amount) : $order_total;

        $payment_id = strval($order->get_meta(self::MOD_PAYMENT_ID, true));
        if (empty($payment_id)) {
            /* translators: 1: Order ID, 2: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'payment-gateway-wc-maib-checkout'), $order_id, self::MOD_PAYMENT_ID));
            return new \WP_Error('order_payment_id', $message);
        }

        $payment_refund_response = null;
        try {
            $client = $this->init_maib_checkout_client();
            $auth_token = $this->maib_checkout_generate_token($client);

            $payment_refund_response = $this->maib_payment_refund($client, $auth_token, $payment_id, $amount, $reason);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'response' => self::get_guzzle_error_response_body($ex),
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        $payment_refund_result = $this->maib_checkout_get_response_result($payment_refund_response);
        if (!empty($payment_refund_result)) {
            $refund_status = strval($payment_refund_result['status']);
            if (in_array(strtolower($refund_status), array('refunded', 'created'), true)) {
                /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s approved.', 'payment-gateway-wc-maib-checkout'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    \WC_Log_Levels::INFO,
                    array(
                        'payment_refund_response' => $payment_refund_response->toArray(),
                    )
                );

                $order->add_order_note($message);
                return true;
            }
        }

        /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed.', 'payment-gateway-wc-maib-checkout'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'payment_refund_response' => $payment_refund_response ? $payment_refund_response->toArray() : null,
            )
        );

        $order->add_order_note($message);
        return new \WP_Error('process_refund', $message);
    }
    //endregion

    //region Integration
    public static function order_actions(array $actions, \WC_Order $order)
    {
        if (!$order->needs_payment() || $order->get_payment_method() !== self::MOD_ID) {
            return $actions;
        }

        /* translators: 1: Payment method title */
        $actions[self::MOD_ACTION_CHECK_PAYMENT] = esc_html(sprintf(__('Check %1$s order payment', 'payment-gateway-wc-maib-checkout'), self::MOD_TITLE));
        return $actions;
    }

    public static function action_check_payment(\WC_Order $order)
    {
        /** @var WC_Gateway_MAIB_Checkout $plugin */
        $plugin = self::get_payment_gateway_instance();
        $plugin->check_payment($order);
    }
    //endregion
}
