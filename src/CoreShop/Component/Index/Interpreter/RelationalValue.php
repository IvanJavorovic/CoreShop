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

namespace CoreShop\Component\Index\Interpreter;

class RelationalValue implements RelationalValueInterface
{
    protected array $params;

    public function __construct(protected int $destinationId, protected string $type, array $params = [])
    {
        $this->params = $params;
    }

    public function getDestinationId(): int
    {
        return $this->destinationId;
    }

    public function setDestinationId(int $destinationId): void
    {
        $this->destinationId = $destinationId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }
}
