<?php

namespace App\Repository;

use App\Entity\TraficVitrine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TraficVitrine>
 */
class TraficVitrineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TraficVitrine::class);
    }

    /**
     * Totaux par type et clé sur la période, du plus grand au plus petit.
     *
     * @return array<string, array<string, int>> type → (clé → nombre)
     */
    public function totaux(\DateTimeImmutable $du, ?\DateTimeImmutable $au = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.type, t.cle, SUM(t.nombre) AS total')
            ->andWhere('t.jour >= :du')->setParameter('du', $du->setTime(0, 0))
            ->groupBy('t.type, t.cle')
            ->orderBy('total', 'DESC');
        if ($au) {
            $qb->andWhere('t.jour < :au')->setParameter('au', $au->setTime(0, 0));
        }
        $totaux = [];
        foreach ($qb->getQuery()->getArrayResult() as $l) {
            $totaux[$l['type']][$l['cle']] = (int) $l['total'];
        }

        return $totaux;
    }

    /** @return array<string, int> 'Y-m-d' → nombre, un élément par jour (0 si aucune visite) */
    public function parJour(string $type, string $cle, int $jours): array
    {
        $serie = [];
        for ($i = $jours - 1; $i >= 0; --$i) {
            $serie[(new \DateTimeImmutable("today -$i days"))->format('Y-m-d')] = 0;
        }
        $lignes = $this->createQueryBuilder('t')
            ->select('t.jour, t.nombre')
            ->andWhere('t.type = :type AND t.cle = :cle AND t.jour >= :du')
            ->setParameter('type', $type)->setParameter('cle', $cle)
            ->setParameter('du', new \DateTimeImmutable('today -'.($jours - 1).' days'))
            ->getQuery()->getArrayResult();
        foreach ($lignes as $l) {
            $serie[$l['jour']->format('Y-m-d')] = $l['nombre'];
        }

        return $serie;
    }
}
