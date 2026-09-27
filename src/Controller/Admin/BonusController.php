<?php

namespace App\Controller\Admin;

use App\Entity\MouvementBonus;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\MouvementBonusRepository;
use App\Util\Csv;
use App\Util\DateFr;
use App\Util\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Suivi du bonus fidélité : gains, utilisations, soldes des clients. */
#[Route('/admin/bonus')]
#[IsGranted(User::ROLE_ADMIN)]
class BonusController extends AbstractController
{
    #[Route('', name: 'admin_bonus_index', methods: ['GET'])]
    public function index(Request $request, MouvementBonusRepository $mouvements, ClientRepository $clients): Response
    {
        $filtres = [
            'du' => $request->query->getString('du'),
            'au' => $request->query->getString('au'),
            'type' => \array_key_exists($t = $request->query->getString('type'), MouvementBonusRepository::TYPES) ? $t : '',
            'q' => $request->query->getString('q'),
        ];
        $qb = $mouvements->filtreQb($filtres);

        if ('csv' === $request->query->get('export')) {
            return Csv::reponse('bonus-'.date('Y-m-d').'.csv',
                ['Date', 'Client', 'Téléphone', 'Type', 'Libellé', 'Colis', 'Montant', 'Par'],
                (function () use ($qb) {
                    foreach ($qb->getQuery()->toIterable() as $m) {
                        /* @var MouvementBonus $m */
                        yield [
                            $m->getCreatedAt()->format('d/m/Y H:i'), $m->getClient()->getNomComplet(), $m->getClient()->getTelephone(),
                            MouvementBonusRepository::TYPES[MouvementBonusRepository::typeDe($m)], $m->getLibelle(),
                            $m->getColis()?->getNumeroSuivi(), Csv::montant($m->getMontant()), $m->getAuteur()?->getNomComplet() ?? 'Automatique',
                        ];
                    }
                })(),
            );
        }

        $parMois = $mouvements->parMois(12);

        return $this->render('admin/bonus/index.html.twig', [
            'mouvements' => Pagination::depuis($qb, $request->query->get('page', 1), 30),
            'filtres' => $filtres,
            'types' => MouvementBonusRepository::TYPES,
            'totaux' => $mouvements->totaux($qb),
            'global' => $clients->soldeBonusGlobal(),
            'meilleurs' => $clients->meilleursSoldes(10),
            'filtreActif' => (bool) array_filter($filtres),
            'chart' => [
                'labels' => array_map(fn ($k) => DateFr::moisCourt(new \DateTimeImmutable($k.'-01')), array_keys($parMois)),
                'gagne' => array_map(fn ($v) => round($v['gagne'] / 100, 2), array_values($parMois)),
                'utilise' => array_map(fn ($v) => round($v['utilise'] / 100, 2), array_values($parMois)),
            ],
        ]);
    }
}
