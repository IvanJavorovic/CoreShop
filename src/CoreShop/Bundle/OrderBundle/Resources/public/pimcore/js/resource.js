/*
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.org)
 * @license    https://www.coreshop.org/license     GNU General Public License version 3 (GPLv3)
 *
 */

pimcore.registerNS('coreshop.order.resource');
coreshop.order.resource = Class.create(coreshop.resource, {
    initialize: function () {
        coreshop.global.addStoreWithRoute('coreshop_cart_price_rules', 'coreshop_cart_price_rule_list');

        Ext.Ajax.request({
            url: Routing.generate('coreshop_admin_order_get_states'),
            success: function (response) {
                var res = Ext.decode(response.responseText);

                if (res.success) {
                    Ext.Object.each(res.states, function(identifier, states) {
                        pimcore.globalmanager.add('coreshop_states' + identifier.replace('coreshop', ''), new Ext.data.Store({
                            restful: false,
                            proxy: {
                                type: 'memory'
                            },
                            reader: {
                                type: 'json'
                            },
                            fields: [
                                 'color', 'label', 'state'
                            ],
                            data: states
                        }));
                    });

                    Ext.Object.each(res.transitions, function(identifier, transitions) {
                        pimcore.globalmanager.add('coreshop_transitions' + identifier.replace('coreshop', ''), new Ext.data.Store({
                            restful: false,
                            proxy: {
                                type: 'memory'
                            },
                            reader: {
                                type: 'json'
                            },
                            fields: [
                                 'name', 'froms', 'tos'
                            ],
                            data: transitions
                        }));
                    });
                }
            }.bind(this)
        });

        coreshop.broker.fireEvent('resource.register', 'coreshop.order', this);
    },

    openResource: function (item) {
        if (item === 'orders') {
            this.openOrders();
        } else if (item === 'quotes') {
            this.openQuotes();
        } else if (item === 'create_order') {
            this.openCreateOrder();
        } else if (item === 'create_quote') {
            this.openCreateQuote();
        } else if (item === 'carts') {
            this.openCarts();
        } else if (item === 'create_cart') {
            this.openCreateCart();
        } else if (item === 'cart_price_rule') {
            this.openCartPriceRules();
        } else if (item === 'open_order_by_number') {
            coreshop.order.helper.openSaleByNumberDialog('order');
        } else if (item === 'coreshop_quote_by_number') {
            coreshop.order.helper.openSaleByNumberDialog('quote');
        }
    },

    openOrders: function () {
        try {
            pimcore.globalmanager.get('coreshop_order').activate();
        }
        catch (e) {
            pimcore.globalmanager.add('coreshop_order', new coreshop.order.order.list());
        }
    },

    openCreateOrder: function () {
        new coreshop.order.order.create.panel();
    },

    openQuotes: function () {
        try {
            pimcore.globalmanager.get('coreshop_quote').activate();
        }
        catch (e) {
            pimcore.globalmanager.add('coreshop_quote', new coreshop.order.quote.list());
        }
    },

    openCreateQuote: function () {
        new coreshop.order.quote.create.panel();
    },

    openCarts: function () {
        try {
            pimcore.globalmanager.get('coreshop_cart').activate();
        }
        catch (e) {
            pimcore.globalmanager.add('coreshop_cart', new coreshop.order.cart.list());
        }
    },

    openCreateCart: function () {
        new coreshop.order.cart.create.panel();
    },

    openCartPriceRules: function () {
        try {
            pimcore.globalmanager.get('coreshop_price_rules_panel').activate();
        }
        catch (e) {
            //console.log(e);
            pimcore.globalmanager.add('coreshop_price_rules_panel', new coreshop.cart.pricerules.panel());
        }
    }
});

coreshop.broker.addListener('pimcore.ready', function() {
    new coreshop.order.resource();
});
