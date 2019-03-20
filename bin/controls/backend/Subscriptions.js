/**
 * List of all Paymill Subscriptions
 *
 * @module package/quiqqer/payment-paymill/bin/controls/backend/Subscriptions
 * @author www.pcsg.de (Patrick Müller)
 */
define('package/quiqqer/payment-paymill/bin/controls/backend/Subscriptions', [

    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'controls/grid/Grid',
    'package/quiqqer/payment-paymill/bin/Paymill',

    'package/quiqqer/payment-paymill/bin/controls/backend/SubscriptionWindow',

    'Locale',

    'css!package/quiqqer/payment-paymill/bin/controls/backend/Subscriptions.css'

], function (QUIControl, QUILoader, QUIConfirm, QUIButton, Grid, Paymill, SubscriptionWindow, QUILocale) {
    "use strict";

    var lg = 'quiqqer/payment-paymill';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paymill/bin/controls/backend/Subscriptions',

        Binds: [
            'refresh',
            '$onCreate',
            '$onImport',
            '$onResize',
            '$clickDetails'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$Grid    = null;
            this.$Content = null;
            this.Loader   = new QUILoader();

            this.$search      = false;
            this.$SearchInput = null;

            this.addEvents({
                onCreate: this.$onCreate,
                onResize: this.$onResize,
                onImport: this.$onImport
            });
        },

        /**
         * Refresh the grid
         */
        refresh: function () {
            if (!this.$Grid) {
                return;
            }

            this.Loader.show();

            var search = this.$SearchInput.value.trim();

            if (search === '') {
                search = false;
            }

            switch (this.$Grid.options.sortOn) {
                case 'active_status':
                    this.$Grid.options.sortOn = 'active';
                    break;

                case 'customer_text':
                    this.$Grid.options.sortOn = 'customer';
                    break;
            }

            return Paymill.getSubscriptions({
                perPage: this.$Grid.options.perPage,
                page   : this.$Grid.options.page,
                sortBy : this.$Grid.options.sortBy,
                sortOn : this.$Grid.options.sortOn,
                search : search
            }).then(function (result) {
                var TableButtons = this.$Grid.getAttribute('buttons');
                TableButtons.details.disable();

                for (var i = 0, len = result.data.length; i < len; i++) {
                    var Row      = result.data[i];
                    var Customer = JSON.decode(Row.customer);

                    Row.customer_text = Customer.firstname + " " + Customer.lastname + " (" + Customer.id + ")";

                    Row.active_status = new Element('span', {
                        'class': parseInt(Row.active) ? 'fa fa-check' : 'fa fa-close'
                    });
                }

                this.$Grid.setData(result);
                this.Loader.hide();
            }.bind(this), function () {
                this.destroy();
            }.bind(this));
        },

        /**
         * Event Handling
         */

        $onImport: function () {
            this.$Content = new Element('div', {
                'class': 'quiqqer-payment-paymill-subscriptions field-container-field'
            }).inject(this.getElm(), 'after');

            this.Loader.inject(this.$Content);

            this.$Content.getParent('form').setStyle('height', '100%');
            this.$Content.getParent('table').setStyle('height', '100%');
            this.$Content.getParent('tbody').setStyle('height', '100%');
            this.$Content.getParent('.field-container').setStyle('height', '100%');

            this.create();
            this.$onCreate();
            this.refresh();
        },

        /**
         * event : on panel create
         */
        $onCreate: function () {
            var self = this;

            // Search
            this.$SearchInput = new Element('input', {
                'class'    : 'quiqqer-payment-paymill-subscriptions-search',
                placeholder: QUILocale.get(lg, 'controls.backend.Subscriptions.search.placeholder'),
                events     : {
                    keydown: function (event) {
                        if (typeof event !== 'undefined' &&
                            event.code === 13) {
                            self.refresh();
                        }
                    }
                }
            }).inject(this.$Content);

            // Grid
            var Container = new Element('div', {
                style: {
                    height: '100%',
                    width : '100%'
                }
            }).inject(this.$Content);

            this.$Grid = new Grid(Container, {
                pagination       : true,
                multipleSelection: true,
                serverSort       : true,
                sortOn           : 'paymill_subscription_id',
                sortBy           : 'DESC',

                accordion           : false,
                autoSectionToggle   : false,
                openAccordionOnClick: false,

                buttons    : [{
                    name     : 'details',
                    text     : QUILocale.get(lg, 'controls.backend.Subscriptions.tbl.btn.details'),
                    textimage: 'fa fa-credit-card',
                    events   : {
                        onClick: this.$clickDetails
                    }
                }],
                columnModel: [{
                    header   : QUILocale.get(lg, 'controls.backend.Subscriptions.tbl.active_status'),
                    dataIndex: 'active_status',
                    dataType : 'node',
                    width    : 45
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.Subscriptions.tbl.paymill_subscription_id'),
                    dataIndex: 'paymill_subscription_id',
                    dataType : 'string',
                    width    : 200
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.Subscriptions.tbl.paymill_offer_id'),
                    dataIndex: 'paymill_offer_id',
                    dataType : 'string',
                    width    : 200
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.Subscriptions.tbl.customer_text'),
                    dataIndex: 'customer_text',
                    dataType : 'string',
                    width    : 200
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.Subscriptions.tbl.global_process_id'),
                    dataIndex: 'global_process_id',
                    dataType : 'string',
                    width    : 250
                }]
            });

            this.$Grid.addEvents({
                onRefresh: this.refresh,

                onClick: function () {
                    var TableButtons = self.$Grid.getAttribute('buttons'),
                        selected     = self.$Grid.getSelectedData().length;

                    if (!Object.getLength(TableButtons)) {
                        return;
                    }

                    if (selected === 1) {
                        TableButtons.details.enable();
                    } else {
                        TableButtons.details.disable();
                    }
                },

                onDblClick: this.$clickDetails
            });

            this.$onResize();
        },

        /**
         * event : on panel resize
         */
        $onResize: function () {
            if (!this.$Grid) {
                return;
            }

            var size = this.$Content.getSize();

            this.$Grid.setHeight(size.y - 20);
            this.$Grid.setWidth(size.x - 20);
            this.$Grid.resize();
        },

        /**
         * Delete Billing Plan dialog
         */
        $clickDetails: function () {
            var self = this;

            new SubscriptionWindow({
                subscriptionId: this.$Grid.getSelectedData()[0].paymill_subscription_id,
                events        : {
                    onCancelSubscription: function () {
                        self.refresh();
                    }
                }
            }).open();
        }
    });
});
