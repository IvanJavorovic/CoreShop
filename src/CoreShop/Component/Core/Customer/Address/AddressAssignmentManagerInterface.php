<?php
/**
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.org)
 * @license    https://www.coreshop.org/license     GNU General Public License version 3 (GPLv3)
 */

declare(strict_types=1);

namespace CoreShop\Component\Core\Customer\Address;

use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Core\Model\CustomerInterface;

interface AddressAssignmentManagerInterface
{
    public function getAddressAffiliationTypesForCustomer(CustomerInterface $customer, bool $useTranslationKeys = true): ?array;

    public function detectAddressAffiliationForCustomer(CustomerInterface $customer, AddressInterface $address): ?string;

    public function checkAddressAffiliationPermissionForCustomer(CustomerInterface $customer, AddressInterface $address): bool;

    public function allocateAddressByAffiliation(CustomerInterface $customer, AddressInterface $address, ?string $affiliation): AddressInterface;
}
