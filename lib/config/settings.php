<?php
$settings = [
    'live_url' => [
        'value' => '',
        'title' => 'Gateway url (LIVE)',
        'description' => _wp('https://gate.payneteasy.com/ etc.'),
        'control_type' => 'input',
        'required' => true
    ],
    'sandbox_url' => [
        'value' => '',
        'title' => 'Gateway url (SANDBOX)',
        'description' => _wp('https://sandbox.payneteasy.com/ etc.'),
        'control_type' => 'input'
    ],
    'login' => [
        'value' => '',
        'title' => 'Login *',
        'description' => _wp('Merchant Login is required to call the API.'),
        'control_type' => 'input',
        'required' => true
    ],
    'control_key' => [
        'value' => '',
        'title' => 'Control key *',
        'description' => _wp('Merchant Control key is required to call the API.'),
        'control_type' => 'input',
        'required' => true
    ],
    'endpoint_id' => [
        'value' => '',
        'title' => 'Endpoint id',
        'description' => _wp('Merchant Endpoint id is required to call the API.'),
        'control_type' => 'input',
        'required' => true
    ],
    'sandbox' => [
        'value' => '1',
        'title' => _wp('Sandbox mode'),
        'description' => _wp('In this mode, the payment for the goods is not charged.s'),
        'control_type' => 'checkbox',
    ],
    'logging' => [
        'value' => '1',
        'title' => _wp('Logging'),
        'description' => _wp('Logging is used to debug plugin performance by storing API request data.'),
        'control_type' => 'checkbox',
    ],
    'three_d_secure' => [
        'value' => '0',
        'title' => _wp('3D Secure'),
        'description' => _wp('3D Secure or Non 3D Secure (WORK ONLY WITH DIRECT INTEGRATION METHOD)'),
        'control_type' => 'checkbox',
    ],
    'payment_method' => [
        'value' => 'form',
        'title' => _wp('Payment method'),
        'description' => _wp(''),
        'control_type' => waHtmlControl::SELECT,
        'options' => [
        ['value' => 'form', 'title' => _wp('Form'), 'description' => _wp('')], 
        ['value' => 'direct', 'title' => _wp('Direct'), 'description' => _wp('')]
    ]
    ],
];

if (is_numeric($this->getPluginKey())) {
    $settings['callback_url'] = [
        'value' => wa()->getRootUrl(true, true) . 'payments.php/' . $this->id . '/?params=' . base64_encode(json_encode([
            'type' => 'webhook',
            'app_id' => $this->app_id, 
            'merchant_id' => $this->getPluginKey()
        ])),
        'title' => _wp('Callback URL'),
        'description' => _wp(''),
        'control_type' => waHtmlControl::TEXTAREA,
        'readonly' => 'readonly'
    ];
}

return $settings;