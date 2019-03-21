<?php

/**
 * This file contains QUI\ERP\Payments\PAYMILL\Payment
 */

namespace QUI\ERP\Payments\Paymill;

use Paymill\Models\Response\Transaction;
use Paymill\Request as PaymillRequest;
use Paymill\Models\Request\Base as PaymillBaseRequest;
use Paymill\Models\Response\Base as PaymillBaseResponse;
use Paymill\Models\Request\Transaction as PaymillTransactionRequest;
use Paymill\Models\Request\Refund as PaymillRefundRequest;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use Paymill\Services\PaymillException as PaymillSdkException;

/**
 * Class Payment
 *
 * Main Payment class for PAYMILL payment processing
 */
class Payment extends QUI\ERP\Accounting\Payments\Api\AbstractPayment
{
    /**
     * PAYMILL API Order attributes
     */
    const ATTR_PAYMILL_TRANSACTION_ID   = 'paymill-TransactionId';
    const ATTR_PAYMILL_REFUND_ID        = 'paymill-RefundId';
    const ATTR_PAYMILL_ORDER_SUCCESSFUL = 'paymill-OrderSuccessful';
    const ATTR_PAYMILL_TOKEN            = 'paymill-Token';

    /**
     * PAYMILL REST API request types
     */
    const PAYMILLL_REQUEST_TYPE_CREATE = 'paymill-api-create';
    const PAYMILLL_REQUEST_TYPE_UPDATE = 'paymill-api-update';
    const PAYMILLL_REQUEST_TYPE_DELETE = 'paymill-api-delete';
    const PAYMILLL_REQUEST_TYPE_GET    = 'paymill-api-get';
    const PAYMILLL_REQUEST_TYPE_LIST   = 'paymill-api-list';

    /**
     * Error codes
     */
    const PAYMILL_ERROR_GENERAL_ERROR      = 'general_error';
    const PAYMILL_ERROR_TRANSACTION_FAILED = 'transaction_failed';
    const PAYMILL_ERROR_REFUND_FAILED      = 'refund_failed';

    /**
     * PAYMILL PHP REST Client (v2)
     *
     * @var PaymillRequest
     */
    protected $PaymillRequest = null;

    /**
     * The Order the payment is processed for
     *
     * @var null
     */
    protected $Order = null;

    /**
     * Payment constructor.
     *
     * @param AbstractOrder $Order (optional) - The Order the payment is processed for
     * @return void
     */
    public function __construct(AbstractOrder $Order = null)
    {
        $this->Order = $Order;
    }

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
        return URL_OPT_DIR.'quiqqer/payment-paymill/bin/images/Payment.jpg';
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
                'PAYMILL :: Cannot check if payment process for Order #'.$hash.' is successful'
                .' -> '.$Exception->getMessage()
            );

            return false;
        }

        return $Order->getPaymentDataEntry(self::ATTR_PAYMILL_ORDER_SUCCESSFUL);
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
     * @param int|float $amount
     * @param string $message
     * @param false|string $hash - if a new hash will be used
     * @throws QUI\ERP\Accounting\Payments\Transactions\RefundException
     */
    public function refund(
        \QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction,
        $amount,
        $message = '',
        $hash = false
    ) {
        try {
            if ($hash === false) {
                $hash = $Transaction->getHash();
            }

            $this->executeRefund($Transaction, $hash, $amount, $message);
        } catch (PaymillException $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            throw new QUI\ERP\Accounting\Payments\Transactions\RefundException([
                'quiqqer/payment-paymill',
                'exception.Payment.refund_error'
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new QUI\ERP\Accounting\Payments\Transactions\RefundException([
                'quiqqer/payment-paymill',
                'exception.Payment.refund_error'
            ]);
        }
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

        $Step->setContent(
            $Engine->fetch(dirname(__FILE__).'/PaymentDisplay.Header.html')
        );

        return $Control->create();
    }

    /**
     * Execute payment
     *
     * @param string $paymillToken - Unique PAYMILL Token for an authorized transaction
     * @return void
     *
     * @throws PaymillException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    public function checkout($paymillToken)
    {
        $this->addOrderHistoryEntry('Creating payment transaction');

        $Transaction      = new PaymillTransactionRequest();
        $PriceCalculation = $this->Order->getPriceCalculation();
        $amount           = $PriceCalculation->getSum()->precision(2)->get();
        $amount           *= 100; // convert to smallest currency unit

        $Transaction->setAmount($amount);
        $Transaction->setCurrency($this->Order->getCurrency()->getCode());
        $Transaction->setToken($paymillToken);
        $Transaction->setDescription(Utils::getTransactionDescription($this->Order));

        /** @var Transaction $Response */
        $Response = $this->paymillApiRequest(self::PAYMILLL_REQUEST_TYPE_CREATE, $Transaction);

        // save PAYMILL Transaction ID to Order
        $this->Order->setPaymentData(self::ATTR_PAYMILL_TRANSACTION_ID, $Response->getId());
        $this->Order->addHistory(
            QUI::getLocale()->get(
                'quiqqer/payment-paymill',
                'history.transaction_id',
                [
                    'transactionId' => $Response->getId()
                ]
            )
        );

        if ($Response->getStatus() !== 'closed') {
            $this->addOrderHistoryEntry(
                'Transaction failed. Status: "'.$Response->getStatus().'"'
                .'" | Response Code: "'.$Response->getResponseCode().'"'
            );

            // @todo Order pending status
            // @todo mark order as problematic?

            $this->saveOrder();
            $this->throwPaymillException(self::PAYMILL_ERROR_TRANSACTION_FAILED);
        }

        $this->addOrderHistoryEntry('Transaction successful');

        $capturedAmount   = $Response->getAmount();
        $capturedCurrency = $Response->getCurrency();

        // Create purchase
        $this->addOrderHistoryEntry('Set Gateway purchase');

        $this->Order->setPaymentData(self::ATTR_PAYMILL_ORDER_SUCCESSFUL, true);
        $this->Order->setSuccessfulStatus();

        $this->addOrderHistoryEntry('Gateway purchase completed and Order payment finished');
        $this->saveOrder();

        $Transaction = Gateway::getInstance()->purchase(
            $capturedAmount / 100,
            new QUI\ERP\Currency\Currency($capturedCurrency),
            $this->Order,
            $this
        );

        $Transaction->setData(
            self::ATTR_PAYMILL_TRANSACTION_ID,
            $this->Order->getPaymentDataEntry(self::ATTR_PAYMILL_TRANSACTION_ID)
        );

        $Transaction->updateData();
    }

    /**
     * Refund partial or full payment of an Order
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     * @param string $refundHash - Hash of the refund Transaction
     * @param float $amount - The amount to be refunden
     * @param string $reason (optional) - The reason for the refund [default: none; max. 255 characters]
     * @return void
     *
     * @throws PaymillException
     * @throws QUI\Exception
     */
    public function executeRefund(
        QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction,
        $refundHash,
        $amount,
        $reason = ''
    ) {
        $Process = new QUI\ERP\Process($Transaction->getGlobalProcessId());
        $Process->addHistory('PAYMILL :: Start refund for transaction #'.$Transaction->getTxId());

        $paymillTransactionId = $Transaction->getData(self::ATTR_PAYMILL_TRANSACTION_ID);

        if (empty($paymillTransactionId)) {
            $Process->addHistory('PAYMILL :: Transaction cannot be refunded because it is not yet captured / completed');

            throw new PaymillException([
                'quiqqer/payment-paymill',
                'exception.Payment.refund_order_not_captured'
            ]);
        }

        // create a refund transaction
        $RefundTransaction = TransactionFactory::createPaymentRefundTransaction(
            $amount,
            $Transaction->getCurrency(),
            $refundHash,
            $Transaction->getPayment()->getName(),
            [
                'isRefund' => 1,
                'message'  => $reason
            ],
            null,
            false,
            $Transaction->getGlobalProcessId()
        );

        $RefundTransaction->pending();

        // Execute refund with Paymill
        $PaymillRefund = new PaymillRefundRequest();
        $AmountValue   = new QUI\ERP\Accounting\CalculationValue($amount, $Transaction->getCurrency(), 2);
        $refundAmount  = $AmountValue->get() * 100; // convert to smallest currency unit

        $PaymillRefund->setAmount($refundAmount);
        $PaymillRefund->setDescription($reason);
        $PaymillRefund->setReason($PaymillRefund::REASON_KEY_REQUESTED_BY_CUSTOMER);
        $PaymillRefund->setId($paymillTransactionId);

        /** @var Transaction $Response */
        try {
            $Response = $this->paymillApiRequest(self::PAYMILLL_REQUEST_TYPE_CREATE, $PaymillRefund);
        } catch (PaymillException $Exception) {
            $Process->addHistory(
                'PAYMILL :: Refund operation failed.'
                .' Reason: "'.$Exception->getMessage().'".'
                .' ReasonCode: "'.$Exception->getCode().'".'
                .' Transaction #'.$Transaction->getTxId()
            );

            $RefundTransaction->error();

            throw $Exception;
        }

        if ($Response->getStatus() !== 'refunded') {
            $Process->addHistory(
                'PAYMILL :: Refund operation failed.'
                .' Status: "'.$Response->getStatus().'".'
                .' Transaction #'.$Transaction->getTxId()
            );

            $RefundTransaction->error();

            $this->throwPaymillException(self::PAYMILL_ERROR_TRANSACTION_FAILED);
        }

        $paymillRefundId = $Response->getId();

        $RefundTransaction->setData(self::ATTR_PAYMILL_REFUND_ID, $paymillRefundId);
        $RefundTransaction->updateData();
        $RefundTransaction->complete();
    }

    /**
     * Execute payment
     *
     * @param string $paymillToken - Unique PAYMILL Token for an authorized transaction
     * @return void
     *
     * @throws PaymillException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    public function paymillRefund($paymillToken)
    {
        $this->addOrderHistoryEntry('Refund transactions');

        $Transaction      = new PaymillTransactionRequest();
        $PriceCalculation = $this->Order->getPriceCalculation();
        $amount           = $PriceCalculation->getSum()->precision(2)->get();
        $amount           *= 100; // convert to smallest currency unit

        $Transaction->setAmount($amount);
        $Transaction->setCurrency($this->Order->getCurrency()->getCode());
        $Transaction->setToken($paymillToken);
        $Transaction->setDescription(Utils::getTransactionDescription($this->Order));

        /** @var Transaction $Response */
        $Response = $this->paymillApiRequest(self::PAYMILLL_REQUEST_TYPE_CREATE, $Transaction);

        // save PAYMILL Transaction ID to Order
        $this->Order->setPaymentData(self::ATTR_PAYMILL_TRANSACTION_ID, $Response->getId());
        $this->Order->addHistory(
            QUI::getLocale()->get(
                'quiqqer/payment-paymill',
                'history.transaction_id',
                [
                    'transactionId' => $Response->getId()
                ]
            )
        );

        if ($Response->getStatus() !== 'closed') {
            $this->addOrderHistoryEntry(
                'Transaction failed. Status: "'.$Response->getStatus().'"'
                .'" | Response Code: "'.$Response->getResponseCode().'"'
            );

            // @todo Order pending status
            // @todo mark order as problematic?

            $this->saveOrder();
            $this->throwPaymillException(self::PAYMILL_ERROR_TRANSACTION_FAILED);
        }

        $this->addOrderHistoryEntry('Transaction successful');

        $capturedAmount   = $Response->getAmount();
        $capturedCurrency = $Response->getCurrency();

        // Create purchase
        $this->addOrderHistoryEntry('Set Gateway purchase');

        $this->Order->setPaymentData(self::ATTR_PAYMILL_ORDER_SUCCESSFUL, true);
        $this->Order->setSuccessfulStatus();

        $this->addOrderHistoryEntry('Gateway purchase completed and Order payment finished');
        $this->saveOrder();

        Gateway::getInstance()->purchase(
            $capturedAmount / 100,
            new QUI\ERP\Currency\Currency($capturedCurrency),
            $this->Order,
            $this
        );
    }

    /**
     * Add history entry for current Order
     *
     * @param string $msg
     * @return void
     */
    protected function addOrderHistoryEntry($msg)
    {
        $this->Order->addHistory('PAYMILL :: '.$msg);
    }

    /**
     * Save Order with SystemUser
     *
     * @return void
     */
    protected function saveOrder()
    {
        $this->Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Make a PAYMILL REST API request
     *
     * @param string $requestType - Request type (see self::PAYMILL_REQUEST_TYPE_*)
     * @param PaymillBaseRequest $RequestData - Request type with filled data
     * @return PaymillBaseResponse|array
     *
     * @throws PaymillException
     */
    public function paymillApiRequest($requestType, $RequestData)
    {
        $Request = $this->getPaymillRequest();

        try {
            switch ($requestType) {
                case self::PAYMILLL_REQUEST_TYPE_CREATE:
                    return $Request->create($RequestData);
                    break;

                case self::PAYMILLL_REQUEST_TYPE_UPDATE:
                    return $Request->update($RequestData);
                    break;

                case self::PAYMILLL_REQUEST_TYPE_DELETE:
                    return $Request->delete($RequestData);
                    break;

                case self::PAYMILLL_REQUEST_TYPE_GET:
                    return $Request->getOne($RequestData);
                    break;

                case self::PAYMILLL_REQUEST_TYPE_LIST:
                    return $Request->getAll($RequestData);
                    break;
            }
        } catch (PaymillSdkException $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            throw new PaymillApiException(
                $Exception->getErrorMessage(),
                $Exception->getResponseCode()
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            $this->throwPaymillException();
        }
    }

    /**
     * Returns the last response data from the Paymill API as an array
     *
     * @return array
     */
    public function getLastPaymillApiResponse()
    {
        return $this->getPaymillRequest()->getLastResponse();
    }

    /**
     * Throw PaymillException for specific PAYMILL API Error
     *
     * @param string $errorCode (optional) - default: general error message
     * @param array $exceptionAttributes (optional) - Additional Exception attributes that may be relevant for the Frontend
     * @return string
     *
     * @throws PaymillException
     */
    protected function throwPaymillException($errorCode = self::PAYMILL_ERROR_GENERAL_ERROR, $exceptionAttributes = [])
    {
        $L   = $this->getLocale();
        $lg  = 'quiqqer/payment-paymill';
        $msg = $L->get($lg, 'payment.error_msg.'.$errorCode);

        $Exception = new PaymillException($msg, 0, $exceptionAttributes);
        $Exception->setAttributes($exceptionAttributes);

        throw $Exception;
    }

    /**
     * Get PAYMILL Client for current payment process
     *
     * @return PaymillRequest
     */
    protected function getPaymillRequest()
    {
        if (!is_null($this->PaymillRequest)) {
            return $this->PaymillRequest;
        }

        if (Provider::getApiSetting('sandbox')) {
            $this->PaymillRequest = new PaymillRequest(Provider::getApiSetting('sandbox_private_key'));
        } else {
            $this->PaymillRequest = new PaymillRequest(Provider::getApiSetting('private_key'));
        }

        return $this->PaymillRequest;
    }
}
