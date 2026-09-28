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
        // Mot de passe vide accepté si « mot de passe provisoire » est coché (contrôlé par le contrôleur).
        $passwordConstraints = self::reglesMotDePasse();

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
            ->add('provisoire', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $options['creation'],
                'label' => $options['creation']
                    ? 'Générer un mot de passe provisoire'
                    : 'Réinitialiser avec un mot de passe provisoire',
                'help' => 'Un mot de passe unique est généré et affiché après l\'enregistrement (une seule fois). À la première connexion, l\'utilisateur devra le changer et indiquer son adresse e-mail. Sinon, choisissez un mot de passe ci-dessous.',
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
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

    /**
     * Règles des mots de passe : 10 caractères minimum, robustesse moyenne (pas « azerty1234 »),
     * absent des listes de mots de passe divulgués (haveibeenpwned, sans envoyer le mot de passe :
     * seuls les 5 premiers caractères de son empreinte sont transmis ; ignoré si le service est injoignable).
     *
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    public static function reglesMotDePasse(): array
    {
        return [
            new Assert\Length(min: 10, max: 4096, minMessage: 'Au moins 10 caractères.'),
            new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Mot de passe trop simple : mélangez majuscules, minuscules, chiffres et signes, ou utilisez une phrase.'),
            new Assert\NotCompromisedPassword(message: 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre.', skipOnError: true),
        ];
    }
}
