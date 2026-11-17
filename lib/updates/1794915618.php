<?php

// paynet_order_id was a signed INT (max 2147483647); PaynetEasy order ids may exceed it
$model = new waModel();
try {
    $model->exec('ALTER TABLE `payneteasy_payments` MODIFY `paynet_order_id` BIGINT UNSIGNED NOT NULL');
} catch (waDbException $e) {
    waLog::log('payneteasy: cannot widen payneteasy_payments.paynet_order_id: '.$e->getMessage(), 'payment/payneteasy.log');
}
