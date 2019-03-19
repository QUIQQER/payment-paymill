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
        },

        /**
         * Get display data for Paymill Subscription confirmation in PaymentDisplay
         *
         * @param {String} orderHash - Order hash
         * @return {Promise}
         */
        getConfirmSubscriptionData: function (orderHash) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paymill_ajax_recurring_getConfirmSubscriptionData', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    onError  : reject
                })
            });
        },

        /**
         * Get Paymill Subscriptions
         *
         * @param {Object} SearchParams - Grid search params
         * @return {Promise}
         */
        getSubscriptions: function (SearchParams) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paymill_ajax_recurring_getSubscriptionList', resolve, {
                    'package'   : pkg,
                    searchParams: JSON.encode(SearchParams),
                    onError     : reject
                })
            });
        },

        /**
         * Get Paymill Subscription
         *
         * @param {string} subscriptionId
         * @return {Promise}
         */
        getSubscription: function (subscriptionId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paymill_ajax_recurring_getSubscription', resolve, {
                    'package'     : pkg,
                    subscriptionId: subscriptionId,
                    onError       : reject
                })
            });
        },

        /**
         * Cancel a Paymill Subscription
         *
         * @param {string} subscriptionId
         * @return {Promise}
         */
        cancelSubscription: function (subscriptionId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paymill_ajax_recurring_cancelSubscription', resolve, {
                    'package'     : pkg,
                    subscriptionId: subscriptionId,
                    onError       : reject
                })
            });
        }
    });
});