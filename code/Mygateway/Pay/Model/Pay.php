<?php

class Mygateway_Pay_Model_Pay extends Mage_Payment_Model_Method_Cc
{
    protected $_code = 'pay';
    protected $_formBlockType = 'pay/form_pay';
    protected $_infoBlockType = 'pay/info_pay';

    //protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    //protected $_canCapturePartial       = true;
    protected $_canRefund               = true;
    protected $_canSaveCc               = false; //if made try, the actual credit card number and cvv code are stored in database.
    protected $_canVoid                 = true;


    public function process($data){

        if($data['cancel'] == 1){
            $order->getPayment()
                ->setTransactionId(null)
                ->setParentTransactionId(time())
                ->void();
            $message = 'Unable to process Payment';
            $order->registerCancellation($message)->save();
        }
    }
	
    /** For capture **/
    public function capture(Varien_Object $payment, $amount)
    {
		if ($amount <= 0) {
            Mage::throwException(Mage::helper('paygate')->__('Invalid amount for capture.'));
        }
		
        $payment->setAmount($amount);    
        $order = $payment->getOrder();
		
		$result = $this->callApi($payment,$amount,'AUTCONT');	
		
		
        if($result === false)
		{
            $errorCode = 'Invalid Data';
            $errorMsg = $this->_getHelper()->__('Error Processing the request');
        }
		else
		{
            Mage::log($result, null, $this->getCode().'.log');
            //process result here to check status etc as per payment gateway.
            // if invalid status throw exception
            if($result['status'] == 1)
			{
				if(isset($_REQUEST['xml']))
				{
					unset($_REQUEST['xml']);
				}
                $payment->setTransactionId($result['transaction_id']);
                $payment->setIsTransactionClosed(0);
                $payment->setAuthCode($result['authcode']);
                $payment->setTransType($result['transaction_type']);
                $payment->setTransactionAdditionalInfo('real_transaction_id', $result['transaction_id']);
            }
			else if($result['status']==2)
			{
				$returnUrl = Mage::getUrl('pay/index/redirect', array('_secure' => false));
				Mage::getSingleton('core/session')->setRedirectUrl($returnUrl);
				Mage::getSingleton('core/session')->setCvvData($result['html_code']);
				Mage::getSingleton('core/session')->setFromData('from_frontend');
				
				if(isset($_POST['payment']['method']))
					Mage::getSingleton('core/session')->setCreditMethod($_POST['payment']['method']);
				
				if(isset($_POST['payment']['cc_type']))
					Mage::getSingleton('core/session')->setCreditType($_POST['payment']['cc_type']);
				
				if(isset($_POST['payment']['cc_number']))
					Mage::getSingleton('core/session')->setCreditNumber($_POST['payment']['cc_number']);
				
				if(isset($_POST['payment']['cc_exp_month']))
					Mage::getSingleton('core/session')->setCreditMonth($_POST['payment']['cc_exp_month']);
				
				if(isset($_POST['payment']['cc_exp_year']))
					Mage::getSingleton('core/session')->setCreditYear($_POST['payment']['cc_exp_year']);
				
				if(isset($_POST['payment']['cc_cid']))
					Mage::getSingleton('core/session')->setCreditCid($_POST['payment']['cc_cid']);
				
				Mage::getSingleton('core/session')->setCredit($result['html_code']);
				return Mage::throwException('CVVTEST');
			}
			else
			{
                Mage::throwException($errorMsg);
            }
        }
        if($errorMsg){
            Mage::throwException($errorMsg);
        }
        return $this;
    }


    /** For authorization **/
    public function authorize(Varien_Object $payment, $amount)
    {
        $order = $payment->getOrder();

        $result = $this->callApi($payment,$amount,'AUTCONT');
        if($result === false) {
            $errorCode = 'Invalid Data';
            $errorMsg = $this->_getHelper()->__('Error Processing the request');
        } else {
            Mage::log($result, null, $this->getCode().'.log');
            //process result here to check status etc as per payment gateway.
            // if invalid status throw exception

            if($result['status'] == 1){
                $payment->setTransactionId($result['transaction_id']);
                /*
                 * This marks transactions as closed or open
                 */
                $payment->setIsTransactionClosed(1);
                /*
                 * This marks transactions authcode and transaction type for refund and cancel
                 */
                $payment->setAuthCode($result['authcode']);
                $payment->setTransType($result['transaction_type']);
                /*
                 * This basically makes order status to be payment review and no invoice is created.
                * and adds a default comment like
                * Authorizing amount of $17.00 is pending approval on gateway. Transaction ID: "1335419269".
                *
                */
                //$payment->setIsTransactionPending(true);
                /*
                 * This basically makes order status to be processing and no invoice is created.
                * add a default comment to order like
                * Authorized amount of $17.00. Transaction ID: "1335419459".
                */
                //$payment->setIsTransactionApproved(true);

                /*
                 * This method is used to display extra informatoin on transaction page
                */
                $order->addStatusToHistory($order->getStatus(), 'Payment Sucessfully Placed with Transaction ID'.$result['transaction_id'], false);
                $order->save();

            } else {
                Mage::throwException($errorMsg);
            }
            // Add the comment and save the order
        }
        if($errorMsg){
            Mage::throwException($errorMsg);
        }
        return $this;
    }

    public function processBeforeRefund($invoice, $payment){
        return parent::processBeforeRefund($invoice, $payment);
    }

    public function refund(Varien_Object $payment, $amount){
        
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('paygate')->__('Invalid amount for refund.'));
        }

        if (!$payment->getParentTransactionId()) {
            Mage::throwException(Mage::helper('paygate')->__('Invalid transaction ID.'));
        }
        
        $order = $payment->getOrder();
        $result = $this->callRefundApi($payment,$amount,'REFUND');
        if($result === false) {
                $errorCode = 'Invalid Data';
                $errorMsg = $this->_getHelper()->__('Error Processing the request');
                Mage::throwException($errorMsg);
        }
        return $this;
    }

    public function processCreditmemo($creditmemo, $payment){
        return parent::processCreditmemo($creditmemo, $payment);
    }
    
    private function callRefundApi(Varien_Object $payment, $amount, $type) {

        $action_code = $type;
        $order = $payment->getOrder();
        //$transactionId = $this->_getRealParentTransactionId($payment);
        //$transaction_id = $transactionId['real_transaction_id'];
		$transaction_id = $payment->getData('last_trans_id');
        $auth_code = $payment->getData('auth_code');

        //$currencyDesc = $order->getBaseCurrencyCode();
        $currencyDesc = Mage::app()->getStore()->getBaseCurrencyCode();
        $url = $this->getConfigData('gateway_url');

        $currency = $currencyDesc;
        $security_key = $this->getConfigData('api_signature');

		$amount = $amount*100; //convert to cents
		
        $string = $transaction_id.$action_code.$auth_code.$amount.$amount.$currency.$security_key;

        $token = sha1($string);	
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <apcc>
                    <request>
                        <transaction_id>'.$transaction_id.'</transaction_id>
                        <action_code>'.$action_code.'</action_code>
                        <auth_code>'.$auth_code.'</auth_code>
                        <amount>'.$amount.'</amount>
                        <amount_op>'.$amount.'</amount_op>
                        <currency>'.$currency.'</currency>>
                    </request>
                    <account>'.$this->getConfigData('api_account').'</account>
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
        $transaction_reference = $xml_response->response->transaction_reference;
        $response_code = $xml_response->response->response_code;

	//	echo "<pre>"; print_r($xml_response); die;
		
        if($response_code == 0){
           return array('status'=>1, 'transaction_id' => $transaction_id, 'fraud' => rand(0,1), 'authcode' => $authcode, 'transaction_reference'=>$transaction_reference); 
        }else{
            Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('checkout/cart'));
            return Mage::app()->getResponse()->sendResponse();
        }
    }

    private function callApi(Varien_Object $payment, $amount,$type) {
        
		$action_code = $type;
        //$storeId = 'Store Id'.' '.Mage::app()->getStore()->getId();
        $order = $payment->getOrder();
        $types = Mage::getSingleton('payment/config')->getCcTypes();

        if (isset($types[$payment->getCcType()])) {
            $type = $types[$payment->getCcType()];
        }

        $billingaddress = $order->getBillingAddress();
        //$totals = number_format($amount, 2, '.', '');
        $orderId = $order->getIncrementId();
        $currencyDesc = $order->getBaseCurrencyCode();
        $url = $this->getConfigData('gateway_url');

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
        $security_key = $this->getConfigData('api_signature');
		
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
                        <billing_email>'.$billingaddress->getData('email').'</billing_email>
                        <billing_address>'.$billingaddress->getData('street').'</billing_address>
                        <billing_city>'.$billingaddress->getData('city').'</billing_city>
                        <billing_region>'.$billingaddress->getData('region').'</billing_region>
                        <billing_postal>'.$billingaddress->getData('postcode').'</billing_postal>
                        <billing_country>'.$billingaddress->getData('country_id').'</billing_country>
                        <billing_phone>'.$billingaddress->getData('telephone').'</billing_phone>
                        <billing_fname>'.$billingaddress->getData('firstname').'</billing_fname>
                        <billing_lname>'.$billingaddress->getData('lastname').'</billing_lname>
                        <bin_name>'.$payment->getCcOwner().'</bin_name>
                        <bin_phone>binphone</bin_phone>
                    </request>
                    <account>'.$this->getConfigData('api_account').'</account>
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
			Mage::getSingleton('checkout/session')->getQuote()->setReservedOrderId(null)->save();
			Mage::throwException('Payment Declined. Please confirm payment details are correct and try again later.');
			return false;
        }
    }
    
    /**
     * Return additional information`s transaction_id value of parent transaction model
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return string
     */
    protected function _getRealParentTransactionId($payment)
    {
        $transaction = $payment->getTransaction($payment->getParentTransactionId());
        return $transaction->getAdditionalInformation($this->_realTransactionIdKey);
    }
}
?>
