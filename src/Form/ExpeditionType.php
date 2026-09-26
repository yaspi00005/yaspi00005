<?php

namespace App\Form;

use App\Entity\Expedition;
use App\Enum\Destination;
use App\Enum\ModeTransport;
use Symfony\Component\Form\AbstractType;
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
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Expedition::class]);
    }
}
