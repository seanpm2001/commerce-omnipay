<?php

namespace craft\commerce\omnipay\base;

use Craft;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\events\GatewayRequestEvent;
use craft\commerce\events\ItemBagEvent;
use craft\commerce\events\SendPaymentRequestEvent;
use craft\commerce\helpers\Currency;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin;
use craft\errors\GatewayRequestCancelledException;
use craft\helpers\UrlHelper;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\CreditCard;
use Omnipay\Common\ItemBag;
use Omnipay\Common\Message\AbstractRequest;
use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\Common\Message\ResponseInterface;
use yii\base\NotSupportedException;

/**
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
abstract class Gateway extends BaseGateway
{
    /**
     * @var AbstractGateway
     */
    private $_gateway;

    /**
     * @inheritdocs
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        if (!$this->supportsAuthorize()) {
            throw new NotSupportedException(Craft::t('commerce', 'Authorizing is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction, $form);
        $authorizeRequest = $this->prepareAuthorizeRequest($request);

        return $this->performRequest($authorizeRequest, $transaction);
    }

    /**
     * @inheritdoc
     */
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        if (!$this->supportsCapture()) {
            throw new NotSupportedException(Craft::t('commerce', 'Capturing is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $captureRequest = $this->prepareCaptureRequest($request, $reference);

        return $this->performRequest($captureRequest, $transaction);
    }

    /**
     * @inheritdoc
     */
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        if (!$this->supportsCompleteAuthorize()) {
            throw new NotSupportedException(Craft::t('commerce', 'Completing authorization is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $completeRequest = $this->prepareCompleteAuthorizeRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        if (!$this->supportsCompletePurchase()) {
            throw new NotSupportedException(Craft::t('commerce', 'Completing purchase is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $completeRequest = $this->prepareCompletePurchaseRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    /**
     * Populate the request array before it's dispatched.
     *
     * @param array $request Parameter array by reference.
     * @param BasePaymentForm $form
     *
     * @return void
     */
    abstract public function populateRequest(array &$request, BasePaymentForm $form = null);

    /**
     * @inheritdocs
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        if (!$this->supportsPurchase()) {
            throw new NotSupportedException(Craft::t('commerce', 'Purchasing is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction, $form);
        $purchaseRequest = $this->preparePurchaseRequest($request);

        return $this->performRequest($purchaseRequest, $transaction);
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction, string $reference): RequestResponseInterface
    {
        if (!$this->supportsRefund()) {
            throw new NotSupportedException(Craft::t('commerce', 'Refunding is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $refundRequest = $this->prepareRefundRequest($request, $reference);

        return $this->performRequest($refundRequest, $transaction);
    }

    /**
     * @inheritdoc
     */
    public function supportsAuthorize(): bool
    {
        return $this->gateway()->supportsAuthorize();
    }

    /**
     * @inheritdoc
     */
    public function supportsCapture(): bool
    {
        return $this->gateway()->supportsCapture();
    }

    /**
     * @inheritdoc
     */
    public function supportsCompleteAuthorize(): bool
    {
        return $this->gateway()->supportsCompleteAuthorize();
    }

    /**
     * @inheritdoc
     */
    public function supportsCompletePurchase(): bool
    {
        return $this->gateway()->supportsCompletePurchase();
    }

    /**
     * @inheritdoc
     */
    public function supportsPurchase(): bool
    {
        return $this->gateway()->supportsPurchase();
    }

    /**
     * @inheritdoc
     */
    public function supportsRefund(): bool
    {
        return $this->gateway()->supportsRefund();
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns an Omnipay gateway instance based on the stored settings.
     *
     * @return AbstractGateway The actual gateway.
     */
    abstract protected function createGateway(): AbstractGateway;

    /**
     * Create a gateway specific item bag for the order.
     *
     * @param Order $order The order.
     *
     * @return ItemBag
     */
    protected function createItemBagForOrder(Order $order): ItemBag
    {
        if (!$this->sendCartInfo) {
            return null;
        }

        $items = $this->getItemListForOrder($order);
        $itemBagClassName = $this->getItemBagClassName();

        return new $itemBagClassName($items);
    }

    /**
     * Create the parameters for a payment request based on a trasaction and optional card and item list.
     *
     * @param Transaction $transaction The transaction that is basis for this request.
     * @param CreditCard  $card        The credit card being used
     * @param ItemBag     $itemBag     The item list.
     *
     * @return array
     */
    protected function createPaymentRequest(Transaction $transaction, $card = null, $itemBag = null): array
    {
        $request = [
            'amount' => $transaction->paymentAmount,
            'currency' => $transaction->paymentCurrency,
            'transactionId' => $transaction->id,
            'description' => Craft::t('commerce', 'Order').' #'.$transaction->orderId,
            'clientIp' => Craft::$app->getRequest()->userIP,
            'transactionReference' => $transaction->hash,
            'returnUrl' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]),
            'cancelUrl' => UrlHelper::siteUrl($transaction->order->cancelUrl),
        ];

        // Each gateway adapter needs to know whether to use our acceptNotification handler because most omnipay gateways
        // implement the notification API differently. Hoping Omnipay v3 will improve this.
        // For now, the standard paymentComplete handler is the default unless the gateway has been tested with our acceptNotification handler.
        // TODO: move the handler logic into the gateway adapter itself if the Omnipay v2 interface cannot standardise.
        // TODO: It was moved. What now?
        if ($this->useNotifyUrl()) {
            $request['notifyUrl'] = UrlHelper::actionUrl('commerce/payments/accept-notification', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]);
            unset($request['returnUrl']);
        } else {
            $request['notifyUrl'] = $request['returnUrl'];
        }

        // Do not use IPv6 loopback
        if ($request['clientIp'] ===  '::1') {
            $request['clientIp'] = '127.0.0.1';
        }

        // custom gateways may wish to access the order directly
        $request['order'] = $transaction->order;
        $request['orderId'] = $transaction->order->id;

        // Stripe only params
        $request['receiptEmail'] = $transaction->order->email;

        // Paypal only params
        $request['noShipping'] = 1;
        $request['allowNote'] = 0;
        $request['addressOverride'] = 1;
        $request['buttonSource'] = 'ccommerce_SP';

        if ($card) {
            $request['card'] = $card;
        }

        if ($itemBag) {
            $request['items'] = $itemBag;
        }

        return $request;
    }

    /**
     * Prepare a request for execution by transaction and a populated payment form.
     *
     * @param Transaction     $transaction
     * @param BasePaymentForm $form        Optional for capture/refund requests.
     *
     * @return mixed
     */
    abstract protected function createRequest(Transaction $transaction, BasePaymentForm $form = null);

    /**
     * @return AbstractGateway
     */
    protected function gateway(): AbstractGateway
    {
        if ($this->_gateway !== null) {
            return $this->_gateway;
        }

        return $this->_gateway = $this->createGateway();
    }

    /**
     * Return the gateway class name.
     *
     * @return string|null
     */
    abstract protected function getGatewayClassName();

    /**
     * Return the class name used for item bags by this gateway.
     *
     * @return string
     */
    protected function getItemBagClassName(): string {
        return ItemBag::class;
    }

    /**
     * Get the item bag for the order.
     *
     * @param Order $order
     *
     * @return mixed
     */
    protected function getItemBagForOrder(Order $order)
    {
        $itemBag = $this->createItemBagForOrder($order);

        $event = new ItemBagEvent([
            'items' => $itemBag,
            'order' => $order
        ]);
        $this->trigger(self::EVENT_AFTER_CREATE_ITEM_BAG, $event);

        return $event->items;
    }

    /**
     * Generate the item list for an Order.
     *
     * @param Order $order
     *
     * @return array
     */
    protected function getItemListForOrder(Order $order): array
    {
        $items = [];

        $priceCheck = 0;
        $count = -1;

        /** @var LineItem $item */
        foreach ($order->lineItems as $item) {
            $price = Currency::round($item->salePrice);
            // Can not accept zero amount items. See item (4) here:
            // https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECCustomizing/#setting-order-details-on-the-paypal-review-page

            if ($price !== 0) {
                $count++;
                /** @var Purchasable $purchasable */
                $purchasable = $item->getPurchasable();
                $defaultDescription = Craft::t('commerce', 'Item ID').' '.$item->id;
                $purchasableDescription = $purchasable ? $purchasable->getDescription() : $defaultDescription;
                $description = isset($item->snapshot['description']) ? $item->snapshot['description'] : $purchasableDescription;
                $description = empty($description) ? 'Item '.$count : $description;
                $items[] = [
                    'name' => $description,
                    'description' => $description,
                    'quantity' => $item->qty,
                    'price' => $price,
                ];

                $priceCheck += ($item->qty * $item->salePrice);
            }
        }

        $count = -1;

        /** @var OrderAdjustment $adjustment */
        foreach ($order->adjustments as $adjustment) {
            $price = Currency::round($adjustment->amount);

            // Do not include the 'included' adjustments, and do not send zero value items
            // See item (4) https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECCustomizing/#setting-order-details-on-the-paypal-review-page
            if (($adjustment->included == 0 || $adjustment->included == false) && $price !== 0) {
                $count++;
                $items[] = [
                    'name' => empty($adjustment->name) ? $adjustment->type." ".$count : $adjustment->name,
                    'description' => empty($adjustment->description) ? $adjustment->type.' '.$count : $adjustment->description,
                    'quantity' => 1,
                    'price' => $price,
                ];
                $priceCheck += $adjustment->amount;
            }
        }

        $priceCheck = Currency::round($priceCheck);
        $totalPrice = Currency::round($order->totalPrice);
        $same = (bool)($priceCheck === $totalPrice);

        if (!$same) {
            Craft::error('Item bag total price does not equal the orders totalPrice, some payment gateways will complain.', __METHOD__);
        }

        return $items;
    }

    /**
     * Perform a request and return the response.
     *
     * @param $request
     * @param $transaction
     *
     * @return RequestResponseInterface
     * @throws GatewayRequestCancelledException
     */
    protected function performRequest($request, $transaction)
    {
        //raising event
        $event = new GatewayRequestEvent([
            'type' => $transaction->type,
            'request' => $request,
            'transaction' => $transaction
        ]);

        // Raise 'beforeGatewayRequestSend' event
        $this->trigger(self::EVENT_BEFORE_GATEWAY_REQUEST_SEND, $event);

        if (!$event->isValid) {
            throw new GatewayRequestCancelledException(Craft::t('commerce', 'The gateway request was cancelled!'));
        }

        $response = $this->sendRequest($request);

        return $this->prepareResponse($response);
    }

    /**
     * @inheritdoc
     */
    protected function prepareAuthorizeRequest($request): RequestInterface
    {
        return $this->gateway()->authorize($request);
    }

    /**
     * @inheritdoc
     */
    protected function prepareCompleteAuthorizeRequest($request): AbstractRequest
    {
        /** @var AbstractRequest $completeRequest */
        $completeRequest = $this->gateway()->completeAuthorize($request);

        return $completeRequest;
    }

    /**
     * @inheritdoc
     */
    protected function prepareCompletePurchaseRequest($request): AbstractRequest
    {
        /** @var AbstractRequest $completeRequest */
        $completeRequest = $this->gateway()->completePurchase($request);

        return $completeRequest;
    }

    /**
     * @inheritdoc
     */
    protected function prepareCaptureRequest($request, string $reference): RequestInterface
    {
        /** @var AbstractRequest $captureRequest */
        $captureRequest = $this->gateway()->capture($request);
        $captureRequest->setTransactionReference($reference);

        return $captureRequest;
    }

    /**
     * @inheritdoc
     */
    protected function preparePurchaseRequest($request): RequestInterface
    {
        return $this->gateway()->purchase($request);
    }

    /**
     * @inheritdoc
     */
    protected function prepareResponse($response): RequestResponseInterface
    {
        /** @var AbstractResponse $response */
        return new RequestResponse($response);
    }

    /**
     * @inheritdoc
     */
    protected function prepareRefundRequest($request, string $reference): RequestInterface
    {
        /** @var AbstractRequest $refundRequest */
        $refundRequest = $this->gateway()->refund($request);
        $refundRequest->setTransactionReference($reference);

        return $refundRequest;

    }

    /**
     * @inheritdoc
     */
    protected function sendRequest($request): ResponseInterface
    {
        /** @var RequestInterface $request */
        $data = $request->getData();

        $event = new SendPaymentRequestEvent([
            'requestData' => $data
        ]);

        // Raise 'beforeSendPaymentRequest' event
        $payments = Plugin::getInstance()->getPayments();
        $payments->trigger($payments::EVENT_BEFORE_SEND_PAYMENT_REQUEST, $event);

        // We can't merge the $data with $modifiedData since the $data is not always an array.
        // For example it could be a XML object, json, or anything else really.
        if ($event->modifiedRequestData !== null) {
            return $request->sendData($event->modifiedRequestData);
        }

        return $request->send();
    }
}
