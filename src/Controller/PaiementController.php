<?php

namespace App\Controller;

use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\ModePaiement;
use App\Repository\ExpeditionRepository;
use App\Repository\PaiementRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\ExportExcel;
use App\Service\PaiementService;
use App\Util\Csv;
use App\Util\Pagination;
use App\Util\Retour;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/paiements')]
class PaiementController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    #[Route('', name: 'paiement_index', methods: ['GET'])]
    public function index(Request $request, PaiementRepository $paiements, UserRepository $users, ExpeditionRepository $expeditions, IdChiffreur $chiffreur, ExportExcel $excel): Response
    {
        $filtres = [
            'du' => $request->query->getString('du', (new \DateTimeImmutable('first day of this month'))->format('Y-m-d')),
            'au' => $request->query->getString('au'),
            'mode' => $request->query->getString('mode'),
            'caissier' => $chiffreur->decodeOptionnel($request->query->get('caissier')),
            'expedition' => $chiffreur->decodeOptionnel($request->query->get('expedition')),
        ];
        $qb = $paiements->filtreQb($this->getUser(), $filtres);

        if ('csv' === $request->query->get('export')) {
            return $excel->reponse('paiements-'.date('Y-m-d').'.xlsx', 'Paiements',
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
                ExportExcel::periode($filtres['du'], $filtres['au']),
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

    #[Route('/{id}/annuler', name: 'paiement_annuler', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function annuler(Request $request, Paiement $paiement, PaiementService $service): Response
    {
        if (!$this->isCsrfTokenValid('annuler-paiement-'.$paiement->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $colis = $paiement->getColis();
        $service->annuler($paiement, $this->getUser());
        $this->addFlash('success', 'Paiement annulé.');

        return $this->redirectToRoute('colis_show', ['id' => $colis->getId(), 'retour' => Retour::depuis($request)], Response::HTTP_SEE_OTHER);
    }
}
