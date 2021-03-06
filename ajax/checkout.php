<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\Paymill\Payment;
use QUI\Utils\Security\Orthos;
use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\ERP\Payments\Paymill\PaymillApiException;

/**
 * Execute PAYMILL payment for an Order
 *
 * @param string $orderHash - Unique order hash to identify Order
 * @param string $paymillToken - Unique PAYMILL payment token for this Order
 * @return bool - success
 * @throws QUI\Exception
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paymill_ajax_checkout',
    function ($orderHash, $paymillToken) {
        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            $Payment = new Payment($Order);
            $Payment->checkout($paymillToken);
        } catch (PaymillApiException $Exception) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/payment-paymill',
                    'exception.ajax.checkout.paymill_error'
                )
            );
        } catch (PaymillException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/payment-paymill',
                    'exception.ajax.checkout.error'
                )
            );
        }

        return true;
    },
    ['orderHash', 'paymillToken']
);
