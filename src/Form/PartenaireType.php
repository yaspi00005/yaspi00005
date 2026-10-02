<?php

namespace App\Form;

use App\Entity\Partenaire;
use App\Service\FichiersVitrine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class PartenaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Partenaire $p */
        $p = $options['data'];
        $builder
            ->add('nom', TextType::class, ['label' => "Nom de l'enseigne", 'attr' => ['maxlength' => 100]])
            ->add('logoFichier', FileType::class, [
                'label' => $p->getLogo() ? 'Remplacer le logo' : 'Logo',
                'mapped' => false,
                'required' => !$p->getLogo(),
                'help' => 'PNG transparent de préférence (ou JPEG, WebP), 600 px de large suffisent.',
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp'],
                'constraints' => array_filter([
                    $p->getLogo() ? null : new Assert\NotNull(message: 'Choisissez le fichier du logo.'),
                    new Assert\File(maxSize: '4M', mimeTypes: array_keys(FichiersVitrine::IMAGES), mimeTypesMessage: 'Logo au format PNG, JPEG ou WebP uniquement.'),
                ]),
            ])
            ->add('url', UrlType::class, ['label' => 'Site internet (facultatif)', 'required' => false, 'default_protocol' => 'https', 'attr' => ['placeholder' => 'https://www.exemple.fr']])
            ->add('ordre', IntegerType::class, ['label' => "Ordre d'affichage", 'required' => false, 'empty_data' => '0'])
            ->add('publie', CheckboxType::class, ['label' => 'Affiché sur le site', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Partenaire::class]);
    }
}
