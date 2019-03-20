<?php

use QUI\ERP\Payments\Paymill\Recurring\Offers;
use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\Utils\Security\Orthos;
use QUI\Utils\Grid;

/**
 * Get list of Paymill Offers
 *
 * @param array $searchParams - Grid search params
 * @return array - PayPal Order/Payment ID and Order hash
 * @throws PaymillException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paymill_ajax_recurring_getOffers',
    function ($searchParams) {
        $searchParams = Orthos::clearArray(json_decode($searchParams, true));

        $page    = 0;
        $perPage = null;

        if (!empty($searchParams['page'])) {
            $page = (int)$searchParams['page'] - 1;
        }

        if (!empty($searchParams['perPage'])) {
            $perPage = (int)$searchParams['perPage'];
        }

        $list = Offers::getOfferList($page, $perPage);

        $offers = [];
        $count  = 0;

        if (!empty($list)) {
            $offers = $list;
            $count  = count($list);
        }

        foreach ($offers as $k => $offer) {
            $offers[$k]['created_at'] = date('Y-m-d H:i:s', $offer['created_at']);
        }

        $Grid = new Grid($searchParams);

        return $Grid->parseResult($offers, $count);
    },
    ['searchParams'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paymill.offers.view']
);
