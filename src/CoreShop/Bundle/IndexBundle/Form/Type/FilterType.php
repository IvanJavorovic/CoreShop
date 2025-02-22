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

namespace CoreShop\Bundle\IndexBundle\Form\Type;

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class FilterType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('orderKey', TextType::class)
            ->add('orderDirection', ChoiceType::class, [
                'choices' => [
                    'ASC' => 'asc',
                    'DESC' => 'desc',
                ],
                'required' => true,
                'constraints' => [
                    new NotBlank(['groups' => $this->validationGroups])
                ]
            ])
            ->add('preConditions', FilterPreConditionCollectionType::class)
            ->add('conditions', FilterUserConditionCollectionType::class)
            ->add('resultsPerPage', IntegerType::class)
            ->add('index', IndexChoiceType::class);
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_filter';
    }
}
