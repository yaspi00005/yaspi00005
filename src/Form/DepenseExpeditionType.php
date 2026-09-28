<?php

namespace App\Form;

use App\Entity\DepenseExpedition;
use App\Enum\CategorieDepense;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DepenseExpeditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('categorie', EnumType::class, [
                'label' => 'Type de dépense',
                'class' => CategorieDepense::class,
                'choice_label' => fn (CategorieDepense $c) => $c->label(),
            ])
            ->add('libelle', TextType::class, ['label' => 'Détail', 'required' => false, 'attr' => ['placeholder' => 'Ex. : location conteneur 40 pieds, transitaire…']])
            ->add('montant', MoneyType::class, [
                'label' => 'Montant',
                'invalid_message' => 'Saisissez un montant valide (ex. : 1 250,00).',
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
            ])
            ->add('date', DateType::class, ['label' => 'Date', 'widget' => 'single_text', 'input' => 'datetime_immutable']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DepenseExpedition::class]);
    }
}
