<?php

/**
 * This file contains QUI\ERP\Payments\Paymill\Provider
 */

namespace QUI\ERP\Payments\Paymill;

use QUI;
use QUI\ERP\Accounting\Payments\Api\AbstractPaymentProvider;

/**
 * Class Provider
 *
 * PaymentProvider class for Paymill
 */
class Provider extends AbstractPaymentProvider
{
    /**
     * @return array
     */
    public function getPaymentTypes()
    {
        return [
            Payment::class
        ];
    }

    /**
     * Get API setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getApiSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paymill')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('api', $setting);
    }

    /**
     * Get Payment setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getPaymentSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paymill')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('payment', $setting);
    }

    /**
     * Get Widgets setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getWidgetsSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paymill')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('widgets', $setting);
    }

    /**
     * Check if the Paymill API settings are correct
     *
     * @return bool
     */
    public static function isApiSetUp()
    {
        try {
            $Conf        = QUI::getPackage('quiqqer/payment-paymill')->getConfig();
            $apiSettings = $Conf->getSection('api');
        } catch (QUI\Exception $Exception) {
            return false;
        }

        foreach ($apiSettings as $k => $v) {
            if (empty($v)) {
                QUI\System\Log::addError(
                    'Your Paymill API credentials seem to be (partially) missing.'
                    .' Paymill CAN NOT be used at the moment. Please enter all your'
                    .' API credentials. See https://dev.quiqqer.com/quiqqer/payment-paymill/wikis/api-configuration'
                    .' for further instructions.'
                );

                return false;
            }
        }

        return true;
    }
}
