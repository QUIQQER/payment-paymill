/**
 * PaymentDisplay for Paymill
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paymill/bin/controls/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/loader/Loader',

    'utils/Controls',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paymill/bin/controls/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUILoader, QUIControlUtils, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-paymill';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paymill/bin/controls/PaymentDisplay',

        Binds: [
            '$onImport',
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

            this.$PayBtnElm    = null;
            this.$PayBtn       = null;
            this.$MsgElm       = null;
            this.$OrderProcess = null;
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
                'class'  : 'btn-primary button',
                disabled : true,
                text     : QUILocale.get(pkg, 'PaymentDisplay.btn_pay.text', {
                    display_price: this.$PayBtnElm.get('data-price')
                }),
                alt      : QUILocale.get(pkg, 'PaymentDisplay.btn_pay.title', {
                    display_price: this.$PayBtnElm.get('data-price')
                }),
                title    : QUILocale.get(pkg, 'PaymentDisplay.btn_pay.title', {
                    display_price: this.$PayBtnElm.get('data-price')
                }),
                textimage: 'fa fa-credit-card',
                events   : {
                    onClick: function () {
                        checkoutLoaderShow();

                        self.$getPaymillToken().then(function (token) {
                            self.$checkout(token).then(function () {
                                self.$OrderProcess.next();
                            }, function (Error) {
                                checkoutLoaderHide();
                                self.$showErrorMsg(Error.getMessage());
                                self.fireEvent('processingError', [self]);
                            });
                        }, function (PaymillError) {
                            checkoutLoaderHide();

                            if (!("apierror" in PaymillError)) {
                                self.$showErrorMsg(
                                    QUILocale.get(pkg, 'PaymentDisplay.service_provider_error')
                                );

                                self.fireEvent('processingError', [self]);

                                return;
                            }

                            switch (PaymillError.apierror) {
                                case 'invalid_payment_data':
                                    self.$showErrorMsg(
                                        QUILocale.get(pkg, 'PaymentDisplay.validation_error')
                                    );

                                    self.$OrderProcess.resize();
                                    checkoutLoaderHide();
                                    break;

                                default:
                                    self.$showErrorMsg(
                                        QUILocale.get(pkg, 'PaymentDisplay.service_provider_error')
                                    );

                                    self.fireEvent('processingError', [self]);
                            }
                        });
                    }
                }
            }).inject(this.$PayBtnElm);

            paymill.embedFrame('quiqqer-payment-paymill-frame', PaymillFrameOptions, function (Error) {
                self.$OrderProcess.Loader.hide();

                if (Error) {
                    self.$showErrorMsg(
                        QUILocale.get(pkg,
                            'payment.error_msg.general_error'
                        )
                    );

                    self.fireEvent('processingError', [self]);
                    return;
                }

                self.$PayBtn.enable();
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
         * Start Order checkout with Paymill
         *
         * @param {String} token
         * @return {Promise}
         */
        $checkout: function (token) {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paymill_ajax_checkout', resolve, {
                    'package'   : pkg,
                    orderHash   : self.getAttribute('orderhash'),
                    paymillToken: token,
                    onError     : reject
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