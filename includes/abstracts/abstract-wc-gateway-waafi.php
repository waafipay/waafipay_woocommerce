<?php

//directory access forbidden
if (!defined('ABSPATH')) {
    exit;
}

class WC_Waafi_Payment_Gateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->has_fields             = false;
        $this->method_title           = 'WaafiPay';
        $this->method_description     = 'WaafiPay Payment Gateway';
		if(empty(wc_gateway_waafipay()->settings->__get('btn_text'))){
			$this->order_button_text      = 'Proceed with WaafiPay';
		}else{
			$this->order_button_text      = wc_gateway_waafipay()->settings->__get('btn_text');
		}
        $this->supports = array(
            'products'
        );
    
        $this->init_form_fields();
        
        $this->init_settings();
		
	$this->testmode             =  'yes' === wc_gateway_waafipay()->settings->__get('testmode');
        $this->enabled              = wc_gateway_waafipay()->settings->__get('enabled');
        $this->title                = wc_gateway_waafipay()->settings->__get('title');
        $this->description          = wc_gateway_waafipay()->settings->__get('description');
        $this->waafi_payment_types   = wc_gateway_waafipay()->settings->__get('waafi_payment_types');
				
		$this->apiurl = $this->testmode ? 'https://sandbox.waafipay.com/asm' : 'https://api.waafipay.net/asm';
		
		$this->store_id = $this->testmode ? wc_gateway_waafipay()->settings->__get( 'test_waafi_store_id' ) : wc_gateway_waafipay()->settings->__get( 'waafi_store_id' );
		
		$this->publishable_key = $this->testmode ? wc_gateway_waafipay()->settings->__get( 'test_waafi_publishable_key' ) : wc_gateway_waafipay()->settings->__get( 'waafi_publishable_key' );
		
		$this->merchant_id = $this->testmode ? wc_gateway_waafipay()->settings->__get( 'test_waafi_merchant_id' ) : wc_gateway_waafipay()->settings->__get( 'waafi_merchant_id' );

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options'));
        
		add_action( 'woocommerce_api_waafisuccess', array( $this, 'waafisuccess' ) );
		add_action( 'woocommerce_api_waafifail', array( $this, 'waafifail' ) );
		add_action('wp_footer', array( $this,'addscript_function'));
        
    }

    public function admin_options()
    {
        if (wc_gateway_waafipay()->admin->is_valid_for_use()) {
            $this->show_admin_options();
            return true;
        }
        
			wc_gateway_waafipay()->settings->__set('enabled', 'no');
			wc_gateway_waafipay()->settings->save();
        ?>
        <div class="inline error"><p><strong><?php echo esc_html('Gateway disabled'); ?></strong>: <?php echo esc_html('WaafiPay Payment Gateway does not support your store currency.'); ?></p></div>
        <?php
    }
    
    public function show_admin_options()
    {
        $plugin_data = get_plugin_data(wc_gateway_waafipay()->file);
        $plugin_version = $plugin_data['Version'];
 
    ?>
        <h3><?php echo esc_html('HPP WAAFIPAY'); ?> <span><?php echo esc_html('Version '.$plugin_version); ?></span> </h3>
        <div id="wc_get_started">
            <span class="main"><?php echo esc_html('HPP Hosted Payment Page'); ?></span>
            <span><br><b>NOTE: </b> You must enter your store ID , publishable key and merchant key</span>
        </div>

        <table class="form-table">
        <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    public function process_payment($order_id)
    {
        $allowed_pay_from = array_merge(array('CREDIT_CARD'), (array) $this->waafi_payment_types);
        $pay_from = isset($_POST['waafi_pay_from']) && is_string($_POST['waafi_pay_from']) ? wc_clean(wp_unslash($_POST['waafi_pay_from'])) : '';
        if ($pay_from === '') {
            $pay_from = "CREDIT_CARD";
        }
        if (!in_array($pay_from, $allowed_pay_from, true)) {
            wc_add_notice(__('Please choose a WaafiPay payment method.', 'woothemes'), 'error');
            return;
        }
        $order = wc_get_order( $order_id );
        $requestId = $this->generateRandomString();
        $referenceId = (string) $order_id;
        // The order key is the value the legacy post_password column held for orders.
        $invoiceId = $order->get_order_key();

        update_post_meta($order_id, 'wc_waafipay_requestId', $requestId);
        update_post_meta($order_id, 'wc_waafipay_referenceid', $referenceId);
        update_post_meta($order_id, 'wc_waafipay_invoice', $invoiceId);
        update_post_meta($order_id, 'wc_waafipay_timestamp', time());

        $arrresp = $this->call_asm('HPP_PURCHASE', $requestId, array(
            'hppSuccessCallbackUrl' => get_site_url().'/wc-api/waafisuccess/?id='.$order_id,
            'hppFailureCallbackUrl' => get_site_url().'/wc-api/waafifail/?id='.$order_id,
            'hppRespDataFormat'     => '4',
            'paymentMethod'         => $pay_from,
            'transactionInfo'       => array(
                'referenceId' => $referenceId,
                'invoiceId'   => $invoiceId,
                'amount'      => (string) $order->get_total(),
                'currency'    => $order->get_currency(),
                'description' => 'Woocommerce Order Payment',
            ),
        ));

        if (!$arrresp || !isset($arrresp['responseCode']) || (string) $arrresp['responseCode'] !== '2001' || empty($arrresp['params']['hppUrl'])) {
            $message = !empty($arrresp['responseMsg']) ? $arrresp['responseMsg'] : __('WaafiPay is not available right now. Please try again.', 'woothemes');
            wc_add_notice(esc_html($message), 'error');
            return;
        }

        $returnurl = $arrresp['params']['hppUrl'];
        $hppRequestId = isset($arrresp['params']['hppRequestId']) ? $arrresp['params']['hppRequestId'] : '';
        $referenceId = isset($arrresp['params']['referenceId']) ? (string) $arrresp['params']['referenceId'] : $referenceId;
        update_post_meta($order_id, 'waafi_pay_from', $pay_from);
        update_post_meta($order_id, 'wc_waafipay_referenceid', $referenceId);
        update_post_meta($order_id, 'wc_waafipay_requestId', $hppRequestId);
        update_post_meta($order_id, 'hppwaafiretrnurl', $returnurl);
        return array(
            'result' => 'success',
            'redirect' => add_query_arg(array('suc' => 'OK', 'rurl' => rawurlencode($returnurl), 'hrid' => rawurlencode($hppRequestId), 'rfid' => rawurlencode($referenceId)), wc_gateway_waafipay()->plugin_url.'includes/redirect.php'),
        );
    }

	public function generateRandomString($length = 15) {
		$characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

	public function payment_fields() {
		 global $woocommerce;
		 if ( $this->description ) {
				echo '<p>'.wp_kses_post($this->description).'</p>';
			}	
			echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';
			
			$inputhtml = "";
			
			foreach((array) $this->waafi_payment_types as $waafi_payment_types){
				
				$txt_waafi = $waafi_payment_types;
				if($waafi_payment_types == "CREDIT_CARD"){
					$txt_waafi = "Credit Card";
				}elseif($waafi_payment_types == "MWALLET_ACCOUNT"){
					$txt_waafi = "Mobile Account";
				}elseif($waafi_payment_types == "MWALLET_BANKACCOUNT"){
					$txt_waafi = "Bank Account";
				}
				$inputhtml .=  '<div class="form-row form-row-wide">
									<input id="'.esc_attr('waafi_pay_from_'.strtolower($waafi_payment_types)).'" name="waafi_pay_from" value="'.esc_attr($waafi_payment_types).'" class="input-radio"  type="radio" />
									<label for="'.esc_attr('waafi_pay_from_'.strtolower($waafi_payment_types)).'" style="font-size: 16px;margin-left: 12px;display:inline;">'.esc_html($txt_waafi).'</label>
								</div>';
			}

			do_action( 'woocommerce_credit_card_form_start', $this->id );
			echo $inputhtml;
			do_action( 'woocommerce_credit_card_form_end', $this->id );
			echo '<div class="clear"></div></fieldset>';
	}

    public function init_form_fields()
    {
        $this->form_fields = wc_gateway_waafipay()->admin->init_form_fields();
    }
	
	public function addscript_function(){
		if ( is_checkout() ) {
			if ( isset( $_REQUEST['cancelled'] ) ){
				wc_add_notice( __( 'Payment Transaction Failed. Please try again.', 'woothemes' ), 'error' );
			}
		}
	}
	
	public function waafifail() {
		$this->handle_hpp_return();
	}

	public function waafisuccess() {
		$this->handle_hpp_return();
	}

	public function call_asm($service_name, $request_id, $service_params)
	{
		$response = wp_remote_post($this->apiurl, array(
			'timeout'     => 60,
			'redirection' => 10,
			'headers'     => array('Content-Type' => 'application/json'),
			'body'        => wp_json_encode(array(
				'schemaVersion' => '1.0',
				'requestId'     => (string) $request_id,
				'timestamp'     => (string) time(),
				'channelName'   => 'WEB',
				'serviceName'   => $service_name,
				'serviceParams' => array_merge(array(
					'storeId'     => $this->store_id,
					'hppKey'      => $this->publishable_key,
					'merchantUid' => $this->merchant_id,
				), $service_params),
			)),
		));
		if (is_wp_error($response)) {
			return null;
		}
		$decoded = json_decode(wp_remote_retrieve_body($response), true);
		return is_array($decoded) ? $decoded : null;
	}

	// Both return URLs only carry the customer back; the order state comes from HPP_GETRESULTINFO for this order's own reference.
	private function handle_hpp_return()
	{
		// The current platform appends "&hppResultToken=" to the callback URL; the legacy platform appended "?hppResultToken=" to the id value.
		$id_parts = explode('?', isset($_REQUEST['id']) && is_string($_REQUEST['id']) ? wp_unslash($_REQUEST['id']) : '', 2);
		$order = wc_get_order(absint($id_parts[0]));
		$hppResultToken = isset($_REQUEST['hppResultToken']) && is_string($_REQUEST['hppResultToken']) ? wp_unslash($_REQUEST['hppResultToken']) : '';
		if ($hppResultToken === '' && isset($id_parts[1])) {
			parse_str($id_parts[1], $legacy_query);
			$hppResultToken = isset($legacy_query['hppResultToken']) ? $legacy_query['hppResultToken'] : '';
		}
		if (!$order || !is_string($hppResultToken) || $hppResultToken === '') {
			wp_safe_redirect(wc_get_checkout_url());
			exit;
		}
		if ($order->is_paid()) {
			wp_safe_redirect($this->get_return_url($order));
			exit;
		}

		$order_id = $order->get_id();
		$referenceId = (string) get_post_meta($order_id, 'wc_waafipay_referenceid', true);
		$result = $this->call_asm('HPP_GETRESULTINFO', $this->generateRandomString(), array(
			'hppResultToken' => $hppResultToken,
			'referenceId'    => $referenceId,
		));
		$params = isset($result['params']) && is_array($result['params']) ? $result['params'] : array();
		$state = isset($params['state']) ? $params['state'] : '';

		if (!$result || !isset($result['responseCode']) || (string) $result['responseCode'] !== '2001') {
			// The lookup failed, so the payment outcome is unknown and the order status is left unchanged.
			$order->add_order_note('WaafiPay result lookup failed, so the payment outcome is unknown. Check the payment in WaafiPay.');
			wc_add_notice(__('We could not confirm your payment yet. Please contact the store before paying again.', 'woothemes'), 'error');
			wp_safe_redirect(wc_get_checkout_url());
			exit;
		}

		if ($state === 'APPROVED' && $this->result_matches_order($params, $order, $referenceId)) {
			$this->complete_order($order, $params, $hppResultToken);
			wp_safe_redirect($this->get_return_url($order));
			exit;
		}

		if ($state === 'DECLINED' || $state === 'CANCELLED') {
			$order->update_status('failed', 'WaafiPay payment ' . strtolower($state) . '.');
			wp_safe_redirect(add_query_arg('cancelled', 'true', wc_get_checkout_url()));
			exit;
		}

		// PENDING, ATTENTION_REQUIRED or an approval for a different order or amount is never an approval for this order.
		$order->update_status('on-hold', sprintf('WaafiPay result needs a manual check: state %s, reference %s, amount %s %s, transaction %s.',
			$state, isset($params['referenceId']) ? $params['referenceId'] : '', isset($params['txAmount']) ? $params['txAmount'] : '',
			isset($params['currencyCode']) ? $params['currencyCode'] : '', isset($params['transactionId']) ? $params['transactionId'] : ''));
		wp_safe_redirect($this->get_return_url($order));
		exit;
	}

	private function result_matches_order($params, $order, $referenceId)
	{
		if ($referenceId === '' || !isset($params['referenceId'], $params['txAmount']) || (string) $params['referenceId'] !== $referenceId) {
			return false;
		}
		if (abs((float) $params['txAmount'] - (float) $order->get_total()) >= 0.005) {
			return false;
		}
		// The legacy platform may leave out currencyCode.
		return empty($params['currencyCode']) || strtoupper($params['currencyCode']) === strtoupper($order->get_currency());
	}

	private function complete_order($order, $params, $hppResultToken)
	{
		global $woocommerce;
		$order_id = $order->get_id();
		$payment_method_title = get_post_meta($order_id, '_payment_method_title', true);
		$waafi_pay_from = get_post_meta($order_id, 'waafi_pay_from', true);
		$order_paymnttyp_name = $payment_method_title;
		if ($waafi_pay_from == "CREDIT_CARD") {
			$order_paymnttyp_name = $payment_method_title . " ( Credit Card )";
		} elseif ($waafi_pay_from == "MWALLET_ACCOUNT") {
			$order_paymnttyp_name = $payment_method_title . " ( MWALLET ACCOUNT )";
		} elseif ($waafi_pay_from == "MWALLET_BANKACCOUNT") {
			$order_paymnttyp_name = $payment_method_title . " ( MWALLET BANKACCOUNT )";
		}

		update_post_meta($order_id, 'hppResultToken', $hppResultToken);
		foreach (array('procCode', 'procDescription', 'transactionId', 'issuerTransactionId', 'txAmount', 'state') as $field) {
			update_post_meta($order_id, $field, isset($params[$field]) ? $params[$field] : '');
		}

		// payment_complete also reduces stock once, so the stock is not reduced here again.
		$order->payment_complete(isset($params['transactionId']) ? $params['transactionId'] : '');
		$order->add_order_note('Hey, your order is paid! Thank you!', true);
		if ($woocommerce->cart) {
			$woocommerce->cart->empty_cart();
		}
		delete_post_meta($order_id, 'hppwaafiretrnurl');
		update_post_meta($order_id, '_payment_method_title', $order_paymnttyp_name);
	}
}
