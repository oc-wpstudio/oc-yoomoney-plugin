<?php namespace Wpstudio\YooMoney\Classes;

use OFFLINE\Mall\Classes\Payments\PaymentProvider;
use OFFLINE\Mall\Classes\Payments\PaymentResult;
use OFFLINE\Mall\Models\OrderProduct;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use Omnipay\Common\Exception\InvalidResponseException;
use Omnipay\Omnipay;
use Omnipay\YooMoney\Gateway;
use Omnipay\YooMoney\Message\PurchaseRequest;
use Omnipay\YooMoney\Message\PurchaseResponse;
use Throwable;
use Session;
use Lang;

class YooMoneyCheckout extends PaymentProvider
{
    public $order;
    public $data;

    public function name(): string
    {
        return Lang::get('wpstudio.yoomoney::lang.settings.yoomoney_checkout');
    }

    public function identifier(): string
    {
        return 'yoomoney';
    }

    public function validate(): bool
    {
        return true;
    }

    public function process(PaymentResult $result): PaymentResult
    {
        $gateway = $this->getGateway();

        try {
            $request = $gateway->purchase([
                'transactionId' => $this->order->id,
                'amount' => $this->order->total_in_currency,
                'currency' => $this->order->currency['code'] ?? 'RUB',
                'returnUrl' => $this->returnUrl(),
                'cancelUrl' => $this->cancelUrl(),
                'description' => Lang::get('wpstudio.yoomoney::lang.messages.order_number') . $this->order->order_number,
            ]);

            assert($request instanceof PurchaseRequest);

            $request->setReceipt($this->getReceipt());

            $response = $request->send();

            if (!$response->isSuccessful() && !$response->isRedirect()) {
                throw new InvalidResponseException();
            }
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        assert($response instanceof PurchaseResponse);

        Session::put('mall.payment.callback', self::class);

        $this->setOrder($result->order);

        $result->order->payment_transaction_id = $response->getTransactionReference();
        $result->order->save();

        if ($response->isRedirect()) {
            return $result->redirect($response->getRedirectUrl());
        }

        return $result->success([], $response);
    }

    public function getReceipt()
    {
        return [
            'customer' => [
                'email' => $this->order->customer->user->email,
            ],
            'items' => $this->getReceiptItems()
        ];
    }

    public function getReceiptItems()
    {
        return $this->order->products->map(fn(OrderProduct $product) => [
            'description' => $product->name,
            'quantity' => (float)$product->quantity,
            'amount' => [
                'value' => $product->pricePostTaxes()->float,
                'currency' => $this->order->currency['code'] ?? 'RUB',
            ],
            'vat_code' => 1, // Без НДС или по умолчанию. В Т-банке было 'none'
            'payment_subject' => 'commodity',
            'payment_mode' => 'full_payment',
        ])->toArray();
    }

    public function complete(PaymentResult $result): PaymentResult
    {
        // Для минималки можно оставить пустым или реализовать проверку статуса
        // Но в Т-банке была проверка статуса.
        return $result->success([], []);
    }

    protected function getGateway()
    {
        $gateway = Omnipay::create('\\Omnipay\\YooMoney\\Gateway');

        $gateway->setShopId(PaymentGatewaySettings::get('yoomoney_shop_id'));
        $gateway->setSecretKey(PaymentGatewaySettings::get('yoomoney_secret_key'));

        if (PaymentGatewaySettings::get('yoomoney_test_mode')) {
            $gateway->setTestMode(true);
            $gateway->setShopId(PaymentGatewaySettings::get('yoomoney_shop_id_test'));
            $gateway->setSecretKey(PaymentGatewaySettings::get('yoomoney_secret_key_test'));
        }

        return $gateway;
    }

    public function settings(): array
    {
        return [
            'yoomoney_test_mode' => [
                'label'   => 'wpstudio.yoomoney::lang.settings.yoomoney_test_mode',
                'comment' => 'wpstudio.yoomoney::lang.settings.yoomoney_test_mode_label',
                'span'    => 'left',
                'type'    => 'switch',
            ],
            'yoomoney_shop_id_test' => [
                'label'   => 'Shop ID (Test)',
                'span'    => 'left',
                'type'    => 'text',
            ],
            'yoomoney_secret_key_test' => [
                'label'   => 'Secret Key (Test)',
                'span'    => 'left',
                'type'    => 'text',
            ],
            'yoomoney_shop_id' => [
                'label'   => 'Shop ID',
                'span'    => 'left',
                'type'    => 'text',
            ],
            'yoomoney_secret_key' => [
                'label'   => 'Secret Key',
                'span'    => 'left',
                'type'    => 'text',
            ],
        ];
    }

    public function encryptedSettings(): array
    {
        return ['yoomoney_secret_key', 'yoomoney_secret_key_test'];
    }
}
