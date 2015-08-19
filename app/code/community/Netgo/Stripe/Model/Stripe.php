<?php
 /**
 * Netgo_Stripe module model
 *
 * @category    Netgo
 * @package     Netgo_Stripe
 * @author      Afroz Alam <afroz92@gmail.com>
 * @copyright   NetAttingo Technologies (http://www.netattingo.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
require_once(Mage::getBaseDir('lib') . '/Netgo/Stripe/lib/Stripe.php');
class Netgo_Stripe_Model_Stripe extends Mage_Payment_Model_Method_Cc
{
     
    protected $_code = 'netgo_stripe';
	protected $_formBlockType = 'stripe/payment_form'; 
	
	 /**
     * this should probably be true if you're using this
     * method to take payments
     */
    protected $_isGateway               = true;
 
    /**
     * can this method authorise?
     */
    protected $_canAuthorize            = true;
 
    /**
     * can this method capture funds?
     */
    protected $_canCapture              = true;
 
    /**
     * can we capture only partial amounts?
     */
    protected $_canCapturePartial       = false;
 
    /**
     * can this method refund?
     */
    protected $_canRefund               = true;
 
    /**
     * can this method void transactions?
     */
    protected $_canVoid                 = true;
 
    /**
     * can admins use this payment method?
     */
    protected $_canUseInternal          = true;
 
    /**
     * show this method on the checkout page
     */
    protected $_canUseCheckout          = true;
 
    /**
     * available for multi shipping checkouts?
     */
    protected $_canUseForMultishipping  = true;
 
    /**
     * can this method save cc info for later use?
     */
    protected $_canSaveCc = false;
 
    /**
     * this method is called if we are just authorising
     * a transaction
	 * As you can see above, what happens in the authorize function is
	 * call the authorize api from payment gateway
     * check if transaction was successful or rejected
     * if transaction is rejected, throw exception
     * if transaction is successful, create order and save transaction id.
     * No invoice is created
	 */
    public function authorize(Varien_Object $payment, $amount)
    {
        
		$order = $payment->getOrder();
        $result = $this->callApi($payment,$amount,false);
		
        if($result === false) {
            $errorCode = 'Invalid Data';
            $errorMsg = $this->_getHelper()->__('Error Processing the request');
        } else {
            Mage::log($result, null, $this->getCode().'.log');
            //process result here to check status etc as per payment gateway.
            // if invalid status throw exception
 
            if($result['status'] == 1){
                $payment->setTransactionId($result['transaction_id']);
                $payment->setIsTransactionClosed(0);
                $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,array('key1'=>'value1','key2'=>'value2')); //use this in case you want to add some extra information
            }else{
                Mage::throwException($errorMsg);
            }
 
            // Add the comment and save the order
        }
        if($errorMsg){
            Mage::throwException($errorMsg);
        }
 
        return $this;
    }


    /**
     * this method is called if we are authorising AND
     * capturing a transaction
	 * Invoice is also created with status = Paid
     */
    public function capture(Varien_Object $payment, $amount)
    {
        
		$order = $payment->getOrder();
        $result = $this->callApi($payment,$amount,true);
        if($result === false) {
            $errorCode = 'Invalid Data';
            $errorMsg = $this->_getHelper()->__('Error Processing the request');
        } else {
            Mage::log($result, null, $this->getCode().'.log');
            //process result here to check status etc as per payment gateway.
            // if invalid status throw exception
 
            if($result['status'] == 1){
                $payment->setTransactionId($result['transaction_id']);
                $payment->setIsTransactionClosed(1);
                $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,array('key1'=>'value1','key2'=>'value2'));
            }else{
                Mage::throwException($errorMsg);
            }
 
            // Add the comment and save the order
        }
        if($errorMsg){
            Mage::throwException($errorMsg);
        }
 
        return $this;
    }


    /**
     * called if refunding
     */
    public function refund(Varien_Object $payment, $amount){
        $order = $payment->getOrder();
        $result = $this->callApi($payment,$amount,'refund');
        if($result === false) {
            $errorCode = 'Invalid Data';
            $errorMsg = $this->_getHelper()->__('Error Processing the request');
            Mage::throwException($errorMsg);
        }
        return $this;
 
    }

	
	public function processBeforeRefund($invoice, $payment){} //before refund
	public function processCreditmemo($creditmemo, $payment){} //after refund
	
    /**
     * called if voiding a payment
     */
    public function void (Varien_Object $payment)
    {
		echo "In Stripe Void";
		die("hello");
    }
	
	private function getApiKey(){
		$test_secret = $this->getConfigData('test_secret');
		$live_secret = $this->getConfigData('live_secret');//test_mode
		$test_mode = $this->getConfigData('test_mode');
		if(1 == $test_mode)
			return $test_secret;
		else
			return $live_secret;
		
	}
	
	/** Creating token for Stripe
	 *  by using stripe Api
	 */
	private function createToken(Varien_Object $payment, $order){
		$billingaddress = $order->getBillingAddress();
		$token_id = Stripe_Token::create(array(
								"card" => array( 
								'number'        => $payment->getCcNumber(),
								'cvc'     => $payment->getCcCid(),
								'exp_month'   => $payment->getCcExpMonth(),
								'exp_year'    => $payment->getCcExpYear(),

								'name'     => $billingaddress->getData('firstname').' '.$billingaddress->getData('lastname'),
								'address_line1'  => $billingaddress->getData('street'),
								'address_line2'  => $billingaddress->getData('street'),
								'address_city'  => $billingaddress->getData('city'),
								'address_state'  => $billingaddress->getData('region'),
								'address_zip'  => $billingaddress->getData('postcode'),
								'address_country' => $billingaddress->getData('country_id')
												) 
									   )
								);
		if('' == $token_id)
			die("Error creating Token ID");
		else
			return $token_id;
	}
	
	
	private function createCharge(Varien_Object $payment, $order, $amount, $type, $tokenid){
		$totals = number_format($amount, 2, '.', '');
		$totals = $totals * 100;		
		$orderId = $order->getIncrementId();
		$currencyDesc = $order->getBaseCurrencyCode();	
		$charge = Stripe_Charge::create(array(
                'amount'			=> $totals,
                'currency'			=> $currencyDesc,
                'card'				=> $tokenid,
                'capture'			=> $type,
                'statement_descriptor'  => 'Order#'.$orderId,
				'metadata'			=> array(
										'Order #'               => $orderId
									   ) ,
                //'receipt_email'		=> $wc_order->billing_email,
                'description'		=> 'Order #'.$orderId,
               /* 'shipping'			=> array(
									'address' => array(
										'line1'            => $wc_order->shipping_address_1,
										'line2'            => $wc_order->shipping_address_2,
										'city'            => $wc_order->shipping_city,
										'state'            => $wc_order->shipping_state,
										'country'        => $wc_order->shipping_country,
										'postal_code'    => $wc_order->shipping_postcode        
										),
									'name' => $wc_order->shipping_first_name.' '.$wc_order->shipping_last_name,
									'phone'=> $wc_order->billing_phone
								)    */            
                 )
            );
			return $charge;
	}
	
	/**callApi method. Main logic for 
	 * Api calls is implemneted here
	 */	 
	private function callApi(Varien_Object $payment, $amount,$type){		
		Stripe::setApiKey($this->getApiKey());
		$order = $payment->getOrder();
      	// create token for customer/buyer credit card
		$token_id = $this->createToken($payment, $order);		
		$charge = $this->createCharge($payment, $order, $amount, $type, $token_id->id);		  
		if ($charge->paid == true)
		  {                
			$chargeid  = $charge->id ; 
			return array('status'=>1,'transaction_id' => $chargeid );
		  }
		else
		  {
			//Do something if charge fails  
			return array('status'=>0,'transaction_id' => $chargeid );
		  }	
		
    }
	
    
}
