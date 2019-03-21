<?php

namespace QUI\ERP\Payments\Paymill;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use Paymill\Models\Request\Base as PaymillBaseRequest;
use Paymill\Models\Response\Base as PaymillBaseResponse;

/**
 * Class Utils
 *
 * Utility methods for quiqqer/payment-paypal
 */
class Utils
{
    /** @var Payment */
    protected static $Payment = null;

    /**
     * Get base URL (with host) for current Project
     *
     * @return string
     */
    public static function getProjectUrl()
    {
        try {
            $url = QUI::getRewrite()->getProject()->get(1)->getUrlRewrittenWithHost();
            return rtrim($url, '/');
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            return '';
        }
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     */
    public static function saveOrder(AbstractOrder $Order)
    {
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Get translated history text
     *
     * @param string $context
     * @param array $data (optional) - Additional data for translation
     * @return string
     */
    public static function getHistoryText(string $context, $data = [])
    {
        return QUI::getLocale()->get('quiqqer/payment-paymill', 'history.'.$context, $data);
    }

    /**
     * Make a PAYMILL REST API request
     *
     * @param string $requestType - Request type (see self::PAYMILL_REQUEST_TYPE_*)
     * @param PaymillBaseRequest $RequestData - Request type with filled data
     * @return PaymillBaseResponse|array
     *
     * @throws PaymillException
     */
    public static function paymillApiRequest($requestType, $RequestData)
    {
        if (is_null(self::$Payment)) {
            self::$Payment = new QUI\ERP\Payments\Paymill\Payment();
        }

        return self::$Payment->paymillApiRequest($requestType, $RequestData);
    }

    /**
     * Returns the last response data from the Paymill API as an array
     *
     * @return array
     */
    public static function getLastPaymillApiResponse()
    {
        if (is_null(self::$Payment)) {
            self::$Payment = new QUI\ERP\Payments\Paymill\Payment();
        }

        return self::$Payment->getLastPaymillApiResponse();
    }

    /**
     * Get transaction description for an Order
     *
     * This description can be seen in the merchant account at PAYMILL
     * and the credit card statement of the buyer for an order
     *
     * @return string
     * @throws QUI\Exception
     */
    public static function getTransactionDescription(AbstractOrder $Order)
    {
        $Conf        = QUI::getPackage('quiqqer/payment-paymill')->getConfig();
        $description = $Conf->get('payment', 'paymill_transaction_description');

        if (empty($description)) {
            $description = [];
        } else {
            $description = json_decode($description, true);
        }

        $lang            = $Order->getCustomer()->getLang();
        $descriptionText = '';

        if (!empty($description[$lang])) {
            $descriptionText = str_replace(['{orderId}'], [$Order->getPrefixedId()], $description[$lang]);
        }

        if (empty($descriptionText)) {
            $L = new QUI\Locale();
            $L->setCurrent($lang);

            $descriptionText = $L->get(
                'quiqqer/payment-paymill',
                'Payment.default_transaction_description',
                [
                    'url'     => QUI::conf('globals', 'host'),
                    'orderId' => $Order->getPrefixedId()
                ]
            );
        }

        return $descriptionText;
    }
}
