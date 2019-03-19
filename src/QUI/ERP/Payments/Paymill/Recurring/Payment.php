<?php

namespace QUI\ERP\Payments\Paymill\Recurring;

use Symfony\Component\HttpFoundation\Response;
use QUI;
use QUI\ERP\Payments\Paymill\Payment as BasePayment;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Paymill\PaymillException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use QUI\ERP\Accounting\Payments\Order\Payment as OrderProcessStepPayments;
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
     * Does the payment support recurring payments (e.g. for subscriptions)?
     *
     * @return bool
     */
    public function supportsRecurringPayments()
    {
        return true;
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
     */
    public function captureSubscription(Invoice $Invoice)
    {
        Subscriptions::billBillingAgreementBalance($Invoice);
    }

    /**
     * Execute the request from the payment provider
     *
     * @param QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway
     *
     * @throws QUI\ERP\Order\Basket\Exception
     * @throws QUI\Exception
     */
    public function executeGatewayPayment(QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway)
    {
        $Order        = $Gateway->getOrder();
        $OrderProcess = new QUI\ERP\Order\OrderProcess([
            'orderHash' => $Order->getHash()
        ]);

        $goToBasket = false;

        if ($Gateway->isSuccessRequest()) {
            if (empty($_REQUEST['token'])) {
                $goToBasket = true;
            } else {
                try {
                    Subscriptions::executeBillingAgreement($Order, $_REQUEST['token']);

                    $GoToStep = new QUI\ERP\Order\Controls\OrderProcess\Finish([
                        'Order' => $Gateway->getOrder()
                    ]);
                } catch (\Exception $Exception) {
                    $goToBasket = true;
                }
            }
        } elseif ($Gateway->isCancelRequest()) {
            $GoToStep = new OrderProcessStepPayments([
                'Order' => $Gateway->getOrder()
            ]);
        } else {
            $goToBasket = true;
        }

        if ($goToBasket) {
            $GoToStep = new QUI\ERP\Order\Controls\OrderProcess\Basket([
                'Order' => $Gateway->getOrder()
            ]);
        }

        $processingUrl = $OrderProcess->getStepUrl($GoToStep->getName());

        // Umleitung zur Bestellung
        $Redirect = new RedirectResponse($processingUrl);
        $Redirect->setStatusCode(Response::HTTP_SEE_OTHER);

        echo $Redirect->getContent();
        $Redirect->send();
        exit;
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
        return $Order->getPaymentDataEntry(self::ATTR_PAYMILL_BILLING_AGREEMENT_ID);
    }

    /**
     * Cancel a Subscription
     *
     * @param int|string $billingAgreementId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PaymillException
     */
    public function cancelSubscription($billingAgreementId, $reason = '')
    {
        Subscriptions::cancelBillingAgreement($billingAgreementId, $reason);
    }
}
