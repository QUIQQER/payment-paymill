<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\Utils\Security\Orthos;

/**
 * Execute PayPal payment for an Order
 *
 * @param string $orderHash - Unique order hash to identify Order
 * @param string $paymillToken - Unique PAYMILL payment token for this Order
 * @return bool - success
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paymill_ajax_checkout',
    function ($orderHash, $paymillToken) {
        $orderHash = Orthos::clear($orderHash);

        \QUI\System\Log::writeRecursive($orderHash);
        \QUI\System\Log::writeRecursive($paymillToken);

//        try {
//            $Order = Handler::getInstance()->getOrderByHash($orderHash);
//
//            if ($express) {
//                $Payment = new PaymentExpress();
//            } else {
//                $Payment = new Payment();
//            }
//
//            $Payment->executePayPalOrder($Order, $paymentId, $payerId);
//
//            /*
//             * Authorization and capturing are only executed
//             * if the user finalizes the Order by clicking
//             * "Pay now" in the QUIQQER ERP Shop (not the PayPal popup)
//             *
//             * With Express checkout this step has not been completed yet here
//             * so these operations are only executed here if it is a
//             * normal PayPal checkout
//             */
//            if (!$express) {
//                $Payment->authorizePayPalOrder($Order);
//                $Payment->capturePayPalOrder($Order);
//            }
//        } catch (PayPalException $Exception) {
//            throw $Exception;
//        } catch (\Exception $Exception) {
//            QUI\System\Log::writeException($Exception);
//            return false;
//        }

        return true;
    },
    ['orderHash', 'paymillToken']
);
