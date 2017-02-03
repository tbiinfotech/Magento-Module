<?php
class Mygateway_Pay_IndexController extends Mage_Core_Controller_Front_Action{
    public function indexAction()
	{
		$this->loadLayout();
		$this->renderLayout();
	}

	public function redirectAction()
	{

		$this->loadLayout();
		$block = $this->getLayout()->createBlock('Mage_Core_Block_Template','pay',array('template' => 'pay/form/cvv.phtml'));
		$this->getLayout()->getBlock('content')->append($block);
		$this->renderLayout();
	}
	
	public function successAction()
	{
		$request = $_REQUEST;
		Mage::log($request, null, 'lps.log');
		$orderIncrementId = $request['Merchant_ref_number'];
		Mage::log($orderIncrementId);
		$order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
		Mage::log($order->getId());
		Mage::log($order->getId(), null, 'lps.log');
		try{
			if($request['Status_'] == 05){
				$comment = $order->sendNewOrderEmail()->addStatusHistoryComment('4Pay Status : Declined')
				->setIsCustomerNotified(false)
				->save();
				$this->_forward('error');
			}
			elseif($request['Status_'] == 90){
				$comment = $order->sendNewOrderEmail()->addStatusHistoryComment('4Pay Status : Communication Failed')
				->setIsCustomerNotified(false)
				->save();
				$this->_forward('error');
			}elseif($request['Status_'] == 00){
				$comment = $order->sendNewOrderEmail()->addStatusHistoryComment('4Pay Status : ----')
				->setIsCustomerNotified(false)
				->save();
				$payment = $order->getPayment();
				$grandTotal = $order->getBaseGrandTotal();
				if(isset($request['Transactionid'])){
					$tid = $request['Transactionid'];
				}
				else {
					$tid = -1 ;
				}
					
				$payment->setTransactionId($tid)
				->setPreparedMessage("Payment Sucessfull Result:")
				->setIsTransactionClosed(0)
				->registerAuthorizationNotification($grandTotal);
				$order->save();


				/*if ($invoice = $payment->getCreatedInvoice()) {
				 $message = Mage::helper('pay')->__('Notified customer about invoice #%s.', $invoice->getIncrementId());
				$comment = $order->sendNewOrderEmail()->addStatusHistoryComment($message)
				->setIsCustomerNotified(true)
				->save();
				}*/
				try {
					if(!$order->canInvoice())
					{
						Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
					}

					$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

					if (!$invoice->getTotalQty()) {
						Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
					}

					$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
					//Or you can use
					//$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
					$invoice->register();
					$transactionSave = Mage::getModel('core/resource_transaction')
					->addObject($invoice)
					->addObject($invoice->getOrder());

					$transactionSave->save();
					$message = Mage::helper('pay')->__('Notified customer about invoice #%s.', $invoice->getIncrementId());
					$comment = $order->sendNewOrderEmail()->addStatusHistoryComment($message)
					->setIsCustomerNotified(true)
					->save();
				}
				catch (Mage_Core_Exception $e) {

				}
				//Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
				//$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true)->save();
				$url = Mage::getUrl('checkout/onepage/success', array('_secure'=>true));
				Mage::register('redirect_url',$url);
				$this->_redirectUrl($url);
			}
		}
		catch(Exception $e)
		{
			Mage::logException($e);
		}
	}

	protected function _getCheckout()
	{
		return Mage::getSingleton('checkout/session');
	}

	public function errorAction()
	{
		$request = $_REQUEST;
		Mage::log($request, null, 'lps.log');
		$gotoSection = false;
		$session = $this->_getCheckout();
		if ($session->getLastRealOrderId()) {
			$order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
			if ($order->getId()) {
				//Cancel order
				if ($order->getState() != Mage_Sales_Model_Order::STATE_CANCELED) {
					$order->registerCancellation($errorMsg)->save();
				}
				$quote = Mage::getModel('sales/quote')
				->load($order->getQuoteId());
				//Return quote
				if ($quote->getId()) {
					$quote->setIsActive(1)
					->setReservedOrderId(NULL)
					->save();
					$session->replaceQuote($quote);
				}

				//Unset data
				$session->unsLastRealOrderId();
				//Redirect to payment step
				$gotoSection = 'payment';
				$url = Mage::getUrl('checkout/onepage/index', array('_secure'=>true));
				Mage::register('redirect_url',$url);
				$this->_redirectUrl($url);
			}
		}

		return $gotoSection;
	}
	
	public function cancelAction()
    {		
		// Retrieve order_id passed by clicking on "Cancel Order" in customer account
        $orderId = $this->getRequest()->getParam('order_id');

        // Load Mage_Sales_Model_Order object
        $order = Mage::getModel('sales/order')->load($orderId);

        // Retrieve catalog session.
        // We must use catalog session as customer session messages are not initiated for sales order view
        // and this is where we want to redirect at the end of this action
        // @see Mage_Sales_Controller_Abstract::_viewAction()
        $session = Mage::getSingleton('catalog/session');
			
		//Get order payment instance
		$payment = $order->getPayment();
		$payment_array = $payment->getData();
		$payment->setParentTransactionId($payment_array['last_trans_id']);
		$amount = number_format($order->getTotalPaid(), 0, '.', '');
		
        try {

            // Make sure that the order can still be canceled since customer clicked on "Cancel Order"
            if(!Mage::helper('pay')->canCancel($order)) {
                throw new Exception('Order cannot be canceled anymore.');
            }
		
			Mage::getModel('pay/pay')->refund($payment, $amount);
			
            // Cancel and save the order
            $order->cancel();
            $order->setStatus('closed');
            $order->save();

			$items = $order->getAllVisibleItems();
			$sku='';
			foreach($items as $items_data)
			{
				$sku=Mage::getModel('catalog/product')->load($items_data->getProductId())->getSku();
			}
			
			$orderItem = $order->getItemsCollection()->getItemByColumnValue('sku', $sku);
			$service = Mage::getModel('sales/service_order', $order);
			$data = array(
				'qtys' => array(
					$orderItem->getId() => 1
				)
			);
			$creditMemo = $service->prepareCreditmemo($data)->register()->save();
			
            // If sending transactionnal email is enabled in system configuration, we send the email
            if(Mage::getStoreConfigFlag('sales/cancel/send_email')) {
                $order->sendOrderUpdateEmail();
            }

            $session->addSuccess($this->__('The order has been canceled.'));
        }
        catch (Exception $e) {
			Mage::logException($e);
            $session->addError($this->__('The order cannot be canceled.'.$e));
        }

        // Redirect to current sale order view
        $this->_redirect('sales/order/view', array('order_id' => $orderId));
    }
	
	public function createOrderAction()
	{
		if(isset($_REQUEST['xml']))
		{
			unset($_REQUEST['xml']);
		}
		
		$from_data = Mage::getSingleton('core/session')->getFromData();
		$quote = Mage::getSingleton('checkout/session')->getQuote()->getId();
		if(isset($from_data) && $from_data=='from_frontend' && isset($quote) && !empty($quote))
		{
			Mage::getSingleton('core/session')->unsFromData();
			
			$quote = Mage::getSingleton('checkout/session')->getQuote();
		
			$ccmethod = Mage::getSingleton('core/session')->getCreditMethod();
			if(isset($ccmethod) && $ccmethod!='')
			{
				
				$ccmethod_data=$ccmethod;
			}
			
			$cctype = Mage::getSingleton('core/session')->getCreditType();
			if(isset($cctype) && $cctype!='')
			{
				
				$cctype_data=$cctype;
			}
			
			$ccnumber = Mage::getSingleton('core/session')->getCreditNumber();
			if(isset($ccnumber) && $ccnumber!='')
			{
				
				$ccnumber_data=$ccnumber;
			}	
			
			$ccmonth = Mage::getSingleton('core/session')->getCreditMonth();
			if(isset($ccmonth) && $ccmonth!='')
			{
				
				$ccmonth_data=$ccmonth;
			}	
			
			$ccyear = Mage::getSingleton('core/session')->getCreditYear();
			if(isset($ccyear) && $ccyear!='')
			{
				
				$ccyear_data=$ccyear;
			}	
			
			$cccid = Mage::getSingleton('core/session')->getCreditCid();
			if(isset($cccid) && $cccid!='')
			{
				$cccid_data=$cccid;
			}	
			
			$quote->getPayment()->importData(array('method' => 'checkmo'));
			
			$quote->collectTotals()->save();  
			$service = Mage::getModel('sales/service_quote', $quote);
			$service->submitAll(); 
			$order = $service->getOrder();
			
			
			//Mage::getSingleton('checkout/session')->setLastRealOrderId($order->getIncrementId());
			//Mage::getSingleton('checkout/session')->setLastSuccessQuoteId($quote->getId());
			
			if(isset($_REQUEST['pay_data']))
			{
				$pay_data = json_decode($_REQUEST['pay_data']);
				$result = array();
				
				$result['transaction_id'] = $pay_data->response->transaction_id;
				$result['authcode'] = $pay_data->response->authcode;
				$result['transaction_type'] = $pay_data->response->transaction_type;
				$result['amount'] = number_format($pay_data->response->amount/100, 0, '.', '');
			}
			
			$order_new = Mage::getModel('sales/order')->loadByIncrementId($order->getIncrementId());
			$payment = $order_new->getPayment();
			$payment->setMethod($ccmethod_data);
			$payment->setCcType($cctype_data); 
			$payment->setCcLast4(substr($ccnumber_data, -4)); 
			$payment->setLastTransId($result['transaction_id']); 
			$payment->setBaseAmountAuthorized($result['amount']); 
			$payment->setBaseAmountPaidOnline($result['amount']); 
			$payment->setAmountAuthorized($result['amount']); 
			$payment->setAuthCode($result['authcode']); 
			$payment->setTransType($result['transaction_type']); 
			$payment->save();
			$order_new->setStatus('processing');
			$order_new->save();
			
			$PaymentOrder_oid = $order_new->getId();
			$PaymentOrder_tid = $result['transaction_id'];
			$PaymentOrder_addinfo = serialize(array('real_transaction_id'=>$result['transaction_id']));
			
			
			$resource = Mage::getSingleton('core/resource');
			$writeConnection = $resource->getConnection('core_write');
			$table = $resource->getTableName('sales/payment_transaction');
			
			$query = "SET foreign_key_checks = 0";
			$writeConnection->query($query);
		
			$query = "INSERT INTO {$table} (`order_id`, `payment_id`, `txn_id`, `txn_type`, `is_closed`, `additional_information`) VALUES (".$PaymentOrder_oid.", ".$PaymentOrder_oid.", '".$PaymentOrder_tid."', 'capture', 0, '".$PaymentOrder_addinfo."')";
			$writeConnection->query($query);
			
			$query = "SET foreign_key_checks = 1";
			$writeConnection->query($query);
			
			
			//Create invoice
			try
			{
				if(!$order_new->canInvoice())
				{
					Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
				}
				 
				$invoice = Mage::getModel('sales/service_order', $order_new)->prepareInvoice();
				 
				if (!$invoice->getTotalQty())
				{
					Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
				}
				 
				$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
				$invoice->register();
				$transactionSave = Mage::getModel('core/resource_transaction')
									->addObject($invoice)
									->addObject($invoice->getOrder());
				 
				$transactionSave->save();
			}
			catch (Mage_Core_Exception $e)
			{
				Mage::throwException(Mage::helper('core')->__('Error. Cannot create an invoice.'));
			}
			
			Mage::getSingleton('core/session')->unsCreditMethod();
			Mage::getSingleton('core/session')->unsCreditType();
			Mage::getSingleton('core/session')->unsCreditNumber();
			Mage::getSingleton('core/session')->unsCreditMonth();
			Mage::getSingleton('core/session')->unsCreditYear();
			Mage::getSingleton('core/session')->unsCreditCid();
			
			//Mage::getSingleton('checkout/session')->setLastRealOrderId($order->getIncrementId());
			//Mage::getSingleton('checkout/session')->setLastSuccessQuoteId($order->getIncrementId());
			
			$orderId = $service->getOrder()->getId(); // Added this line
			$incrementId = $service->getOrder()->getIncrementId();

			$onepage = Mage::getSingleton('checkout/type_onepage');
			$checkout = $onepage->getCheckout();
			$checkout->setLastQuoteId($quote->getId());
			$checkout->setLastSuccessQuoteId($quote->getId());
			$checkout->setLastOrderId($orderId); // Not incrementId!!
			$checkout->setLastRealOrderId($incrementId);
			
			
			
			//Mage::app()->getResponse()->setRedirect(Mage::getUrl('checkout/onepage/index', array('_secure'=>true)));
			Mage::getSingleton('checkout/cart')->truncate();
			Mage::getSingleton('checkout/cart')->save();
			
			
			/*$onepage = Mage::getSingleton('checkout/type_onepage');
			$checkout = $onepage->getCheckout();
			//$checkout->setLastQuoteId($quote->getId());
			$checkout->setLastSuccessQuoteId($quote->getId());
			$checkout->setLastOrderId($order->getIncrementId());
			//$checkout->setLastRealOrderId($order->getIncrementId());*/
			
			
			//Mage_Core_Controller_Varien_Action::_redirect( 'checkout/onepage/success' ); return $this;
			Mage::app()->getResponse()->setRedirect(Mage::getBaseUrl() . 'checkout/onepage/success')->sendResponse();
			
		}
		else
		{
			$pay_data = base64_encode($_REQUEST['pay_data']);
			echo Mage::app()->getResponse()->setRedirect(Mage::helper('adminhtml')->getUrl("adminhtml/sales_order_create/save", array('_success'=>true, 'pay_data'=>$pay_data)));
			Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('adminhtml/sales_order_create/save', array('_success'=>true, 'pay_data'=>$pay_data)));
			die('redirect');
		}
	}
	
	public function failOrderAction()
	{
		if(isset($_REQUEST['xml']))
		{
			unset($_REQUEST['xml']);
		}
		
		$from_data = Mage::getSingleton('core/session')->getFromData();
		if(isset($from_data) && $from_data=='from_frontend')
		{
			Mage::getSingleton('core/session')->unsFromData();
			Mage::getSingleton('core/session')->addError($this->__('Payment Declined. Please confirm payment details are correct and try again later.'));
			Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('checkout/onepage/index'));
		}
		else
		{
			echo Mage::app()->getResponse()->setRedirect(Mage::helper('adminhtml')->getUrl("adminhtml/sales_order_create/failure"));
			Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('adminhtml/sales_order_create/failure'));
			die('redirect');
		}
	}
	
}
