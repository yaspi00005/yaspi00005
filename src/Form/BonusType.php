<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class BonusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sens', ChoiceType::class, [
                'label' => 'Opération',
                'choices' => ['Créditer' => 1, 'Débiter' => -1],
                'expanded' => true,
                'data' => 1,
            ])
            ->add('montant', MoneyType::class, [
                'label' => 'Montant',
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
                'constraints' => [new Assert\NotBlank(), new Assert\Positive()],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Motif',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['placeholder' => 'Ex. : Parrainage, geste commercial…'],
            ]);
    }
}
