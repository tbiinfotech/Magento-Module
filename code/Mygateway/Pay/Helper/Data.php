<?php
class Mygateway_Pay_Helper_Data extends Mage_Core_Helper_Abstract
{
	 public function canCancel(Mage_Sales_Model_Order $order)
    {
        // If order cancelation is disabled in system configuration: return false
        if(!Mage::getStoreConfigFlag('sales/cancel/enabled')) {
            return false;
        }
		
		//If order status is closed
		if($order->getStatus()=='closed')
		{
			return false;
		}
		
        // If Magento decides that this order cannot be canceled
        /*if(!$order->canCancel()) {
            return false;
        }

        // If order has shipment(s) but can still be shipped, it means that is partially ship.
        // If order is partially shipped and that cancelation of partially shipped orders is disabled in system config: return false
        /*if($order->hasShipments() && $order->canShip() && !Mage::getStoreConfigFlag('sales/cancel/cancel_partially_shipped')) {
            return false;
        }

        // If order cannot be shipped is means that is has been fully shipped.
        // If order has been fully shipped and that cancelation of fully shipped order is disabled in system config: return false
        if(!$order->canShip() && !Mage::getStoreConfigFlag('sales/cancel/cancel_fully_shipped')) {
            return false;
        }

        // Calculate the number of days since the order's datetime
        $dateModel = Mage::getModel('core/date');
        $createdAt = $order->getCreatedAtStoreDate();
        $deltaDays = ($dateModel->gmtTimestamp() - $dateModel->gmtTimestamp($createdAt)) / 86400;

        // If the numebr of days since order's datetime is larger than the cancelation leadtime in system config: return false
        if(Mage::getStoreConfig('sales/cancel/leadtime') !== '' && $deltaDays > Mage::getStoreConfig('sales/cancel/leadtime')) {
            return false;
        }*/

        // Else... return true
        return true;
    }
}
	 