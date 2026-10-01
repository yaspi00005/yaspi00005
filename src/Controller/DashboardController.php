<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\StatutColis;
use App\Repository\ClientRepository;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\PaiementRepository;
use App\Repository\RamassageRepository;
use App\Repository\TraficVitrineRepository;
use App\Security\Perimetre;
use App\Util\DateFr;
use App\Vitrine\CompteurTrafic;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_accueil')]
    public function index(
        ColisRepository $colisRepository,
        ClientRepository $clientRepository,
        ExpeditionRepository $expeditionRepository,
        PaiementRepository $paiementRepository,
        RamassageRepository $ramassageRepository,
        TraficVitrineRepository $traficRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $parStatut = $colisRepository->compterParStatut($user);
        $totaux = $colisRepository->totaux($user);
        $totauxMois = $colisRepository->totaux($user, new \DateTimeImmutable('first day of this month 00:00'));
        $colisParMois = $colisRepository->colisParMois(12, $user);
        // Représentant : ses propres encaissements ; administrateur : tous.
        $encaissements = match (true) {
            $this->isGranted(User::ROLE_ADMIN) => $paiementRepository->encaissementsParJour(30),
            Perimetre::estRepresentant($user) => $paiementRepository->encaissementsParJour(30, null, $user),
            default => [],
        };

        // Administrateur : rentabilité des expéditions parties cette année.
        $rentabilite = null;
        if ($this->isGranted(User::ROLE_ADMIN)) {
            $lignes = $expeditionRepository->rentabilite(new \DateTimeImmutable('first day of january this year'));
            $rentabilite = ['ca' => 0, 'depenses' => 0, 'marge' => 0, 'lignes' => \array_slice($lignes, 0, 5)];
            foreach ($lignes as $l) {
                $rentabilite['ca'] += $l['ca'];
                $rentabilite['depenses'] += $l['depenses'];
                $rentabilite['marge'] += $l['marge'];
            }
            $rentabilite['taux'] = $rentabilite['ca'] > 0 ? round($rentabilite['marge'] / $rentabilite['ca'] * 100, 1) : null;
        }

        // Administrateur : fréquentation du site sotrama.net sur 30 jours (mesure anonyme, voir CompteurTrafic).
        $trafic = null;
        if ($this->isGranted(User::ROLE_ADMIN)) {
            $frequentation = $traficRepository->totaux(new \DateTimeImmutable('today -29 days'));
            $serie = $traficRepository->parJour('visiteurs', 'total', 30);
            $trafic = [
                'visiteurs' => $frequentation['visiteurs']['total'] ?? 0,
                'precedent' => $traficRepository->totaux(new \DateTimeImmutable('today -59 days'), new \DateTimeImmutable('today -29 days'))['visiteurs']['total'] ?? 0,
                'pagesVues' => array_sum($frequentation['page'] ?? []),
                'pages' => $frequentation['page'] ?? [],
                'sources' => $frequentation['source'] ?? [],
                'appareils' => $frequentation['appareil'] ?? [],
                'actions' => $frequentation['action'] ?? [],
                'libellesPages' => CompteurTrafic::PAGES,
                'libellesActions' => CompteurTrafic::ACTIONS,
                'chart' => [
                    'labels' => array_map(fn ($k) => (new \DateTimeImmutable($k))->format('d/m'), array_keys($serie)),
                    'data' => array_values($serie),
                ],
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'trafic' => $trafic,
            'rentabilite' => $rentabilite,
            'parStatut' => $parStatut,
            'totalColis' => array_sum($parStatut),
            'totaux' => $totaux,
            'totauxMois' => $totauxMois,
            'resteAEncaisser' => max(0, $totaux['frais'] - $totaux['remise'] - $totaux['paye']),
            // Les représentants ne voient que les colis de leur pays : pas le nombre total de clients.
            'totalClients' => $this->isGranted(User::ROLE_ADMIN) ? $clientRepository->count([]) : null,
            // Performance personnelle : agents de ramassage et administrateurs (qui enregistrent aussi des colis).
            'performance' => $this->isGranted(User::ROLE_RAMASSAGE) ? $colisRepository->performance($user) : null,
            'performanceAgents' => $this->isGranted(User::ROLE_ADMIN) ? $colisRepository->performanceAgents(new \DateTimeImmutable('first day of this month 00:00')) : [],
            'estRepresentant' => Perimetre::estRepresentant($user),
            'mesEncaissementsMois' => $paiementRepository->totalCaissier($user, new \DateTimeImmutable('first day of this month 00:00')),
            'estRamassage' => Perimetre::estRamassage($user),
            'expeditions' => $this->isGranted(User::ROLE_ADMIN) ? $expeditionRepository->filtreQb($user)->setMaxResults(5)->getQuery()->getResult() : [],
            'derniersColis' => $colisRepository->derniers($user, 8),
            'enAttenteRetrait' => \count($colisRepository->enAttenteRetrait($user, 7)),
            'ramassages' => $this->isGranted(User::ROLE_RAMASSAGE) ? $ramassageRepository->aVenir(5) : [],
            'chartMois' => [
                'labels' => array_map(fn ($k) => DateFr::moisCourt(new \DateTimeImmutable($k.'-01')), array_keys($colisParMois)),
                'data' => array_values($colisParMois),
            ],
            'chartEncaissements' => [
                'labels' => array_map(fn ($k) => (new \DateTimeImmutable($k))->format('d/m'), array_keys($encaissements)),
                'data' => array_map(fn ($v) => round($v / 100, 2), array_values($encaissements)),
            ],
            'statuts' => StatutColis::cases(),
        ]);
    }
}
