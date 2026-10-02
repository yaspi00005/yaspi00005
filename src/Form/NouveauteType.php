<?php

namespace App\Form;

use App\Entity\Nouveaute;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NouveauteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, ['label' => 'Titre', 'attr' => ['placeholder' => 'Ex. : Kayes, bientôt desservie', 'maxlength' => 120]])
            ->add('etiquette', ChoiceType::class, ['label' => 'Étiquette', 'choices' => Nouveaute::ETIQUETTES])
            ->add('icone', ChoiceType::class, ['label' => 'Icône', 'choices' => Nouveaute::ICONES])
            ->add('texte', TextareaType::class, ['label' => 'Texte', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 600, 'placeholder' => 'Ex. : Dès janvier, envoyez vos colis vers Kayes. Inscrivez-vous sur WhatsApp pour être prévenu du premier départ.']])
            ->add('lien', TextType::class, ['label' => 'Lien (facultatif)', 'required' => false, 'help' => 'Ex. : /ramassage, ou une adresse https://…', 'attr' => ['maxlength' => 255]])
            ->add('dateFin', DateType::class, ['label' => "Afficher jusqu'au (facultatif)", 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'help' => "Après cette date, l'annonce disparaît toute seule du site."])
            ->add('ordre', IntegerType::class, ['label' => "Ordre d'affichage", 'required' => false, 'empty_data' => '0', 'help' => 'Les plus petits nombres en premier.'])
            ->add('publiee', CheckboxType::class, ['label' => 'Publiée sur le site', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Nouveaute::class]);
    }
}
