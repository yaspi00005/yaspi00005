<?php

namespace App\Controller;

use App\Entity\Appel;
use App\Entity\Camion;
use App\Entity\User;
use App\Form\AppelType;
use App\Repository\AppelRepository;
use App\Repository\CamionRepository;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\CodePostal;
use App\Service\DocumentService;
use App\Service\Doublons;
use App\Service\ExportExcel;
use App\Service\RegistreAppels;
use App\Util\Csv;
use App\Util\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\String\u;

/** Registre des appels des clients et suivi logistique (camions, chauffeurs, conteneurs). */
#[Route('/appels')]
#[IsGranted(User::ROLE_RAMASSAGE)]
class AppelController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    public function __construct(
        private readonly RegistreAppels $registre,
        private readonly IdChiffreur $chiffreur,
        private readonly AppelRepository $appels,
    ) {
    }

    #[Route('', name: 'appel_index', methods: ['GET', 'POST'])]
    public function index(Request $request, AppelRepository $appels, CamionRepository $camions, ExpeditionRepository $expeditions, ExportExcel $excel, UserRepository $utilisateurs): Response
    {
        $appel = (new Appel())->setUtilisateur($this->getUser());
        $form = $this->createForm(AppelType::class, $appel, ['action' => $this->generateUrl('appel_index')]);
        if ($reponse = $this->traiter($request, $form, $appel, 'Appel de '.$appel->getNomComplet().' enregistré.')) {
            return $reponse;
        }

        $filtres = [
            'q' => $request->query->getString('q'),
            'vue' => \array_key_exists($v = $request->query->getString('vue'), AppelRepository::VUES) ? $v : 'tous',
            'camion' => $this->chiffreur->decodeOptionnel($request->query->get('camion')),
            'chauffeur' => $this->chiffreur->decodeOptionnel($request->query->get('chauffeur')),
            'expedition' => $this->chiffreur->decodeOptionnel($request->query->get('expedition')),
            'du' => $request->query->getString('du'),
            'au' => $request->query->getString('au'),
            'utilisateur' => $this->chiffreur->decodeOptionnel($request->query->get('utilisateur')),
            'source' => $request->query->getString('source'),
            'tri' => \array_key_exists($t = $request->query->getString('tri'), AppelRepository::TRIS) ? $t : '',
            'sens' => 'desc' === $request->query->getString('sens') ? 'desc' : 'asc',
        ];
        $qb = $appels->filtreQb($filtres);

        if ('csv' === $request->query->get('export')) {
            return $excel->reponse('registre-appels-'.date('Y-m-d').'.xlsx', 'Registre des appels',
                ['Date appel', 'Date conteneur', 'Camion', 'Ordre de passage', 'Nom', 'Téléphone', 'Code postal', 'Ville', 'Adresse', 'Estimation', 'Utilisateur', 'Confirmé', 'État', 'Commentaire'],
                (function () use ($qb) {
                    foreach ($qb->getQuery()->toIterable() as $a) {
                        /* @var Appel $a */
                        yield [
                            $a->getDateAppel()->format('d/m/Y'), $a->getDateConteneurPrevue()?->format('d/m/Y'), $a->getCamion()?->getNom(), $a->getOrdreTournee(),
                            $a->getNomComplet(), $a->getTelephone(), $a->getCodePostal(), $a->getVille(), $a->getAdresse(),
                            null !== $a->getEstimation() ? Csv::montant($a->getEstimation()) : null, $a->getUtilisateur()?->getPrenom(),
                            $a->isConfirme() ? 'Oui' : 'Non', $a->getEtat()[0], $a->getCommentaire(),
                        ];
                    }
                })(),
            );
        }

        $donnees = [
            'appels' => Pagination::depuisRequete($qb, $request, 50),
            // Compteurs : appels en cours (hors archives), avec les mêmes filtres sauf l'onglet.
            'compteurs' => $appels->compteurs($appels->filtreQb(['vue' => 'archives' === $filtres['vue'] ? 'archives' : 'en_cours'] + $filtres)),
            'filtres' => $filtres,
            'synchro' => new \DateTimeImmutable(),
        ];
        // Rafraîchissement automatique : seulement le tableau et les compteurs.
        if ($request->isXmlHttpRequest()) {
            return $this->render('appel/_liste.html.twig', $donnees);
        }

        $camionsActifs = $camions->actifs();

        return $this->render('appel/index.html.twig', $donnees + [
            'form' => $form,
            'vues' => AppelRepository::VUES,
            'camions' => $camionsActifs,
            'chauffeurs' => array_values(array_unique(array_filter(array_map(fn ($c) => $c->getChauffeur(), $camionsActifs)), \SORT_REGULAR)),
            'expeditions' => $expeditions->findBy(['statut' => \App\Enum\StatutExpedition::EnPreparation], ['dateDepart' => 'ASC']),
            'utilisateurs' => $utilisateurs->findBy(['actif' => true], ['prenom' => 'ASC']),
        ]);
    }

    /** Client déjà connu pour ce téléphone : pré-remplit le formulaire. */
    #[Route('/client.json', name: 'appel_client', methods: ['GET'])]
    public function client(Request $request, Doublons $doublons, ColisRepository $colis): JsonResponse
    {
        $client = $doublons->clientParTelephone($request->query->getString('telephone'));
        if (!$client) {
            return $this->json(['client' => null]);
        }

        return $this->json(['client' => [
            'id' => $client->getId(),
            'nom' => $client->getNom(), 'prenom' => $client->getPrenom(), 'codePostal' => $client->getCodePostal(),
            'ville' => $client->getVille(), 'adresse' => $client->getAdresse(),
            'colis' => $colis->count(['client' => $client]),
            'fiche' => $this->generateUrl('client_show', ['id' => $client->getId()]),
        ]]);
    }

    /** Ville saisie → codes postaux possibles (France). */
    #[Route('/codes-postaux.json', name: 'appel_codes_postaux', methods: ['GET'])]
    public function codesPostaux(Request $request, CodePostal $codePostal): JsonResponse
    {
        return $this->json(['communes' => $codePostal->codesPostaux($request->query->getString('ville'))]);
    }

    /** Liste des clients affectés à un camion (appels en cours), en PDF, pour la tournée. */
    #[Route('/camion/{id}.pdf', name: 'appel_camion_pdf', requirements: ['id' => self::ID], methods: ['GET'])]
    public function pdfCamion(Camion $camion, DocumentService $documents): Response
    {
        $pdf = $documents->pdf('pdf/camion_appels.html.twig', [
            'camion' => $camion,
            'appels' => $this->appels->enCoursDuCamion($camion->getId()),
            'genereLe' => new \DateTimeImmutable(),
        ], 'landscape');

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, 'clients-camion-'.u($camion->getNom())->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-').'.pdf'),
        ]);
    }

    #[Route('/{id}', name: 'appel_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function edit(Request $request, Appel $appel): Response
    {
        $form = $this->createForm(AppelType::class, $appel);
        if ($reponse = $this->traiter($request, $form, $appel, 'Appel de '.$appel->getNomComplet().' mis à jour.')) {
            return $reponse;
        }

        return $this->render('appel/edit.html.twig', ['form' => $form, 'appel' => $appel]);
    }

    /** Case « Confirmé » du tableau : bascule sans ouvrir la fiche (le ramassage suit). */
    #[Route('/{id}/confirmer', name: 'appel_confirmer', requirements: ['id' => self::ID], methods: ['POST'])]
    public function confirmer(Request $request, Appel $appel): Response
    {
        if ($this->isCsrfTokenValid('confirmer-appel-'.$appel->getId(), $request->getPayload()->getString('_token'))) {
            $appel->setConfirme(!$appel->isConfirme());
            $this->registre->enregistrer($appel, $appel->getRamassage()?->getDatePrevue(), $this->getUser());
            $this->addFlash('success', $appel->getNomComplet().' : '.($appel->isConfirme() ? 'confirmé.' : 'remis en attente.'));
        }

        return $this->redirect($this->retour($request));
    }

    /** Ordre de passage du camion : ce client avant ou après le précédent / le suivant. */
    #[Route('/{id}/deplacer', name: 'appel_deplacer', requirements: ['id' => self::ID], methods: ['POST'])]
    public function deplacer(Request $request, Appel $appel): Response
    {
        if ($this->isCsrfTokenValid('deplacer-appel-'.$appel->getId(), $request->getPayload()->getString('_token'))) {
            $this->registre->deplacer($appel, 'haut' === $request->getPayload()->getString('sens'));
        }

        return $this->redirect($this->retour($request));
    }

    #[Route('/{id}/supprimer', name: 'appel_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(Request $request, Appel $appel, EntityManagerInterface $em): Response
    {
        // L'administrateur, ou la personne qui a pris l'appel.
        if (!$this->isGranted(User::ROLE_ADMIN) && $appel->getUtilisateur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isCsrfTokenValid('supprimer-appel-'.$appel->getId(), $request->getPayload()->getString('_token'))) {
            $ramassage = $appel->getRamassage();
            if ($ramassage && $ramassage->getStatut()->estAFaire()) {
                $em->remove($ramassage); // rendez-vous pas encore fait : retiré du planning
            }
            $em->remove($appel);
            $em->flush();
            $this->addFlash('success', 'Appel supprimé.');
        }

        return $this->redirectToRoute('appel_index', [], Response::HTTP_SEE_OTHER);
    }

    private function traiter(Request $request, FormInterface $form, Appel $appel, string $message): ?Response
    {
        $form->handleRequest($request);
        // Un même numéro ne peut figurer qu'une fois parmi les appels en cours.
        if ($form->isSubmitted() && ($existant = $this->appels->enCoursAuTelephone($appel->getTelephone(), $appel->getId()))) {
            $form->get('telephone')->addError(new FormError(\sprintf('Ce numéro est déjà dans le registre : appel de %s du %s%s. Modifiez cet appel plutôt que d\'en créer un nouveau.',
                $existant->getNomComplet(), $existant->getDateAppel()->format('d/m/Y'), $existant->getCamion() ? ' (camion '.$existant->getCamion()->getNom().')' : '')));
            $this->addFlash('danger', 'Numéro déjà présent dans le registre : '.$existant->getNomComplet().' ('.$existant->getTelephone().').');
        }
        if (!$form->isSubmitted() || !$form->isValid()) {
            return null;
        }
        $date = $form->get('dateRamassage')->getData();
        $this->registre->enregistrer($appel, $date, $this->getUser());
        $this->addFlash('success', $message.match (true) {
            null !== $date => ' Ramassage au planning le '.$date->format('d/m/Y à H:i').'.',
            $appel->isConfirme() => ' À planifier : ajoutez la date du ramassage quand elle est fixée.',
            default => '',
        });

        return $this->redirectToRoute('appel_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Retour à la liste filtrée d'où vient l'action (jamais vers un autre site). */
    private function retour(Request $request): string
    {
        $retour = $request->getPayload()->getString('retour');

        return str_starts_with($retour, '/appels') && !str_starts_with($retour, '//') ? $retour : $this->generateUrl('appel_index');
    }
}
