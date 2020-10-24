<?php
defined('_JEXEC') or die('Restricted access');

/*
RemiBit Payment Module
Modified April 16th 2020 by Blockchain Remittance Ltd.
Adapted to handle calls to RemiBit API.
*/



/**
 * @author Valérie Isaksen
 * @version $Id: remibit.php 9821 2018-04-16 18:04:39Z Leon $
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (C) 2004-Copyright (C) 2004 - 2018 Virtuemart Team. All rights reserved.   - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */
if (!class_exists('vmPSPlugin')) {
	require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

class plgVmPaymentRemibit extends vmPSPlugin {
	const RELEASE = 'VM 3.4.2';


	function __construct (& $subject, $config) {

		parent::__construct($subject, $config);

		$this->_loggable = TRUE;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; //virtuemart_remibit_id';
		$this->_tableId = 'id'; //'virtuemart_remibit_id';

		$varsToPush = $this->getVarsToPush();

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

	}

	/**
	 * @return string
	 */
	public function getVmPluginCreateTableSQL () {

		return $this->createTableSQL('Payment RemiBit Table');
	}

	/**
	 * @return array
	 */
	function getTableSQLFields () {

		$SQLfields = array(
			'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(1) UNSIGNED',
			'order_number' => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'payment_name' => 'varchar(1000)',
            'email_currency' => 'smallint(1)',
            'payment_order_total' => 'decimal(15,5) NOT NULL',
			'payment_currency' => 'smallint(1)',
			'cost_per_transaction' => 'decimal(10,2)',
			'cost_percent_total' => 'decimal(10,2)',
			'tax_id' => 'smallint(1)',
		);
		return $SQLfields;
	}

	/**
	 * @param $cart
	 * @param $order
	 * @return bool|null
	 */
	function plgVmConfirmedOrder ($cart, $order) {
	    if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
			return FALSE;
		}
        $p_currency = isset($this->_currentMethod->payment_currency) ? $this->_currentMethod->payment_currency : '';
        $email_currency = $this->getEmailCurrency($this->_currentMethod);
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $p_currency);
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['payment_name'] = $this->renderPluginName($this->_currentMethod, 'create_order');
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = isset($this->_currentMethod->cost_per_transaction) ? $this->_currentMethod->cost_per_transaction : '';
        $dbValues['cost_percent_total'] = isset($this->_currentMethod->cost_percent_total) ? $this->_currentMethod->cost_percent_total : '';
        $dbValues['payment_currency'] = $p_currency;
        $dbValues['email_currency'] = $email_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
        $dbValues['tax_id'] = $this->_currentMethod->tax_id;

        $this->storePSPluginInternalData($dbValues);

		$this->sendTransactionRequest($order);
	}


	function displayErrors ($errors) {

		foreach ($errors as $error) {
			vmError(vmText::sprintf('VMPAYMENT_REMIBIT_ERROR_FROM', $error ['message'], $error ['field'], $error ['code']));
			vmInfo(vmText::sprintf('VMPAYMENT_REMIBIT_ERROR_FROM', $error ['message'], $error ['field'], $error ['code']));
			if ($error ['message'] == 401) {
				vmdebug('check you payment parameters: custom_id, project_id, api key');
			}
		}
	}


	function sendTransactionRequest ($order) {

//$this->_debug = $method->debug;
		//$this->debugLog('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
		vmdebug('REMIBIT sendTransactionRequest');
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(VMPATH_ADMIN . DS . 'helpers' . DS . 'currencydisplay.php');
		}

		if (!class_exists('TableVendors')) {
			require(VMPATH_ADMIN . DS . 'tables' . DS . 'vendors.php');
		}

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number))) {
            //vmdebug('REMIBIT plgVmOnPaymentResponseReceived NOT getOrderIdByOrderNumber');
            return NULL;
        }

		$this->getPaymentCurrency($this->_currentMethod);
		$currency_code_3 = shopFunctions::getCurrencyByID($this->_currentMethod->payment_currency, 'currency_code_3');
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$this->_currentMethod->payment_currency);
        $orderTotal = $totalInPaymentCurrency['value'];

        $timeStamp = time();
        $transactionKey = $this->_currentMethod->transaction_key;

        if (function_exists('hash_hmac')) {
            $hash_d = hash_hmac('md5', sprintf('%s^%s^%s^%s^%s',
                $this->_currentMethod->login_id,
                $virtuemart_order_id,
                $timeStamp,
                $orderTotal,
                $currency_code_3
            ), $transactionKey);
        } else {
            $hash_d = bin2hex(mhash($this->_currentMethod->md5_hash, sprintf('%s^%s^%s^%s^%s',
                $this->_currentMethod->login_id,
                $virtuemart_order_id,
                $timeStamp,
                $orderTotal,
                $currency_code_3
            ), $transactionKey));
        }

        $params = array(
            'x_login' =>    $this->_currentMethod->login_id,
            'x_amount' => $orderTotal,
            'x_invoice_num' => $virtuemart_order_id,
            'x_relay_response' => 'TRUE',
            'x_fp_sequence' => $virtuemart_order_id,
            'x_fp_hash' => $hash_d,
            'x_show_form' => 'PAYMENT_FORM',
            'x_version' => '1.0',
            'x_type' => 'AUTH_CAPTURE',
            'x_relay_url' => $this::getNotificationUrl($order),
            'x_currency_code' => $currency_code_3,
            'x_fp_timestamp' => $timeStamp,
            'x_first_name' => $order['details']['BT']->first_name,
            'x_last_name' => $order['details']['BT']->last_name,
            'x_company' => $order['details']['BT']->company,
            'x_address' => $order['details']['BT']->address_1 .' '.$order['details']['BT']->address_2,
            'x_city' => $order['details']['BT']->city,
            'x_state' =>  isset($address->virtuemart_state_id) ? ShopFunctions::getStateByID($order['details']['BT']->virtuemart_state_id) : 'XX',
            'x_zip' => $order['details']['BT']->zip,
            'x_country' => ShopFunctions::getCountryByID($order['details']['BT']->virtuemart_country_id, 'country_3_code'),
            'x_phone' => $order['details']['BT']->phone_1,
            'x_email' => $order['details']['BT']->email,
            'x_tax' => (float)$order['details']['BT']->order_tax,
            'x_cancel_url' => JURI::root() . 'index.php?option=com_virtuemart&view=cart&Itemid=' . vRequest::getInt('Itemid').'&lang='.vRequest::getCmd('lang',''),
            'x_cancel_url_text' => 'Cancel Payment',
            'x_test_request' => 'FALSE',
            'x_ship_to_first_name' => $order['details']['ST']->first_name,
            'x_ship_to_last_name' => $order['details']['ST']->last_name,
            'x_ship_to_company' => $order['details']['ST']->company,
            'x_ship_to_address' => $order['details']['ST']->address_1 .' '.$order['details']['ST']->address_2,
            'x_ship_to_city' => $order['details']['ST']->city,
            'x_ship_to_state' => isset($address->virtuemart_state_id) ? ShopFunctions::getStateByID($order['details']['ST']->virtuemart_state_id) : 'XX',
            'x_ship_to_zip' => $order['details']['ST']->zip,
            'x_ship_to_country' => ShopFunctions::getCountryByID($order['details']['ST']->virtuemart_country_id, 'country_3_code'),
            'x_freight' => (float)$order['details']['BT']->order_shipment+(float)$order['details']['BT']->order_shipment_tax
        );

        $gateway_url = $this->_currentMethod->endpoint;
        $this->sendTransactionToGateway($gateway_url, $params);
	}

    function sendTransactionToGateway($url, $parameters)
    {
        $post_string = array();

        foreach ($parameters as $key => $value) {
            $post_string[] = "<input type='hidden' name='$key' value='$value'/>";
        }

        $loading = ' <div style="width: 100%; height: 100%;top: 50%; padding-top: 10px;padding-left: 10px;  left: 50%; transform: translate(40%, 40%)"><div style="width: 150px;height: 150px;border-top: #CC0000 solid 5px; border-radius: 50%;animation: a1 2s linear infinite;position: absolute"></div> </div> <style>*{overflow: hidden;}@keyframes a1 {to{transform: rotate(360deg)}}</style>';

        $html_form = '<form action="' . $url . '" method="post" id="authorize_payment_form">' . implode('', $post_string) . '<input type="submit" id="submit_authorize_payment_form" style="display: none"/>' . $loading . '</form><script>document.getElementById("submit_authorize_payment_form").click();</script>';

        echo $html_form;
        die();
    }

	function redirectToCart ($msg = NULL) {

		if (!$msg) {
			$msg = vmText::_('VMPAYMENT_REMIBIT_ERROR_TRY_AGAIN');
		}
		$app = JFactory::getApplication();
		$app->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&Itemid=' . vRequest::getInt('Itemid').'&lang='.vRequest::getCmd('lang',''), false), $msg);
	}

	/**
	 * @return bool|null|string
	 */
	function plgVmOnPaymentResponseReceived () {

		if (!class_exists('VirtueMartCart')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists('shopFunctionsF')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
		$order_number = vRequest::getString('on', 0);

		if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			//vmdebug('plgVmOnPaymentResponseReceived NOT getVmPluginMethod');
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->_currentMethod ->payment_element)) {
			//vmdebug('REMIBIT plgVmOnPaymentResponseReceived NOT selectedThisElement');
			return NULL;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			//vmdebug('REMIBIT plgVmOnPaymentResponseReceived NOT getOrderIdByOrderNumber');
			return NULL;
		}
		if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		if ($this->validate($this->_currentMethod->signature_key)){
            $cart = VirtueMartCart::getCart();
            if($cart) {
                $orderModel = VmModel::getModel('orders');
                $order = $orderModel->getOrder($virtuemart_order_id);
                // may be we did not receive the notification
                // Thus the call of the success-URL should check, if the notification has already been arrived at the shop  .
                //If this is not true, a transaction detail request (step 4) should be triggered with the call of the success-URL,

                $html = $this->_getPaymentResponseHtml($this->_currentMethod, $order, $payments);
                //We delete the old stuff
                // get the correct cart / session
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();

                $order_history = array();
                //$this->debugLog('plgVmOnPaymentNotification getStatus:' .$status. ' '.var_export($method, true) , 'message');

                $order_history['customer_notified'] = false;
                $order_history['order_status'] = $this->_currentMethod->status_received;
				//$order_history['order_status'] = 'U';
                $order_history['comments'] = vmText::_('Tx ID:'.$_POST['x_trans_id'] );
                $modelOrder = VmModel::getModel('orders');
                $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order_history, TRUE);
                return TRUE;
            } else {
                return FALSE;
            }

        } else {
            $this->redirectToCart();
            return FALSE;
        }
	}

    /**
     * @return bool|null
     */
    function plgVmOnPaymentNotification () {
        if (!class_exists('VirtueMartCart')) {
            require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(VMPATH_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        // the payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        $order_number = vRequest::getString('on', 0);

        if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            //vmdebug('plgVmOnPaymentResponseReceived NOT getVmPluginMethod');
            return NULL; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($this->_currentMethod ->payment_element)) {
            //vmdebug('REMIBIT plgVmOnPaymentResponseReceived NOT selectedThisElement');
            return NULL;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            //vmdebug('REMIBIT plgVmOnPaymentResponseReceived NOT getOrderIdByOrderNumber');
            return NULL;
        }

        if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        if($virtuemart_order_id === $_POST['x_invoice_num']) {
            $orderModel = VmModel::getModel('orders');
            $order = $orderModel->getOrder($virtuemart_order_id);
            $this->sendTransactionToGateway($this::getSuccessUrl($order), $_POST);
        } else {
            $this->redirectToCart();
        }
    }

	/**
	 * @return bool|null
	 */
	function plgVmOnUserPaymentCancel () {
		$order_number = vRequest::getString('on', '');
		$virtuemart_paymentmethod_id = vRequest::getInt('pm', '');
		if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId($virtuemart_paymentmethod_id)) {
			vmdebug('plgVmOnUserPaymentCancel', $order_number, $virtuemart_paymentmethod_id);
			return NULL;
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
			return NULL;
		}
		vmdebug('plgVmOnUserPaymentCancel', 'VMPAYMENT_REMIBIT_PAYMENT_CANCELLED');

		VmInfo(vmText::_('VMPAYMENT_REMIBIT_PAYMENT_CANCELLED'));
		$session = JFactory::getSession();
		$return_context = $session->getId();
		if (strcmp($paymentTable->sofort_custom, $return_context) === 0) {
			vmDebug('handlePaymentUserCancel');
			$this->handlePaymentUserCancel($virtuemart_order_id);
		} else {
			vmDebug('Return context', 'payment error', $return_context);

		}
		return TRUE;
	}

	/**
	 * @param $method
	 * @param $order
	 * @return string
	 */
	function _getPaymentResponseHtml ($method, $order, $payments) {
		vmLanguage::loadJLang('com_virtuemart_orders', TRUE);
		if (!class_exists('CurrencyDisplay')) {
			require(VMPATH_ADMIN . DS . 'helpers' . DS . 'currencydisplay.php');
		}

		if (!class_exists('VirtueMartCart')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
		}

		vmLanguage::loadJLang('com_virtuemart_orders',TRUE);

		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$order['details']['BT']->order_currency);
		$payment = end($payments);

		$pluginName = $this->renderPluginName($method, $where = 'post_payment');
		$html = $this->renderByLayout('post_payment', array(
		                                                   'order' => $order,
		                                                   'paymentInfos' => $payment,
		                                                   'pluginName' => $pluginName,
		                                                   'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display']
		                                              ));

		return $html;
	}

	/*
		 * @param $method plugin
	 *  @param $where from where tis function is called
		 */

	protected function renderPluginName ($method, $where = 'checkout') {

		$display_logos = "";
		$payment_name = $method->payment_name;
		$html = $this->renderByLayout('render_pluginname', array(
		                                                        'where' => $where,
		                                                        'logo' => $display_logos,
		                                                        'payment_name' => $payment_name,
		                                                        'payment_description' => $method->payment_desc,
		                                                   ));

		return $html;
	}

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 *
	 * @author: Valerie Isaksen
	 *
	 * @param $cart_prices: cart prices
	 * @param $payment
	 * @return true: if the conditions are fulfilled, false otherwise
	 *
	 */
	protected function checkConditions ($cart, $method, $cart_prices) {

		$this->convert_condition_amount($method);
		$amount = $this->getCartAmount($cart_prices);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
			OR
			($method->min_amount <= $amount AND ($method->max_amount == 0)));

		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			if ($amount_cond) {
				return TRUE;
			}
		}

		return FALSE;
	}



	/**
	 * We must reimplement this triggers for joomla 1.7
	 */

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @author Valérie Isaksen
	 *
	 */
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @author Valérie isaksen
	 *
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
	 *
	 */
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {

		return $this->OnSelectCheck($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object  $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on success, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 * @author Valerie Isaksen
	 */
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			} else {
				return false;
			}
		}
		$htmla = array();
		$html = '';
		vmLanguage::loadJLang('com_virtuemart');
		$currency = CurrencyDisplay::getInstance();
		foreach ($this->methods as $this->_currentMethod) {
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {
				$cartPrices = $cart->cartPrices;
				$methodSalesPrice = $this->calculateSalesPrice($cart, $this->_currentMethod, $cartPrices);

				$logo = '';
				$payment_cost = '';
				if ($methodSalesPrice) {
					$payment_cost = $currency->priceDisplay($methodSalesPrice);
				}
				if ($selected == $this->_currentMethod->virtuemart_paymentmethod_id) {
					$checked = 'checked="checked"';
				} else {
					$checked = '';
				}
				$html .= $this->renderByLayout('display_payment', array(
				                                                       'plugin' => $this->_currentMethod,
				                                                       'checked' => $checked,
				                                                       'payment_logo' => $logo,
				                                                       'payment_cost' => $payment_cost,
				                                                  ));

				$htmla[] = $html;
			}
		}
		if (!empty($htmla)) {
			$htmlIn[] = $htmla;
		}

		return true;
	}

	/*
		 * plgVmonSelectedCalculatePricePayment
		 * Calculate the price (value, tax_id) of the selected method
		 * It is called by the calculator
		 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
		 * @author Valerie Isaksen
		 * @cart: VirtueMartCart the current cart
		 * @cart_prices: array the new cart prices
		 * @return null if the method was not selected, false if the payment is not valid any more, true otherwise
		 *
		 *
		 */

	/**
	 * @param VirtueMartCart $cart
	 * @param array          $cart_prices
	 * @param                $cart_prices_name
	 * @return bool|null
	 */
	public function plgVmOnSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 *
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {


		if (!($method = $this->selectedThisByMethodId($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		$payments = $this->getDatasByOrderId($virtuemart_order_id);
		$nb = count($payments);

		$payment_name = $this->renderByLayout('order_fe', array(
		                                                       'paymentInfos' => $payments[$nb - 1],
		                                                       'paymentName' => $payments[0]->payment_name,
		                                                  ));
	}

	/**
	 * This event is fired during the checkout process. It can be used to validate the
	 * method data as entered by the user.
	 *
	 * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.

	public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
	return null;
	}
	 */

	/**
	 * This method is fired when showing when printing an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $order_number The order number
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

		return $this->onShowOrderPrint($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}

	/**
	 * @param $name
	 * @param $id
	 * @param $table
	 * @return bool
	 */
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}

    function validate($signature_key)
    {
        if(isset($_POST['x_trans_id'])){
            $hashData = implode('^', [
                $_POST['x_trans_id'],
                $_POST['x_test_request'],
                $_POST['x_response_code'],
                $_POST['x_auth_code'],
                $_POST['x_cvv2_resp_code'],
                $_POST['x_cavv_response'],
                $_POST['x_avs_code'],
                $_POST['x_method'],
                $_POST['x_account_number'],
                $_POST['x_amount'],
                $_POST['x_company'],
                $_POST['x_first_name'],
                $_POST['x_last_name'],
                $_POST['x_address'],
                $_POST['x_city'],
                $_POST['x_state'],
                $_POST['x_zip'],
                $_POST['x_country'],
                $_POST['x_phone'],
                $_POST['x_fax'],
                $_POST['x_email'],
                $_POST['x_ship_to_company'],
                $_POST['x_ship_to_first_name'],
                $_POST['x_ship_to_last_name'],
                $_POST['x_ship_to_address'],
                $_POST['x_ship_to_city'],
                $_POST['x_ship_to_state'],
                $_POST['x_ship_to_zip'],
                $_POST['x_ship_to_country'],
                $_POST['x_invoice_num'],
            ]);

            $digest = strtoupper(HASH_HMAC('sha512', "^" . $hashData . "^", hex2bin($signature_key)));

            if ($_POST['x_response_code'] != '' && (strtoupper($_POST['x_SHA2_Hash']) == $digest)) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

	static function   getSuccessUrl ($order) {
		return JURI::root()."index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=" . $order['details']['BT']->virtuemart_paymentmethod_id . '&on=' . $order['details']['BT']->order_number . "&Itemid=" . vRequest::getInt('Itemid'). '&lang='.vRequest::getCmd('lang',''); ;
	}

	static function   getCancelUrl ($order) {
		return  JURI::root()."index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&pm=" . $order['details']['BT']->virtuemart_paymentmethod_id . '&on=' . $order['details']['BT']->order_number . '&Itemid=' . vRequest::getInt('Itemid').'&lang='.vRequest::getCmd('lang','');
	}

	static function   getNotificationUrl ($order) {
		return JURI::root()  .  "index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm=" . $order['details']['BT']->virtuemart_paymentmethod_id . '&on=' . $order['details']['BT']->order_number .'&lang='.vRequest::getCmd('lang','');
	}
}

// No closing tag
