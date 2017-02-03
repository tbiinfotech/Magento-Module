<?php
$installer = $this;
/* @var $installer Mage_Customer_Model_Entity_Setup */

$installer->startSetup();

$installer->run("
ALTER TABLE `{$installer->getTable('sales/order_payment')}` ADD `auth_code` VARCHAR( 100 ) NULL ;
ALTER TABLE `{$installer->getTable('sales/order_payment')}` ADD `trans_type` VARCHAR( 100 ) NULL ;
");

$installer->endSetup();
