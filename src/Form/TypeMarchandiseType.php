<?php

namespace App\Form;

use App\Entity\TypeMarchandise;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TypeMarchandiseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('prixUnitaire', MoneyType::class, [
                'invalid_message' => 'Saisissez un montant valide (ex. : 12,50).',
                'label' => 'Tarif indicatif',
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'required' => false,
                'attr' => ['step' => '0.01', 'min' => '0'],
                'help' => 'Pour mémoire : le prix de chaque ligne se saisit dans le colis.',
            ])
            ->add('actif', CheckboxType::class, ['label' => 'Proposé à la saisie', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TypeMarchandise::class]);
    }
}
