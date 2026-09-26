<?php

namespace App\Controller;

use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\ModePaiement;
use App\Repository\ExpeditionRepository;
use App\Repository\PaiementRepository;
use App\Repository\UserRepository;
use App\Service\PaiementService;
use App\Util\Csv;
use App\Util\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/paiements')]
class PaiementController extends AbstractController
{
    #[Route('', name: 'paiement_index', methods: ['GET'])]
    public function index(Request $request, PaiementRepository $paiements, UserRepository $users, ExpeditionRepository $expeditions): Response
    {
        $filtres = [
            'du' => $request->query->getString('du', (new \DateTimeImmutable('first day of this month'))->format('Y-m-d')),
            'au' => $request->query->getString('au'),
            'mode' => $request->query->getString('mode'),
            'caissier' => $request->query->getInt('caissier') ?: null,
            'expedition' => $request->query->getInt('expedition') ?: null,
        ];
        $qb = $paiements->filtreQb($this->getUser(), $filtres);

        if ('csv' === $request->query->get('export')) {
            return Csv::reponse('paiements-'.date('Y-m-d').'.csv',
                ['Date', 'Colis', 'Client', 'Montant', 'Remise', 'Mode', 'Référence', 'Caissier', 'Commentaire'],
                (function () use ($qb) {
                    foreach ($qb->getQuery()->toIterable() as $p) {
                        /* @var Paiement $p */
                        yield [
                            $p->getDate()->format('d/m/Y H:i'), $p->getColis()->getNumeroSuivi(), $p->getColis()->getClient()->getNomComplet(),
                            Csv::montant($p->getMontant()), Csv::montant($p->getRemise()), $p->getMode()->label(),
                            $p->getReference(), $p->getCaissier()?->getNomComplet(), $p->getCommentaire(),
                        ];
                    }
                })(),
            );
        }

        $parMode = $paiements->totauxParMode($qb);

        return $this->render('paiement/index.html.twig', [
            'paiements' => Pagination::depuis($qb, $request->query->get('page', 1), 30),
            'filtres' => $filtres,
            'parCaissier' => $paiements->totauxParCaissier($qb),
            'parMode' => $parMode,
            'total' => array_sum($parMode),
            'modes' => ModePaiement::cases(),
            'caissiers' => $users->findBy([], ['prenom' => 'ASC']),
            'expeditions' => $expeditions->filtreQb($this->getUser())->setMaxResults(50)->getQuery()->getResult(),
        ]);
    }

    #[Route('/{id}/annuler', name: 'paiement_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function annuler(Request $request, Paiement $paiement, PaiementService $service): Response
    {
        if (!$this->isCsrfTokenValid('annuler-paiement-'.$paiement->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $colis = $paiement->getColis();
        $service->annuler($paiement, $this->getUser());
        $this->addFlash('success', 'Paiement annulé.');

        return $this->redirectToRoute('colis_show', ['id' => $colis->getId()], Response::HTTP_SEE_OTHER);
    }
}
