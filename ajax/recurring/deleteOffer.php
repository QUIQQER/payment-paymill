<?php

use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\ERP\Payments\Paymill\Recurring\Offers;

/**
 * Delete a Paymill Offer
 *
 * @param string $billingPlanId
 * @return void
 * @throws PaymillException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_deleteOffer',
    function ($offerId) {
        Offers::deleteOffer($offerId);

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'message.ajax.recurring.deleteOffer.success',
                [
                    'offerId' => $offerId
                ]
            )
        );
    },
    ['offerId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paymill.offers.delete']
);
