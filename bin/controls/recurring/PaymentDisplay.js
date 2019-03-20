/**
 * PaymentDisplay for Paymill recurring payments
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paymill/bin/controls/recurring/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/loader/Loader',

    'utils/Controls',
    'package/quiqqer/payment-paymill/bin/Paymill',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paymill/bin/controls/recurring/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUILoader, QUIControlUtils, Paymill, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-paymill';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paymill/bin/controls/recurring/PaymentDisplay',

        Binds: [
            '$onImport',
            '$loadPaymillButton',
            '$showErrorMsg',
            '$showMsg',
            '$showPaymillForm',
            '$checkout',
            '$getPaymillToken',
            '$showErrorMsg',
            '$onPayBtnClick'
        ],

        options: {
            orderhash  : '',
            successful : false,
            publickey  : '',
            amount     : 0,
            currency   : '',
            displaylang: 'en'
        },

        initialize: function (options) {
            this.parent(options);

            this.$MsgElm       = null;
            this.$OrderProcess = null;
            this.$PayBtnElm    = null;
            this.$PayBtn       = null;
            this.$MsgElm       = null;
            this.Loader        = new QUILoader();

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            var self = this;
            var Elm  = this.getElm();

            if (Elm.getElement('.message-error')) {
                (function () {
                    self.fireEvent('processingError', [self]);
                }).delay(2000);
            }

            if (!Elm.getElement('.quiqqer-payment-paymill-content')) {
                return;
            }

            this.Loader.inject(Elm);

            this.$MsgElm    = Elm.getElement('.quiqqer-payment-paymill-message');
            this.$PayBtnElm = Elm.getElement('.quiqqer-payment-paymill-btn-pay');

            window.PAYMILL_PUBLIC_KEY = this.getAttribute('publickey');

            this.$showMsg(QUILocale.get(pkg, 'PaymentDisplay.info'));

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then(function (OrderProcess) {
                self.$OrderProcess = OrderProcess;

                if (self.getAttribute('successful')) {
                    OrderProcess.next();
                    return;
                }

                self.$loadPaymillWidgets();
            });
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadPaymillWidgets: function () {
            var self      = this;
            var widgetUrl = "https://bridge.paymill.com/";

            if (typeof paymill !== 'undefined') {
                this.$showPaymillForm();
                return;
            }

            this.$OrderProcess.Loader.show();

            new Element('script', {
                async: "async",
                src  : widgetUrl
            }).inject(document.body);

            var checkPaymillLoaded = setInterval(function () {
                if (typeof paymill === 'undefined') {
                    return;
                }

                clearInterval(checkPaymillLoaded);
                self.$showPaymillForm();
            }, 200);
        },

        /**
         * Show Paymill credit card form
         */
        $showPaymillForm: function () {
            var self = this;

            this.$OrderProcess.Loader.show();

            var PaymillFrameOptions = {
                lang: this.getAttribute('displaylang')
            };

            var checkoutLoaderShow = function () {
                self.$OrderProcess.Loader.show(
                    QUILocale.get(pkg, 'PaymentDisplay.checkout_process')
                );

                self.$PayBtn.disable();
                self.$PayBtn.setAttribute('textimage', 'fa fa-spin fa-spinner');
            };

            var checkoutLoaderHide = function () {
                self.$OrderProcess.Loader.hide();
                self.$PayBtn.enable();
                self.$PayBtn.setAttribute('textimage', 'fa fa-credit-card');
            };

            this.$PayBtn = new QUIButton({
                'class'  : 'btn-primary',
                disabled : true,
                text     : QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.btn_pay.text'),
                alt      : QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.btn_pay.title'),
                title    : QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.btn_pay.title'),
                textimage: 'fa fa-credit-card',
                events   : {
                    onClick: function () {
                        checkoutLoaderShow();

                        self.$getPaymillToken().then(function (token) {
                            Paymill.createSubscription(self.getAttribute('orderhash'), token).then(function () {
                                self.$OrderProcess.next();
                            }, function (Error) {
                                checkoutLoaderHide();
                                self.$showErrorMsg(Error.getMessage());
                                self.fireEvent('processingError', [self]);
                            });
                        }, function () {
                            checkoutLoaderHide();

                            (function () {
                                self.$OrderProcess.resize();
                            }).delay(200);
                        });
                    }
                }
            }).inject(this.$PayBtnElm);

            paymill.embedFrame('quiqqer-payment-paymill-frame', PaymillFrameOptions, function (Error) {
                if (Error) {
                    self.$showErrorMsg(
                        QUILocale.get(pkg,
                            'payment.error_msg.general_error'
                        )
                    );

                    self.fireEvent('processingError', [self]);
                    return;
                }

                // Load subscription data
                Paymill.getConfirmSubscriptionData(self.getAttribute('orderhash')).then(function (Data) {
                    self.$OrderProcess.Loader.hide();
                    self.$PayBtn.enable();

                    new Element('p', {
                        html: QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.recurring_info', Data)
                    }).inject(self.$PayBtnElm, 'top');

                    self.$OrderProcess.resize();
                });
            });
        },

        /**
         * Get unique PAYMILL token for the payment
         *
         * @return {Promise}
         */
        $getPaymillToken: function () {
            var self = this;

            return new Promise(function (resolve, reject) {
                paymill.createTokenViaFrame({
                    amount_int: self.getAttribute('amount'),
                    currency  : self.getAttribute('currency')
                }, function (Error, Result) {
                    if (Error) {
                        reject(Error);
                    } else {
                        resolve(Result.token);
                    }
                });
            });
        },

        /**
         * Show error msg
         *
         * @param {String} msg
         */
        $showErrorMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<p class="message-error">' + msg + '</p>'
            );
        },

        /**
         * Show normal msg
         *
         * @param {String} msg
         */
        $showMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<p>' + msg + '</p>'
            );
        }
    });
});