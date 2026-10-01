<?php

namespace App\Form;

use App\Entity\Camion;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CamionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom', 'attr' => ['placeholder' => 'Ex. : Peugeot Boxer 2']])
            ->add('immatriculation', TextType::class, ['label' => 'Immatriculation', 'required' => false, 'attr' => ['placeholder' => 'AB-123-CD']])
            ->add('volume', TextType::class, ['label' => 'Volume', 'required' => false, 'attr' => ['placeholder' => 'Ex. : 20 m³']])
            ->add('chauffeur', EntityType::class, [
                'label' => 'Chauffeur habituel',
                'class' => User::class,
                'required' => false,
                'query_builder' => fn (UserRepository $r) => $r->createQueryBuilder('u')->andWhere('u.actif = true')
                    ->andWhere('u.roles LIKE :admin OR u.roles LIKE :ramassage')
                    ->setParameter('admin', '%'.User::ROLE_ADMIN.'%')->setParameter('ramassage', '%'.User::ROLE_RAMASSAGE.'%')
                    ->orderBy('u.prenom'),
                'choice_label' => 'nomComplet',
                'placeholder' => 'Aucun',
                'help' => 'Reçoit les ramassages affectés à ce camion.',
            ])
            ->add('couleur', ChoiceType::class, ['label' => 'Couleur dans le planning', 'choices' => Camion::COULEURS])
            ->add('actif', CheckboxType::class, ['label' => 'En service', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Camion::class]);
    }
}
