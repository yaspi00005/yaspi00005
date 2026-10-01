<?php

namespace App\Form;

use App\Entity\Expedition;
use App\Enum\Destination;
use App\Enum\ModeTransport;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExpeditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('modeTransport', EnumType::class, [
                'label' => 'Mode de transport',
                'class' => ModeTransport::class,
                'choice_label' => fn (ModeTransport $m) => $m->label(),
                'expanded' => true,
            ])
            ->add('destination', EnumType::class, [
                'label' => 'Destination',
                'class' => Destination::class,
                'choice_label' => fn (Destination $d) => $d->label(),
                'placeholder' => 'Choisir',
            ])
            ->add('dateDepart', DateType::class, ['label' => 'Date de départ', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('dateArriveePrevue', DateType::class, ['label' => 'Arrivée prévue', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('reference', TextType::class, ['label' => 'Référence transporteur / conteneur', 'required' => false])
            ->add('notes', TextareaType::class, ['label' => 'Notes (internes)', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('publieeSurSite', CheckboxType::class, [
                'label' => 'Annoncer ce départ sur le site sotrama.net',
                'required' => false,
                'help' => 'L\'annonce disparaît d\'elle-même quand l\'expédition part.',
            ])
            ->add('dateLimiteDepot', DateType::class, ['label' => 'Dépôt des colis jusqu\'au', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('annonce', TextareaType::class, [
                'label' => 'Message de l\'annonce (public)',
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 500, 'placeholder' => 'Ex. : Places limitées, réservez votre ramassage !'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Expedition::class]);
    }
}
