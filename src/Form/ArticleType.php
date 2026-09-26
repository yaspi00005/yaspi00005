<?php

namespace App\Form;

use App\Entity\Article;
use App\Repository\TypeMarchandiseRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class ArticleType extends AbstractType
{
    public function __construct(private readonly TypeMarchandiseRepository $types)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $noms = array_map(fn ($t) => $t->getNom(), $this->types->findActifs());

        $builder
            ->add('typeMarchandise', ChoiceType::class, $this->optionsType($noms))
            ->add('poidsVolume', NumberType::class, [
                'invalid_message' => 'Saisissez un nombre valide (ex. : 2,5).',
                'label' => 'Poids (kg)',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01', 'class' => 'js-poids'],
            ])
            ->add('prix', MoneyType::class, [
                'invalid_message' => 'Saisissez un montant valide (ex. : 12,50).',
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

        // En modification, un article peut porter un type retiré de la liste depuis : on le garde sélectionnable.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($noms) {
            $actuel = $event->getData()?->getTypeMarchandise();
            if ($actuel && !\in_array($actuel, $noms, true)) {
                $event->getForm()->add('typeMarchandise', ChoiceType::class, $this->optionsType([...$noms, $actuel]));
            }
        });
    }

    /** @param list<string> $noms */
    private function optionsType(array $noms): array
    {
        return [
            'label' => 'Marchandise',
            'choices' => array_combine($noms, $noms),
            'placeholder' => 'Choisir',
            'invalid_message' => 'Choisissez un type de marchandise dans la liste.',
            'attr' => ['class' => 'js-type-marchandise'],
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Article::class]);
    }
}
