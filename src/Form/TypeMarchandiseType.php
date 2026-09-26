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
                'label' => 'Tarif indicatif au kg',
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'required' => false,
                'attr' => ['step' => '0.01', 'min' => '0'],
                'help' => 'Pré-remplit le prix lors de la saisie (poids × tarif).',
            ])
            ->add('actif', CheckboxType::class, ['label' => 'Proposé à la saisie', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TypeMarchandise::class]);
    }
}
