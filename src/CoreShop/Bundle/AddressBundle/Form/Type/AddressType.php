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

namespace CoreShop\Bundle\AddressBundle\Form\Type;

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AddressType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('company', TextType::class, [
                'label' => 'coreshop.form.address.company',
                'required' => false,
            ])
            ->add('salutation', SalutationChoiceType::class, [
                'label' => 'coreshop.form.address.salutation',
            ])
            ->add('firstname', TextType::class, [
                'label' => 'coreshop.form.address.firstname',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'coreshop.form.address.lastname',
            ])
            ->add('street', TextType::class, [
                'label' => 'coreshop.form.address.street',
            ])
            ->add('number', TextType::class, [
                'label' => 'coreshop.form.address.number',
            ])
            ->add('postcode', TextType::class, [
                'label' => 'coreshop.form.address.postcode',
            ])
            ->add('city', TextType::class, [
                'label' => 'coreshop.form.address.city',
            ])
            ->add('country', CountryChoiceType::class, [
                'active' => true,
                'label' => 'coreshop.form.address.country',
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'coreshop.form.address.phone_number',
                'required' => false,
            ])
            ->add('_redirect', HiddenType::class, [
                'mapped' => false,
            ]);

        if ($options['show_address_identifier_choice'] === true) {
            $builder->add('addressIdentifier', AddressIdentifierChoiceType::class, [
                'label' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefault('show_address_identifier_choice', false);
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_address';
    }
}
