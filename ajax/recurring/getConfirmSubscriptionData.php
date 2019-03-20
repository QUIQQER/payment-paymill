<?php

use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\Utils\Security\Orthos;
use QUI\ERP\Order\Handler;
use QUI\ERP\Plans\Utils as ErpPlanUtils;

/**
 * Get Paymill subscription data
 *
 * @param string $subscriptionId - Paymill Subscription ID
 * @return array - PayPal Order/Payment ID and Order hash
 * @throws PaymillException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paymill_ajax_recurring_getConfirmSubscriptionData',
    function ($orderHash) {
        try {
            $orderHash = Orthos::clear($orderHash);
            $Order     = Handler::getInstance()->getOrderByHash($orderHash);

            $planDetails     = ErpPlanUtils::getPlanDetailsFromOrder($Order);
            $InvoiceInterval = ErpPlanUtils::parseIntervalFromDuration($planDetails['invoice_interval']);

            return [
                'sum'      => $Order->getPriceCalculation()->getSum()->formatted(),
                'interval' => ErpPlanUtils::intervalToIntervalText($InvoiceInterval)
            ];
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
    },
    ['orderHash']
);
