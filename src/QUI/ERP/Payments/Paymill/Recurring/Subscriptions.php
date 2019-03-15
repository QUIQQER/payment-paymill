<?php

namespace QUI\ERP\Payments\Paymill\Recurring;

use Paymill\Models\Request\Base;
use Paymill\Models\Response\Subscription;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\ERP\Payments\Paymill\Recurring\Payment as RecurringPayment;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Payments\Paymill\Utils;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Payments\Paymill\Payment as BasePayment;
use QUI\Utils\Security\Orthos;
use Paymill\Models\Request\Subscription as PaymillSubscriptionRequest;
use QUI\ERP\Payments\Paymill\PaymillPayments;
use QUI\ERP\Plans\Utils as ErpPlansUtils;

/**
 * Class Subscriptions
 *
 * Handler for PayPal Billing Agreement management
 */
class Subscriptions
{
    const TBL_BILLING_AGREEMENTS = 'paypal_billing_agreements';

    /**
     * @var QUI\ERP\Payments\Paymill\Payment
     */
    protected static $Payment = null;

    /**
     * Create a PayPal Billing Agreement based on a Billing Plan
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws QUI\ERP\Payments\Paymill\PaymillException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     * @throws \Exception
     */
    public static function createSubscription(AbstractOrder $Order)
    {
        if ($Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_SUBSCRIPTION_ID)) {
            return;
        }

        $paymillToken = $Order->getAttribute(BasePayment::ATTR_PAYMILL_TOKEN);

        if (empty($paymillToken)) {
            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.Payment.createSubscription.missing_token'
                )
            );
        }

        // Create/get Paymill Offer
        $offerId = Offers::createOfferFromOrder($Order);

        $Order->addHistory(Utils::getHistoryText('order.offer_created', [
            'offerdId' => $offerId
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_PAYMILL_OFFER_ID, $offerId);
        Utils::saveOrder($Order);

        // Create and verify payment
        $PaymillPayment = PaymillPayments::createPaymillPayment($paymillToken);
        $paymentId      = $PaymillPayment->getId();

        /**
         * To check the "is_recurring" flag in the new Paymill Payment
         * the last raw API response is checked here, because at this time
         * the flag is not represented in the \Paymill\Models\Response\Payment object.
         *
         * @author Patrick Müller [15.03.2019]
         */
        $paymillResponse = Utils::getLastPaymillApiResponse();

        if (empty($paymillResponse['data']['is_recurring'])) {
            // Immediately delete the payment if it is not eligible for recurring payments
            PaymillPayments::deletePaymillPayment($paymentId);

            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.Payment.createSubscription.not_recurring'
                )
            );
        }

        $Order->addHistory(Utils::getHistoryText('order.payment_created', [
            'paymentId' => $paymentId
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_PAYMILL_PAYMENT_ID, $paymentId);
        Utils::saveOrder($Order);

        // Create subscription
        $PaymillSubscription = new PaymillSubscriptionRequest();

        $PaymillSubscription->setOffer($offerId);
        $PaymillSubscription->setPayment($paymentId);

        // Set subscription amount and currency
        $totalSum    = $Order->getPriceCalculation()->getSum()->get();
        $AmountValue = new QUI\ERP\Accounting\CalculationValue($totalSum, $Order->getCurrency(), 2);
        $offerAmount = $AmountValue->get() * 100; // convert to smallest currency unit

        $PaymillSubscription->setAmount($offerAmount);
        $PaymillSubscription->setCurrency($Order->getCurrency());

        // Set name
        $PaymillSubscription->setName(
            QUI::getLocale()->get(
                'quiqqer/payment-paymill',
                'recurring.subscription.name',
                [
                    'orderReference' => $Order->getPrefixedId(),
                    'url'            => Utils::getProjectUrl()
                ]
            )
        );

        // Set period of validity for non-auto-extended subscriptions
        $planDetails = ErpPlansUtils::getPlanDetailsFromOrder($Order);

        if (!empty($planDetails['auto_extend'])) {
            $durationIntervalParts = explode("-", $planDetails['duration_interval']);
            $number                = (int)$durationIntervalParts[0] - 1; // -1 because last payment is executed at end of period of validity; see API docs
            $interval              = mb_strtoupper($durationIntervalParts[1]);

            $PaymillSubscription->setPeriodOfValidity($number." ".$interval);
        }

        /** @var Subscription $Subscription */
        $Subscription   = Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_CREATE, $PaymillSubscription);
        $subscriptionId = $Subscription->getId();

        $Order->addHistory(Utils::getHistoryText('order.subscription_created', [
            'subscriptionId' => $subscriptionId
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_PAYMILL_SUBSCRIPTION_ID, $subscriptionId);
        Utils::saveOrder($Order);

        // Write subscription data to db
        QUI::getDataBase()->insert(
            self::getSubscriptionsTable(),
            [
                'paymill_subscription_id' => $subscriptionId,
                'paymill_offer_id'        => $offerId,
                'customer'                => json_encode($Order->getCustomer()->getAttributes()),
                'global_process_id'       => $Order->getHash(),
                'active'                  => 1
            ]
        );
    }

    /**
     * Bills the balance for an agreement based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     * @throws PaymillException
     */
    public static function billSubscriptionBalance(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_ID);

        if (empty($billingAgreementId)) {
            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.agreement_id_not_found',
                    [
                        'invoiceId' => $Invoice->getId()
                    ]
                ),
                404
            );
        }

        $data = self::getSubscriptionData($billingAgreementId);

        if ($data === false) {
            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.agreement_not_found',
                    [
                        'billingAgreementId' => $billingAgreementId
                    ]
                ),
                404
            );
        }

        try {
            /** @var QUI\Locale $Locale */
            $Locale = $Invoice->getCustomer()->getLocale();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT,
            [
                'note' => $Locale->get(
                    'quiqqer/payment-paypal',
                    'recurring.billing_agreement.bill_balance.note',
                    [
                        'invoiceReference' => $Invoice->getId(),
                        'url'              => Utils::getProjectUrl()
                    ]
                )
            ],
            [
                RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_ID => $billingAgreementId
            ]
        );
    }

    /**
     * Get data of all Paymill Subscriptions (QUIQQER data only; no Pamill query performed!)
     *
     * @param array $searchParams
     * @param bool $countOnly (optional) - Return count of all results
     * @return array|int
     */
    public static function getSubscriptionList($searchParams, $countOnly = false)
    {
        $Grid       = new QUI\Utils\Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);

        $binds = [];
        $where = [];

        if ($countOnly) {
            $sql = "SELECT COUNT(paymill_subscription_id)";
        } else {
            $sql = "SELECT *";
        }

        $sql .= " FROM `".self::getSubscriptionsTable()."`";

        if (!empty($searchParams['search'])) {
            $where[] = '`global_process_id` LIKE :search';

            $binds['search'] = [
                'value' => '%'.$searchParams['search'].'%',
                'type'  => \PDO::PARAM_STR
            ];
        }

        // build WHERE query string
        if (!empty($where)) {
            $sql .= " WHERE ".implode(" AND ", $where);
        }

        // ORDER
        if (!empty($searchParams['sortOn'])
        ) {
            $sortOn = Orthos::clear($searchParams['sortOn']);
            $order  = "ORDER BY ".$sortOn;

            if (isset($searchParams['sortBy']) &&
                !empty($searchParams['sortBy'])
            ) {
                $order .= " ".Orthos::clear($searchParams['sortBy']);
            } else {
                $order .= " ASC";
            }

            $sql .= " ".$order;
        }

        // LIMIT
        if (!empty($gridParams['limit'])
            && !$countOnly
        ) {
            $sql .= " LIMIT ".$gridParams['limit'];
        } else {
            if (!$countOnly) {
                $sql .= " LIMIT ".(int)20;
            }
        }

        $Stmt = QUI::getPDO()->prepare($sql);

        // bind search values
        foreach ($binds as $var => $bind) {
            $Stmt->bindValue(':'.$var, $bind['value'], $bind['type']);
        }

        try {
            $Stmt->execute();
            $result = $Stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }

        if ($countOnly) {
            return (int)current(current($result));
        }

        return $result;
    }

    /**
     * Get details of a Billing Agreement
     *
     * @param string $billingAgreementId
     * @return array
     * @throws PaymillException
     */
    public static function getSubscriptionDetails($billingAgreementId)
    {
        return self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT,
            [],
            [
                RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_ID => $billingAgreementId
            ]
        );
    }

    /**
     * Cancel a Billing Agreement
     *
     * @param int|string $billingAgreementId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PaymillException
     */
    public static function cancelSubscription($billingAgreementId, $reason = '')
    {
        $data = self::getSubscriptionData($billingAgreementId);

        if (empty($data)) {
            return;
        }

        try {
            $Locale = new QUI\Locale();
            $Locale->setCurrent($data['customer']['lang']);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        if (empty($reason)) {
            $reason = $Locale->get(
                'quiqqer/payment-paypal',
                'recurring.billing_agreement.cancel.note',
                [
                    'url'             => Utils::getProjectUrl(),
                    'globalProcessId' => $data['globalProcessId']
                ]
            );
        }

        try {
            self::payPalApiRequest(
                RecurringPayment::PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT,
                [
                    'note' => $reason
                ],
                [
                    RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_ID => $billingAgreementId
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.cancel.error'
                )
            );
        }

        // Remove from QUIQQER database
//        QUI::getDataBase()->delete(
//            self::getSubscriptionsTable(),
//            [
//                'paypal_agreement_id' => $billingAgreementId
//            ]
//        );
    }

    /**
     * Execute a Billing Agreement
     *
     * @param AbstractOrder $Order
     * @param string $agreementToken
     * @return void
     * @throws PaymillException
     */
    public static function executeSubscription(AbstractOrder $Order, string $agreementToken)
    {
        try {
            $response = self::payPalApiRequest(
                RecurringPayment::PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT,
                [],
                [
                    RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_TOKEN => $agreementToken
                ]
            );
        } catch (PaymillException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            Utils::saveOrder($Order);

            QUI\System\Log::writeException($Exception);

            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $Order->addHistory(Utils::getHistoryText('order.billing_agreement_accepted', [
            'agreementToken' => $agreementToken,
            'agreementId'    => $response['id']
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_TOKEN, $agreementToken);
        $Order->setPaymentData(RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_ID, $response['id']);
        $Order->setPaymentData(BasePayment::ATTR_PAYMILL_PAYMENT_SUCCESSFUL, true);
        Utils::saveOrder($Order);

        // Save billing agreement reference in database
        try {
            QUI::getDataBase()->insert(
                self::getSubscriptionsTable(),
                [
                    'paypal_agreement_id' => $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_BILLING_AGREEMENT_ID),
                    'paypal_plan_id'      => $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_BILLING_PLAN_ID),
                    'customer'            => json_encode($Order->getCustomer()->getAttributes()),
                    'global_process_id'   => $Order->getHash()
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }
    }

    /**
     * Get available data by Billing Agreement ID
     *
     * @param string $billingAgreementId - PayPal Billing Agreement ID
     * @return array|false
     */
    protected static function getSubscriptionData($billingAgreementId)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from'  => self::getSubscriptionsTable(),
                'where' => [
                    'paypal_agreement_id' => $billingAgreementId
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        $data = current($result);

        return [
            'globalProcessId' => $data['global_process_id'],
            'customer'        => json_decode($data['customer'], true),
        ];
    }

    /**
     * @return string
     */
    protected static function getSubscriptionsTable()
    {
        return QUI::getDBTableName(self::TBL_BILLING_AGREEMENTS);
    }
}
