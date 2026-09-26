<?php

namespace App\Repository;

use App\Entity\Ramassage;
use App\Enum\StatutRamassage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ramassage>
 */
class RamassageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ramassage::class);
    }

    /** @return Ramassage[] */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c')->join('r.client', 'c')
            ->andWhere('r.datePrevue >= :d AND r.datePrevue < :f')
            ->setParameter('d', $debut)->setParameter('f', $fin)
            ->orderBy('r.datePrevue', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return Ramassage[] ramassages filtrés par statut et période (bornes incluses), triés par date */
    public function pourExport(?StatutRamassage $statut, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('c', 'a')->join('r.client', 'c')->leftJoin('r.agent', 'a')
            ->orderBy('r.datePrevue', 'ASC');
        if ($statut) {
            $qb->andWhere('r.statut = :s')->setParameter('s', $statut);
        }
        if ($du) {
            $qb->andWhere('r.datePrevue >= :du')->setParameter('du', $du->setTime(0, 0));
        }
        if ($au) {
            $qb->andWhere('r.datePrevue <= :au')->setParameter('au', $au->setTime(23, 59, 59));
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Ramassage[] */
    public function aVenir(int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c')->join('r.client', 'c')
            ->andWhere('r.datePrevue >= :now')->setParameter('now', new \DateTimeImmutable('today'))
            ->andWhere('r.statut = :s')->setParameter('s', StatutRamassage::EnAttente)
            ->orderBy('r.datePrevue', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
