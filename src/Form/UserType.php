<?php

namespace App\Form;

use App\Entity\User;
use App\Enum\Destination;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [new Assert\Length(min: 8, minMessage: 'Au moins 8 caractères.')];
        if ($options['creation']) {
            $passwordConstraints[] = new Assert\NotBlank(message: 'Choisissez un mot de passe.');
        }

        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom'])
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('username', TextType::class, ['label' => 'Identifiant de connexion'])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('rolePrincipal', ChoiceType::class, ['label' => 'Rôle', 'choices' => User::ROLES, 'expanded' => true, 'constraints' => [new Assert\NotBlank(message: 'Choisissez un rôle.')]])
            ->add('pays', EnumType::class, [
                'label' => 'Pays (représentants)',
                'class' => Destination::class,
                'choice_label' => fn (Destination $d) => $d->label(),
                'required' => false,
                'placeholder' => '—',
                'help' => 'Un représentant ne voit que les expéditions vers son pays.',
            ])
            ->add('actif', CheckboxType::class, ['label' => 'Compte actif', 'required' => false])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => $options['creation'],
                'first_options' => ['label' => 'Mot de passe', 'attr' => ['autocomplete' => 'new-password'], 'help' => $options['creation'] ? null : 'Laisser vide pour ne pas changer.'],
                'second_options' => ['label' => 'Confirmation', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'constraints' => $passwordConstraints,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class, 'creation' => false]);
        $resolver->setAllowedTypes('creation', 'bool');
    }
}
