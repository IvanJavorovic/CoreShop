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

namespace CoreShop\Bundle\ResourceBundle\CoreExtension;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

trait TempEntityManagerTrait
{
    protected function createTempEntityManager(EntityManagerInterface $entityManager): EntityManager
    {
        return EntityManager::create($entityManager->getConnection(), $entityManager->getConfiguration(), $entityManager->getEventManager());
    }
}
