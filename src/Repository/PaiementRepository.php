<?php

namespace App\Repository;

use App\Entity\Expedition;
use App\Entity\Paiement;
use App\Entity\User;
use App\Security\Perimetre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Paiement>
 */
class PaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paiement::class);
    }

    /**
     * @param array{du?: ?string, au?: ?string, mode?: ?string, caissier?: ?int, expedition?: ?int} $filtres
     */
    public function filtreQb(?User $user, array $filtres = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 'cl', 'u')
            ->join('p.colis', 'c')
            ->join('c.client', 'cl')
            ->leftJoin('p.caissier', 'u')
            ->leftJoin('c.expedition', 'e')
            ->orderBy('p.date', 'DESC');

        Perimetre::appliquer($qb, $user, 'c');
        if (!empty($filtres['du'])) {
            $qb->andWhere('p.date >= :du')->setParameter('du', new \DateTimeImmutable($filtres['du'].' 00:00:00'));
        }
        if (!empty($filtres['au'])) {
            $qb->andWhere('p.date <= :au')->setParameter('au', new \DateTimeImmutable($filtres['au'].' 23:59:59'));
        }
        if (!empty($filtres['mode'])) {
            $qb->andWhere('p.mode = :mode')->setParameter('mode', $filtres['mode']);
        }
        if (!empty($filtres['caissier'])) {
            $qb->andWhere('u.id = :caissier')->setParameter('caissier', (int) $filtres['caissier']);
        }
        if (!empty($filtres['expedition'])) {
            $qb->andWhere('e.id = :exp')->setParameter('exp', (int) $filtres['expedition']);
        }

        return $qb;
    }

    /** @return list<array{caissier: string, total: int, remises: int, nb: int}> */
    public function totauxParCaissier(QueryBuilder $filtre): array
    {
        $qb = clone $filtre;
        $qb->resetDQLPart('select')->resetDQLPart('orderBy')
            ->select("COALESCE(CONCAT(u.prenom, ' ', u.nom), '—') AS caissier, SUM(p.montant) AS total, SUM(p.remise) AS remises, COUNT(p.id) AS nb")
            ->groupBy('u.id, u.prenom, u.nom')
            ->orderBy('total', 'DESC');

        return array_map(fn ($r) => [
            'caissier' => $r['caissier'],
            'total' => (int) $r['total'],
            'remises' => (int) $r['remises'],
            'nb' => (int) $r['nb'],
        ], $qb->getQuery()->getArrayResult());
    }

    /** @return array<string, int> total par mode de paiement */
    public function totauxParMode(QueryBuilder $filtre): array
    {
        $qb = clone $filtre;
        $qb->resetDQLPart('select')->resetDQLPart('orderBy')
            ->select('p.mode AS mode, SUM(p.montant) AS total')
            ->groupBy('p.mode');
        $res = [];
        foreach ($qb->getQuery()->getArrayResult() as $r) {
            $mode = \is_object($r['mode']) ? $r['mode']->value : $r['mode'];
            $res[$mode] = (int) $r['total'];
        }

        return $res;
    }

    /** @return array<string, int> encaissements par jour sur N jours */
    public function encaissementsParJour(int $jours, ?User $user = null, ?User $caissier = null): array
    {
        $debut = new \DateTimeImmutable('-'.($jours - 1).' days 00:00');
        $qb = $this->filtreQb($user, ['du' => $debut->format('Y-m-d'), 'caissier' => $caissier?->getId()]);
        $res = [];
        for ($i = 0; $i < $jours; ++$i) {
            $res[$debut->modify("+$i days")->format('Y-m-d')] = 0;
        }
        foreach ($qb->getQuery()->getResult() as $p) {
            $k = $p->getDate()->format('Y-m-d');
            if (isset($res[$k])) {
                $res[$k] += $p->getMontant();
            }
        }

        return $res;
    }

    public function totalPourExpedition(Expedition $expedition): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.montant), 0)')
            ->join('p.colis', 'c')
            ->andWhere('c.expedition = :e')->setParameter('e', $expedition)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array{montant: int, nb: int} encaissé par un caissier depuis une date */
    public function totalCaissier(User $caissier, \DateTimeImmutable $depuis): array
    {
        $r = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.montant), 0) AS montant, COUNT(p.id) AS nb')
            ->andWhere('p.caissier = :caissier AND p.date >= :depuis')
            ->setParameter('caissier', $caissier)->setParameter('depuis', $depuis)
            ->getQuery()->getSingleResult();

        return ['montant' => (int) $r['montant'], 'nb' => (int) $r['nb']];
    }
}
