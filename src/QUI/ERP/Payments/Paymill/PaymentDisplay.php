<?php

/**
 * This file contains QUI\ERP\Payments\Example\PaymentDisplay
 */

namespace QUI\ERP\Payments\Paymill;

use QUI;
use QUI\ERP\Order\Handler as OrderHandler;

/**
 * Class PaymentDisplay
 *
 * Display Paymill payment process
 */
class PaymentDisplay extends QUI\Control
{
    /**
     * Constructor
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->addCSSFile(dirname(__FILE__) . '/PaymentDisplay.css');
        $this->setJavaScriptControl('package/quiqqer/payment-paymill/bin/controls/PaymentDisplay');
    }

    /**
     * Return the body of the control
     * Here you can integrate the payment form, or forwarding functionality to the gateway
     *
     * @return string
     * @throws QUI\Exception
     */
    public function getBody()
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        /* @var $Order QUI\ERP\Order\OrderInProcess */
        $Order            = $this->getAttribute('Order');
        $PriceCalculation = $Order->getPriceCalculation();

        if (Provider::isApiSetUp() === false) {
            $this->Events->fireEvent('processingError', [$this]);
        }

        $Engine->assign([
            'display_price' => $PriceCalculation->getSum()->formatted(),
            'apiSetUp'      => Provider::isApiSetUp()
        ]);

        $amount = $PriceCalculation->getSum()->precision(2)->get() * 100;

        $this->setJavaScriptControlOption('orderhash', $Order->getHash());
        $this->setJavaScriptControlOption('publickey', Provider::getApiSetting('public_key'));
        $this->setJavaScriptControlOption('amount', $amount);
        $this->setJavaScriptControlOption('currency', $Order->getCurrency()->getCode());
        $this->setJavaScriptControlOption('displaylang', QUI::getLocale()->getCurrent());

        return $Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.html');
    }
}
