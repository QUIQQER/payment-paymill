<?php

namespace QUI\ERP\Payments\Paymill\Recurring;

use Paymill\Models\Response\Offer;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Paymill\Recurring\Payment as RecurringPayment;
use QUI\ERP\Plans\Utils as ErpPlansUtils;
use QUI\ERP\Products\Handler\Products as ProductsHandler;
use Paymill\Models\Request\Offer as PaymillOfferRequest;
use QUI\ERP\Payments\Paymill\PaymillException;
use QUI\ERP\Payments\Paymill\Payment as BasePayment;
use QUI\ERP\Payments\Paymill\Utils;

/**
 * Class Offers
 *
 * Handler for Paymill Offers
 *
 * An "Offer" represents a subscription plan that subscriptions subscribe to
 */
class Offers
{
    /**
     * Paymill tables
     */
    const TBL_OFFERS = 'paymill_offers';

    /**
     * @var QUI\ERP\Payments\Paymill\Payment
     */
    protected static $Payment = null;

    /**
     * Create a Paymill Offer based on an Order
     *
     * @param AbstractOrder $Order
     * @return string - Paymill Offer ID
     * @throws QUI\ERP\Exception
     * @throws QUI\ERP\Payments\Paymill\PaymillException
     * @throws QUI\Exception
     */
    public static function createOfferFromOrder(AbstractOrder $Order)
    {
        if ($Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_OFFER_ID)) {
            return $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYMILL_OFFER_ID);
        }

        $offerId = self::getOfferIdByOrder($Order);

        if ($offerId !== false) {
            return $offerId;
        }

        if (!ErpPlansUtils::isPlanOrder($Order)) {
            throw new QUI\ERP\Accounting\Payments\Exception(
                'Order #'.$Order->getHash().' contains no plan products.'
            );
        }

        // Create new Offer
        $PlanProduct = false;

        /** @var QUI\ERP\Accounting\Article $Article */
        foreach ($Order->getArticles() as $Article) {
            if ($PlanProduct === false && ErpPlansUtils::isPlanArticle($Article)) {
                $PlanProduct = ProductsHandler::getProduct($Article->getId());
                break;
            }
        }

        // Read name and description from PlanProduct (= Product that contains subscription plan information)
        $Locale = $Order->getCustomer()->getLocale();

        $name = $PlanProduct->getTitle($Locale);

        $PaymillOffer = new PaymillOfferRequest();

        // Set name
        $PaymillOffer->setName($name);

        // Set amount and currency
        $totalSum    = $Order->getPriceCalculation()->getSum()->get();
        $AmountValue = new QUI\ERP\Accounting\CalculationValue($totalSum, $Order->getCurrency(), 2);
        $offerAmount = $AmountValue->get() * 100; // convert to smallest currency unit

        $PaymillOffer->setAmount($offerAmount);
        $PaymillOffer->setCurrency($Order->getCurrency()->getCode());

        // Set invoice interval
        $planDetails          = ErpPlansUtils::getPlanDetailsFromProduct($PlanProduct);
        $invoiceIntervalParts = explode('-', $planDetails['invoice_interval']);
        $number               = $invoiceIntervalParts[0];
        $interval             = mb_strtoupper($invoiceIntervalParts[1]);

        $PaymillOffer->setInterval("$number $interval");

        // Set trial period
        // @todo Trial periods are not supported yet

        /** @var Offer $NewOffer */
        $NewOffer = Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_CREATE, $PaymillOffer);
        $offerId  = $NewOffer->getId();

        // Save reference in database
        QUI::getDataBase()->insert(
            self::getOffersTable(),
            [
                'paymill_id'          => $offerId,
                'identification_hash' => self::getIdentificationHash($Order)
            ]
        );

        return $offerId;
    }

    public static function updateBillingPlan(AbstractOrder $Order)
    {
        // todo
    }

    /**
     * Delete a Paymill offer
     *
     * @param string $offerId
     * @param bool $deleteSubscriptions (optional) - Also delete all subscriptions of this Offer [default: false]
     * @return void
     * @throws PaymillException
     */
    public static function deleteOffer($offerId, $deleteSubscriptions = false)
    {
        $PaymillOffer = new PaymillOfferRequest();
        $PaymillOffer->setId($offerId);
        $PaymillOffer->setRemoveWithSubscriptions($deleteSubscriptions);

        Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_DELETE, $PaymillOffer);
    }

    /**
     * Get list of all Paymill Offers
     *
     * @param int $page (optional) - Start page of list [min: 0]
     * @param int $pageSize (optional) - Number of plans per page [range: 1 to 20]
     * @return array
     * @throws PaymillException
     */
    public static function getOfferList($page = 0, $pageSize = 10)
    {
        if ($page < 0) {
            $page = 0;
        }

        if ($pageSize < 1) {
            $pageSize = 1;
        }

        $PaymillOffer = new PaymillOfferRequest();
        $PaymillOffer->setFilter([
            'count'  => $pageSize,
            'offset' => $page * $pageSize
        ]);

        return Utils::paymillApiRequest(BasePayment::PAYMILLL_REQUEST_TYPE_LIST, $PaymillOffer);
    }

    /**
     * Get Paymill Offer ID based on the articles of an order has already been created
     *
     * @param AbstractOrder $Order
     * @return string|false - ID or false if no Billing Plan exists
     */
    protected static function getOfferIdByOrder(AbstractOrder $Order)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paymill_id'],
                'from'   => self::getOffersTable(),
                'where'  => [
                    'identification_hash' => self::getIdentificationHash($Order)
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        return $result[0]['paymill_id'];
    }

    /**
     * Get identification hash for a Billing Plan
     *
     * @param AbstractOrder $Order
     * @return string
     * @throws QUI\Exception
     */
    protected static function getIdentificationHash(AbstractOrder $Order)
    {
        $productIds = [];

        /** @var QUI\ERP\Accounting\Article $Article */
        foreach ($Order->getArticles() as $Article) {
            $productIds[] = (int)$Article->getId();
        }

        // sort IDs ASC
        sort($productIds);

        $lang     = $Order->getCustomer()->getLang();
        $totalSum = $Order->getPriceCalculation()->getSum()->get();

        return hash('sha256', $lang.$totalSum.implode(',', $productIds));
    }

    /**
     * @return string
     */
    public static function getOffersTable()
    {
        return QUI::getDBTableName(self::TBL_OFFERS);
    }
}
