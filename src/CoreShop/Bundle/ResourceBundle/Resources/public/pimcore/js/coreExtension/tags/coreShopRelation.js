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

pimcore.registerNS("pimcore.object.tags.coreShopRelation");
pimcore.object.tags.coreShopRelation = Class.create(pimcore.object.tags.manyToOneRelation, {
    type: "coreShopRelation",
});
