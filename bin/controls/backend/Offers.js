/**
 * List of all Paymill Offers
 *
 * @module package/quiqqer/payment-paymill/bin/controls/backend/Offers
 * @author www.pcsg.de (Patrick Müller)
 */
define('package/quiqqer/payment-paymill/bin/controls/backend/Offers', [

    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'controls/grid/Grid',
    'package/quiqqer/payment-paymill/bin/Paymill',

    'Locale',

    'css!package/quiqqer/payment-paymill/bin/controls/backend/Offers.css'

], function (QUIControl, QUILoader, QUIConfirm, QUIButton, Grid, Paymill, QUILocale) {
    "use strict";

    var lg = 'quiqqer/payment-paymill';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paymill/bin/controls/backend/Offers',

        Binds: [
            'refresh',
            '$onCreate',
            '$onImport',
            '$onResize',
            '$clickDelete'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$Grid    = null;
            this.$Content = null;
            this.Loader   = new QUILoader();

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

            return Paymill.getOffers({
                perPage: this.$Grid.options.perPage,
                page   : this.$Grid.options.page,
                sortBy : this.$Grid.options.sortBy,
                sortOn : this.$Grid.options.sortOn
            }).then(function (result) {
                var TableButtons = this.$Grid.getAttribute('buttons');
                TableButtons.delete.disable();

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
                'class': 'quiqqer-payment-paymill-offers field-container-field'
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

            // Buttons

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

                accordion           : false,
                autoSectionToggle   : false,
                openAccordionOnClick: false,

                buttons    : [{
                    name     : 'delete',
                    text     : QUILocale.get(lg, 'controls.backend.Offers.tbl.btn.delete'),
                    textimage: 'fa fa-trash',
                    events   : {
                        onClick: this.$clickDelete
                    }
                }],
                columnModel: [{
                    header   : QUILocale.get(lg, 'controls.backend.Offers.tbl.id'),
                    dataIndex: 'id',
                    dataType : 'string',
                    width    : 200
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.Offers.tbl.name'),
                    dataIndex: 'name',
                    dataType : 'string',
                    width    : 250
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.Offers.tbl.created_at'),
                    dataIndex: 'created_at',
                    dataType : 'string',
                    width    : 150
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
                        TableButtons.delete.enable();
                    } else {
                        TableButtons.delete.disable();
                    }
                }
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
        $clickDelete: function () {
            var self    = this;
            var offerId = this.$Grid.getSelectedData()[0].id;

            new QUIConfirm({
                maxHeight: 300,
                maxWidth : 500,
                autoclose: false,

                information: QUILocale.get(lg, 'controls.backend.Offers.delete.information', {
                    offerId: offerId
                }),
                title      : QUILocale.get(lg, 'controls.backend.Offers.delete.title'),
                texticon   : 'fa fa-trash',
                text       : QUILocale.get(lg, 'controls.backend.Offers.delete.text'),
                icon       : 'fa fa-trash',
                ok_button  : {
                    text     : QUILocale.get(lg, 'controls.backend.Offers.delete.ok'),
                    textimage: 'icon-ok fa fa-trash'
                },
                events     : {
                    onSubmit: function (Popup) {
                        Popup.Loader.show();

                        Paymill.deleteOffer(offerId).then(function () {
                            self.refresh();
                            Popup.close();
                        }, function () {
                            Popup.Loader.hide();
                        })
                    }
                }

            }).open();
        }
    });
});
