<?php

use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\Utils\Security\Orthos;
use QUI\Utils\Grid;
use QUI\ERP\Payments\Paymill\Recurring\Subscriptions;

/**
 * Get list of Paymill Subscriptions
 *
 * @param array $searchParams - GRID search params
 * @return array - Subscriptions list
 * @throws PaymillException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paymill_ajax_recurring_getSubscriptionList',
    function ($searchParams) {
        $searchParams = Orthos::clearArray(json_decode($searchParams, true));

        $Grid = new Grid($searchParams);

        return $Grid->parseResult(
            Subscriptions::getSubscriptionList($searchParams),
            Subscriptions::getSubscriptionList($searchParams, true)
        );
    },
    ['searchParams'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paymill.subscriptions.view']
);
