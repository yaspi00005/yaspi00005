<?php

namespace App\Form;

use App\Entity\Article;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('typeMarchandise', TextType::class, [
                'label' => 'Marchandise',
                'attr' => ['list' => 'types-marchandises', 'class' => 'js-type-marchandise', 'placeholder' => 'Ex. : Vêtements'],
            ])
            ->add('poidsVolume', NumberType::class, [
                'label' => 'Poids (kg)',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01', 'class' => 'js-poids'],
            ])
            ->add('prix', MoneyType::class, [
                'label' => 'Prix',
                'divisor' => 100,
                'currency' => false,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0', 'class' => 'js-prix'],
            ])
            ->add('observations', TextType::class, ['label' => 'Observations', 'required' => false])
            ->add('photoFile', FileType::class, [
                'label' => 'Photo',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/*', 'capture' => 'environment'],
                'constraints' => [new Image(maxSize: '8M', mimeTypesMessage: 'Envoyez une image (JPEG, PNG, WebP).')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Article::class]);
    }
}
