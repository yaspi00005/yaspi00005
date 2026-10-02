<?php

namespace App\Controller\Admin;

use App\Entity\Nouveaute;
use App\Entity\Partenaire;
use App\Entity\PhotoSite;
use App\Entity\TemoignageClient;
use App\Entity\User;
use App\Form\NouveauteType;
use App\Form\PartenaireType;
use App\Form\PhotoSiteType;
use App\Form\TemoignageClientType;
use App\Repository\NouveauteRepository;
use App\Repository\PartenaireRepository;
use App\Repository\PhotoSiteRepository;
use App\Repository\TemoignageClientRepository;
use App\Service\FichiersVitrine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Administration du site sotrama.net : nouveautés, témoignages des clients, partenaires (logos), photos « En images ». */
#[Route('/admin/site')]
#[IsGranted(User::ROLE_ADMIN)]
class SiteController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FichiersVitrine $fichiers,
    ) {
    }

    #[Route('', name: 'admin_site_index', methods: ['GET'])]
    public function index(NouveauteRepository $nouveautes, TemoignageClientRepository $temoignages, PartenaireRepository $partenaires, PhotoSiteRepository $photos): Response
    {
        return $this->render('admin/site/index.html.twig', [
            'nouveautes' => $nouveautes->ordonnes(),
            'photos' => $photos->ordonnees(),
            'max_galerie' => PhotoSiteRepository::MAX_SITE,
            'temoignages' => $temoignages->ordonnes(),
            'partenaires' => $partenaires->ordonnes(),
        ]);
    }

    // ---------- Nouveautés ----------

    #[Route('/nouveautes/nouvelle', name: 'admin_site_nouveaute_new', methods: ['GET', 'POST'])]
    public function nouvelleNouveaute(Request $request): Response
    {
        return $this->formNouveaute($request, new Nouveaute());
    }

    #[Route('/nouveautes/{id}', name: 'admin_site_nouveaute_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function modifierNouveaute(Request $request, Nouveaute $nouveaute): Response
    {
        return $this->formNouveaute($request, $nouveaute);
    }

    #[Route('/nouveautes/{id}/supprimer', name: 'admin_site_nouveaute_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function supprimerNouveaute(Request $request, Nouveaute $nouveaute): Response
    {
        return $this->supprimer($request, $nouveaute, 'Annonce « '.$nouveaute->getTitre().' » supprimée.');
    }

    private function formNouveaute(Request $request, Nouveaute $nouveaute): Response
    {
        $form = $this->createForm(NouveauteType::class, $nouveaute)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->enregistrer($nouveaute, 'Annonce « '.$nouveaute->getTitre().' » enregistrée.');
        }

        return $this->render('admin/site/form.html.twig', ['form' => $form, 'titre' => $nouveaute->getId() ? 'Modifier l\'annonce' : 'Nouvelle annonce', 'element' => $nouveaute, 'suppression' => $nouveaute->getId() ? 'admin_site_nouveaute_delete' : null]);
    }

    // ---------- Témoignages ----------

    #[Route('/temoignages/nouveau', name: 'admin_site_temoignage_new', methods: ['GET', 'POST'])]
    public function nouveauTemoignage(Request $request): Response
    {
        return $this->formTemoignage($request, new TemoignageClient());
    }

    #[Route('/temoignages/{id}', name: 'admin_site_temoignage_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function modifierTemoignage(Request $request, TemoignageClient $temoignage): Response
    {
        return $this->formTemoignage($request, $temoignage);
    }

    #[Route('/temoignages/{id}/supprimer', name: 'admin_site_temoignage_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function supprimerTemoignage(Request $request, TemoignageClient $temoignage): Response
    {
        $fichiers = [$temoignage->getVideo(), $temoignage->getApercu()];
        $reponse = $this->supprimer($request, $temoignage, 'Témoignage de '.$temoignage->getNom().' supprimé.');
        if (!$this->em->contains($temoignage)) {
            array_map($this->fichiers->supprimer(...), $fichiers);
        }

        return $reponse;
    }

    private function formTemoignage(Request $request, TemoignageClient $temoignage): Response
    {
        $form = $this->createForm(TemoignageClientType::class, $temoignage)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->has('retirerVideo') && $form->get('retirerVideo')->getData()) {
                $this->fichiers->supprimer($temoignage->getVideo());
                $temoignage->setVideo(null);
            }
            $this->remplacer($form, 'videoFichier', 'temoignages', FichiersVitrine::VIDEOS, $temoignage->getVideo(), $temoignage->setVideo(...));
            $this->remplacer($form, 'apercuFichier', 'temoignages', FichiersVitrine::IMAGES, $temoignage->getApercu(), $temoignage->setApercu(...));
            if (!$temoignage->getVideo() && !$temoignage->getTexte()) {
                $this->addFlash('warning', 'Ajoutez une vidéo ou le texte du témoignage.');

                return $this->render('admin/site/form.html.twig', ['form' => $form, 'titre' => 'Témoignage', 'element' => $temoignage, 'suppression' => null], new Response(null, 422));
            }

            return $this->enregistrer($temoignage, 'Témoignage de '.$temoignage->getNom().' enregistré.');
        }

        return $this->render('admin/site/form.html.twig', ['form' => $form, 'titre' => $temoignage->getId() ? 'Modifier le témoignage' : 'Nouveau témoignage', 'element' => $temoignage, 'suppression' => $temoignage->getId() ? 'admin_site_temoignage_delete' : null]);
    }

    // ---------- Partenaires ----------

    #[Route('/partenaires/nouveau', name: 'admin_site_partenaire_new', methods: ['GET', 'POST'])]
    public function nouveauPartenaire(Request $request): Response
    {
        return $this->formPartenaire($request, new Partenaire());
    }

    #[Route('/partenaires/{id}', name: 'admin_site_partenaire_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function modifierPartenaire(Request $request, Partenaire $partenaire): Response
    {
        return $this->formPartenaire($request, $partenaire);
    }

    #[Route('/partenaires/{id}/supprimer', name: 'admin_site_partenaire_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function supprimerPartenaire(Request $request, Partenaire $partenaire): Response
    {
        $logo = $partenaire->getLogo();
        $reponse = $this->supprimer($request, $partenaire, 'Partenaire « '.$partenaire->getNom().' » supprimé.');
        if (!$this->em->contains($partenaire)) {
            $this->fichiers->supprimer($logo);
        }

        return $reponse;
    }

    private function formPartenaire(Request $request, Partenaire $partenaire): Response
    {
        $form = $this->createForm(PartenaireType::class, $partenaire)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->remplacer($form, 'logoFichier', 'partenaires', FichiersVitrine::IMAGES, $partenaire->getLogo(), $partenaire->setLogo(...));

            return $this->enregistrer($partenaire, 'Partenaire « '.$partenaire->getNom().' » enregistré.');
        }

        return $this->render('admin/site/form.html.twig', ['form' => $form, 'titre' => $partenaire->getId() ? 'Modifier le partenaire' : 'Nouveau partenaire', 'element' => $partenaire, 'suppression' => $partenaire->getId() ? 'admin_site_partenaire_delete' : null]);
    }

    // ---------- Photos « En images » ----------

    #[Route('/photos/nouvelles', name: 'admin_site_photo_new', methods: ['GET', 'POST'])]
    public function nouvellesPhotos(Request $request): Response
    {
        $modele = new PhotoSite();
        $form = $this->createForm(PhotoSiteType::class, $modele)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Une photo par fichier, avec la catégorie, la légende et l'ordre choisis.
            $fichiers = array_filter((array) $form->get('photosFichiers')->getData(), fn ($f) => $f instanceof UploadedFile);
            foreach ($fichiers as $fichier) {
                $photo = (new PhotoSite())->setCategorie($modele->getCategorie())->setLegende($modele->getLegende())
                    ->setOrdre($modele->getOrdre())->setPublie($modele->isPublie())
                    ->setPhoto($this->fichiers->enregistrer($fichier, 'galerie', FichiersVitrine::IMAGES));
                $this->em->persist($photo);
            }
            $this->em->flush();
            $this->addFlash('success', 1 === \count($fichiers) ? 'Photo ajoutée.' : \count($fichiers).' photos ajoutées.');

            return $this->redirectToRoute('admin_site_index', ['_fragment' => 'photos'], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/site/form.html.twig', ['form' => $form, 'titre' => 'Ajouter des photos', 'element' => $modele, 'suppression' => null]);
    }

    #[Route('/photos/{id}', name: 'admin_site_photo_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function modifierPhoto(Request $request, PhotoSite $photo): Response
    {
        $form = $this->createForm(PhotoSiteType::class, $photo)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->remplacer($form, 'photoFichier', 'galerie', FichiersVitrine::IMAGES, $photo->getPhoto(), $photo->setPhoto(...));

            return $this->enregistrer($photo, 'Photo enregistrée.');
        }

        return $this->render('admin/site/form.html.twig', ['form' => $form, 'titre' => 'Modifier la photo', 'element' => $photo, 'suppression' => 'admin_site_photo_delete']);
    }

    #[Route('/photos/{id}/supprimer', name: 'admin_site_photo_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function supprimerPhoto(Request $request, PhotoSite $photo): Response
    {
        $fichier = $photo->getPhoto();
        $reponse = $this->supprimer($request, $photo, 'Photo supprimée.');
        if (!$this->em->contains($photo)) {
            $this->fichiers->supprimer($fichier);
        }

        return $reponse;
    }

    // ---------- Commun ----------

    /** Nouveau fichier envoyé : il remplace l'ancien (supprimé du serveur). */
    private function remplacer(FormInterface $form, string $champ, string $dossier, array $types, ?string $ancien, callable $affecter): void
    {
        $fichier = $form->get($champ)->getData();
        if ($fichier instanceof UploadedFile) {
            $affecter($this->fichiers->enregistrer($fichier, $dossier, $types));
            $this->fichiers->supprimer($ancien);
        }
    }

    private function enregistrer(object $element, string $message): Response
    {
        $this->em->persist($element);
        $this->em->flush();
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_site_index', [], Response::HTTP_SEE_OTHER);
    }

    private function supprimer(Request $request, object $element, string $message): Response
    {
        if ($this->isCsrfTokenValid('supprimer-site-'.$element->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($element);
            $this->em->flush();
            $this->addFlash('success', $message);
        }

        return $this->redirectToRoute('admin_site_index', [], Response::HTTP_SEE_OTHER);
    }
}
