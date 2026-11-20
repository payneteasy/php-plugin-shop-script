<?php

// Every payment attempt of an order is kept (a customer may pay in an older tab):
// one row per attempt instead of one row per order
$model = new waModel();
try {
    $column = $model->query("SHOW COLUMNS FROM `payneteasy_payments` LIKE 'id'")->fetchAssoc();
    if (!$column) {
        $model->exec('ALTER TABLE `payneteasy_payments` DROP PRIMARY KEY, ADD `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST, ADD INDEX `merchant_order_id` (`merchant_order_id`)');
    }
} catch (waDbException $e) {
    waLog::log('payneteasy: cannot update payneteasy_payments: '.$e->getMessage(), 'payment/payneteasy.log');
}
