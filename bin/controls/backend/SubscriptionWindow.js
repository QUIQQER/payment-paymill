/**
 * Show details of a Paymill subscription
 *
 * @module package/quiqqer/payment-paymill/bin/controls/backend/SubscriptionWindow
 * @author www.pcsg.de (Patrick Müller)
 *
 * @event onCancelSubscription [this]
 */
define('package/quiqqer/payment-paymill/bin/controls/backend/SubscriptionWindow', [

    'qui/controls/windows/Popup',
    'qui/controls/loader/Loader',
    'qui/controls/buttons/Button',

    'Locale',
    'Ajax',

    'package/quiqqer/payment-paymill/bin/Paymill',

    'css!package/quiqqer/payment-paymill/bin/controls/backend/SubscriptionWindow.css'

], function (QUIPopup, QUILoader, QUIButton, QUILocale, QUIAjax, Paymill) {
    "use strict";

    var lg = 'quiqqer/payment-paymill';

    return new Class({
        Extends: QUIPopup,
        Type   : 'package/quiqqer/payment-paymill/bin/controls/backend/SubscriptionWindow',

        Binds: [
            '$onOpen',
            '$onSubmit',
            '$confirmCancel'
        ],

        options: {
            subscriptionId: false,

            maxWidth : 900,	// {integer} [optional]max width of the window
            maxHeight: 900,	// {integer} [optional]max height of the window
            content  : false,	// {string} [optional] content of the window
            icon     : 'fa fa-paypal',
            title    : QUILocale.get(lg, 'controls.backend.SubscriptionWindow.title'),

            // buttons
            buttons         : true, // {bool} [optional] show the bottom button line
            closeButton     : true, // {bool} show the close button
            titleCloseButton: true  // {bool} show the title close button
        },

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onOpen: this.$onOpen
            });
        },

        /**
         * Event: onOpen
         *
         * Build content
         */
        $onOpen: function () {
            var self = this,
                CancelBtn;

            this.getElm().addClass('quiqqer-payment-paymill-backend-billingagreementwindow');

            this.Loader.show();

            Paymill.getSubscription(this.getAttribute('subscriptionId')).then(function (Subscription) {
                self.Loader.hide();
                self.setContent('<pre>' + JSON.stringify(Subscription, null, 2) + '</pre>');

                if (Subscription.status === 'active') {
                    CancelBtn.enable();
                }
            }, function () {
                self.close();
            });

            CancelBtn = new QUIButton({
                text     : QUILocale.get(lg, 'controls.backend.SubscriptionWindow.btn.cancel'),
                textimage: 'fa fa-ban',
                disabled : true,
                events   : {
                    onClick: this.$confirmCancel
                }
            });

            this.addButton(CancelBtn);
        },

        /**
         * Confirm Subscription cancellation
         */
        $confirmCancel: function () {
            var self = this;

            new QUIConfirm({
                maxHeight: 300,
                maxWidth : 600,
                autoclose: false,

                information: QUILocale.get(lg, 'controls.backend.SubscriptionWindow.cancel.information'),
                title      : QUILocale.get(lg, 'controls.backend.SubscriptionWindow.cancel.title'),
                texticon   : 'fa fa-ban',
                text       : QUILocale.get(lg, 'controls.backend.SubscriptionWindow.cancel.text'),
                icon       : 'fa fa-ban',

                cancel_button: {
                    text     : false,
                    textimage: 'icon-remove fa fa-remove'
                },
                ok_button    : {
                    text     : QUILocale.get(lg, 'controls.backend.SubscriptionWindow.cancel.confirm'),
                    textimage: 'icon-ok fa fa-check'
                },

                events: {
                    onSubmit: function () {
                        Popup.Loader.show();

                        Paymill.cancelSubscription(self.getAttribute('subscriptionId')).then(function () {
                            self.close();
                            self.fireEvent('cancelSubscription', [self]);
                        }, function () {
                            Popup.Loader.hide();
                        })
                    }
                }
            }).open();
        }
    });
});
