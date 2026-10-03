<?php

namespace App\Controller;

use App\Entity\Colis;
use App\Entity\User;
use App\Enum\ModePaiement;
use App\Enum\StatutColis;
use App\Enum\StatutPaiement;
use App\Form\ColisType;
use App\Form\EncaissementType;
use App\Repository\ClientRepository;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\JournalRepository;
use App\Repository\TypeMarchandiseRepository;
use App\Security\ColisVoter;
use App\Security\IdChiffreur;
use App\Security\Perimetre;
use App\Service\BonusFidelite;
use App\Service\ColisService;
use App\Service\DocumentService;
use App\Service\Doublons;
use App\Service\ExportExcel;
use App\Service\PaiementService;
use App\Service\PhotoUploader;
use App\Service\RecuPartage;
use App\Util\Csv;
use App\Util\Pagination;
use App\Util\Retour;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/colis')]
class ColisController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    public function __construct(
        private readonly ColisService $colisService,
        private readonly PaiementService $paiementService,
        private readonly PhotoUploader $photos,
        private readonly IdChiffreur $chiffreur,
    ) {
    }

    #[Route('', name: 'colis_index', methods: ['GET'])]
    public function index(Request $request, ColisRepository $colisRepository, ExpeditionRepository $expeditions, ExportExcel $excel): Response
    {
        $filtres = [
            'q' => $request->query->getString('q'),
            'statut' => $request->query->getString('statut'),
            'paiement' => $request->query->getString('paiement'),
            'expedition' => $this->chiffreur->decodeOptionnel($request->query->get('expedition')),
            'du' => $request->query->getString('du'),
            'au' => $request->query->getString('au'),
        ];
        // Liste allégée : sans recherche ni filtre, les colis déjà récupérés par le destinataire sont masqués
        // (l'export Excel, lui, reste complet).
        $recherche = [] !== array_filter($filtres);
        $masquerRecuperes = !$recherche && !$request->query->getBoolean('tous') && 'csv' !== $request->query->get('export');
        $qb = $colisRepository->filtreQb($this->getUser(), $filtres + ['sansRecuperes' => $masquerRecuperes]);

        if ('csv' === $request->query->get('export')) {
            return $excel->reponse('colis-'.date('Y-m-d').'.xlsx', 'Liste des colis',
                ['N° suivi', 'Date', 'Ajouté par', 'Client', 'Téléphone client', 'Destinataire', 'Tél. destinataire', 'Expédition', 'Articles', 'Poids (kg)', 'Tarif', 'Remise', 'Payé', 'Reste', 'Statut', 'Paiement'],
                (function () use ($qb) {
                    foreach ($qb->getQuery()->toIterable() as $c) {
                        /* @var Colis $c */
                        yield [
                            $c->getNumeroSuivi(), $c->getDateReception()->format('d/m/Y H:i'), $c->getAgent()?->getNomComplet(),
                            $c->getClient()->getNomComplet(), $c->getClient()->getTelephone(),
                            $c->getDestinataireNom(), $c->getDestinataireTelephone(),
                            $c->getExpedition()?->getNumero(), $c->getNombreArticles(), $c->getPoidsTotal(),
                            Csv::montant($c->getFrais()), Csv::montant($c->getRemise()), Csv::montant($c->getMontantPaye()), Csv::montant($c->getMontantRestant()),
                            $c->getStatut()->label(), $c->getStatutPaiement()->label(),
                        ];
                    }
                })(),
                ExportExcel::periode($filtres['du'], $filtres['au']),
            );
        }

        return $this->render('colis/index.html.twig', [
            'colis' => Pagination::depuisRequete($qb, $request),
            'filtres' => $filtres,
            // Un agent de ramassage ne voit que ses propres colis : la colonne « Ajouté par » serait toujours lui.
            'agentSeul' => Perimetre::estRamassage($this->getUser()),
            'recuperesMasques' => $masquerRecuperes
                ? $colisRepository->filtreQb($this->getUser(), ['statut' => StatutColis::Recupere->value])->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult()
                : 0,
            'statuts' => StatutColis::cases(),
            'statutsPaiement' => StatutPaiement::cases(),
            'expeditions' => $expeditions->filtreQb($this->getUser())->setMaxResults(50)->getQuery()->getResult(),
        ]);
    }

    /** Vérification avant l'envoi du formulaire : colis semblables (même expéditeur, même destinataire, 3 derniers jours). */
    #[Route('/doublons.json', name: 'colis_doublons', methods: ['GET'])]
    #[IsGranted(User::ROLE_RAMASSAGE)]
    public function doublons(Request $request, ClientRepository $clients, Doublons $doublons): JsonResponse
    {
        $client = ($id = $request->query->getInt('client')) ? $clients->find($id) : null;

        return $this->json(array_map(fn (Colis $c) => [
            'numero' => $c->getNumeroSuivi(),
            'date' => $c->getDateReception()->format('d/m/Y à H:i'),
            'destinataire' => $c->getDestinataireNom(),
            'agent' => $c->getAgent()?->getNomComplet(),
        ], $doublons->colisSemblables($client, $request->query->getString('tel'))));
    }

    #[Route('/nouveau', name: 'colis_new', methods: ['GET', 'POST'])]
    #[IsGranted(User::ROLE_RAMASSAGE)]
    public function new(Request $request, ClientRepository $clients, ColisRepository $colisRepository, TypeMarchandiseRepository $types, Doublons $doublons): Response
    {
        $colis = new Colis();
        if (($clientId = $this->chiffreur->decodeOptionnel($request->query->get('client'))) && $client = $clients->find($clientId)) {
            $colis->setClient($client);
        }
        $form = $this->createForm(ColisType::class, $colis);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Anti double-clic : un même formulaire ne peut créer qu'un seul colis.
            $nonce = $request->request->getString('_nonce');
            $session = $request->getSession();
            $deja = $session->get('colis_nonces', []);
            if ($nonce && isset($deja[$nonce]) && $existant = $colisRepository->find($deja[$nonce])) {
                $this->addFlash('warning', 'Ce colis a déjà été enregistré.');

                return $this->redirectToRoute('colis_show', ['id' => $existant->getId()], Response::HTTP_SEE_OTHER);
            }

            if ($form->isValid()) {
                // Colis semblable enregistré ces derniers jours (double saisie ?) : confirmation demandée.
                // Le formulaire le vérifie aussi avant l'envoi (doublons.json) pour ne pas perdre les photos.
                if (!$request->request->getBoolean('confirmer_doublon')
                    && $semblables = $doublons->colisSemblables($colis->getClient(), $colis->getDestinataireTelephone())) {
                    return $this->renderForm($form, $colis, $types, $nonce, $semblables);
                }
                try {
                    $this->enregistrerPhotos($form);
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('danger', $e->getMessage());

                    return $this->renderForm($form, $colis, $types, $nonce);
                }
                $this->colisService->creer($colis, $this->getUser());
                if ($nonce) {
                    $session->set('colis_nonces', \array_slice([$nonce => $colis->getId()] + $deja, 0, 20, true));
                }
                $this->addFlash('success', 'Colis '.$colis->getNumeroSuivi().' enregistré. Code de retrait : '.$colis->getCodeRetrait());

                return $this->redirectToRoute('colis_show', ['id' => $colis->getId(), 'nouveau' => 1], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderForm($form, $colis, $types, bin2hex(random_bytes(8)));
    }

    #[Route('/{id}', name: 'colis_show', requirements: ['id' => self::ID], methods: ['GET'])]
    public function show(Request $request, Colis $colis, DocumentService $documents, BonusFidelite $bonus, RecuPartage $partage, JournalRepository $journal): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::VOIR, $colis);

        $encaissement = null;
        if ($this->isGranted(ColisVoter::ENCAISSER, $colis) && !$colis->isSolde()) {
            $encaissement = $this->createForm(EncaissementType::class, ['montant' => $colis->getMontantRestant(), 'remise' => 0, 'mode' => ModePaiement::Especes], [
                'action' => $this->generateUrl('colis_encaisser', ['id' => $colis->getId()]),
            ]);
        }

        return $this->render('colis/show.html.twig', [
            'colis' => $colis,
            'qr' => $documents->qrCodeColis($colis),
            'encaissement' => $encaissement,
            'bonusDisponible' => min($bonus->disponiblePour($colis), $colis->getMontantRestant()),
            'bonusGagne' => $bonus->gainDuColis($colis)?->getMontant() ?? 0,
            'statuts' => array_filter(StatutColis::cases(), fn (StatutColis $s) => StatutColis::Recupere !== $s),
            'whatsappExpediteur' => $partage->whatsappExpediteur($colis),
            'whatsappDestinataire' => $partage->whatsappDestinataire($colis),
            'lienRecuPublic' => $partage->lienPdf($colis),
            'journal' => $this->isGranted(User::ROLE_ADMIN) ? $journal->pourColis($colis->getId()) : null,
            'retour' => ($r = Retour::depuis($request)) ? ['url' => $r, 'libelle' => Retour::libelle($r)] : null,
        ]);
    }

    #[Route('/{id}/modifier', name: 'colis_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function edit(Request $request, Colis $colis, EntityManagerInterface $em, TypeMarchandiseRepository $types): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::MODIFIER, $colis);
        $anciennesPhotos = array_filter($colis->getArticles()->map(fn ($a) => $a->getPhoto())->toArray());

        $form = $this->createForm(ColisType::class, $colis);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->enregistrerPhotos($form);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->renderForm($form, $colis, $types, null);
            }
            $this->colisService->apresModification($colis);
            $restantes = array_filter($colis->getArticles()->map(fn ($a) => $a->getPhoto())->toArray());
            foreach (array_diff($anciennesPhotos, $restantes) as $photo) {
                $this->photos->supprimer($photo);
            }
            $this->addFlash('success', 'Colis mis à jour.');

            return $this->redirectToRoute('colis_show', ['id' => $colis->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm($form, $colis, $types, null);
    }

    #[Route('/{id}/supprimer', name: 'colis_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(Request $request, Colis $colis): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::MODIFIER, $colis);
        $this->verifierCsrf($request, 'delete-colis-'.$colis->getId());

        try {
            // Corbeille : photos conservées jusqu'à la suppression définitive (7 jours).
            $this->colisService->supprimer($colis, $this->getUser());
            $this->addFlash('success', \sprintf('Colis %s mis à la corbeille : vous pouvez le restaurer pendant %d jours.', $colis->getNumeroSuivi(), Colis::JOURS_CORBEILLE));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('colis_show', ['id' => $colis->getId()]);
        }

        return $this->redirectToRoute('colis_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/recu.pdf', name: 'colis_recu', requirements: ['id' => self::ID], methods: ['GET'])]
    public function recu(Colis $colis, DocumentService $documents): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::VOIR, $colis);

        return new Response($documents->recuPdf($colis, Perimetre::estRepresentant($this->getUser())), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="recu-'.$colis->getNumeroSuivi().'.pdf"',
        ]);
    }

    #[Route('/{id}/recu/email', name: 'colis_recu_email', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_RAMASSAGE)]
    public function recuEmail(Request $request, Colis $colis, RecuPartage $partage): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::VOIR, $colis);
        $this->verifierCsrf($request, 'recu-email-'.$colis->getId());
        try {
            $this->addFlash('success', 'Reçu envoyé par e-mail à '.$partage->envoyerEmail($colis).'.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->versFiche($colis, $request);
    }

    #[Route('/{id}/encaisser', name: 'colis_encaisser', requirements: ['id' => self::ID], methods: ['POST'])]
    public function encaisser(Request $request, Colis $colis): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::ENCAISSER, $colis);
        $form = $this->createForm(EncaissementType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            try {
                $this->paiementService->encaisser($colis, (int) $d['montant'], $d['mode'], $this->getUser(), (int) ($d['remise'] ?? 0), $d['reference'], $d['commentaire']);
                $this->addFlash('success', 'Paiement enregistré. '.($colis->isSolde() ? 'Le colis est soldé.' : 'Reste à payer : '.PaiementService::format($colis->getMontantRestant())));

                return $this->versFiche($colis, $request, ['paye' => 1]);
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->versFiche($colis, $request);
    }

    #[Route('/{id}/bonus', name: 'colis_bonus', requirements: ['id' => self::ID], methods: ['POST'])]
    public function bonus(Request $request, Colis $colis): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::ENCAISSER, $colis);
        $this->verifierCsrf($request, 'bonus-colis-'.$colis->getId());
        try {
            $montant = $this->paiementService->appliquerBonus($colis, $this->getUser());
            $this->addFlash('success', 'Bonus de '.PaiementService::format($montant).' appliqué.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->versFiche($colis, $request);
    }

    #[Route('/{id}/statut', name: 'colis_statut', requirements: ['id' => self::ID], methods: ['POST'])]
    public function statut(Request $request, Colis $colis): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::LIVRER, $colis);
        $this->verifierCsrf($request, 'statut-colis-'.$colis->getId());

        $statut = StatutColis::tryFrom($request->getPayload()->getString('statut'));
        if (!$statut) {
            $this->addFlash('danger', 'Statut invalide.');
        } else {
            try {
                if ($this->colisService->changerStatut($colis, $statut, $this->getUser(), $request->getPayload()->getString('commentaire') ?: null)) {
                    $this->addFlash('success', 'Statut : '.$statut->label().'.');
                }
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->versFiche($colis, $request);
    }

    #[Route('/{id}/remettre', name: 'colis_remettre', requirements: ['id' => self::ID], methods: ['POST'])]
    public function remettre(Request $request, Colis $colis, RateLimiterFactory $codeRetraitLimiter): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::LIVRER, $colis);
        $this->verifierCsrf($request, 'remettre-colis-'.$colis->getId());

        $admin = $this->isGranted(User::ROLE_ADMIN);
        $sansCode = $admin && $request->getPayload()->getBoolean('sansCode');
        // 5 codes faux par colis et par personne, puis blocage 15 minutes (pas d'essais au hasard).
        $essais = $codeRetraitLimiter->create($colis->getId().'-'.$this->getUser()?->getUserIdentifier());
        if (!$sansCode && 0 === $essais->consume(0)->getRemainingTokens()) {
            $this->addFlash('danger', 'Trop de codes erronés pour ce colis : réessayez dans 15 minutes.');

            return $this->versFiche($colis, $request);
        }
        try {
            $this->colisService->remettre($colis, $this->getUser(), $request->getPayload()->getString('recuperePar'),
                $admin && $request->getPayload()->getBoolean('forcer'), $request->getPayload()->getString('code'), $sansCode);
            $essais->reset();
            $this->addFlash('success', 'Colis remis à '.$colis->getRecupererPar().'.');
        } catch (\DomainException $e) {
            if (str_starts_with($e->getMessage(), 'Code de retrait')) {
                $essais->consume();
            }
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->versFiche($colis, $request);
    }

    #[Route('/{id}/tarif', name: 'colis_tarif', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function tarif(Request $request, Colis $colis): Response
    {
        $this->verifierCsrf($request, 'tarif-colis-'.$colis->getId());
        $valeur = str_replace([',', ' '], ['.', ''], $request->getPayload()->getString('frais'));
        if (!is_numeric($valeur)) {
            $this->addFlash('danger', 'Montant invalide.');
        } else {
            try {
                $this->paiementService->ajusterFrais($colis, (int) round((float) $valeur * 100));
                $this->addFlash('success', 'Tarif mis à jour.');
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->versFiche($colis, $request);
    }

    /** @param list<Colis> $semblables */
    private function renderForm(FormInterface $form, Colis $colis, TypeMarchandiseRepository $types, ?string $nonce, array $semblables = []): Response
    {
        return $this->render('colis/form.html.twig', [
            'form' => $form,
            'colis' => $colis,
            'types' => $types->findActifs(),
            'nonce' => $nonce,
            'semblables' => $semblables,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function enregistrerPhotos(FormInterface $form): void
    {
        if ($fichier = $form->get('photoFile')->getData()) {
            $colis = $form->getData();
            $ancienne = $colis->getPhoto();
            $colis->setPhoto($this->photos->upload($fichier));
            $this->photos->supprimer($ancienne);
        }
        foreach ($form->get('articles') as $articleForm) {
            $fichier = $articleForm->get('photoFile')->getData();
            if ($fichier) {
                $article = $articleForm->getData();
                $ancienne = $article->getPhoto();
                $article->setPhoto($this->photos->upload($fichier));
                $this->photos->supprimer($ancienne);
            }
        }
    }

    private function verifierCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide, rechargez la page.');
        }
    }

    /** Retour sur la fiche du colis en gardant la page d'origine (?retour=…). */
    private function versFiche(Colis $colis, Request $request, array $parametres = []): Response
    {
        return $this->redirectToRoute('colis_show', ['id' => $colis->getId(), 'retour' => Retour::depuis($request)] + $parametres, Response::HTTP_SEE_OTHER);
    }
}
