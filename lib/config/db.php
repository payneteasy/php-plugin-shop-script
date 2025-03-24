<?php

return array(
    'payneteasy_payments' => array(
        'paynet_order_id' => array('int', 'null' => 0),
        'merchant_order_id' => array('int', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'merchant_order_id',
        ),
        ':options' => ['charset' => 'utf8mb4'],
    ),
);