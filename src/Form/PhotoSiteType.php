<?php

namespace App\Form;

use App\Entity\PhotoSite;
use App\Service\FichiersVitrine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Nouvelle photo : plusieurs fichiers d'un coup (même catégorie, même légende). Modification : une seule. */
class PhotoSiteType extends AbstractType
{
    public const MAX_ENVOI = 20;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var PhotoSite $p */
        $p = $options['data'];
        $image = new Assert\File(maxSize: '8M', mimeTypes: array_keys(FichiersVitrine::IMAGES), mimeTypesMessage: 'Photo au format JPEG, PNG ou WebP uniquement.');
        $aide = 'JPEG, PNG ou WebP, 8 Mo maximum par photo (limite du serveur : '.FichiersVitrine::limiteEnvoi().' par envoi).';

        if ($p->getPhoto()) {
            $builder->add('photoFichier', FileType::class, [
                'label' => 'Remplacer la photo', 'mapped' => false, 'required' => false, 'help' => $aide,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'], 'constraints' => [$image],
            ]);
        } else {
            $builder->add('photosFichiers', FileType::class, [
                'label' => 'Photos', 'mapped' => false, 'multiple' => true,
                'help' => 'Vous pouvez en choisir plusieurs à la fois ('.self::MAX_ENVOI.' maximum). '.$aide,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [
                    new Assert\Count(min: 1, max: self::MAX_ENVOI, minMessage: 'Choisissez au moins une photo.', maxMessage: self::MAX_ENVOI.' photos maximum par envoi.'),
                    new Assert\All([$image]),
                ],
            ]);
        }

        $builder
            ->add('categorie', ChoiceType::class, ['label' => 'Catégorie', 'choices' => array_flip(PhotoSite::CATEGORIES)])
            ->add('legende', TextType::class, ['label' => 'Légende (facultatif)', 'required' => false, 'attr' => ['maxlength' => 150, 'placeholder' => 'Ex. : Chargement du conteneur pour Bamako']])
            ->add('ordre', IntegerType::class, ['label' => "Ordre d'affichage", 'required' => false, 'empty_data' => '0', 'help' => 'Les plus petits nombres en premier.'])
            ->add('publie', CheckboxType::class, ['label' => 'Affichée sur le site', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PhotoSite::class]);
    }
}
