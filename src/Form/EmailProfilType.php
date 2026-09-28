<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Adresse e-mail de l'utilisateur (rapports, rappels des ramassages). */
class EmailProfilType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Mon adresse e-mail',
            'attr' => ['autocomplete' => 'email', 'placeholder' => 'prenom.nom@exemple.com'],
            'constraints' => [
                new Assert\NotBlank(message: 'Indiquez votre adresse e-mail.'),
                new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.'),
                new Assert\Length(max: 180),
            ],
        ]);
    }
}
