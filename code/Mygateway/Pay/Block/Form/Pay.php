<?php
class Mygateway_Pay_Block_Form_Pay extends Mage_Payment_Block_Form_Ccsave
{
	 protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('pay/form/pay.phtml');
    }
}
