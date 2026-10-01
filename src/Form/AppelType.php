<?php

namespace App\Form;

use App\Entity\Appel;
use App\Entity\Camion;
use App\Entity\Expedition;
use App\Entity\User;
use App\Enum\StatutExpedition;
use App\Repository\CamionRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AppelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Appel $appel */
        $appel = $options['data'];
        $builder
            ->add('utilisateur', EntityType::class, [
                'label' => 'Utilisateur',
                'class' => User::class,
                // Administrateurs et agents de ramassage en service.
                'query_builder' => fn (UserRepository $r) => $r->createQueryBuilder('u')->andWhere('u.actif = true')
                    ->andWhere('u.roles LIKE :admin OR u.roles LIKE :ramassage')
                    ->setParameter('admin', '%'.User::ROLE_ADMIN.'%')->setParameter('ramassage', '%'.User::ROLE_RAMASSAGE.'%')
                    ->orderBy('u.prenom'),
                'choice_label' => 'nomComplet',
            ])
            ->add('dateAppel', DateType::class, ['label' => "Date d'appel", 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('camion', EntityType::class, [
                'label' => 'Affectation camion',
                'class' => Camion::class,
                'required' => false,
                // Camions en service, plus celui déjà affecté s'il a été retiré depuis.
                'query_builder' => fn (CamionRepository $r) => $r->createQueryBuilder('c')
                    ->andWhere('c.actif = true OR c.id = :actuel')->setParameter('actuel', $appel->getCamion()?->getId() ?? 0)
                    ->orderBy('c.nom'),
                'choice_label' => fn (Camion $c) => $c->getNom().($c->getChauffeur() ? ' — '.$c->getChauffeur()->getPrenom() : ''),
                'placeholder' => '-- À affecter plus tard --',
            ])
            ->add('expedition', EntityType::class, [
                'label' => 'Conteneur (expédition)',
                'class' => Expedition::class,
                'required' => false,
                // Expéditions en préparation, plus celle déjà choisie si elle est partie depuis.
                'query_builder' => fn (ExpeditionRepository $r) => $r->createQueryBuilder('e')
                    ->andWhere('e.statut = :prep OR e.id = :actuelle')
                    ->setParameter('prep', StatutExpedition::EnPreparation)->setParameter('actuelle', $appel->getExpedition()?->getId() ?? 0)
                    ->orderBy('e.dateDepart', 'ASC'),
                'choice_label' => fn (Expedition $e) => $e->getDestination()->ville().' — départ le '.$e->getDateDepart()->format('d/m/Y').' ('.$e->getNumero().')',
                'placeholder' => '-- Pas encore créée : date libre ci-dessous --',
            ])
            ->add('dateConteneur', DateType::class, [
                'label' => 'Date du conteneur prévue',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => "Seulement si l'expédition n'existe pas encore.",
            ])
            ->add('nom', TextType::class, ['label' => 'Nom du client', 'attr' => ['placeholder' => 'Ex. : Dupont', 'autocomplete' => 'off']])
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'required' => false, 'attr' => ['placeholder' => 'Ex. : Jean', 'autocomplete' => 'off']])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'attr' => ['placeholder' => 'Ex. : 06 12 34 56 78', 'inputmode' => 'tel', 'class' => 'js-appel-telephone']])
            ->add('codePostal', TextType::class, ['label' => 'Code postal', 'attr' => ['placeholder' => 'Ex. : 75000', 'class' => 'js-cp-code', 'inputmode' => 'numeric', 'autocomplete' => 'off']])
            ->add('ville', TextType::class, ['label' => 'Ville', 'attr' => ['placeholder' => 'Ex. : Paris', 'class' => 'js-cp-ville', 'list' => 'villes-cp', 'autocomplete' => 'off']])
            ->add('adresse', TextType::class, ['label' => 'Adresse complète', 'required' => false, 'attr' => ['placeholder' => 'Ex. : 10 rue de la Paix']])
            ->add('estimation', MoneyType::class, [
                'label' => 'Estimation du prix (€)',
                'required' => false,
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'invalid_message' => 'Saisissez un montant valide (ex. : 150).',
                'attr' => ['step' => '1', 'min' => '0', 'placeholder' => 'Ex. : 150'],
            ])
            ->add('commentaire', TextareaType::class, ['label' => "Commentaire / objet de l'appel", 'required' => false, 'attr' => ['rows' => 2, 'placeholder' => 'Détails de la demande…']])
            ->add('dateRamassage', DateTimeType::class, [
                'label' => 'Date et heure du ramassage',
                'mapped' => false,
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'data' => $appel->getRamassage()?->getDatePrevue(),
                'help' => 'Renseignée : le ramassage apparaît dans le planning.',
                'constraints' => [new Assert\GreaterThan(value: '-1 year', message: 'Date de ramassage invalide.')],
            ])
            ->add('confirme', CheckboxType::class, ['label' => 'Appel / demande confirmé(e)', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Appel::class]);
    }
}
