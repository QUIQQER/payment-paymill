<?php

/**
 * This file contains QUI\ERP\Payments\PayPal\Payment
 */

namespace QUI\ERP\Payments\Paymill;

use PayPal\v1\Payments\OrderAuthorizeRequest;
use PayPal\v1\Payments\OrderCaptureRequest;
use PayPal\v1\Payments\OrderVoidRequest;
use PayPal\v1\Payments\PaymentExecuteRequest;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use PayPal\Core\PayPalHttpClient as PaymillClient;
use PayPal\Core\ProductionEnvironment;
use PayPal\Core\SandboxEnvironment;
use PayPal\v1\Payments\PaymentCreateRequest;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Utils\User as ERPUserUtils;
use QUI\ERP\Accounting\CalculationValue;

/**
 * Class Payment
 *
 * Main Payment class for PayPal payment processing
 */
class Payment extends QUI\ERP\Accounting\Payments\Api\AbstractPayment
{
    /**
     * PayPal API Order attributes
     */
    const ATTR_PAYPAL_PAYMENT_ID         = 'paymill-PaymentId';
    const ATTR_PAYPAL_PAYER_ID           = 'paymill-PayerId';
    const ATTR_PAYPAL_ORDER_ID           = 'paymill-OrderId';
    const ATTR_PAYPAL_AUTHORIZATION_ID   = 'paymill-AuthorizationId';
    const ATTR_PAYPAL_CAPTURE_ID         = 'paymill-CaptureId';
    const ATTR_PAYPAL_PAYMENT_SUCCESSFUL = 'paymill-PaymentSuccessful';
    const ATTR_PAYPAL_PAYER_DATA         = 'paymill-PayerData';

    /**
     * PayPal REST API request types
     */
    const PAYPAL_REQUEST_TYPE_CREATE_ORDER    = 'paymill-api-create_order';
    const PAYPAL_REQUEST_TYPE_EXECUTE_ORDER   = 'paymill-api-execute_order';
    const PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER = 'paymill-api-authorize_order';
    const PAYPAL_REQUEST_TYPE_CAPTURE_ORDER   = 'paymill-api-capture_order';
    const PAYPAL_REQUEST_TYPE_VOID_ORDER      = 'paymill-api-void_oder';

    /**
     * Error codes
     */
    const PAYPAL_ERROR_GENERAL_ERROR        = 'general_error';
    const PAYPAL_ERROR_ORDER_NOT_APPROVED   = 'order_not_approved';
    const PAYPAL_ERROR_ORDER_NOT_AUTHORIZED = 'order_not_authorized';
    const PAYPAL_ERROR_ORDER_NOT_CAPTURED   = 'order_not_captured';

    /**
     * PayPal PHP REST Client (v2)
     *
     * @var PaymillClient
     */
    protected $PaymillClient = null;

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getLocale()->get('quiqqer/payment-paymill', 'payment.title');
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getLocale()->get('quiqqer/payment-paymill', 'payment.description');
    }

    /**
     * Return the payment icon (the URL path)
     * Can be overwritten
     *
     * @return string
     */
    public function getIcon()
    {
        return URL_OPT_DIR . 'quiqqer/payment-paymill/bin/images/Payment.jpg';
    }

    /**
     * Is the payment process successful?
     * This method returns the payment success type
     *
     * @param string $hash - Vorgangsnummer - hash number - procedure number
     * @return bool
     */
    public function isSuccessful($hash)
    {
        try {
            $Order = OrderHandler::getInstance()->getOrderByHash($hash);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                'PayPal :: Cannot check if payment process for Order #' . $hash . ' is successful'
                . ' -> ' . $Exception->getMessage()
            );

            return false;
        }

        return $Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL);
    }

    /**
     * Is the payment a gateway payment?
     *
     * @return bool
     */
    public function isGateway()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function refundSupport()
    {
        return true;
    }

    /**
     * Execute a refund
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     */
    public function refund(QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction)
    {
        // @todo
    }

    /**
     * If the Payment method is a payment gateway, it can return a gateway display
     *
     * @param AbstractOrder $Order
     * @param QUI\ERP\Order\Controls\OrderProcess\Processing $Step
     * @return string
     *
     * @throws QUI\Exception
     */
    public function getGatewayDisplay(AbstractOrder $Order, $Step = null)
    {
        $Control = new PaymentDisplay();
        $Control->setAttribute('Order', $Order);
        $Control->setAttribute('Payment', $this);

        $Step->setTitle(
            QUI::getLocale()->get(
                'quiqqer/payment-paymill',
                'payment.step.title'
            )
        );

        $Engine = QUI::getTemplateManager()->getEngine();
        $Step->setContent($Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     */
    protected function saveOrder(AbstractOrder $Order)
    {
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Make a PayPal REST API request
     *
     * @param string $request - Request type (see self::PAYPAL_REQUEST_TYPE_*)
     * @param array $body - Request data
     * @param AbstractOrder $Order - The QUIQQER ERP Order the request is intended for
     * ($Order has to have the required paymentData attributes for the given $request value!)
     * @return array|false - Response body or false on error
     *
     * @throws PayPalException
     */
    protected function payPalApiRequest($request, $body, AbstractOrder $Order)
    {
        switch ($request) {
            case self::PAYPAL_REQUEST_TYPE_CREATE_ORDER:
                $Request = new PaymentCreateRequest();
                break;

            case self::PAYPAL_REQUEST_TYPE_EXECUTE_ORDER:
                $Request = new PaymentExecuteRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER:
                $Request = new OrderAuthorizeRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER:
                $Request = new OrderCaptureRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_VOID_ORDER:
                $Request = new OrderVoidRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            default:
                $this->throwPayPalException();
        }

        $Request->body = $body;

        try {
            $Response = $this->getPaymillClient()->execute($Request);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            $this->throwPayPalException();
        }

        // turn stdClass object to array
        return json_decode(json_encode($Response->result), true);
    }

    /**
     * Get PayPal Client for current payment process
     *
     * @return PaymillClient
     */
    protected function getPaymillClient()
    {
        if (!is_null($this->PaymillClient)) {
            return $this->PaymillClient;
        }

        $clientId     = Provider::getApiSetting('client_id');
        $clientSecret = Provider::getApiSetting('client_secret');

        if (Provider::getApiSetting('sandbox')) {
            $Environment = new SandboxEnvironment($clientId, $clientSecret);
        } else {
            $Environment = new ProductionEnvironment($clientId, $clientSecret);
        }

        $this->PaymillClient = new PaymillClient($Environment);

        return $this->PaymillClient;
    }
}
