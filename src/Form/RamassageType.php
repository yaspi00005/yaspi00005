<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\StatutRamassage;
use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RamassageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'query_builder' => fn (ClientRepository $r) => $r->createQueryBuilder('c')->orderBy('c.nom'),
                'choice_label' => fn (Client $c) => $c->getNomComplet().' — '.$c->getTelephone(),
                'placeholder' => 'Rechercher un client…',
                'attr' => ['class' => 'js-select2'],
            ])
            ->add('datePrevue', DateTimeType::class, ['label' => 'Date et heure', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('adresse', TextareaType::class, ['label' => 'Adresse de ramassage', 'required' => false, 'help' => 'Laisser vide pour utiliser l\'adresse du client.', 'attr' => ['rows' => 2]])
            ->add('commentaire', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('agent', EntityType::class, [
                'label' => 'Agent',
                'class' => User::class,
                'required' => false,
                'query_builder' => fn (UserRepository $r) => $r->createQueryBuilder('u')->andWhere('u.actif = true')->orderBy('u.prenom'),
                'choice_label' => 'nomComplet',
                'placeholder' => 'Non attribué',
            ])
            ->add('statut', EnumType::class, [
                'label' => 'Statut',
                'class' => StatutRamassage::class,
                'choice_label' => fn (StatutRamassage $s) => $s->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Ramassage::class]);
    }
}
