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

namespace CoreShop\Bundle\PayumBundle\EventListener;

use CoreShop\Bundle\PayumBundle\Controller\PaymentController;
use Doctrine\DBAL\Connection;
use Payum\Bundle\PayumBundle\Controller\PayumController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TransactionListener implements EventSubscriberInterface
{
    public function __construct(protected Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (!is_callable($controller)) {
            return;
        }

        if (!is_array($controller)) {
            return;
        }

        if (!$controller[0] instanceof PayumController && !$controller[0] instanceof PaymentController) {
            return;
        }

        $event->getRequest()->attributes->add(['PAYUM_TRANSACTION_ACTIVE' => true]);
        $this->connection->beginTransaction();
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->getRequest()->attributes->get('PAYUM_TRANSACTION_ACTIVE')) {
            return;
        }

        $this->connection->commit();
    }
}
