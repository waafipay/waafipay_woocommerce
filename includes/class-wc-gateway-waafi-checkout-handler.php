<?php

//directory access forbidden
if (!defined('ABSPATH')) {
    exit;
}
$includes_path = wc_gateway_waafipay()->includes_path;
require_once($includes_path. 'abstracts/abstract-wc-gateway-waafi.php');

class WC_Gateway_Waafi_Checkout_Handler
{
    public function __construct()
    {
        $this->waafi_payment_gateway = new WC_Waafi_Payment_Gateway();
        $this->payment_mode = wc_gateway_waafipay()->settings->__get('payment_mode');
        $this->payment_mode_woocomm = wc_gateway_waafipay()->settings->__get('payment_mode');
    }
    
    public function process_payment($order_id)
    {
			$allowed_pay_from = array_merge(array('CREDIT_CARD'), (array) $this->waafi_payment_gateway->waafi_payment_types);
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

			$arrresp = $this->waafi_payment_gateway->call_asm('HPP_PURCHASE', $requestId, array(
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
				'redirect' => add_query_arg(array('suc' => 'OK', 'rurl' => rawurlencode($returnurl), 'hrid' => rawurlencode($hppRequestId), 'rfid' => rawurlencode($referenceId)), plugin_dir_url(__FILE__).'redirect.php'),
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
            
}
