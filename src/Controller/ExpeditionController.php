<?php

namespace App\Controller;

use App\Entity\Colis;
use App\Entity\DepenseExpedition;
use App\Entity\Expedition;
use App\Entity\User;
use App\Enum\Destination;
use App\Enum\StatutColis;
use App\Enum\StatutExpedition;
use App\Form\DepenseExpeditionType;
use App\Form\ExpeditionType;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\PaiementRepository;
use App\Security\IdChiffreur;
use App\Service\Corbeille;
use App\Service\DocumentService;
use App\Service\ExpeditionService;
use App\Service\ExportExcel;
use App\Service\PaiementService;
use App\Util\Csv;
use App\Util\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/expeditions')]
class ExpeditionController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    #[Route('', name: 'expedition_index', methods: ['GET'])]
    public function index(Request $request, ExpeditionRepository $expeditions): Response
    {
        $filtres = [
            'statut' => $request->query->getString('statut'),
            'destination' => $request->query->getString('destination'),
            'du' => $request->query->getString('du'),
            'au' => $request->query->getString('au'),
            'q' => $request->query->getString('q'),
        ];

        return $this->render('expedition/index.html.twig', [
            'expeditions' => Pagination::depuisRequete($expeditions->filtreQb($this->getUser(), $filtres), $request, 20),
            'filtres' => $filtres,
            'statuts' => StatutExpedition::cases(),
            'destinations' => Destination::cases(),
        ]);
    }

    /** Bénéfices : chiffre d'affaires, charges et marge de chaque expédition. */
    #[Route('/rentabilite', name: 'expedition_rentabilite', methods: ['GET'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function rentabilite(Request $request, ExpeditionRepository $expeditions, ExportExcel $excel): Response
    {
        $annee = $request->query->getInt('annee', (int) date('Y'));
        $lignes = $expeditions->rentabilite(new \DateTimeImmutable("$annee-01-01"), new \DateTimeImmutable("$annee-12-31"));
        $totaux = ['ca' => 0, 'encaisse' => 0, 'depenses' => 0, 'marge' => 0, 'nbColis' => 0];
        foreach ($lignes as $l) {
            foreach ($totaux as $k => $v) {
                $totaux[$k] += $l[$k];
            }
        }

        if ('csv' === $request->query->get('export')) {
            return $excel->reponse('rentabilite-expeditions-'.$annee.'.xlsx', 'Rentabilité des expéditions '.$annee,
                ['Expédition', 'Destination', 'Transport', 'Départ', 'Statut', 'Colis', "Chiffre d'affaires", 'Encaissé', 'Charges', 'Marge', 'Marge (%)'],
                array_map(fn ($l) => [
                    $l['expedition']->getNumero(), $l['expedition']->getDestination()->label(), $l['expedition']->getModeTransport()->label(),
                    $l['expedition']->getDateDepart()->format('d/m/Y'), $l['expedition']->getStatut()->label(), $l['nbColis'],
                    Csv::montant($l['ca']), Csv::montant($l['encaisse']), Csv::montant($l['depenses']), Csv::montant($l['marge']),
                    null !== $l['taux'] ? str_replace('.', ',', (string) $l['taux']) : '',
                ], $lignes),
                'Année '.$annee,
            );
        }

        return $this->render('expedition/rentabilite.html.twig', [
            'lignes' => $lignes,
            'totaux' => $totaux,
            'tauxGlobal' => $totaux['ca'] > 0 ? round($totaux['marge'] / $totaux['ca'] * 100, 1) : null,
            'annee' => $annee,
            'annees' => range((int) date('Y'), 2024),
        ]);
    }

    /**
     * Colis de l'expédition visibles par l'utilisateur, filtrés et triés (liste et manifeste Excel).
     *
     * @return array{0: list<Colis>, 1: array{q: string, statut: string, paiement: string, tri: string, sens: string}}
     */
    private function colisFiltres(Request $request, Expedition $expedition): array
    {
        $f = [
            'q' => trim($request->query->getString('q')),
            'statut' => StatutColis::tryFrom($request->query->getString('statut'))?->value ?? '',
            'paiement' => \in_array($p = $request->query->getString('paiement'), ['solde', 'reste'], true) ? $p : '',
            'tri' => \in_array($t = $request->query->getString('tri'), self::TRIS, true) ? $t : 'numero',
            'sens' => 'desc' === $request->query->getString('sens') ? 'desc' : 'asc',
        ];
        $q = mb_strtolower($f['q']);
        $chiffres = preg_replace('/\D/', '', $f['q']);

        $liste = array_values(array_filter($expedition->getColis()->toArray(), function (Colis $c) use ($f, $q, $chiffres) {
            if (!$this->isGranted('COLIS_VOIR', $c)) {
                return false;
            }
            if ($f['statut'] && $c->getStatut()->value !== $f['statut']) {
                return false;
            }
            if (('solde' === $f['paiement'] && !$c->isSolde()) || ('reste' === $f['paiement'] && $c->isSolde())) {
                return false;
            }
            if ('' === $q) {
                return true;
            }
            $texte = mb_strtolower($c->getNumeroSuivi().' '.$c->getClient()->getNomComplet().' '.$c->getDestinataireNom());
            $tels = preg_replace('/\D/', '', $c->getClient()->getTelephone().' '.$c->getDestinataireTelephone());

            return str_contains($texte, $q) || (\strlen($chiffres) >= 4 && str_contains($tels, $chiffres));
        }));

        $valeur = fn (Colis $c) => match ($f['tri']) {
            'client' => mb_strtolower($c->getClient()->getNomComplet()),
            'destinataire' => mb_strtolower((string) $c->getDestinataireNom()),
            'nombre' => $c->getNombreArticles(),
            'montant' => $c->getMontantNet(),
            'reste' => $c->getMontantRestant(),
            'statut' => array_search($c->getStatut(), StatutColis::cases(), true),
            'date' => $c->getDateReception()->getTimestamp(),
            default => $c->getNumeroSuivi(),
        };
        usort($liste, fn (Colis $a, Colis $b) => ('desc' === $f['sens'] ? -1 : 1) * ($valeur($a) <=> $valeur($b) ?: strcmp($a->getNumeroSuivi(), $b->getNumeroSuivi())));

        return [$liste, $f];
    }

    #[Route('/nouvelle', name: 'expedition_new', methods: ['GET', 'POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function new(Request $request, ExpeditionService $service): Response
    {
        $expedition = (new Expedition())->setDateDepart(new \DateTimeImmutable('today'));
        $form = $this->createForm(ExpeditionType::class, $expedition);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $service->creer($expedition);
            $this->addFlash('success', 'Expédition '.$expedition->getNumero().' créée.');

            return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('expedition/form.html.twig', ['form' => $form, 'expedition' => $expedition]);
    }

    /** Tris proposés sur la liste des colis de l'expédition : clé → valeur comparée. */
    private const TRIS = ['numero', 'client', 'destinataire', 'nombre', 'montant', 'reste', 'statut', 'date'];

    #[Route('/{id}', name: 'expedition_show', requirements: ['id' => self::ID], methods: ['GET'])]
    public function show(Request $request, Expedition $expedition, ColisRepository $colisRepository, PaiementRepository $paiements, ExportExcel $excel, DocumentService $documents): Response
    {
        $this->verifierAcces($expedition);
        [$liste, $filtres] = $this->colisFiltres($request, $expedition);

        if ('pdf' === $request->query->get('export')) {
            // Liste imprimable des colis (filtres et tri de la page).
            $criteres = array_filter([
                $filtres['q'] ? 'recherche « '.$filtres['q'].' »' : null,
                $filtres['statut'] ? StatutColis::from($filtres['statut'])->label() : null,
                ['solde' => 'soldés', 'reste' => 'reste à payer'][$filtres['paiement']] ?? null,
            ]);

            return new Response($documents->pdf('pdf/colis_expedition.html.twig', [
                'expedition' => $expedition,
                'colis' => $liste,
                'criteres' => implode(' · ', $criteres),
                'genereLe' => new \DateTimeImmutable(),
            ], 'landscape'), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="colis-'.$expedition->getNumero().'.pdf"',
            ]);
        }

        if ('csv' === $request->query->get('export')) {
            // Les codes de retrait ne sortent que pour les administrateurs : au guichet, le représentant
            // retrouve le colis avec le code présenté par le destinataire, il n'a pas à les connaître d'avance.
            $avecCodes = $this->isGranted(User::ROLE_ADMIN);

            return $excel->reponse('manifeste-'.$expedition->getNumero().'.xlsx', 'Manifeste '.$expedition->getNumero(),
                ['N° suivi', 'Client', 'Tél. client', 'Destinataire', 'Tél. destinataire', 'Nb colis', 'Tarif net', 'Payé', 'Reste', 'Statut', ...($avecCodes ? ['Code retrait'] : [])],
                array_map(fn (Colis $c) => [
                    $c->getNumeroSuivi(), $c->getClient()->getNomComplet(), $c->getClient()->getTelephone(),
                    $c->getDestinataireNom(), $c->getDestinataireTelephone(), $c->getNombreArticles(),
                    Csv::montant($c->getMontantNet()), Csv::montant($c->getMontantPaye()), Csv::montant($c->getMontantRestant()),
                    $c->getStatut()->label(), ...($avecCodes ? [$c->getCodeRetrait()] : []),
                ], $liste),
                $expedition->getDestination()->label().' · '.$expedition->getModeTransport()->label().' · départ le '.$expedition->getDateDepart()->format('d/m/Y'),
            );
        }

        $colisSansExpedition = [];
        if ($this->isGranted(User::ROLE_ADMIN) && $expedition->getStatut()->accepteNouveauxColis()) {
            $colisSansExpedition = $colisRepository->createQueryBuilder('c')
                ->addSelect('cl')->join('c.client', 'cl')
                ->andWhere('c.expedition IS NULL')
                ->orderBy('c.dateReception', 'ASC')
                ->getQuery()->getResult();
        }

        $filtrePaiements = $paiements->filtreQb(null, ['expedition' => $expedition->getId()]);

        return $this->render('expedition/show.html.twig', [
            'expedition' => $expedition,
            'colisListe' => $liste,
            'filtres' => $filtres,
            'statutsColis' => StatutColis::cases(),
            'parStatut' => $colisRepository->compterParStatut(null, $expedition),
            'clients' => \count(array_unique($expedition->getColis()->map(fn (Colis $c) => $c->getClient()->getId())->toArray())),
            'parCaissier' => $paiements->totauxParCaissier($filtrePaiements),
            'statuts' => StatutExpedition::cases(),
            'colisSansExpedition' => $colisSansExpedition,
            'formDepense' => $this->isGranted(User::ROLE_ADMIN)
                ? $this->createForm(DepenseExpeditionType::class, new DepenseExpedition(), ['action' => $this->generateUrl('expedition_depense_new', ['id' => $expedition->getId()])])->createView()
                : null,
        ]);
    }

    #[Route('/{id}/modifier', name: 'expedition_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function edit(Request $request, Expedition $expedition, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ExpeditionType::class, $expedition);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Expédition mise à jour.');

            return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('expedition/form.html.twig', ['form' => $form, 'expedition' => $expedition]);
    }

    #[Route('/{id}/statut', name: 'expedition_statut', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function statut(Request $request, Expedition $expedition, ExpeditionService $service): Response
    {
        $this->verifierCsrf($request, 'statut-expedition-'.$expedition->getId());
        $statut = StatutExpedition::tryFrom($request->getPayload()->getString('statut'));
        if (!$statut) {
            $this->addFlash('danger', 'Statut invalide.');
        } else {
            $n = $service->changerStatut($expedition, $statut, $this->getUser(), $request->getPayload()->getBoolean('notifier', true));
            $this->addFlash('success', \sprintf('Expédition « %s » — %d colis mis à jour.', $statut->label(), $n));
        }

        return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/affecter', name: 'expedition_affecter', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function affecter(Request $request, Expedition $expedition, ColisRepository $colisRepository, EntityManagerInterface $em, IdChiffreur $chiffreur): Response
    {
        $this->verifierCsrf($request, 'affecter-expedition-'.$expedition->getId());
        if (!$expedition->getStatut()->accepteNouveauxColis()) {
            $this->addFlash('danger', 'Cette expédition est déjà partie.');

            return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()]);
        }
        $ids = array_values(array_filter(array_map(fn ($v) => $chiffreur->decodeOptionnel($v), $request->getPayload()->all('colis'))));
        $n = 0;
        foreach ($ids ? $colisRepository->findBy(['id' => $ids, 'expedition' => null]) : [] as $colis) {
            $colis->setExpedition($expedition);
            ++$n;
        }
        $em->flush();
        $this->addFlash('success', $n.' colis ajouté(s) à l\'expédition.');

        return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/retirer/{colis}', name: 'expedition_retirer', requirements: ['id' => self::ID, 'colis' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function retirer(Request $request, Expedition $expedition, Colis $colis, EntityManagerInterface $em): Response
    {
        $this->verifierCsrf($request, 'retirer-colis-'.$colis->getId());
        if ($colis->getExpedition() === $expedition && $expedition->getStatut()->accepteNouveauxColis()) {
            $colis->setExpedition(null);
            $em->flush();
            $this->addFlash('success', 'Colis '.$colis->getNumeroSuivi().' retiré de l\'expédition.');
        }

        return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Bouton « Publier sur le site » / « Retirer » : annonce du départ sur sotrama.net. */
    #[Route('/{id}/publier', name: 'expedition_publier', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function publier(Request $request, Expedition $expedition, EntityManagerInterface $em): Response
    {
        $this->verifierCsrf($request, 'publier-expedition-'.$expedition->getId());
        $expedition->setPublieeSurSite(!$expedition->isPublieeSurSite());
        $em->flush();
        $this->addFlash('success', $expedition->isPublieeSurSite()
            ? 'Départ annoncé sur sotrama.net'.($expedition->isAnnonceVisible() ? '.' : ' (visible quand la date de départ est à venir et l\'expédition en préparation).')
            : 'Annonce retirée du site.');

        return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/depenses', name: 'expedition_depense_new', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function ajouterDepense(Request $request, Expedition $expedition, EntityManagerInterface $em): Response
    {
        $depense = (new DepenseExpedition())->setAuteur($this->getUser());
        $form = $this->createForm(DepenseExpeditionType::class, $depense);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $expedition->addDepense($depense);
            $em->persist($depense);
            $em->flush();
            $this->addFlash('success', 'Dépense de '.PaiementService::format($depense->getMontant()).' € ajoutée.');
        } else {
            foreach ($form->getErrors(true) as $erreur) {
                $this->addFlash('danger', $erreur->getMessage());
            }
        }

        return $this->redirect($this->generateUrl('expedition_show', ['id' => $expedition->getId()]).'#rentabilite', Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/depenses/{depense}/supprimer', name: 'expedition_depense_delete', requirements: ['id' => self::ID, 'depense' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function supprimerDepense(Request $request, Expedition $expedition, DepenseExpedition $depense, EntityManagerInterface $em): Response
    {
        $this->verifierCsrf($request, 'supprimer-depense-'.$depense->getId());
        if ($depense->getExpedition() === $expedition) {
            $em->remove($depense);
            $em->flush();
            $this->addFlash('success', 'Dépense supprimée.');
        }

        return $this->redirect($this->generateUrl('expedition_show', ['id' => $expedition->getId()]).'#rentabilite', Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/supprimer', name: 'expedition_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function delete(Request $request, Expedition $expedition, EntityManagerInterface $em, Corbeille $corbeille): Response
    {
        $this->verifierCsrf($request, 'delete-expedition-'.$expedition->getId());
        if (!$expedition->getColis()->isEmpty()) {
            $this->addFlash('danger', 'Retirez d\'abord les colis de cette expédition.');

            return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()]);
        }
        // Colis de la corbeille encore rattachés : détachés pour pouvoir supprimer l'expédition.
        $corbeille->avecCorbeille(function () use ($em, $expedition) {
            foreach ($em->getRepository(Colis::class)->findBy(['expedition' => $expedition]) as $colis) {
                $colis->setExpedition(null);
            }
        });
        $em->remove($expedition);
        $em->flush();
        $this->addFlash('success', 'Expédition supprimée.');

        return $this->redirectToRoute('expedition_index', [], Response::HTTP_SEE_OTHER);
    }

    private function verifierAcces(Expedition $expedition): void
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isGranted(User::ROLE_ADMIN) && !$this->isGranted(User::ROLE_RAMASSAGE)
            && $user->getPays() !== $expedition->getDestination()) {
            throw $this->createAccessDeniedException('Cette expédition ne concerne pas votre pays.');
        }
    }

    private function verifierCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide, rechargez la page.');
        }
    }
}
