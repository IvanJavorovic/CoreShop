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

namespace CoreShop\Component\Resource\Factory;

use CoreShop\Component\Resource\Exception\UnexpectedTypeException;
use CoreShop\Component\Resource\Model\TranslatableInterface;
use CoreShop\Component\Resource\Translation\Provider\TranslationLocaleProviderInterface;

final class TranslatableFactory implements TranslatableFactoryInterface
{
    public function __construct(private FactoryInterface $factory, private TranslationLocaleProviderInterface $localeProvider)
    {
    }

    public function createNew()
    {
        $resource = $this->factory->createNew();

        if (!$resource instanceof TranslatableInterface) {
            throw new UnexpectedTypeException($resource, TranslatableInterface::class);
        }

        $resource->setCurrentLocale($this->localeProvider->getDefaultLocaleCode());
        $resource->setFallbackLocale($this->localeProvider->getDefaultLocaleCode());

        return $resource;
    }
}
