<?php

namespace App\Repository;

use App\Entity\MouvementBonus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MouvementBonus>
 */
class MouvementBonusRepository extends ServiceEntityRepository
{
    /** Types de mouvement proposés dans les filtres. */
    public const TYPES = [
        'gain' => 'Gagné sur un colis',
        'utilisation' => 'Utilisé sur un colis',
        'credit' => 'Crédit manuel / ajustement',
        'debit' => 'Débit manuel / ajustement',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MouvementBonus::class);
    }

    public static function typeDe(MouvementBonus $m): string
    {
        return match (true) {
            $m->isGainColis() => 'gain',
            $m->getMontant() < 0 && null !== $m->getColis() => 'utilisation',
            $m->getMontant() >= 0 => 'credit',
            default => 'debit',
        };
    }

    /**
     * @param array{du?: ?string, au?: ?string, type?: ?string, q?: ?string} $filtres
     */
    public function filtreQb(array $filtres = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('cl', 'c', 'a')
            ->join('m.client', 'cl')
            ->leftJoin('m.colis', 'c')
            ->leftJoin('m.auteur', 'a')
            ->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC');

        if (!empty($filtres['du'])) {
            $qb->andWhere('m.createdAt >= :du')->setParameter('du', new \DateTimeImmutable($filtres['du'].' 00:00:00'));
        }
        if (!empty($filtres['au'])) {
            $qb->andWhere('m.createdAt <= :au')->setParameter('au', new \DateTimeImmutable($filtres['au'].' 23:59:59'));
        }
        match ($filtres['type'] ?? null) {
            'gain' => $qb->andWhere('m.gainColis = true'),
            'utilisation' => $qb->andWhere('m.gainColis = false AND m.montant < 0 AND m.colis IS NOT NULL'),
            'credit' => $qb->andWhere('m.gainColis = false AND m.montant >= 0'),
            'debit' => $qb->andWhere('m.gainColis = false AND m.montant < 0 AND m.colis IS NULL'),
            default => null,
        };
        if (!empty($filtres['q'])) {
            $qb->andWhere('LOWER(cl.nom) LIKE :q OR LOWER(cl.prenom) LIKE :q OR cl.telephone LIKE :q OR LOWER(c.numeroSuivi) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($filtres['q'])).'%');
        }

        return $qb;
    }

    /** @return array{gagne: int, utilise: int, credits: int, debits: int} totaux (centimes) de la sélection */
    public function totaux(QueryBuilder $filtre): array
    {
        $qb = clone $filtre;
        $qb->resetDQLPart('select')->resetDQLPart('orderBy')->select(
            'COALESCE(SUM(CASE WHEN m.gainColis = true THEN m.montant ELSE 0 END), 0) AS gagne',
            'COALESCE(SUM(CASE WHEN m.gainColis = false AND m.montant < 0 AND m.colis IS NOT NULL THEN -m.montant ELSE 0 END), 0) AS utilise',
            'COALESCE(SUM(CASE WHEN m.gainColis = false AND m.montant >= 0 THEN m.montant ELSE 0 END), 0) AS credits',
            'COALESCE(SUM(CASE WHEN m.gainColis = false AND m.montant < 0 AND m.colis IS NULL THEN -m.montant ELSE 0 END), 0) AS debits',
        );
        $r = $qb->getQuery()->getSingleResult();

        return array_map('intval', $r);
    }

    /** @return array<string, array{gagne: int, utilise: int}> par mois (AAAA-MM) sur N mois */
    public function parMois(int $mois = 12): array
    {
        $debut = (new \DateTimeImmutable('first day of this month 00:00'))->modify('-'.($mois - 1).' months');
        $res = [];
        for ($i = 0; $i < $mois; ++$i) {
            $res[$debut->modify("+$i months")->format('Y-m')] = ['gagne' => 0, 'utilise' => 0];
        }
        $lignes = $this->createQueryBuilder('m')
            ->select('m.createdAt AS d, m.montant AS montant, m.gainColis AS gain, IDENTITY(m.colis) AS colis')
            ->andWhere('m.createdAt >= :debut')->setParameter('debut', $debut)
            ->getQuery()->getArrayResult();
        foreach ($lignes as $l) {
            $k = $l['d']->format('Y-m');
            if (!isset($res[$k])) {
                continue;
            }
            if ($l['gain']) {
                $res[$k]['gagne'] += (int) $l['montant'];
            } elseif ($l['montant'] < 0 && $l['colis']) {
                $res[$k]['utilise'] -= (int) $l['montant'];
            }
        }

        return $res;
    }
}
