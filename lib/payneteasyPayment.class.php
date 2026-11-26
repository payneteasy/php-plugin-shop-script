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
    /** Extra status queries while the customer waits for a "processing" payment */
    private const STATUS_RETRIES = 3;

    /** Seconds between those status queries */
    private const STATUS_RETRY_DELAY = 2;

    /**
     * Seconds during which only one request per order waits for the payment status.
     */
    private const STATUS_WAIT_INTERVAL = 60;

    /**
     * @var int Minutes after which card data of an order with an unopened payment page is removed.
     */
    private const CARD_DATA_MAX_AGE = 15;

    /**
     * @var int Minutes during which the payment form of an unpaid attempt is shown again.
     */
    private const FORM_REUSE_MINUTES = 10;

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

        // The card data of the order is kept only in memory for this request: it
        // leaves the database before anything else can fail or return early.
        try {
            $this->forgetCardData($order->id);
            $this->forgetStaleCardData();
        } catch (\Exception $e) {
            $this->logger->error('payment > cannot remove card data: ' . $e->getMessage() . '; Order id: ' . $order->id);
        }

        try {
            // if (!in_array($order->currency_id, $this->allowedCurrency())) {
            //     throw new waException($this->_w('Currency not supported.'));
            // }
            // The payment page can be shown many times (page reload, reopening the order).
            // A new sale must not be sent while a previous one exists and is approved or still processing.
            $has_payment = $this->getStoredPaynetOrderId($order->id) !== null;
            $remote_status = '';
            if ($has_payment) {
                $status = $this->getPaymentStatusData($order->id);
                $remote_status = trim((string)($status['status'] ?? ''));
            }

            if ($remote_status === 'approved') {
                // The gateway callback may have been lost: mark the order paid now
                $this->syncApprovedOrder($order->id, $status);
                return $this->renderMessage($this->_w('The order has already been paid.'));
            }

            if ($this->payment_method == 'direct') {
                if (!$has_payment) {
                    // Card data is sent only once and is not stored after that
                    $response = $this->getPayUrl($order);
                    if (empty($response['paynet-order-id'])) {
                        return $this->renderGatewayError($order->id, $response);
                    }
                } elseif ($remote_status !== 'processing') {
                    return $this->renderMessage($this->_w('The payment was declined. Please contact the store to pay for the order.'));
                }

                $pay_url = $this->getRelayUrl().'?transaction_result=result&app_id='.$this->app_id.'&merchant_id='.$this->merchant_id.'&order_id='.$order->id;
                if ($this->three_d_secure) {
                    $status = $this->getPaymentStatusData($order->id);
                    $view = wa()->getView();
                    $view->assign('html', $status['html'] ?? '');
                }
            } else {
                // Payment form: while the form of the previous attempt still waits for the
                // card, the customer gets that form again and no new sale is started.
                $pay_url = ($has_payment && $remote_status === 'processing')
                    ? $this->getOpenFormUrl($order->id, $status)
                    : '';
                if ($pay_url === '') {
                    $response = $this->getPayUrl($order);
                    $pay_url = (string)($response['redirect-url'] ?? '');
                }
                if ($pay_url === '') {
                    // Without a form URL the page would reload itself forever and resend the request
                    return $this->renderGatewayError($order->id, $response ?? []);
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


    /**
     * Card fields collected by customFields(); Shop-Script stores them as order params
     */
    private const CARD_PARAM_NAMES = [
        'payment_params_credit_card_number',
        'payment_params_card_printed_name',
        'payment_params_expire_month',
        'payment_params_expire_year',
        'payment_params_cvv2',
    ];


    /**
     * Removes the card data from the order parameters (PCI DSS: CVV must not be stored)
     *
     * @param int $order_id
     *
     * @return void
     */
    private function forgetCardData($order_id): void
    {
        (new shopOrderParamsModel())->deleteByField([
            'order_id' => (int)$order_id,
            'name' => self::CARD_PARAM_NAMES,
        ]);
    }


    /**
     * Removes the card data of orders whose payment page was never opened
     * (the customer left right after placing the order).
     *
     * Only orders of this payment plugin older than CARD_DATA_MAX_AGE minutes.
     *
     * @return void
     */
    private function forgetStaleCardData(): void
    {
        (new waModel())->exec(
            "DELETE p FROM `shop_order_params` p
                JOIN `shop_order` o ON o.id = p.order_id
                JOIN `shop_order_params` pp ON pp.order_id = p.order_id AND pp.name = 'payment_plugin' AND pp.value = s:plugin
              WHERE p.name IN (s:names) AND o.create_datetime < s:before",
            [
                'plugin' => $this->id,
                'names'  => self::CARD_PARAM_NAMES,
                'before' => date('Y-m-d H:i:s', time() - self::CARD_DATA_MAX_AGE * 60),
            ]
        );
    }


    /**
     * Widens payneteasy_payments.paynet_order_id to BIGINT UNSIGNED while the column is still INT.
     *
     * The lib/updates script does not run when the plugin is updated by replacing files without clearing
     * the Webasyst cache, so the schema is checked here. The check runs once:
     * the result is stored in the plugin settings.
     *
     * @return void
     */
    private function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (!$this->getGeneralSettings('payments_attempts')) {
            $this->ensureAttemptColumns();
        }
        $this->ensureFormColumns();
    }


    /**
     * Widens paynet_order_id and turns the table into one row per payment attempt.
     *
     * @return void
     */
    private function ensureAttemptColumns(): void
    {
        try {
            $model = new waModel();
            $column = $model->query("SHOW COLUMNS FROM `payneteasy_payments` LIKE 'paynet_order_id'")->fetchAssoc();
            if (!$column) {
                return;
            }
            if (stripos((string)$column['Type'], 'bigint') === false) {
                $model->exec('ALTER TABLE `payneteasy_payments` MODIFY `paynet_order_id` BIGINT UNSIGNED NOT NULL');
            }
            // One row per payment attempt, not per order
            if (!$model->query("SHOW COLUMNS FROM `payneteasy_payments` LIKE 'id'")->fetchAssoc()) {
                $model->exec('ALTER TABLE `payneteasy_payments` DROP PRIMARY KEY, ADD `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST, ADD INDEX `merchant_order_id` (`merchant_order_id`)');
            }
            $this->setGeneralSettings('paynet_order_id_bigint', 1);
            $this->setGeneralSettings('payments_attempts', 1);
        } catch (waException $e) {
            self::log($this->id, 'Cannot update payneteasy_payments: '.$e->getMessage());
        }
    }


    /**
     * Adds the columns that keep the payment form of an attempt (see getOpenFormUrl()).
     * The check runs once: the result is stored in the plugin settings.
     *
     * @return void
     */
    private function ensureFormColumns(): void
    {
        if ($this->getGeneralSettings('payments_form_url')) {
            return;
        }

        try {
            $model = new waModel();
            if (!$model->query("SHOW COLUMNS FROM `payneteasy_payments` LIKE 'id'")->fetchAssoc()) {
                // The table is missing or could not be converted: nothing to add yet
                return;
            }
            if (!$model->query("SHOW COLUMNS FROM `payneteasy_payments` LIKE 'redirect_url'")->fetchAssoc()) {
                $model->exec("ALTER TABLE `payneteasy_payments` ADD `redirect_url` VARCHAR(1024) NULL, ADD `created` INT UNSIGNED NOT NULL DEFAULT 0");
            }
            $this->setGeneralSettings('payments_form_url', 1);
        } catch (waException $e) {
            self::log($this->id, 'Cannot update payneteasy_payments: '.$e->getMessage());
        }
    }


    /**
     * Returns the paynet_order_id of a payment already created for the order, or null
     *
     * @param int $order_id
     *
     * @return int|null
     */
    private function getStoredPaynetOrderId($order_id): ?int
    {
        $this->ensureSchema();
        $model = new waModel();
        $paynet_order_id = $model->query(
            "SELECT paynet_order_id FROM `payneteasy_payments` WHERE merchant_order_id = i:order_id ORDER BY id DESC LIMIT 1",
            ['order_id' => (int)$order_id]
        )->fetchField('paynet_order_id');

        return $paynet_order_id ? (int)$paynet_order_id : null;
    }


    /**
     * The payment form of the latest attempt, if it can still be used.
     *
     * Only the form of the attempt the gateway reports as processing, started
     * not more than FORM_REUSE_MINUTES ago.
     *
     * @param int   $order_id
     * @param array $status_data Gateway status of the attempt in progress
     *
     * @return string The form URL, or an empty string.
     */
    private function getOpenFormUrl($order_id, array $status_data): string
    {
        try {
            $this->ensureSchema();
            $row = (new waModel())->query(
                "SELECT paynet_order_id, redirect_url, created FROM `payneteasy_payments` WHERE merchant_order_id = i:order_id ORDER BY id DESC LIMIT 1",
                ['order_id' => (int)$order_id]
            )->fetchAssoc();
        } catch (waException $e) {
            return '';
        }

        $url = (string)($row['redirect_url'] ?? '');
        if ($url === '' || strpos($url, 'https://') !== 0
            || (string)$row['paynet_order_id'] !== trim((string)($status_data['paynet-order-id'] ?? ''))
            || time() - (int)$row['created'] > self::FORM_REUSE_MINUTES * 60) {
            return '';
        }

        return $url;
    }


    /**
     * Marks the order paid when the gateway reports approved and its callback has not reached the shop.
     * The status comes from a request made by this server; a sync error does not stop the page.
     *
     * @param int   $order_id
     * @param array $status_data Gateway response to the status request
     *
     * @return void
     */
    private function syncApprovedOrder($order_id, array $status_data): void
    {
        try {
            $order_row = (new shopOrderModel())->getById((int)$order_id);
            if ($order_row && empty($order_row['paid_date'])) {
                $this->changeStatus($status_data, [], waOrder::factory($order_row), false);
            }
        } catch (\Exception | waException | PaynetEasyException $e) {
            $this->logger->error(sprintf(
                __FUNCTION__ . ' > cannot mark order as paid: %s; Order id: %s;',
                $e->getMessage(),
                $order_id
            ));
        }
    }


    /**
     * A server callback of the gateway (not a customer return): PaynetEasy always sends orderid and control
     *
     * @param array $request
     *
     * @return bool
     */
    private function isGatewayCallback(array $request): bool
    {
        return isset($request['orderid'], $request['control']);
    }


    /**
     * The gateway did not create the payment: logs the gateway response (always, error level) and
     * shows the customer a message instead of the payment form.
     *
     * @param int   $order_id
     * @param array $response Gateway response to sale / sale-form
     *
     * @return string
     */
    private function renderGatewayError($order_id, array $response): string
    {
        $this->logger->error(sprintf(
            'Gateway did not create the payment. Order id: %s; type: %s; error-code: %s; error-message: %s',
            $order_id,
            $response['type'] ?? '',
            $response['error-code'] ?? '',
            $response['error-message'] ?? ''
        ));

        return $this->renderMessage($this->_w('The payment could not be started. Please try again later or contact the store.'));
    }


    /**
     * Text message instead of the payment form
     *
     * @param string $message
     *
     * @return string
     */
    private function renderMessage(string $message): string
    {
        return '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
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
     * Gets the payment URL for the redirect or the html of the 3-D Secure form.
     *
     * @return array Payment URL or the html of the 3-D Secure form.
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
        $return_url = $this->getRelayUrl() . '?params=' . rawurlencode($params) . '&order_id=' . (int)$order->id;

        $email = $order->getContact()->get('email', 'default');
        $phone = $order->getContact()->get('phone', 'default');
        
        // The order total already contains the tax, also when it is added on top of the prices
        $total = $order->total;

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

        // Card data must not stay in the database: remove it before the request is sent,
        // so it is gone even if the gateway call fails
        if ($this->payment_method == 'direct') {
            $this->forgetCardData($order->id);
        }

        $notify_url = $this->getRelayUrl().'?transaction_result=result&app_id='.$this->app_id.'&merchant_id='.$this->merchant_id.'&order_id='.$order->id;

        $contact = $order->getContact();
        $first_name = trim((string)$contact->get('firstname'));
        $last_name = trim((string)$contact->get('lastname'));
        if ($first_name === '' && $last_name === '') {
            // Fallback for contacts with only a full name: "First Last"
            $name_parts = preg_split('/\s+/', trim((string)$contact->getName()), 2);
            $first_name = $name_parts[0] ?? '';
            $last_name = $name_parts[1] ?? '';
        }
        $shipping_address = (array)($order->shipping_address ?? []);
        $country_name = $shipping_address['country_name'] ?? null;
        $countries = $this->getCountries();

        foreach ($countries as $country) {
            if (isset($country_name) && mb_strtolower($country_name) == mb_strtolower($country[1])) {
                $country_iso = $country[0];
            }
        }

        $city = $shipping_address['city'] ?? '';
        $zip = $shipping_address['zip'] ?? '';
        $address = $shipping_address['address'] ?? '';
        $data = [
            'client_orderid' => (string)$order->id,
            'order_desc' => 'Order # ' . $order->id,
            'amount' => number_format((float)$total, 2, '.', ''),
            'currency' => $order->currency,
            'address1' => $address,
            'city' => $city,
            'zip_code' => $zip,
            'country' => $country_iso??'RU',
            'phone' => $phone,
            'email' => $email,
            'ipaddress' => (string)waRequest::getIp(),
            'cvv2' => $card_data['cvv2'],
            'credit_card_number' => $card_data['credit_card_number'],
            'card_printed_name' => $card_data['card_printed_name'],
            'expire_month' => $card_data['expire_month'],
            'expire_year' => $card_data['expire_year'],
            'first_name' => $first_name,
            'last_name' => $last_name,
            // The customer always comes back through the plugin: it queries the payment
            // status from the gateway (in case the server callback is late or lost),
            // updates the order and then redirects to the shop's success or fail page.
            'redirect_success_url' => $return_url,
            'redirect_fail_url' => $return_url,
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
            $this->ensureSchema();
            $model = new waModel();
            // Every attempt is kept: the customer may pay in an older tab
            $sql = "INSERT INTO `payneteasy_payments` (`paynet_order_id`, `merchant_order_id`, `redirect_url`, `created`) VALUES (i:paynet_order_id, i:merchant_order_id, s:redirect_url, i:created)";
            $model->exec($sql, [
                'paynet_order_id' => (int)$paynet_order_id,
                'merchant_order_id' => (int)($merchant_order_id ?? $order->id),
                'redirect_url' => substr(trim((string)($response['redirect-url'] ?? '')), 0, 1024),
                'created' => time(),
            ]);
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

                // redirect_url and the callback URL from the settings carry app_id,
                // merchant_id and type packed into ?params=<base64 json>
                if (isset($request['params']) && is_string($request['params'])) {
                    // the value is not urlencoded, so "+" from base64 arrives as a space
                    $params = json_decode((string)base64_decode(strtr($request['params'], ' ', '+'), true), true);
                    // Our own params take precedence: PaynetEasy callbacks carry their own
                    // "type" field (sale/reversal/chargeback), which must not replace type=webhook
                    if (is_array($params)) {
                        foreach (['app_id', 'merchant_id', 'type'] as $key) {
                            if (isset($params[$key]) && is_scalar($params[$key])) {
                                $request[$key] = (string)$params[$key];
                            }
                        }
                    }
                }

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
     * Sets the order ID for each request type (return/webhook)
     *
     * @param array $request The request parameters
     *
     * @return void
     */
    private function setOrderId(array $request): void
    {
        // PaynetEasy sends the shop order id as client_orderid (merchant_order in some responses).
        // The order status itself is always queried from the gateway, never taken from the request.
        if ($this->request_type === 'return' || $this->request_type === 'webhook') {
            try {
                $this->order_id = self::validateOrderId(
                    $request['client_orderid'] ?? $request['merchant_order'] ?? $request['order_id'] ?? null
                );
            } catch (waPaymentException $e) {
                // An invalid number is answered by callbackHandler() as an unknown order
                $this->order_id = null;
            }
        }
    }


    /**
     * Checks that the order ID has digits only
     *
     * @param mixed $order_id
     *
     * @return string
     *
     * @throws waPaymentException If the order ID is not a positive integer
     */
    private static function validateOrderId($order_id): string
    {
        if (!is_scalar($order_id) || !preg_match('/^[1-9][0-9]{0,18}$/', (string)$order_id)) {
            throw new waPaymentException('Invalid order ID.');
        }
        return (string)$order_id;
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

            $order_id = (int)self::validateOrderId($this->order_id ?? $request['order_id'] ?? null);

            $order_model = new shopOrderModel();
            $order_by_id = $order_model->getById($order_id);
            if (empty($order_by_id)) {
                throw new waPaymentException('Order not found.');
            }

            // Only orders placed with this payment plugin may be updated
            $order_params = (new shopOrderParamsModel())->get($order_id);
            if (($order_params['payment_plugin'] ?? null) !== $this->id) {
                throw new waPaymentException('Order is not paid with this payment plugin.');
            }

            $order = waOrder::factory($order_by_id);
            $is_paid = !empty($order_by_id['paid_date']);

            $status_data = $this->getPaymentStatusData($order_id);

            // The customer is waiting on this request (return from the payment form or a click
            // on the payment page). If the payment is not final yet (3-D Secure, bank processing),
            // ask the gateway again a few times instead of relying on a later server callback.
            $customer_request = $this->request_type === 'return'
                || ($this->request_type !== 'webhook' && !$this->isGatewayCallback($request));
            // The return URL is public: only one request per order may wait for the
            // status at a time, repeated requests get the current status at once.
            if ($customer_request && trim((string)($status_data['status'] ?? '')) === 'processing') {
                $customer_request = $this->acquireStatusWait($order_id);
            }
            for ($attempt = 0;
                 $customer_request && $attempt < self::STATUS_RETRIES
                    && trim((string)($status_data['status'] ?? '')) === 'processing';
                 $attempt++) {
                sleep(self::STATUS_RETRY_DELAY);
                $status_data = $this->getPaymentStatusData($order_id);
            }

            // The customer return and the server callback come almost together: the order
            // is updated by one request at a time, with its paid state read under the lock.
            $order_lock = $this->lockOrder($order_id);
            try {
                $order_by_id = $order_model->getById($order_id);
                $is_paid = !empty($order_by_id['paid_date']);
                $transaction_data = $this->changeStatus($status_data, $request, waOrder::factory($order_by_id), $is_paid);
            } finally {
                $this->unlockOrder($order_lock);
            }

            try {
                $this->forgetStaleCardData();
            } catch (\Exception $e) {
                $this->logger->error(__FUNCTION__ . ' > cannot remove stale card data: ' . $e->getMessage());
            }

            if ($this->request_type === 'return') {
                if (($transaction_data['state'] ?? null) == self::STATE_DECLINED || ($transaction_data['state'] ?? null) == self::STATE_CANCELED) {
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                } else {
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
                }
                return array(
                    'redirect' => $url,
                    'message' => 'return'
                );
                wa()->getResponse()->redirect($url);
            } elseif ($this->request_type === 'webhook' || $this->isGatewayCallback($request)) {
                // Server-to-server callback from PaynetEasy: answer 200 "OK", not a redirect.
                // An empty 'template' makes the payments relay exit after the output.
                echo 'OK';
                return array('template' => false);
            }
            if (($transaction_data['state'] ?? null) == self::STATE_DECLINED || ($transaction_data['state'] ?? null) == self::STATE_CANCELED) {
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
            } else {
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
            }
            return array(
                'redirect' => $url,
            );
            wa()->getResponse()->redirect($url);
        } catch (waPaymentException $e) {
            // Unknown order, an order of another payment method, an invalid number:
            // nothing to update. The request is answered without a server error.
            $this->logger->error(sprintf(
                __FUNCTION__ . ' > %s; Order id: %s',
                $e->getMessage(),
                is_scalar($this->order_id ?? null) ? $this->order_id : ''
            ));
            if ($this->request_type === 'webhook' || $this->isGatewayCallback((array)$request)) {
                wa()->getResponse()->setStatus(404);
                wa()->getResponse()->sendHeaders();
                echo 'Order not found';
                return array('template' => false);
            }
            return array(
                'redirect' => wa()->getRouteUrl('shop/frontend', array(), true),
            );
        } catch (\Exception | waException | PaynetEasyException $e) {
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
     * Takes the exclusive lock of the order, waiting for another request to finish.
     *
     * @param int $order_id Order ID.
     *
     * @return resource|null The lock handle, or null when the lock file cannot be used.
     */
    private function lockOrder(int $order_id)
    {
        try {
            $dir = wa()->getTempPath('payneteasy', 'shop');
            waFiles::create($dir, true);
            $handle = @fopen($dir . '/order-lock-' . $order_id, 'c');
            if ($handle && flock($handle, LOCK_EX)) {
                return $handle;
            }
            if ($handle) {
                fclose($handle);
            }
        } catch (\Throwable $e) {
            // Without the lock the order is updated as before
        }
        return null;
    }


    /**
     * Releases the lock taken by lockOrder().
     *
     * @param resource|null $handle
     *
     * @return void
     */
    private function unlockOrder($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }


    /**
     * Allows a request to wait for the final payment status of the order.
     *
     * The return URL can be opened by anyone, and waiting means several status
     * requests to the gateway. One request per order may wait within
     * STATUS_WAIT_INTERVAL seconds; the others answer with the current status.
     *
     * @param int $order_id Order ID.
     *
     * @return bool TRUE if this request may wait.
     */
    private function acquireStatusWait(int $order_id): bool
    {
        try {
            $dir = wa()->getTempPath('payneteasy', 'shop');
            waFiles::create($dir, true);
            $handle = @fopen($dir . '/status-wait-' . $order_id, 'c+');
            if (!$handle) {
                return false;
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                return false;
            }
            $last = (int) stream_get_contents($handle);
            $allowed = time() - $last >= self::STATUS_WAIT_INTERVAL;
            if ($allowed) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) time());
                fflush($handle);
            }
            flock($handle, LOCK_UN);
            fclose($handle);
            return $allowed;
        } catch (\Throwable $e) {
            return false;
        }
    }


    /**
     * Gets the payment data and changes the order status
     *
     * @param array $status_data Payment status data.
     * @param bool $is_paid       The order is already paid.
     *
     * @return array
     */
    private function changeStatus(
        array $status_data, 
        array $request, 
        $order,
        bool $is_paid = false
    ): array {
        $transaction_data = $this->formalizeData($status_data);
        $transaction_data['order_id'] = $order->id;

        $available_statuses = [
            'approved' => [
                'method' => self::CALLBACK_PAYMENT,
                'state' => self::STATE_CAPTURED,
                'type' => self::OPERATION_AUTH_CAPTURE
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
            'error' => [
                'method' => self::CALLBACK_DECLINE,
                'state' => self::STATE_DECLINED,
                'type' => self::OPERATION_CANCEL
            ],
            'filtered' => [
                'method' => self::CALLBACK_DECLINE,
                'state' => self::STATE_DECLINED,
                'type' => self::OPERATION_CANCEL
            ],
        ];
            
        // 'processing' (customer still paying), unknown or absent status: leave the order unchanged
        $payment_status = trim((string)($status_data['status'] ?? ''));
        $order_status = $available_statuses[$payment_status] ?? [];

        if (empty($order_status)) {
            $this->logger->debug(sprintf(
                __FUNCTION__ . ' > getOrderInfo. Payment not final, order unchanged. Status: %s; Order ID: %s', 
                $payment_status,
                $order->id
            ));
            return $transaction_data;
        }

        // Never downgrade an already paid order (only a refund may follow a payment)
        if ($is_paid && $order_status['state'] !== self::STATE_REFUNDED) {
            $this->logger->debug(sprintf(
                __FUNCTION__ . ' > getOrderInfo. Order already paid, order unchanged. Status: %s; Order ID: %s', 
                $payment_status,
                $order->id
            ));
            return $transaction_data;
        }

        $current_order_status = self::getTransaction($order->__get('params')['payment_transaction_id'] ?? []);
        $app_payment_method = $order_status['method'];
        $transaction_data['state'] = $order_status['state'];
        $transaction_data['type'] = $order_status['type'];

        if (
            $current_order_status['state'] !== $transaction_data['state'] &&
            $current_order_status['type'] !== $transaction_data['type']
        ) {
            // A repeated return or callback for the same gateway transaction reuses its record
            $known = self::getTransactionsByFields([
                'plugin'    => $this->id,
                'order_id'  => $order->id,
                'native_id' => $transaction_data['native_id'],
                'state'     => $transaction_data['state'],
                'type'      => $transaction_data['type'],
            ]);
            if ($known) {
                $transaction_data['id'] = (int)key($known);
            } else {
                $transaction_data = $this->saveTransaction($transaction_data, $request);
            }
            $result = $this->execAppCallback($app_payment_method, $transaction_data);

            // The reused record keeps the error of an earlier refusal: clear it once the shop accepts
            if ($known && is_array($result) && empty($result['error']) && !empty(reset($known)['error'])) {
                (new waTransactionModel())->updateById($transaction_data['id'], ['error' => null]);
            }

            // The application refused the payment (e.g. the amount does not match the order):
            // the order is not paid, the customer must not see the success page.
            if ($order_status['state'] === self::STATE_CAPTURED && is_array($result) && !empty($result['error'])) {
                $this->logger->error(sprintf(
                    __FUNCTION__ . ' > the shop did not accept the payment: %s; Order ID: %s',
                    is_scalar($result['error']) ? $result['error'] : 'error',
                    $order->id
                ));
                $transaction_data['state'] = self::STATE_DECLINED;
            }
        }
        
        return $transaction_data;
    }
    
    
    /**
     * Gets the URL of the payment result page
     *
     * @param array $transaction_data Transaction data.
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
     * Gets the order status from the Paynet API.
     *
     * @return array Payment status data.
     */
    private function getPaymentStatusData($order_id): array
    {
        $this->setPayneteasyLogger();

        $order_id = (int)$order_id;
        $this->ensureSchema();
        $model = new waModel();
        // All attempts of the order, newest first: an approved attempt wins,
        // then a processing one, otherwise the latest attempt
        $sql = "SELECT paynet_order_id FROM `payneteasy_payments` WHERE merchant_order_id = i:order_id ORDER BY id DESC LIMIT 20";
        $attempts = $model->query($sql, ['order_id' => $order_id])->fetchAll();
        if (empty($attempts[0]['paynet_order_id'])) {
            throw new waPaymentException('PaynetEasy payment not found for order.');
        }

        $action_url = $this->live_url;
        if ($this->sandbox)
            $action_url = $this->sandbox_url;

        $latest = null;
        $processing = null;

        foreach ($attempts as $row) {
            $data = [
                'login' => $this->login,
                'client_orderid' => (string)$order_id,
                'orderid' => (string)$row['paynet_order_id'],
            ];
            $data['control'] = $this->signStatusRequest($data, $this->login, $this->control_key);

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
                'paynet_order_id' => $row['paynet_order_id'],
                'response' => $response
            ]);

            // The gateway answers for any transaction id: use only answers for this order
            if (isset($response['merchant-order-id']) && trim((string)$response['merchant-order-id']) !== (string)$order_id) {
                continue;
            }

            $status = trim((string)($response['status'] ?? ''));
            if ($status === 'approved') {
                return $response;
            }
            if ($status === 'processing' && $processing === null) {
                $processing = $response;
            }
            if ($latest === null) {
                $latest = $response;
            }
        }

        return $processing ?? $latest ?? [];
    }
    
    
    /**
     * Runs after an exception is caught 
     *
     * @param \Exception|PaynetEasyException $e   Exception object.
     * @param int $order_id                   Order ID.
     * @param string $caller_func             Name of the function that calls the exception handler.
     * @param bool $context                   Context data
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
        $transaction_data['native_id'] = $transaction_raw_data['paynet-order-id'] ?? $this->order_id;
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
     * Creates and configures the PaynetEasyLogger object.
     *
     * This function sets up the logger the PaynetEasy plugin writes its log with.
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
        $base .= (int)round((float)$data['amount'] * 100);
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }
}
