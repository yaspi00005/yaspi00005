<?php

namespace App\Form;

use App\Enum\ModePaiement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Données : ['montant' => int, 'remise' => int, 'mode' => ModePaiement, 'reference' => ?string, 'commentaire' => ?string] */
class EncaissementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('montant', MoneyType::class, [
                'label' => 'Montant encaissé',
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0'],
                'constraints' => [new Assert\NotNull(), new Assert\PositiveOrZero()],
            ])
            ->add('remise', MoneyType::class, [
                'label' => 'Remise',
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'required' => false,
                'empty_data' => '0',
                'attr' => ['step' => '0.01', 'min' => '0'],
                'constraints' => [new Assert\PositiveOrZero()],
            ])
            ->add('mode', EnumType::class, [
                'label' => 'Mode de paiement',
                'class' => ModePaiement::class,
                'choices' => ModePaiement::encaissables(),
                'choice_label' => fn (ModePaiement $m) => $m->label(),
            ])
            ->add('reference', TextType::class, ['label' => 'Référence (transaction, chèque…)', 'required' => false])
            ->add('commentaire', TextType::class, ['label' => 'Commentaire', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
