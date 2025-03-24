<?php
require __DIR__ . '/vendor/autoload.php';

use \Payneteasy\Classes\Api\PaynetApi,
    \Payneteasy\Classes\Common\PaynetEasyLogger,
    \Payneteasy\Classes\Exception\PaynetEasyException;

/**
 * PayneteasyPayment Class
 *
 * This class provides methods to handle payment operations with Paynet API.
 */
class payneteasyPayment extends waPayment implements waIPayment
{  
    private ?string $order_id = null;
    private string $request_type;
    private PaynetEasyLogger $logger;
    
    
    /**
     * Get Paynet API instance
     *
     * This method initializes and returns an instance of the Paynet API using 
     * the provided configuration values.
     *
     * @return PaynetApi An instance of the PaynetApi
     */
    private function getPaynetApi() 
    {
        return new PaynetApi(
            $this->login,
            $this->control_key,
            $this->endpoint_id,
            $this->payment_method,
            $this->sandbox
        );
    }
    

    /**
     * Payment Method
     *
     * This method manages the payment process. It creates a new order, validates it,
     * retrieves the payment URL from PAYNET API, and then returns a view with the form URL.
     *
     * @param array $payment_form_data The form data for the payment
     * @param array $order_data The order data
     * @param bool $auto_submit Whether to auto-submit the form
     *
     * @return string The rendered view with the payment form
     *
     * @throws waException If the order currency is not supported
     */
    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $this->setPayneteasyLogger()
             ->setOption('additionalCommonText', 'payment-' . rand(1111, 9999));
        $order = waOrder::factory($order_data);

        try {
            // if (!in_array($order->currency_id, $this->allowedCurrency())) {
            //     throw new waException($this->_w('Currency not supported.'));
            // }
            $pay_url = $this->getPayUrl($order);
            if ($this->payment_method == 'form') {
                $pay_url = $pay_url['redirect-url'];
            } elseif ($this->payment_method == 'direct') {
                $pay_url = $this->getRelayUrl().'?transaction_result=result&app_id='.$this->app_id.'&merchant_id='.$this->merchant_id.'&order_id='.$order->id;
                if ($this->three_d_secure) {
                    $pay_url = $this->getRelayUrl().'?transaction_result=result&app_id='.$this->app_id.'&merchant_id='.$this->merchant_id.'&order_id='.$order->id;
                    $status = $this->getPaymentStatusData($order->id);
                    $view = wa()->getView();
                    $view->assign('html', $status['html']);
                }
            }

            $view = wa()->getView();
            $view->assign('payment_method', $this->payment_method);
            $view->assign('three_d_secure', $this->three_d_secure);
            $view->assign('form_url', $pay_url);
            $view->assign('auto_submit', $auto_submit);
            $view->assign('form_desc', $this->_w('Redirecting to the bank website for payment...')); 
            $view->assign('form_btn', $this->_w('Go to the payment form, to the bank page'));
            return $view->fetch($this->path . '/templates/payment.html');
            
        } catch (\Exception | waException | waPaymentException | PaynetEasyException $e) {
            // Handle exception and log error
            $this->executeErrorScenario(
                $e, 
                $order->id, 
                __FUNCTION__
            );
        }
    }


    public function customFields(waOrder $order)
    {
        $fields = array();
        if ($this->payment_method == 'direct') {
            $fields['credit_card_number'] = array(
                'title' => 'Card Number *',
                'description' => '',
                'class' => 'credit_card_number',
                'id' => '',
                'style' => '',
                'size' => '',
                'maxlength' => '',
                'placeholder' => '',
                'control_type' => waHtmlControl::INPUT,
                'required' => true,
                'autocomplete' => 'cc-number'
            );
            $fields['card_printed_name'] = array(
                'title' => 'Printed name *',
                'description' => '',
                'class' => 'card_printed_name',
                'id' => '',
                'style' => '',
                'size' => '',
                'maxlength' => '',
                'placeholder' => 'Printed name',
                'control_type' => waHtmlControl::INPUT,
                'required' => true,
                'autocomplete' => 'cc-name'
            );
            $fields['expire_month'] = array(
                'title' => 'Expiry month *',
                'description' => '',
                'class' => 'expire_month',
                'id' => '',
                'style' => '',
                'size' => '',
                'maxlength' => '2',
                'minlength' => '2',
                'placeholder' => 'MM',
                'control_type' => waHtmlControl::INPUT,
                'required' => true,
                'autocomplete' => 'off'
            );
            $fields['expire_year'] = array(
                'title' => 'Expiry year *',
                'description' => '',
                'class' => 'expire_year',
                'id' => '',
                'style' => '',
                'size' => '',
                'maxlength' => '4',
                'minlength' => '4',
                'placeholder' => 'YYYY',
                'control_type' => waHtmlControl::INPUT,
                'required' => true,
                'autocomplete' => 'off'
            );
            $fields['cvv2'] = array(
                'title' => 'CVC *',
                'description' => '',
                'class' => 'cvv2',
                'id' => '',
                'style' => '',
                'size' => '',
                'maxlength' => '4',
                'minlength' => '3',
                'placeholder' => '',
                'control_type' => waHtmlControl::PASSWORD,
                'required' => true,
                'autocomplete' => 'cc-csc'
            );
        }

        return parent::customFields($order) + $fields;
    }


    private function getCountries(): array
    {
        $countries = [
            ['AF', 'Afghanistan'],
            ['AX', 'Aland Islands'],
            ['AL', 'Albania'],
            ['DZ', 'Algeria'],
            ['AS', 'American Samoa'],
            ['AD', 'Andorra'],
            ['AO', 'Angola'],
            ['AI', 'Anguilla'],
            ['AQ', 'Antarctica'],
            ['AG', 'Antigua And Barbuda'],
            ['AR', 'Argentina'],
            ['AM', 'Armenia'],
            ['AW', 'Aruba'],
            ['AU', 'Australia'],
            ['AT', 'Austria'],
            ['AZ', 'Azerbaijan'],
            ['BS', 'Bahamas'],
            ['BH', 'Bahrain'],
            ['BD', 'Bangladesh'],
            ['BB', 'Barbados'],
            ['BY', 'Belarus'],
            ['BE', 'Belgium'],
            ['BZ', 'Belize'],
            ['BJ', 'Benin'],
            ['BM', 'Bermuda'],
            ['BT', 'Bhutan'],
            ['BO', 'Bolivia'],
            ['BA', 'Bosnia And Herzegovina'],
            ['BW', 'Botswana'],
            ['BV', 'Bouvet Island'],
            ['BR', 'Brazil'],
            ['IO', 'British Indian Ocean Territory'],
            ['BN', 'Brunei Darussalam'],
            ['BG', 'Bulgaria'],
            ['BF', 'Burkina Faso'],
            ['BI', 'Burundi'],
            ['KH', 'Cambodia'],
            ['CM', 'Cameroon'],
            ['CA', 'Canada'],
            ['CV', 'Cape Verde'],
            ['KY', 'Cayman Islands'],
            ['CF', 'Central African Republic'],
            ['TD', 'Chad'],
            ['CL', 'Chile'],
            ['CN', 'China'],
            ['CX', 'Christmas Island'],
            ['CC', 'Cocos (Keeling) Islands'],
            ['CO', 'Colombia'],
            ['KM', 'Comoros'],
            ['CG', 'Congo'],
            ['CD', 'Congo, Democratic Republic'],
            ['CK', 'Cook Islands'],
            ['CR', 'Costa Rica'],
            ['CI', 'Cote DIvoire'],
            ['HR', 'Croatia'],
            ['CU', 'Cuba'],
            ['CY', 'Cyprus'],
            ['CZ', 'Czech Republic'],
            ['DK', 'Denmark'],
            ['DJ', 'Djibouti'],
            ['DM', 'Dominica'],
            ['DO', 'Dominican Republic'],
            ['EC', 'Ecuador'],
            ['EG', 'Egypt'],
            ['SV', 'El Salvador'],
            ['GQ', 'Equatorial Guinea'],
            ['ER', 'Eritrea'],
            ['EE', 'Estonia'],
            ['ET', 'Ethiopia'],
            ['FK', 'Falkland Islands (Malvinas)'],
            ['FO', 'Faroe Islands'],
            ['FJ', 'Fiji'],
            ['FI', 'Finland'],
            ['FR', 'France'],
            ['GF', 'French Guiana'],
            ['PF', 'French Polynesia'],
            ['TF', 'French Southern Territories'],
            ['GA', 'Gabon'],
            ['GM', 'Gambia'],
            ['GE', 'Georgia'],
            ['DE', 'Germany'],
            ['GH', 'Ghana'],
            ['GI', 'Gibraltar'],
            ['GR', 'Greece'],
            ['GL', 'Greenland'],
            ['GD', 'Grenada'],
            ['GP', 'Guadeloupe'],
            ['GU', 'Guam'],
            ['GT', 'Guatemala'],
            ['GG', 'Guernsey'],
            ['GN', 'Guinea'],
            ['GW', 'Guinea-Bissau'],
            ['GY', 'Guyana'],
            ['HT', 'Haiti'],
            ['HM', 'Heard Island & Mcdonald Islands'],
            ['VA', 'Holy See (Vatican City State)'],
            ['HN', 'Honduras'],
            ['HK', 'Hong Kong'],
            ['HU', 'Hungary'],
            ['IS', 'Iceland'],
            ['IN', 'India'],
            ['ID', 'Indonesia'],
            ['IR', 'Iran, Islamic Republic Of'],
            ['IQ', 'Iraq'],
            ['IE', 'Ireland'],
            ['IM', 'Isle Of Man'],
            ['IL', 'Israel'],
            ['IT', 'Italy'],
            ['JM', 'Jamaica'],
            ['JP', 'Japan'],
            ['JE', 'Jersey'],
            ['JO', 'Jordan'],
            ['KZ', 'Kazakhstan'],
            ['KE', 'Kenya'],
            ['KI', 'Kiribati'],
            ['KR', 'Korea'],
            ['KW', 'Kuwait'],
            ['KG', 'Kyrgyzstan'],
            ['LA', 'Lao Peoples Democratic Republic'],
            ['LV', 'Latvia'],
            ['LB', 'Lebanon'],
            ['LS', 'Lesotho'],
            ['LR', 'Liberia'],
            ['LY', 'Libyan Arab Jamahiriya'],
            ['LI', 'Liechtenstein'],
            ['LT', 'Lithuania'],
            ['LU', 'Luxembourg'],
            ['MO', 'Macao'],
            ['MK', 'Macedonia'],
            ['MG', 'Madagascar'],
            ['MW', 'Malawi'],
            ['MY', 'Malaysia'],
            ['MV', 'Maldives'],
            ['ML', 'Mali'],
            ['MT', 'Malta'],
            ['MH', 'Marshall Islands'],
            ['MQ', 'Martinique'],
            ['MR', 'Mauritania'],
            ['MU', 'Mauritius'],
            ['YT', 'Mayotte'],
            ['MX', 'Mexico'],
            ['FM', 'Micronesia, Federated States Of'],
            ['MD', 'Moldova'],
            ['MC', 'Monaco'],
            ['MN', 'Mongolia'],
            ['ME', 'Montenegro'],
            ['MS', 'Montserrat'],
            ['MA', 'Morocco'],
            ['MZ', 'Mozambique'],
            ['MM', 'Myanmar'],
            ['NA', 'Namibia'],
            ['NR', 'Nauru'],
            ['NP', 'Nepal'],
            ['NL', 'Netherlands'],
            ['AN', 'Netherlands Antilles'],
            ['NC', 'New Caledonia'],
            ['NZ', 'New Zealand'],
            ['NI', 'Nicaragua'],
            ['NE', 'Niger'],
            ['NG', 'Nigeria'],
            ['NU', 'Niue'],
            ['NF', 'Norfolk Island'],
            ['MP', 'Northern Mariana Islands'],
            ['NO', 'Norway'],
            ['OM', 'Oman'],
            ['PK', 'Pakistan'],
            ['PW', 'Palau'],
            ['PS', 'Palestinian Territory, Occupied'],
            ['PA', 'Panama'],
            ['PG', 'Papua New Guinea'],
            ['PY', 'Paraguay'],
            ['PE', 'Peru'],
            ['PH', 'Philippines'],
            ['PN', 'Pitcairn'],
            ['PL', 'Poland'],
            ['PT', 'Portugal'],
            ['PR', 'Puerto Rico'],
            ['QA', 'Qatar'],
            ['RE', 'Reunion'],
            ['RO', 'Romania'],
            ['RU', 'Russian Federation'],
            ['RU', 'Russia'],
            ['RU', 'Россия'],
            ['RW', 'Rwanda'],
            ['BL', 'Saint Barthelemy'],
            ['SH', 'Saint Helena'],
            ['KN', 'Saint Kitts And Nevis'],
            ['LC', 'Saint Lucia'],
            ['MF', 'Saint Martin'],
            ['PM', 'Saint Pierre And Miquelon'],
            ['VC', 'Saint Vincent And Grenadines'],
            ['WS', 'Samoa'],
            ['SM', 'San Marino'],
            ['ST', 'Sao Tome And Principe'],
            ['SA', 'Saudi Arabia'],
            ['SN', 'Senegal'],
            ['RS', 'Serbia'],
            ['SC', 'Seychelles'],
            ['SL', 'Sierra Leone'],
            ['SG', 'Singapore'],
            ['SK', 'Slovakia'],
            ['SI', 'Slovenia'],
            ['SB', 'Solomon Islands'],
            ['SO', 'Somalia'],
            ['ZA', 'South Africa'],
            ['GS', 'South Georgia And Sandwich Isl.'],
            ['ES', 'Spain'],
            ['LK', 'Sri Lanka'],
            ['SD', 'Sudan'],
            ['SR', 'Suriname'],
            ['SJ', 'Svalbard And Jan Mayen'],
            ['SZ', 'Swaziland'],
            ['SE', 'Sweden'],
            ['CH', 'Switzerland'],
            ['SY', 'Syrian Arab Republic'],
            ['TW', 'Taiwan'],
            ['TJ', 'Tajikistan'],
            ['TZ', 'Tanzania'],
            ['TH', 'Thailand'],
            ['TL', 'Timor-Leste'],
            ['TG', 'Togo'],
            ['TK', 'Tokelau'],
            ['TO', 'Tonga'],
            ['TT', 'Trinidad And Tobago'],
            ['TN', 'Tunisia'],
            ['TR', 'Turkey'],
            ['TM', 'Turkmenistan'],
            ['TC', 'Turks And Caicos Islands'],
            ['TV', 'Tuvalu'],
            ['UG', 'Uganda'],
            ['UA', 'Ukraine'],
            ['AE', 'United Arab Emirates'],
            ['GB', 'United Kingdom'],
            ['US', 'United States'],
            ['UM', 'United States Outlying Islands'],
            ['UY', 'Uruguay'],
            ['UZ', 'Uzbekistan'],
            ['VU', 'Vanuatu'],
            ['VE', 'Venezuela'],
            ['VN', 'Viet Nam'],
            ['VG', 'Virgin Islands, British'],
            ['VI', 'Virgin Islands, U.S.'],
            ['WF', 'Wallis And Futuna'],
            ['EH', 'Western Sahara'],
            ['YE', 'Yemen'],
            ['ZM', 'Zambia'],
            ['ZW', 'Zimbabwe']
        ];
        return $countries;
    }
    
    
    /**
     * Получает URL-адрес платежа для перенаправления или html формы ввода 3d secure.
     *
     * @return array URL-адрес платежа или html формы ввода 3d secure.
     */
    private function getPayUrl($order): array
    {
        $this->setPayneteasyLogger();

        // Return url
        $params = base64_encode(json_encode([
            'type' => 'return',
            'app_id' => $this->app_id, 
            'merchant_id' => $this->merchant_id
        ]));
        $return_url = $this->getRelayUrl() . '?params=' . $params;

        $email = $order->getContact()->get('email', 'default');
        $phone = $order->getContact()->get('phone', 'default');
        
        // total
        $total = $order->tax_included == false && $order->tax ? $order->tax + $order->total : $order->total;

        $order_params = $order->__get('params');

        $payneteasy_card_number       = $order_params['payment_params_credit_card_number']??'';
        $payneteasy_card_expiry_month = $order_params['payment_params_expire_month']??'';
        $payneteasy_card_expiry_year  = $order_params['payment_params_expire_year']??'';
        $payneteasy_card_name         = $order_params['payment_params_card_printed_name']??'';
        $payneteasy_card_cvv          = $order_params['payment_params_cvv2']??'';

        $card_data = [
            'credit_card_number' => $payneteasy_card_number??'',
            'card_printed_name' => $payneteasy_card_name??'',
            'expire_month' => $payneteasy_card_expiry_month??'',
            'expire_year' => $payneteasy_card_expiry_year??'',
            'cvv2' => $payneteasy_card_cvv??'',
        ];

        $transaction_data = array(
            'order_id' => $order->id,
        );
        $success_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
        $fail_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);

        $notify_url = $this->getRelayUrl().'?transaction_result=result&app_id='.$this->app_id.'&merchant_id='.$this->merchant_id.'&order_id='.$order->id;
//        $success_url = $this->getRelayUrl() . '?transaction_result=success&order_id='.$order->id;
//        $fail_url = $this->getRelayUrl() . '?transaction_result=failure';

        $full_name = $order->getContact()->getName();
        $full_name = explode(' ', $full_name);
        $last_name = $full_name[0];
        $first_name = $full_name[1];
        $shipping_address = $order->shipping_address;
        $country_name = $shipping_address['country_name'];
        $countries = $this->getCountries();

        foreach ($countries as $country) {
            if (isset($country_name) && mb_strtolower($country_name) == mb_strtolower($country[1])) {
                $country_iso = $country[0];
            }
        }

        $city = $shipping_address['city'];
        $zip = $shipping_address['zip'];
        $address = $shipping_address['address'];
        $data = [
            'client_orderid' => (string)$order->id,
            'order_desc' => 'Order # ' . $order->id,
            'amount' => $total,
            'currency' => $order->currency,
            'address1' => $address,
            'city' => $city,
            'zip_code' => $zip,
            'country' => $country_iso??'RU',
            'phone' => $phone,
            'email' => $email,
            'ipaddress' => $_SERVER['REMOTE_ADDR'],
            'cvv2' => $card_data['cvv2'],
            'credit_card_number' => $card_data['credit_card_number'],
            'card_printed_name' => $card_data['card_printed_name'],
            'expire_month' => $card_data['expire_month'],
            'expire_year' => $card_data['expire_year'],
            'first_name' => $first_name,
            'last_name' => $last_name,
            'redirect_success_url' => $success_url,
            'redirect_fail_url' => $fail_url,
            'redirect_url' => $return_url,
            'server_callback_url' => $notify_url,
        ];
        $data['control'] = $this->signPaymentRequest($data, $this->endpoint_id, $this->control_key);

        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > getOrderLink - INPUT: ', [
            'arguments' => [
                'order_id' => $order->id, 
                'email' => $email, 
                'time' => time(), 
                'total' => $total, 
                'return_url' => $return_url,
            ]
        ]);

        $action_url = $this->live_url;
        if ($this->sandbox)
            $action_url = $this->sandbox_url;

        if ($this->payment_method == 'form') {
            $response = $this->getPaynetApi()->saleForm(
                $data,
                $this->payment_method,
                $this->sandbox,
                $action_url,
                $this->endpoint_id
            );
        } elseif ($this->payment_method == 'direct') {
            $response = $this->getPaynetApi()->saleDirect(
                $data,
                $this->payment_method,
                $this->sandbox,
                $action_url,
                $this->endpoint_id
            );
        }

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > getOrderLink - OUTPUT: ', [
            'response' => $response
        ]);

        if (isset($response['paynet-order-id']))
            $paynet_order_id = $response['paynet-order-id'];
        if (isset($response['merchant-order-id']))
            $merchant_order_id = $response['merchant-order-id'];

        if (isset($paynet_order_id)) {
            $model = new waModel();
            $sql = "REPLACE INTO `payneteasy_payments` (`paynet_order_id`, `merchant_order_id`) VALUES ($paynet_order_id, $merchant_order_id)";
            $model->query($sql);
        }

        return $response;
    }

    
    /**
     * Callback Init Method
     *
     * This method initializes the callback by extracting parameters from the request,
     * and then calls the parent callback method for further processing.
     *
     * @param array $request The request parameters
     *
     * @return array Parent's callback method result
     *
     * @throws waPaymentException If the invoice number is invalid
     */
    protected function callbackInit($request)
    {
        try {
            if (isset($request) && !empty($request)) {

                $this->app_id = $request['app_id'] ?? '';
                $this->merchant_id = $request['merchant_id'] ?? '';
                $this->request_type = $request['type'] ?? '';
                
                $this->setOrderId($request);
            } else {
                throw new waPaymentException($this->_w('Invalid invoice number.'));
            }
            // calling parent's method to continue plugin initialization
            return parent::callbackInit($request);
          
        } catch (\Exception | waException | waPaymentException | PaynetEasyException $e) {
            // Handle exception and log error
            $this->executeErrorScenario(
                $e, 
                null, 
                __FUNCTION__, [
                'Request data' => $request
            ]);
        }
    }
    
    
    /**
     * Устанавливаем идентификатор заказа для разных типов запроса (return/webhook)
     *
     * @param array $request The request parameters
     *
     * @return void
     */
    private function setOrderId(array $request): void
    {
        if ($this->request_type === 'return') {
            $this->order_id = $request['order_id'];
        } elseif ($this->request_type === 'webhook') {
            $php_input = json_decode(file_get_contents('php://input'), true) ?: null;
            $this->order_id = $php_input['object']['order_id'] ?? '';
        }
    }


    /**
     * Callback Handler Method
     *
     * This method manages the callback process. It retrieves the order data based on the order_id,
     * processes the transaction, and then redirects the customer based on the transaction status.
     *
     * @param array $request The request parameters
     *
     * @throws waPaymentException If the order ID or the order itself is not found
     */
    protected function callbackHandler($request)
    {
        $this->setPayneteasyLogger()
             ->setOption('additionalCommonText', $this->request_type . '-' . rand(1111, 9999));

        try {
//            if (!$this->order_id || !$request['order_id']) {
//                throw new waPaymentException($this->_w('Order ID not found.'));
//            }

            $order_model = new shopOrderModel();
            $order_by_id = $order_model->getById($request['order_id']);
            $order = waOrder::factory($order_by_id);

//            if (!$order) {
//                throw new waPaymentException($this->_w('Order not found.'));
//            }
            
            $status_data = $this->getPaymentStatusData($this->order_id??$request['order_id']);
            $transaction_data = $this->changeStatus($status_data, $request, $order);

            if ($this->request_type === 'return') {
                if ($transaction_data['state'] == self::STATE_DECLINED || $transaction_data['state'] == self::STATE_CANCELED) {
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                } else {
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
                }
                return array(
                    'redirect' => $url,
                    'message' => 'return'
                );
                wa()->getResponse()->redirect($url);
            } elseif ($this->request_type === 'webhook') {
                die('OK');
            }
            if ($transaction_data['state'] == self::STATE_DECLINED || $transaction_data['state'] == self::STATE_CANCELED) {
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
            } else {
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
            }
            return array(
                'redirect' => $url,
            );
            wa()->getResponse()->redirect($url);
        } catch (\Exception | waException | waPaymentException | PaynetEasyException $e) {
            // Handle exception and log error
            $this->executeErrorScenario(
                $e, 
                $this->order_id??$request['order_id'],
                __FUNCTION__, [
                'Request data' => $request
            ]);
        }
    }
    
    
    /**
     * Получение информации о платеже и изменение статуса заказа
     *
     * @param array $status_data Массив данных статуса платежа.
     *
     * @return array
     */
    private function changeStatus(
        array $status_data, 
        array $request, 
        $order
    ): array {
        $transaction_data = $this->formalizeData($status_data);
        $transaction_data['order_id'] = $order->id;

        $available_statuses = [
            'approved' => [
                'method' => self::CALLBACK_PAYMENT,
                'state' => self::STATE_CAPTURED,
                'type' => self::OPERATION_AUTH_CAPTURE
            ],
            'processing' => [
                'method' => self::CALLBACK_AUTH,
                'state' => self::STATE_AUTH,
                'type' => self::OPERATION_AUTH_ONLY
            ],
            'refunded' => [
                'method' => self::CALLBACK_REFUND,
                'state' => self::STATE_REFUNDED,
                'type' => self::OPERATION_REFUND
            ],
            'declined' => [
                'method' => self::CALLBACK_DECLINE,
                'state' => self::STATE_DECLINED,
                'type' => self::OPERATION_CANCEL
            ],
        ];
            
        $payment_status = trim($status_data['status']);
        $order_status = $available_statuses[$payment_status] ?? [];
        $current_order_status = self::getTransaction($order->__get('params')['payment_transaction_id'] ?? []);

        if (!empty($order_status)) {
            $app_payment_method = $order_status['method'];
            $transaction_data['state'] = $order_status['state'];
            $transaction_data['type'] = $order_status['type'];
        } else {
            $app_payment_method = self::CALLBACK_CANCEL;
            $transaction_data['state'] = self::STATE_CANCELED;
            $transaction_data['type'] = self::OPERATION_CANCEL;
            $this->logger->debug(sprintf(
                __FUNCTION__ . ' > getOrderInfo. Payment not paid or canceled. Order ID: %s', 
                $order->id
            ));
        }

        if (
            $current_order_status['state'] !== $transaction_data['state'] &&
            $current_order_status['type'] !== $transaction_data['type']
        ) {
            $transaction_data = $this->saveTransaction($transaction_data, $request);
            $this->execAppCallback($app_payment_method, $transaction_data);
        }
        
        return $transaction_data;
    }
    
    
    /**
     * Получение ссылки для редиректа на страницу результата оплаты
     *
     * @param array $transaction_data Массив данных транзакции.
     *
     * @return string
     */
    private function getFinalRedirectUrl(array $transaction_data): string
    {
        switch ($transaction_data['state']) {
          case self::STATE_CAPTURED:
          case self::STATE_AUTH:
              $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
              break;
          default:
              $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
              break;
        }
        return $url;
    }
    
    
    /**
     * Получение статуса заказа из Paynet API.
     *
     * @return array Массив данных статуса платежа.
     */
    private function getPaymentStatusData($order_id): array
    {
        $this->setPayneteasyLogger();

        $model = new waModel();
        $sql = "SELECT paynet_order_id FROM `payneteasy_payments` WHERE merchant_order_id = $order_id";
        $paynet_order_id = $model->query($sql)->fetchAll();

        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > status - INPUT: ', [
            'arguments' => [
                'order_id' => $order_id,
                'paynet_order_id' => $paynet_order_id[0]['paynet_order_id']
            ]
        ]);

        $data = [
            'login' => $this->login,
            'client_orderid' => (string)$order_id,
            'orderid' => $paynet_order_id[0]['paynet_order_id'],
        ];
        $data['control'] = $this->signStatusRequest($data, $this->login, $this->control_key);

        $action_url = $this->live_url;
        if ($this->sandbox)
            $action_url = $this->sandbox_url;

        $response = $this->getPaynetApi()->status(
            $data,
            $this->payment_method,
            $this->sandbox,
            $action_url,
            $this->endpoint_id
        );

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > status - OUTPUT: ', [
            'response' => $response
        ]);

        return $response;
    }
    
    
    /**
     * Сценарий, выполняемый после отлова исключения 
     *
     * @param \Exception|PaynetEasyException $e   Объект исключения.
     * @param int $order_id                   Идентификатор заказа.
     * @param string $caller_func             Название функции, котоорая вызывает обработчик исключений.
     * @param bool $context                   Массив данных контекста
     *
     * @return void
     */
    private function executeErrorScenario(
        $e, 
        $order_id = null, 
        $caller_func = '', 
        $context = []
    ): void {
        $this->setPayneteasyLogger();
        if (method_exists($e, 'getContext')) $context = $e->getContext();

        $this->logger->error(sprintf(
            $caller_func . ' > ' . __FUNCTION__ . ' > PaynetEasy exception : %s; Order id: %s;',
            $e->getMessage(),
            $order_id ?: ''
        ), $context);
        
        throw $e;
    }
    
    
    /**
     * Formalize Data Method
     *
     * This method formats the raw transaction data into a more usable format.
     *
     * @param array $transaction_raw_data The raw transaction data
     *
     * @return array The formatted transaction data
     */
    protected function formalizeData($transaction_raw_data): array
    {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['order_id'] = ifset($transaction_raw_data['merchant-order-id']);
        $transaction_data['amount'] = ifset($transaction_raw_data['amount']);
        $transaction_data['currency_id'] = ifset($transaction_raw_data['currency']);
        $transaction_data['native_id'] = $this->order_id;
        return $transaction_data;
    }


    /**
     * Allowed Currency Method
     *
     * This method returns the list of allowed currencies for the payment.
     *
     * @return array List of allowed currencies
     */
     public function allowedCurrency()
     {
         return [
             'RUB',
             'EUR',
             'USD',
             'GBP',
             'UAH',
             'BYN',
             'KZT',
             'AZN',
             'CHF',
             'CZK',
             'CAD',
             'PLN',
             'SEK',
             'TRY',
             'CNY',
             'INR',
             'BRL',
             'UZS'
         ];
     }
    
    
    /**
     * Инициализация и настройка объекта класса PaynetEasyLogger.
     *
     * Эта функция инициализирует и настраивает логгер, используемый плагином PaynetEasy для ведения журнала.
     *
     * @return PaynetEasyLogger
     */
    private function setPayneteasyLogger(): PaynetEasyLogger
    {
        $obj_id = $this->id;
        $logging = $this->logging;

        $this->logger = PaynetEasyLogger::getInstance()
                        ->setOption('showCurrentDate', false)
                        ->setCustomRecording(function($message) use ($obj_id) {
                            self::log($obj_id, $message);
                        }, PaynetEasyLogger::LOG_LEVEL_ERROR)
                        ->setCustomRecording(function($message) use ($obj_id, $logging) {
                            if ($logging) self::log($obj_id, $message);
                        }, PaynetEasyLogger::LOG_LEVEL_DEBUG);
                        
        return $this->logger;
    }


    private function signStatusRequest($requestFields, $login, $merchantControl)
    {
        $base = '';
        $base .= $login;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['orderid'];

        return $this->signString($base, $merchantControl);
    }


    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= $data['amount'] * 100;
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }
}
