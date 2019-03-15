/**
 * Paymill JavaScript API
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paymill/bin/classes/Paymill', [

    'Ajax'

], function (QUIAjax) {
    "use strict";

    var pkg = 'quiqqer/payment-paymill';

    return new Class({

        Type: 'package/quiqqer/payment-paymill/bin/classes/Paymill',

        /**
         * Get Paymill Offers
         *
         * @param {Object} SearchParams - Grid search params
         * @return {Promise}
         */
        getOffers: function (SearchParams) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paymill_ajax_recurring_getOffers', resolve, {
                    'package'   : pkg,
                    searchParams: JSON.encode(SearchParams),
                    onError     : reject
                })
            });
        },

        /**
         * Delete a Paymill offer
         *
         * @param {String} offerId - Paymill Offer ID
         * @return {Promise}
         */
        deleteOffer: function (offerId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paymill_ajax_recurring_deleteOffer', resolve, {
                    'package': pkg,
                    offerId  : offerId,
                    onError  : reject
                })
            });
        }
    });
});