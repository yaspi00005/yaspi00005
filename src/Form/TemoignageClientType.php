<?php

namespace App\Form;

use App\Entity\TemoignageClient;
use App\Service\FichiersVitrine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class TemoignageClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var TemoignageClient $t */
        $t = $options['data'];
        $limite = FichiersVitrine::limiteEnvoi();
        $builder
            ->add('nom', TextType::class, ['label' => 'Client', 'attr' => ['placeholder' => 'Ex. : Awa, Montreuil', 'maxlength' => 100]])
            ->add('texte', TextareaType::class, ['label' => 'Son témoignage (facultatif)', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 500, 'placeholder' => 'Ex. : Mes cartons sont arrivés à Bamako en parfait état.']])
            ->add('videoFichier', FileType::class, [
                'label' => $t->getVideo() ? 'Remplacer la vidéo' : 'Vidéo (facultative)',
                'mapped' => false,
                'required' => false,
                'help' => 'MP4 de préférence (ou WebM, MOV d\'iPhone), 720p conseillé. Taille maximale acceptée par le serveur : '.$limite.'.',
                'attr' => ['accept' => 'video/mp4,video/webm,video/quicktime'],
                'constraints' => [new Assert\File(maxSize: '500M', mimeTypes: array_keys(FichiersVitrine::VIDEOS), mimeTypesMessage: 'Vidéo au format MP4, WebM ou MOV uniquement.')],
            ])
            ->add('apercuFichier', FileType::class, [
                'label' => $t->getApercu() ? "Remplacer l'image d'aperçu" : "Image d'aperçu (facultative)",
                'mapped' => false,
                'required' => false,
                'help' => 'Affichée avant la lecture de la vidéo (JPEG, PNG ou WebP).',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [new Assert\File(maxSize: '8M', mimeTypes: array_keys(FichiersVitrine::IMAGES), mimeTypesMessage: 'Image JPEG, PNG ou WebP uniquement.')],
            ])
            ->add('ordre', IntegerType::class, ['label' => "Ordre d'affichage", 'required' => false, 'empty_data' => '0'])
            ->add('publie', CheckboxType::class, ['label' => 'Publié sur le site', 'required' => false]);
        if ($t->getVideo()) {
            $builder->add('retirerVideo', CheckboxType::class, ['label' => 'Retirer la vidéo (garder seulement le texte)', 'mapped' => false, 'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TemoignageClient::class]);
    }
}
