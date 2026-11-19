<?php
/*
 * Waafipay Plugin for WooCommerce
*/

//directory access forbidden
if (!defined('ABSPATH')) {
    exit;
}

require_once(dirname(__FILE__) . '/abstracts/abstract-wc-gateway-waafi.php');

class WC_Gateway_Waafi extends WC_Waafi_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'wc-waafipay';

        parent::__construct();
    }
}
