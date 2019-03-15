/**
 * Paymill JavaScript API
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paymill/bin/Paymill', [

    'package/quiqqer/payment-paymill/bin/classes/Paymill'

], function (PaymillApi) {
    "use strict";
    return new PaymillApi();
});