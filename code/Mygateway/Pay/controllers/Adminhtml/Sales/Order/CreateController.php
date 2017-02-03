<?php
require_once Mage::getModuleDir('controllers', 'Mage_Adminhtml').DS.'Sales'.DS.'Order'.DS.'CreateController.php';

class Mygateway_Pay_Adminhtml_Sales_Order_CreateController extends Mage_Adminhtml_Sales_Order_CreateController
{
	/**
     * Saving quote and create order
     */
    public function saveAction()
    {	
        try {
			$order_data = Mage::getSingleton('adminhtml/session')->getOrderData();
			$payment_data = Mage::getSingleton('adminhtml/session')->getPaymentData();
			if(isset($order_data) && !empty($order_data))
			{
				Mage::getSingleton('adminhtml/session')->unsOrderData();
				Mage::getSingleton('adminhtml/session')->unsPaymentData();
				
				$lastcc4 = substr($payment_data['cc_number'], -4);
				$paymentData = array('method'=>'checkmo', 'cc_number_enc'=>'', 'cc_last4'=>'', 'cc_cid_enc'=>'');
				$this->_getOrderCreateModel()->getQuote()->getPayment()->addData($paymentData);
				
				$order = $this->_getOrderCreateModel()
						->setIsValidate(true)
						->importPostData($order_data)
						->createOrder();
			
				$pay_data = $this->getRequest()->getParam('pay_data');
				if(isset($pay_data) && !empty($pay_data))
				{
					$pay_data = json_decode(base64_decode($pay_data));
					$result = array();
					
					$result['transaction_id'] = $pay_data->response->transaction_id;
					$result['authcode'] = $pay_data->response->authcode;
					$result['transaction_type'] = $pay_data->response->transaction_type;
					$result['amount'] = number_format($pay_data->response->amount/100, 0, '.', '');
				}
				
				
				$order_new = Mage::getModel('sales/order')->loadByIncrementId($order->getIncrementId());
				$payment = $order_new->getPayment();
				$payment->setMethod('pay');
				$payment->setCcLast4($lastcc4);
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
				
				
				//Create Inovice
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
					Mage::throwException(Mage::helper('core')->__('Error. Cannot create an invoice.'.$e));
				}
				
				$this->_getSession()->clear();
				Mage::getSingleton('adminhtml/session')->addSuccess($this->__('The order has been created.'));
				echo Mage::app()->getResponse()->setRedirect(Mage::helper('adminhtml')->getUrl("adminhtml/sales_order/view", array('order_id' => $order->getId())));
				Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('adminhtml/sales_order/view', array('order_id' => $order->getId())));
				die('created');
			}
			
            $this->_processActionData('save');
            if ($paymentData = $this->getRequest()->getPost('payment')) {
                $this->_getOrderCreateModel()->setPaymentData($paymentData);
                $this->_getOrderCreateModel()->getQuote()->getPayment()->addData($paymentData);
            }
			
			if($paymentData['method']=='pay')
			{
				$payment = new Varien_Object($paymentData);
				$cart_data = Mage::getSingleton('adminhtml/session_quote')->getQuote()->getTotals();
				$amount = $cart_data["grand_total"]->getValue();
				
				$result = $this->callApi($payment,$amount,'AUTCONT');
				if($result === false)
				{
					$message = 'Payment Declined. Please confirm payment details are correct and try again later.';
					$this->_getSession()->addError($message);
					$this->_redirect('*/*/');
				}
				else
				{
					//Mage::log($result, null, $this->getCode().'.log');
					//process result here to check status etc as per payment gateway.
					// if invalid status throw exception
					if($result['status'] == 1)
					{
						$lastcc4 = substr($paymentData['cc_number'], -4);
						$paymentData = array('method'=>'checkmo', 'cc_number_enc'=>'', 'cc_last4'=>'', 'cc_cid_enc'=>'');
						$this->_getOrderCreateModel()->getQuote()->getPayment()->addData($paymentData);
						
						$payment->setTransactionId($result['transaction_id']);
						$payment->setIsTransactionClosed(0);
						$payment->setAuthCode($result['authcode']);
						$payment->setTransType($result['transaction_type']);
						$payment->setTransactionAdditionalInfo('real_transaction_id', $result['transaction_id']);
						
						$order = $this->_getOrderCreateModel()
								->setIsValidate(true)
								->importPostData($this->getRequest()->getPost('order'))
								->createOrder();

						
						$order_new = Mage::getModel('sales/order')->loadByIncrementId($order->getIncrementId());
						$payment = $order_new->getPayment();
						$payment->setMethod('pay');
						$payment->setCcLast4($lastcc4); 
						$payment->save();
						$order_new->setStatus('processing');
						$order_new->save();
						
						//Create Inovice
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
							Mage::throwException(Mage::helper('core')->__('Error. Cannot create an invoice.'.$e));
						}
						
						$this->_getSession()->clear();
						Mage::getSingleton('adminhtml/session')->addSuccess($this->__('The order has been created.'));
						$this->_redirect('*/sales_order/view', array('order_id' => $order->getId()));
						
						
					}
					else if($result['status']==2)
					{
						Mage::getSingleton('adminhtml/session')->setOrderData($this->getRequest()->getPost('order'));
						Mage::getSingleton('adminhtml/session')->setPaymentData($paymentData);
						echo $result['html_code'];
					}
					else
					{
						$message = 'Payment Declined. Please confirm payment details are correct and try again later.';
						$this->_getSession()->addError($message);
						$this->_redirect('*/*/');
					}
				}
			}
			else
			{
				$order = $this->_getOrderCreateModel()
                ->setIsValidate(true)
                ->importPostData($this->getRequest()->getPost('order'))
                ->createOrder();

				$this->_getSession()->clear();
				Mage::getSingleton('adminhtml/session')->addSuccess($this->__('The order has been created.'));
				$this->_redirect('*/sales_order/view', array('order_id' => $order->getId()));
			}
			
        } catch (Mage_Payment_Model_Info_Exception $e) {
            $this->_getOrderCreateModel()->saveQuote();
            $message = $e->getMessage();
            if( !empty($message) ) {
                $this->_getSession()->addError($message);
            }
            $this->_redirect('*/*/');
        } catch (Mage_Core_Exception $e){
            $message = $e->getMessage();
            if( !empty($message) ) {
                $this->_getSession()->addError($message);
            }
            $this->_redirect('*/*/');
        }
        catch (Exception $e){
            $this->_getSession()->addException($e, $this->__('Order saving error: %s', $e->getMessage()));
            $this->_redirect('*/*/');
        }
    }
	
	public function failureAction()
	{
		Mage::getSingleton('adminhtml/session')->unsOrderData();
		Mage::getSingleton('adminhtml/session')->unsPaymentData();
		$this->_getSession()->addError('Payment Declined. Please confirm payment details are correct and try again later.');
		$this->_redirect('*/sales_order_create/index');
	}
	
	private function callApi(Varien_Object $payment,$amount, $type)
	{    
		$action_code = $type;
		$types = Mage::getSingleton('payment/config')->getCcTypes();

		if (isset($types[$payment->getCcType()]))
		{
			$type = $types[$payment->getCcType()];
		}
		
		$billingaddress = $this->getRequest()->getPost('order');
		$orderId = Mage::getSingleton('adminhtml/session_quote')->getQuoteId().date("YmdHis", Mage::getModel('core/date')->timestamp(time()));
		$currencyDesc = Mage::app()->getStore()->getBaseCurrencyCode();
		$url = Mage::getModel('pay/pay')->getConfigData('gateway_url');
		
        $month = $payment->getCcExpMonth();
        if ($month<10) {
            $month = '0'.$month;
        }
        $numbers = $payment->getCcExpYear();
        $year = substr((string)$numbers,-2); 

        $transaction_id = 'TEST'.$orderId; 
        $card_number =  $payment->getCcNumber();
        $expire_date = $month.$year;
        $cvv2 = $payment->getCcCid();
        $currency = $currencyDesc;
        $description = 'test transaction';
        $security_key = Mage::getModel('pay/pay')->getConfigData('api_signature');
		
		$amount = $amount*100; //convert to cents
		

        $string = $transaction_id.$action_code.$card_number.$expire_date.$amount.$currency.$security_key;
		
        $token = sha1($string);	
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <apcc>
                    <request>
                        <transaction_id>'.$transaction_id.'</transaction_id>
                        <action_code>'.$action_code.'</action_code>
                        <pan>'.$card_number.'</pan>
                        <expire_date>'.$expire_date.'</expire_date>
                        <cvv2>'.$cvv2.'</cvv2>
                        <amount>'.$amount.'</amount>
                        <currency>'.$currency.'</currency>
                        <description>'.$description.'</description>
                        <customer_ip>'.$_SERVER['REMOTE_ADDR'].'</customer_ip>
                        <billing_email>'.$billingaddress['account']['email'].'</billing_email>
                        <billing_address>'.$billingaddress['billing_address']['street'][0].$billingaddress['billing_address']['street'][1].'</billing_address>
                        <billing_city>'.$billingaddress['billing_address']['city'].'</billing_city>
                        <billing_region>'.$billingaddress['billing_address']['region'].'</billing_region>
                        <billing_postal>'.$billingaddress['billing_address']['postcode'].'</billing_postal>
                        <billing_country>'.$billingaddress['billing_address']['country_id'].'</billing_country>
                        <billing_phone>'.$billingaddress['billing_address']['telephone'].'</billing_phone>
                        <billing_fname>'.$billingaddress['billing_address']['firstname'].'</billing_fname>
                        <billing_lname>'.$billingaddress['billing_address']['lastname'].'</billing_lname>
                        <bin_name>'.$payment->getCcOwner().'</bin_name>
                        <bin_phone>binphone</bin_phone>
                    </request>
                    <account>'.Mage::getModel('pay/pay')->getConfigData('api_account').'</account>
                    <token>'.$token.'</token>
                    <test>true</test>
                </apcc>';

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/xml')); //setting content type header
        curl_setopt($curl, CURLOPT_VERBOSE, 1);
        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);//Setting raw post data as xml
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 300);

        $result = curl_exec($curl);
        curl_close($curl);
        $xml_response=simplexml_load_string($result);
        $html1 = $xml_response->response->html;
        $uri = base64_decode($html1);
        $response_code = $xml_response->response->response_code;
        $response_description = $xml_response->response->description;
        $authcode = $xml_response->response->authcode;
        $transaction_type = $xml_response->response->transaction_type;
		$html_code = base64_decode("'".$xml_response->response->html."'");
        
		//echo "<pre>"; print_r($xml_response); die;
		
		if(isset($response_code) && $response_code==0 && !isset($xml_response->response->html))
		{
           return array('status'=>1, 'transaction_id' => $transaction_id, 'fraud' => rand(0,1), 'authcode' => $authcode, 'transaction_type'=>$transaction_type); 
        }
		else if(isset($html_code) && $html_code!='')
		{
			return array('status'=>2, 'html_code'=>$html_code); 
		}
		else
		{
			//Mage::getSingleton('checkout/session')->getQuote()->setReservedOrderId(null)->save();
			//Mage::throwException('Payment Declined. Please confirm payment details are correct and try again later.');
			return false;
        }
    }
	
	
}