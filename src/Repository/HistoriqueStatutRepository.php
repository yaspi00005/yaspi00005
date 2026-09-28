<?php

namespace App\Repository;

use App\Entity\HistoriqueStatut;
use App\Entity\User;
use App\Enum\StatutColis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HistoriqueStatut>
 */
class HistoriqueStatutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HistoriqueStatut::class);
    }

    /** Nombre de colis remis au destinataire par cet utilisateur depuis une date. */
    public function compterRemisesPar(User $user, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->andWhere('h.auteur = :user AND h.statut = :remis AND h.date >= :depuis')
            ->setParameter('user', $user)->setParameter('remis', StatutColis::Recupere)->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult();
    }
}
