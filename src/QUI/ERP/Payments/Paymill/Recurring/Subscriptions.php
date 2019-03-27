<?php

namespace QUI\ERP\Payments\Paymill\Recurring;

use Paymill\Models\Response\Subscription;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\ERP\Payments\Paymill\Recurring\Payment as RecurringPayment;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Payments\Paymill\Utils;
use QUI\ERP\Payments\Paymill\Payment as BasePayment;
use QUI\Utils\Security\Orthos;
use Paymill\Models\Request\Subscription as PaymillSubscriptionRequest;
use Paymill\Models\Request\Transaction as PaymillTransactionRequest;
use QUI\ERP\Payments\Paymill\PaymillPayments;
use QUI\ERP\Plans\Utils as ErpPlansUtils;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;

/**
 * Class Subscriptions
 *
 * Handler for PayPal Subscription management
 */
class Subscriptions
{
    const TBL_SUBSCRIPTIONS             = 'paymill_subscriptions';
    const TBL_SUBSCRIPTION_TRANSACTIONS = 'paymill_subscription_transactions';

    /**
     * Runtime cache that knows then a transaction history
     * for a Subscriptios has been freshly fetched from Paymill.
     *
     * Prevents multiple unnecessary API calls.
     *
     * @var array
     */
    protected static $transactionsRefreshed = [];

    /**
     * @var QUI\ERP\Payments\Paymill\Payment
     */
    protected static $Payment = null;

    /**
     * Create a PayPal Subscription based on a Billing Plan
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
                    'quiqqer/payment-paymill',
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
        $paymentId = $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_PAYMENT_ID);

        if (empty($paymentId)) {
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
            $paymillResponse = $paymillResponse['body'];

            if (empty($paymillResponse['data']['is_recurring'])) {
                // Immediately delete the payment if it is not eligible for recurring payments
                PaymillPayments::deletePaymillPayment($paymentId);

                throw new PaymillException(
                    QUI::getLocale()->get(
                        'quiqqer/payment-paymill',
                        'exception.Recurring.Payment.createSubscription.not_recurring'
                    )
                );
            }

            $Order->addHistory(Utils::getHistoryText('order.payment_created', [
                'paymentId' => $paymentId
            ]));

            $Order->setPaymentData(RecurringPayment::ATTR_PAYMILL_PAYMENT_ID, $paymentId);
            Utils::saveOrder($Order);
        }

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
        $PaymillSubscription->setName(Utils::getTransactionDescription($Order));

        // Set period of validity for non-auto-extended subscriptions
        $planDetails = ErpPlansUtils::getPlanDetailsFromOrder($Order);

        if (empty($planDetails['auto_extend'])) {
            $durationIntervalParts = explode("-", $planDetails['duration_interval']);
            $number                = (int)$durationIntervalParts[0] - 1; // -1 because last payment is executed at end of period of validity; see API docs
            $interval              = mb_strtoupper($durationIntervalParts[1]);

            if ($number <= 0) {
                $number = 1;
            }

            $PaymillSubscription->setPeriodOfValidity($number." ".$interval);
        }

        /** @var Subscription $Subscription */
        $Subscription   = Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_CREATE, $PaymillSubscription);
        $subscriptionId = $Subscription->getId();

        $Order->addHistory(Utils::getHistoryText('order.subscription_created', [
            'subscriptionId' => $subscriptionId
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_PAYMILL_SUBSCRIPTION_ID, $subscriptionId);
        $Order->setPaymentData(BasePayment::ATTR_PAYMILL_ORDER_SUCCESSFUL, true);
        Utils::saveOrder($Order);

        // Write subscription data to db
        QUI::getDataBase()->insert(
            self::getSubscriptionsTable(),
            [
                'paymill_subscription_id' => $subscriptionId,
                'paymill_offer_id'        => $offerId,
                'paymill_payment_id'      => $paymentId,
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
     * @throws QUI\Database\Exception
     * @throws PaymillException
     * @throws \Exception
     */
    public static function billSubscriptionBalance(Invoice $Invoice)
    {
        $subscriptionId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_SUBSCRIPTION_ID);

        if (empty($subscriptionId)) {
            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paymill',
                    'exception.Recurring.Subscriptions.subscription_id_not_found',
                    [
                        'invoiceId' => $Invoice->getId()
                    ]
                ),
                404
            );
        }

        $data = self::getSubscriptionData($subscriptionId);

        if ($data === false) {
            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paymill',
                    'exception.Recurring.Subscriptions.subscription_not_found',
                    [
                        'subscriptionId' => $subscriptionId,
                        'invoiceId'      => $Invoice->getId()
                    ]
                ),
                404
            );
        }

        // Check if a Paymill transaction matches the Invoice
        $unprocessedTransactions = self::getUnprocessedTransactions($subscriptionId);
        $Invoice->calculatePayments();

        $invoiceAmount   = (float)$Invoice->getAttribute('toPay');
        $AmountValue     = new QUI\ERP\Accounting\CalculationValue($invoiceAmount, $Invoice->getCurrency(), 2);
        $invoiceAmount   = $AmountValue->get() * 100; // convert to smallest currency unit
        $invoiceCurrency = $Invoice->getCurrency()->getCode();

        $Payment = new RecurringPayment();

        foreach ($unprocessedTransactions as $transaction) {
            $amount   = (int)$transaction['amount'];
            $currency = $transaction['currency'];

            if ($currency !== $invoiceCurrency) {
                continue;
            }

            if ($amount < $invoiceAmount) {
                continue;
            }

            // Transaction amount equals Invoice amount
            try {
                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    ($amount / 100),
                    $Invoice->getCurrency(),
                    $Invoice->getHash(),
                    $Payment->getName(),
                    [],
                    null,
                    false,
                    $Invoice->getGlobalProcessId()
                );

                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBase()->update(
                    self::getSubscriptionTransactionsTable(),
                    [
                        'quiqqer_transaction_id'        => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'paymill_transaction_id' => $transaction['id']
                    ]
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.add_paymill_transaction', [
                        'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                        'paypalTransactionId'  => $transaction['id']
                    ])
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }

            break;
        }
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
     * Get details of a Subscription
     *
     * @param string $subscriptionId
     * @return array
     * @throws PaymillException
     */
    public static function getSubscriptionDetails($subscriptionId)
    {
        $Subscription = new PaymillSubscriptionRequest();
        $Subscription->setId($subscriptionId);

        try {
            /** @var Subscription $SubscriptionResponse */
            Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_GET, $Subscription);
            $paymillResponse = Utils::getLastPaymillApiResponse();
            return $paymillResponse['body']['data'];
        } catch (\Exception $Exception) {
            $paymillResponse = Utils::getLastPaymillApiResponse();
            return $paymillResponse['body']['data'][0];
        }
    }

    /**
     * Cancel a Subscription
     *
     * @param int|string $subscriptionId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PaymillException
     * @throws \QUI\Database\Exception
     */
    public static function cancelSubscription($subscriptionId, $reason = '')
    {
        $data = self::getSubscriptionData($subscriptionId);

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

        try {
            $Subscription = new PaymillSubscriptionRequest();
            $Subscription->setId($subscriptionId);

            Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_DELETE, $Subscription);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new PaymillException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paymill',
                    'exception.Recurring.cancel.error'
                )
            );
        }

        // Set status in QUIQQER database to "not active"
        QUI::getDataBase()->update(
            self::getSubscriptionsTable(),
            [
                'active' => 0
            ],
            [
                'paymill_subscription_id' => $subscriptionId
            ]
        );
    }

    /**
     * Get transaction list for a Subscription
     *
     * @param string $paymentId - Paymill Payment ID
     * @param \DateTime $Start (optional)
     * @param \DateTime $End (optional)
     * @return array
     * @throws PaymillException
     * @throws \Exception
     */
    public static function getSubscriptionTransactions(
        $paymentId,
        \DateTime $Start = null,
        \DateTime $End = null
    ) {
        if (is_null($Start)) {
            $Start = new \DateTime(date('Y-m').'-01 00:00:00');
        }

        if (is_null($End)) {
            $End = clone $Start;
            $End->add(new \DateInterval('P1M')); // Start + 1 month as default time period
        }

        $data['start_date'] = $Start->format('Y-m-d');
        $data['end_date']   = $End->format('Y-m-d');

        $PaymillTransaction = new PaymillTransactionRequest();

        $PaymillTransaction->setFilter([
            'created_at' => $Start->getTimestamp().'-'.$End->getTimestamp(),
            'payment'    => $paymentId
        ]);

        return Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_LIST, $PaymillTransaction);
    }

    /**
     * Process all unpaid Invoices of Subscriptions
     *
     * @return void
     */
    public static function processUnpaidInvoices()
    {
        $Invoices = InvoiceHandler::getInstance();

        // Determine payment type IDs
        $payments = Payments::getInstance()->getPayments([
            'select' => ['id'],
            'where'  => [
                'payment_type' => RecurringPayment::class
            ]
        ]);

        $paymentTypeIds = [];

        /** @var QUI\ERP\Accounting\Payments\Types\Payment $Payment */
        foreach ($payments as $Payment) {
            $paymentTypeIds[] = $Payment->getId();
        }

        if (empty($paymentTypeIds)) {
            return;
        }

        // Get all unpaid Invoices
        $result = $Invoices->search([
            'select' => ['id', 'global_process_id'],
            'where'  => [
                'paid_status'    => 0,
                'payment_method' => [
                    'type'  => 'IN',
                    'value' => $paymentTypeIds
                ]
            ]
        ]);

        $invoiceIds = [];

        foreach ($result as $row) {
            $globalProcessId = $row['global_process_id'];

            if (!isset($invoiceIds[$globalProcessId])) {
                $invoiceIds[$globalProcessId] = [];
            }

            $invoiceIds[$globalProcessId][] = $row['id'];
        }

        if (empty($invoiceIds)) {
            return;
        }

        // Determine relevant Subscriptions
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['global_process_id'],
                'from'   => self::getSubscriptionsTable(),
                'where'  => [
                    'global_process_id' => [
                        'type'  => 'IN',
                        'value' => array_keys($invoiceIds)
                    ]
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        // Refresh Billing Agreement transactions
        foreach ($result as $row) {
            // Handle invoices
            foreach ($invoiceIds as $globalProcessId => $invoices) {
                if ($row['global_process_id'] !== $globalProcessId) {
                    continue;
                }

                foreach ($invoices as $invoiceId) {
                    try {
                        $Invoice = $Invoices->get($invoiceId);

                        // First: Process all failed transactions for Invoice
                        self::processDeniedTransactions($Invoice);

                        // Second: Process all completed transactions for Invoice
                        self::billSubscriptionBalance($Invoice);
                    } catch (\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }
    }

    /**
     * Processes all denied Paymill transactions for an Invoice and create corresponding ERP Transactions
     *
     * @param Invoice $Invoice
     * @return void
     */
    public static function processDeniedTransactions(Invoice $Invoice)
    {
        $subscriptionId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_SUBSCRIPTION_ID);

        if (empty($subscriptionId)) {
            return;
        }

        $data = self::getSubscriptionData($subscriptionId);

        if (empty($data)) {
            return;
        }

        // Get all "failed" Paymill transactions
        try {
            $unprocessedTransactions = self::getUnprocessedTransactions($subscriptionId, 'failed');
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        try {
            $Invoice->calculatePayments();

            $invoiceAmount   = (float)$Invoice->getAttribute('toPay');
            $AmountValue     = new QUI\ERP\Accounting\CalculationValue($invoiceAmount, $Invoice->getCurrency(), 2);
            $invoiceAmount   = $AmountValue->get() * 100; // convert to smallest currency unit
            $invoiceCurrency = $Invoice->getCurrency()->getCode();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        $Payment = new RecurringPayment();

        foreach ($unprocessedTransactions as $transaction) {
            $amount   = (int)$transaction['amount'];
            $currency = $transaction['currency'];

            if ($currency !== $invoiceCurrency) {
                continue;
            }

            if ($amount < $invoiceAmount) {
                continue;
            }

            // Transaction amount equals Invoice amount
            try {
                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    $amount,
                    $Invoice->getCurrency(),
                    $Invoice->getHash(),
                    $Payment->getName(),
                    [],
                    null,
                    false,
                    $Invoice->getGlobalProcessId()
                );

                $InvoiceTransaction->changeStatus(TransactionHandler::STATUS_ERROR);

                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBase()->update(
                    self::getSubscriptionTransactionsTable(),
                    [
                        'quiqqer_transaction_id'        => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'paymill_transaction_id' => $transaction['id']
                    ]
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.add_paymill_transaction', [
                        'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                        'paymillTransactionId' => $transaction['id']
                    ])
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    /**
     * Refreshes transactions for Subscriptions
     *
     * @param string $subscriptionId
     * @throws PaymillException
     * @throws QUI\Database\Exception
     * @throws \Exception
     */
    protected static function refreshTransactionList($subscriptionId)
    {
        if (isset(self::$transactionsRefreshed[$subscriptionId])) {
            return;
        }

        // Get global process id
        $data            = self::getSubscriptionData($subscriptionId);
        $globalProcessId = $data['globalProcessId'];

        // Determine start date
        $result = QUI::getDataBase()->fetch([
            'select' => ['paymill_transaction_date'],
            'from'   => self::getSubscriptionTransactionsTable(),
            'where'  => [
                'paymill_subscription_id' => $subscriptionId
            ],
            'order'  => [
                'field' => 'paymill_transaction_date',
                'sort'  => 'DESC'
            ],
            'limit'  => 1
        ]);

        if (empty($result)) {
            $Start = new \DateTime(date('Y').'-01-01 00:00:00'); // Beginning of current year
            $End   = new \DateTime();
            $End->add(new \DateInterval('P1M')); // add 1 month
        } else {
            $Start = new \DateTime($result[0]['paymill_transaction_date']);
            $End   = null;
        }

        // Determine existing transactions
        $result = QUI::getDataBase()->fetch([
            'select' => ['paymill_transaction_id', 'paymill_transaction_date'],
            'from'   => self::getSubscriptionTransactionsTable(),
            'where'  => [
                'paymill_subscription_id' => $subscriptionId
            ]
        ]);

        $existing = [];

        foreach ($result as $row) {
            $idHash            = md5($row['paymill_transaction_id'].$row['paymill_transaction_date']);
            $existing[$idHash] = true;
        }

        // Parse NEW transactions
        $transactions = self::getSubscriptionTransactions($data['paymentId'], $Start, $End);

        foreach ($transactions as $transaction) {
            if (!isset($transaction['amount'])) {
                continue;
            }

            // Only collect transactions with status "closed" or "failed"
            if ($transaction['status'] !== 'closed' && $transaction['status'] !== 'failed') {
                continue;
            }

            $TransactionTime = new \DateTime('@'.$transaction['created_at']);
            $transactionTime = $TransactionTime->format('Y-m-d H:i:s');

            $idHash = md5($transaction['id'].$transactionTime);

            if (isset($existing[$idHash])) {
                continue;
            }

            QUI::getDataBase()->insert(
                self::getSubscriptionTransactionsTable(),
                [
                    'paymill_transaction_id'   => $transaction['id'],
                    'paymill_subscription_id'  => $subscriptionId,
                    'paymill_transaction_data' => json_encode($transaction),
                    'paymill_transaction_date' => $transactionTime,
                    'global_process_id'        => $globalProcessId
                ]
            );
        }

        self::$transactionsRefreshed[$subscriptionId] = true;
    }

    /**
     * Get all completed Subscription transactions that are unprocessed by QUIQQER ERP
     *
     * @param string $subscriptionId
     * @param string $status (optional) - Get transactions with this status [default: "closed"]
     * @return array
     * @throws QUI\Database\Exception
     * @throws PaymillException
     * @throws \Exception
     */
    protected static function getUnprocessedTransactions($subscriptionId, $status = 'closed')
    {
        $result = QUI::getDataBase()->fetch([
            'select' => ['paymill_transaction_data'],
            'from'   => self::getSubscriptionTransactionsTable(),
            'where'  => [
                'paymill_subscription_id' => $subscriptionId,
                'quiqqer_transaction_id'  => null
            ]
        ]);

        // Try to refresh list if no unprocessed transactions found
        if (empty($result)) {
            self::refreshTransactionList($subscriptionId);

            $result = QUI::getDataBase()->fetch([
                'select' => ['paymill_transaction_data'],
                'from'   => self::getSubscriptionTransactionsTable(),
                'where'  => [
                    'paymill_subscription_id' => $subscriptionId,
                    'quiqqer_transaction_id'  => null
                ]
            ]);
        }

        $transactions = [];

        foreach ($result as $row) {
            $t = json_decode($row['paymill_transaction_data'], true);

            if ($t['status'] !== $status) {
                continue;
            }

            $transactions[] = $t;
        }

        return $transactions;
    }

    /**
     * Get available data by Subscription ID
     *
     * @param string $subscriptionId - PayPal Subscription ID
     * @return array|false
     */
    protected static function getSubscriptionData($subscriptionId)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from'  => self::getSubscriptionsTable(),
                'where' => [
                    'paymill_subscription_id' => $subscriptionId
                ],
                'limit' => 1
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
            'paymentId'       => $data['paymill_payment_id'],
            'globalProcessId' => $data['global_process_id'],
            'customer'        => json_decode($data['customer'], true),
        ];
    }

    /**
     * @return string
     */
    protected static function getSubscriptionsTable()
    {
        return QUI::getDBTableName(self::TBL_SUBSCRIPTIONS);
    }

    /**
     * @return string
     */
    protected static function getSubscriptionTransactionsTable()
    {
        return QUI::getDBTableName(self::TBL_SUBSCRIPTION_TRANSACTIONS);
    }
}
