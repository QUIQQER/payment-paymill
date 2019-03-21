<?php

namespace QUI\ERP\Payments\Paymill\Recurring;

use function GuzzleHttp\Promise\queue;
use QUI;
use QUI\ERP\Payments\Paymill\Provider;

/**
 * Class PaymentDisplay
 *
 * Display Paymill recurring payment process
 */
class PaymentDisplay extends QUI\Control
{
    /**
     * Constructor
     *
     * @param array $attributes
     * @throws QUI\ERP\Order\ProcessingException
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setJavaScriptControl('package/quiqqer/payment-paymill/bin/controls/recurring/PaymentDisplay');

//        if (Provider::isApiSetUp() === false) {
//            throw new QUI\ERP\Order\ProcessingException([
//                'quiqqer/payment-paymill',
//                'exception.message.missing.setup'
//            ]);
//        }
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
        $Order = $this->getAttribute('Order');
        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        $Engine->assign([
            'apiSetUp' => Provider::isApiSetUp()
        ]);

        $PriceCalculation = $Order->getPriceCalculation();
        $amount           = $PriceCalculation->getSum()->precision(2)->get() * 100;
        $this->setJavaScriptControlOption('amount', $amount);
        $this->setJavaScriptControlOption('currency', $Order->getCurrency()->getCode());

        $this->setJavaScriptControlOption('orderhash', $Order->getHash());
        $this->setJavaScriptControlOption('publickey', Provider::getApiPublicKey());
        $this->setJavaScriptControlOption('currency', $Order->getCurrency()->getCode());
        $this->setJavaScriptControlOption('displaylang', QUI::getLocale()->getCurrent());

        return $Engine->fetch(dirname(__FILE__).'/PaymentDisplay.html');
    }
}
