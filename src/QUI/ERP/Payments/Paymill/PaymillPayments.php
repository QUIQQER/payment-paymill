<?php

namespace QUI\ERP\Payments\Paymill;

use QUI;
use Paymill\Models\Request\Payment as PaymillPaymentRequest;
use Paymill\Models\Response\Payment as PaymillPaymentResponse;

/**
 * Class PaymillPayments
 *
 * Manage Paymill Payments
 *
 * @see https://developers.paymill.com/API/index#payments
 */
class PaymillPayments
{
    /**
     * Create a new Paymill Payment
     *
     * @param string $paymillToken - Unique PAYMILL Token for an authorized transaction/payment
     *
     * @return PaymillPaymentResponse - Paymill Payment
     * @throws PaymillException
     */
    public static function createPaymillPayment($paymillToken)
    {
        $PaymillPayment = new PaymillPaymentRequest();
        $PaymillPayment->setToken($paymillToken);

        /** @var PaymillPaymentResponse $Payment */
        $Payment = Utils::paymillApiRequest(Payment::PAYMILLL_REQUEST_TYPE_CREATE, $PaymillPayment);

        return $Payment;
    }

    /**
     * Delete a Paymill Payment
     *
     * @param string $paymentId
     *
     * @return void
     * @throws PaymillException
     */
    public static function deletePaymillPayment($paymentId)
    {
        $PaymillPayment = new PaymillPaymentRequest();
        $PaymillPayment->setId($paymentId);

        /** @var \Paymill\Models\Response\Payment $Payment */
        Utils::paymillApiRequest(Payment::PAYMILLL_REQUEST_TYPE_DELETE, $PaymillPayment);
    }
}
