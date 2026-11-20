<?php

return array(
    'payneteasy_payments' => array(
        'id' => array('int', 11, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'paynet_order_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        'merchant_order_id' => array('int', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'merchant_order_id' => 'merchant_order_id',
        ),
        ':options' => ['charset' => 'utf8mb4'],
    ),
);
