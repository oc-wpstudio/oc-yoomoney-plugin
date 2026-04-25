<?php namespace Wpstudio\YooMoney;

use OFFLINE\Mall\Classes\Payments\PaymentGateway;
use System\Classes\PluginBase;
use Wpstudio\YooMoney\Classes\YooMoneyCheckout;

class Plugin extends PluginBase
{
    /**
     * @var array Plugin dependencies
     */
    public $require = ['Offline.Mall'];

    public function boot()
    {
        $gateway = $this->app->get(PaymentGateway::class);
        $gateway->registerProvider(new YooMoneyCheckout());
    }
}
