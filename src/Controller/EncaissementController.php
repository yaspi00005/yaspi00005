<?php

namespace App\Controller;

use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\ModePaiement;
use App\Repository\HistoriqueStatutRepository;
use App\Repository\PaiementRepository;
use App\Util\Csv;
use App\Util\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** « Mes encaissements » : les paiements enregistrés par l'utilisateur connecté (représentant en agence). */
#[IsGranted(User::ROLE_REPRESENTANT)]
class EncaissementController extends AbstractController
{
    #[Route('/encaissements', name: 'encaissement_index', methods: ['GET'])]
    public function index(Request $request, PaiementRepository $paiements, HistoriqueStatutRepository $historiques): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $filtres = [
            'du' => $request->query->getString('du', (new \DateTimeImmutable('first day of this month'))->format('Y-m-d')),
            'au' => $request->query->getString('au'),
            'mode' => $request->query->getString('mode'),
            'caissier' => $user->getId(),
        ];
        $qb = $paiements->filtreQb(null, $filtres);

        if ('csv' === $request->query->get('export')) {
            return Csv::reponse('mes-encaissements-'.date('Y-m-d').'.csv',
                ['Date', 'Colis', 'Client', 'Destinataire', 'Montant', 'Remise', 'Mode', 'Référence', 'Commentaire'],
                (function () use ($qb) {
                    foreach ($qb->getQuery()->toIterable() as $p) {
                        /* @var Paiement $p */
                        yield [
                            $p->getDate()->format('d/m/Y H:i'), $p->getColis()->getNumeroSuivi(), $p->getColis()->getClient()->getNomComplet(),
                            $p->getColis()->getDestinataireNom(), Csv::montant($p->getMontant()), Csv::montant($p->getRemise()),
                            $p->getMode()->label(), $p->getReference(), $p->getCommentaire(),
                        ];
                    }
                })(),
            );
        }

        $parMode = $paiements->totauxParMode($qb);
        $debutMois = new \DateTimeImmutable('first day of this month 00:00');

        return $this->render('encaissement/index.html.twig', [
            'paiements' => Pagination::depuis($qb, $request->query->get('page', 1), 30),
            'filtres' => $filtres,
            'parMode' => $parMode,
            'total' => array_sum($parMode),
            'modes' => ModePaiement::cases(),
            'aujourdhui' => $paiements->totalCaissier($user, new \DateTimeImmutable('today')),
            'semaine' => $paiements->totalCaissier($user, new \DateTimeImmutable('monday this week 00:00')),
            'mois' => $paiements->totalCaissier($user, $debutMois),
            'remisesMois' => $historiques->compterRemisesPar($user, $debutMois),
        ]);
    }
}
