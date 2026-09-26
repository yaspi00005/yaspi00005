<?php

namespace App\Form;

use App\Entity\Client;
use App\Service\CodePostal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientType extends AbstractType
{

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom'])
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'attr' => ['placeholder' => '06 12 34 56 78']])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false, 'help' => 'Pour recevoir le suivi du colis par e-mail.'])
            ->add('adresse', TextareaType::class, ['label' => 'Adresse', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('pays', ChoiceType::class, [
                'label' => 'Pays',
                'choices' => array_combine($pays = [...array_keys(CodePostal::PAYS), 'Mali', 'Sénégal'], $pays),
                'attr' => ['class' => 'js-cp-pays'],
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'attr' => ['class' => 'js-cp-code', 'autocomplete' => 'postal-code', 'inputmode' => 'numeric'],
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'help' => 'Remplie automatiquement à partir du code postal.',
                'attr' => ['class' => 'js-cp-ville', 'list' => 'villes-cp', 'autocomplete' => 'address-level2'],
            ])
            ->add('notes', TextareaType::class, ['label' => 'Notes internes', 'required' => false, 'attr' => ['rows' => 2]])
            // Position GPS : remplie par le téléphone (« Ma position ») ou à partir de l'adresse.
            ->add('latitude', HiddenType::class, ['error_bubbling' => false, 'attr' => ['class' => 'js-geo-lat']])
            ->add('longitude', HiddenType::class, ['error_bubbling' => false, 'attr' => ['class' => 'js-geo-lng']])
            ->add('positionSource', HiddenType::class, ['error_bubbling' => false, 'attr' => ['class' => 'js-geo-source']]);

        $coordonnee = new CallbackTransformer(
            fn (?float $v) => null === $v ? '' : (string) $v,
            fn (?string $v) => null === $v || '' === trim($v) || !is_numeric($v = str_replace(',', '.', trim($v))) ? null : (float) $v,
        );
        $builder->get('latitude')->addModelTransformer($coordonnee);
        $builder->get('longitude')->addModelTransformer($coordonnee);

        // Position incomplète (une seule coordonnée) : on l'efface plutôt que d'enregistrer un point faux.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $client = $event->getData();
            if ($client instanceof Client && !$client->hasPosition()) {
                $client->positionner(null, null, null);
            } elseif ($client instanceof Client && !$client->getPositionSource()) {
                $client->setPositionSource(Client::POSITION_ADRESSE);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Client::class]);
    }
}
