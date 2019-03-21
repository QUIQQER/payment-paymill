<?php

use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\ERP\Payments\Paymill\Recurring\Subscriptions;

/**
 * Get Paymill subscription data
 *
 * @param string $subscriptionId - Paymill Subscription ID
 * @return array - PayPal Order/Payment ID and Order hash
 * @throws PaymillException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paymill_ajax_recurring_getSubscription',
    function ($subscriptionId) {
        return Subscriptions::getSubscriptionDetails($subscriptionId);
    },
    ['subscriptionId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paymill.subscriptions.view']
);
