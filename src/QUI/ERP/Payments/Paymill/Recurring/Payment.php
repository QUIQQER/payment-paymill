<?php

namespace QUI\ERP\Payments\Paymill\Recurring;

use QUI;
use QUI\ERP\Payments\Paymill\Payment as BasePayment;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\ERP\Accounting\Payments\Types\RecurringPaymentInterface;
use QUI\ERP\Accounting\Invoice\Invoice;

/**
 * Class Payment
 *
 * Main payment provider for Paymill billing
 */
class Payment extends BasePayment implements RecurringPaymentInterface
{
    /**
     * Paymill Order attribute for recurring payments
     */
    const ATTR_PAYMILL_OFFER_ID        = 'paymill-OfferId';
    const ATTR_PAYMILL_PAYMENT_ID      = 'paymill-PaymentId';
    const ATTR_PAYMILL_SUBSCRIPTION_ID = 'paymill-SubscriptionId';

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getLocale()->get('quiqqer/payment-paymill', 'payment.recurring.title');
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getLocale()->get('quiqqer/payment-paymill', 'payment.recurring.description');
    }

    /**
     * Does the payment ONLY support recurring payments (e.g. for subscriptions)?
     *
     * @return bool
     */
    public function supportsRecurringPaymentsOnly()
    {
        return true;
    }

    /**
     * Create a Paymill Subscription based on an Offer
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws QUI\ERP\Payments\Paymill\PaymillException
     * @throws QUI\ERP\Payments\Paymill\PaymillApiException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     * @throws \Exception
     */
    public function createSubscription(AbstractOrder $Order)
    {
        Subscriptions::createSubscription($Order);
    }

    /**
     * Bills the balance for an agreement based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     * @throws PaymillException
     * @throws \Exception
     */
    public function captureSubscription(Invoice $Invoice)
    {
        Subscriptions::billSubscriptionBalance($Invoice);
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

        $Step->setTitle(
            QUI::getLocale()->get(
                'quiqqer/payment-paymill',
                'payment.step.title'
            )
        );

        $Engine = QUI::getTemplateManager()->getEngine();
        $Step->setContent($Engine->fetch(dirname(__FILE__, 2).'/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Can the Subscription of this payment method be edited
     * regarding essential data like invoice frequency, amount etc.?
     *
     * @return bool
     */
    public function isSubscriptionEditable()
    {
        return false;
    }

    /**
     * Check if a Subscription is associated with an order and
     * return its ID (= identification at the payment method side; e.g. Paymill)
     *
     * @param AbstractOrder $Order
     * @return int|string|false - ID or false of no ID associated
     */
    public function getSubscriptionIdByOrder(AbstractOrder $Order)
    {
        return $Order->getPaymentDataEntry(self::ATTR_PAYMILL_SUBSCRIPTION_ID);
    }

    /**
     * Cancel a Subscription
     *
     * @param int|string $subscriptionId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PaymillException
     * @throws \Exception
     */
    public function cancelSubscription($subscriptionId, $reason = '')
    {
        Subscriptions::cancelSubscription($subscriptionId, $reason);
    }

    /**
     * Sets a subscription as inactive (on the side of this QUIQQER system only!)
     *
     * IMPORTANT: This does NOT mean that the corresponding subscription at the payment provider
     * side is cancelled. If you want to do this please use cancelSubscription() !
     *
     * @param $subscriptionId
     * @return void
     */
    public function setSubscriptionAsInactive($subscriptionId)
    {
        Subscriptions::setSubscriptionAsInactive($subscriptionId);
    }

    /**
     * Return the extra text for the invoice
     *
     * @param QUI\ERP\Accounting\Invoice\Invoice|QUI\ERP\Accounting\Invoice\InvoiceTemporary|QUI\ERP\Accounting\Invoice\InvoiceView $Invoice
     * @return mixed
     */
    public function getInvoiceInformationText($Invoice)
    {
        try {
            return $Invoice->getCustomer()->getLocale()->get(
                'quiqqer/payment-paymill',
                'recurring.additional_invoice_text'
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return '';
        }
    }

    /**
     * Checks if the subscription is active at the payment provider side
     *
     * @param string|int $subscriptionId
     * @return bool
     */
    public function isSubscriptionActiveAtPaymentProvider($subscriptionId)
    {
        try {
            $data = Subscriptions::getSubscriptionDetails($subscriptionId);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return true;
        }

        if (!empty($data['status']) && $data['status'] === 'active') {
            return true;
        }

        return false;
    }

    /**
     * Get IDs of all subscriptions
     *
     * @param bool $includeInactive (optional) - Include inactive subscriptions [default: false]
     * @return int[]
     */
    public function getSubscriptionIds($includeInactive = false)
    {
        $where = [];

        if (empty($includeInactive)) {
            $where['active'] = 1;
        }

        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paymill_subscription_id'],
                'from'   => Subscriptions::getSubscriptionsTable(),
                'where'  => $where
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }

        return \array_column($result, 'paymill_subscription_id');
    }

    /**
     * Get global processing ID of a subscription
     *
     * @param string|int $subscriptionId
     * @return string|false
     */
    public function getSubscriptionGlobalProcessingId($subscriptionId)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['global_process_id'],
                'from'   => Subscriptions::getSubscriptionsTable(),
                'where'  => [
                    'paymill_subscription_id' => $subscriptionId
                ],
                'limit'  => 1
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        return $result[0]['global_process_id'];
    }
}
