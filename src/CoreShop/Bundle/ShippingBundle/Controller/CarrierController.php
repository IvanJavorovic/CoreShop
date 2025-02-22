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

namespace CoreShop\Bundle\ShippingBundle\Controller;

use CoreShop\Bundle\ResourceBundle\Controller\ResourceController;
use Symfony\Component\HttpFoundation\JsonResponse;

class CarrierController extends ResourceController
{
    public function getConfigAction(): JsonResponse
    {
        /**
         * @var array $strategies
         */
        $strategies = $this->container->getParameter('coreshop.shipping.tax_calculation_strategies');

        $convertedStrategies = [];
        foreach ($strategies as $strategy) {
            $convertedStrategies[] = [
                'value' => $strategy,
                // key length has a maximum
                'label' => 'coreshop_shipping_tax_strategy_' . $strategy,
            ];
        }

        return $this->viewHandler->handle(
            [
                'success' => true,
                'taxCalculationStrategies' => $convertedStrategies,
            ]
        );
    }
}
