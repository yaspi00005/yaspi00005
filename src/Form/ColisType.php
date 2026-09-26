<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Colis;
use App\Entity\Expedition;
use App\Enum\StatutExpedition;
use App\Enum\TypeExpedition;
use App\Repository\ClientRepository;
use App\Repository\ExpeditionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ColisType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Colis|null $colis */
        $colis = $builder->getData();
        $expeditionActuelle = $colis?->getExpedition();

        $builder
            ->add('client', EntityType::class, [
                'label' => 'Client expéditeur',
                'class' => Client::class,
                'query_builder' => fn (ClientRepository $r) => $r->createQueryBuilder('c')->orderBy('c.nom')->addOrderBy('c.prenom'),
                'choice_label' => fn (Client $c) => $c->getNomComplet().' — '.$c->getTelephone(),
                'placeholder' => 'Rechercher un client…',
                'attr' => ['class' => 'js-select2'],
            ])
            ->add('destinataireNom', TextType::class, ['label' => 'Nom du destinataire'])
            ->add('destinataireTelephone', TelType::class, ['label' => 'Téléphone du destinataire', 'help' => 'Le destinataire recevra un SMS à l\'arrivée.'])
            ->add('expedition', EntityType::class, [
                'label' => 'Expédition',
                'class' => Expedition::class,
                'required' => false,
                'query_builder' => function (ExpeditionRepository $r) use ($expeditionActuelle) {
                    $qb = $r->createQueryBuilder('e')
                        ->andWhere('e.statut = :s')->setParameter('s', StatutExpedition::EnPreparation)
                        ->orderBy('e.dateDepart', 'ASC');
                    if ($expeditionActuelle) {
                        $qb->orWhere('e.id = :actuelle')->setParameter('actuelle', $expeditionActuelle->getId());
                    }

                    return $qb;
                },
                'choice_label' => fn (Expedition $e) => $e->getModeTransport()?->label().' → '.$e->getDestination()?->label().' · départ '.$e->getDateDepart()?->format('d/m/Y').' ('.$e->getNumero().')',
                'placeholder' => 'À affecter plus tard',
            ])
            ->add('typeExpedition', EnumType::class, [
                'label' => 'Type',
                'class' => TypeExpedition::class,
                'choice_label' => fn (TypeExpedition $t) => $t->label(),
                'expanded' => true,
            ])
            ->add('observations', TextareaType::class, ['label' => 'Observations', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('articles', CollectionType::class, [
                'label' => false,
                'entry_type' => ArticleType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'error_bubbling' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Colis::class]);
    }
}
