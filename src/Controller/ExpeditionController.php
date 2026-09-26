<?php

namespace App\Controller;

use App\Entity\Colis;
use App\Entity\Expedition;
use App\Entity\User;
use App\Enum\Destination;
use App\Enum\StatutExpedition;
use App\Form\ExpeditionType;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\PaiementRepository;
use App\Security\IdChiffreur;
use App\Service\ExpeditionService;
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
            'expeditions' => Pagination::depuis($expeditions->filtreQb($this->getUser(), $filtres), $request->query->get('page', 1), 20),
            'filtres' => $filtres,
            'statuts' => StatutExpedition::cases(),
            'destinations' => Destination::cases(),
        ]);
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

    #[Route('/{id}', name: 'expedition_show', requirements: ['id' => self::ID], methods: ['GET'])]
    public function show(Request $request, Expedition $expedition, ColisRepository $colisRepository, PaiementRepository $paiements): Response
    {
        $this->verifierAcces($expedition);

        if ('csv' === $request->query->get('export')) {
            return Csv::reponse('manifeste-'.$expedition->getNumero().'.csv',
                ['N° suivi', 'Client', 'Tél. client', 'Destinataire', 'Tél. destinataire', 'Articles', 'Poids (kg)', 'Tarif net', 'Payé', 'Reste', 'Statut', 'Code retrait'],
                $expedition->getColis()->map(fn (Colis $c) => [
                    $c->getNumeroSuivi(), $c->getClient()->getNomComplet(), $c->getClient()->getTelephone(),
                    $c->getDestinataireNom(), $c->getDestinataireTelephone(), $c->getNombreArticles(), $c->getPoidsTotal(),
                    Csv::montant($c->getMontantNet()), Csv::montant($c->getMontantPaye()), Csv::montant($c->getMontantRestant()),
                    $c->getStatut()->label(), $c->getCodeRetrait(),
                ])->toArray(),
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
            'parStatut' => $colisRepository->compterParStatut(null, $expedition),
            'clients' => \count(array_unique($expedition->getColis()->map(fn (Colis $c) => $c->getClient()->getId())->toArray())),
            'parCaissier' => $paiements->totauxParCaissier($filtrePaiements),
            'statuts' => StatutExpedition::cases(),
            'colisSansExpedition' => $colisSansExpedition,
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

    #[Route('/{id}/supprimer', name: 'expedition_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function delete(Request $request, Expedition $expedition, EntityManagerInterface $em): Response
    {
        $this->verifierCsrf($request, 'delete-expedition-'.$expedition->getId());
        if (!$expedition->getColis()->isEmpty()) {
            $this->addFlash('danger', 'Retirez d\'abord les colis de cette expédition.');

            return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()]);
        }
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
