<?php

$model = new waModel();

$model->query('CREATE TABLE IF NOT EXISTS `payneteasy_payments` (`paynet_order_id` int NOT NULL, `merchant_order_id` int NOT NULL, PRIMARY KEY  (`merchant_order_id`));');