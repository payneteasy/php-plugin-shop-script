<?php

// The payment form of an attempt is kept, so that the customer gets the same
// form again while it is not paid, instead of a new sale on every page view
$model = new waModel();
try {
    if (!$model->query("SHOW COLUMNS FROM `payneteasy_payments` LIKE 'redirect_url'")->fetchAssoc()) {
        $model->exec("ALTER TABLE `payneteasy_payments` ADD `redirect_url` VARCHAR(1024) NULL, ADD `created` INT UNSIGNED NOT NULL DEFAULT 0");
    }
} catch (waDbException $e) {
    waLog::log('payneteasy: cannot update payneteasy_payments: '.$e->getMessage(), 'payment/payneteasy.log');
}
