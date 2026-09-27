<?php

namespace App\Repository;

use App\Entity\Journal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Journal> */
class JournalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Journal::class);
    }

    /** @param array{utilisateur?: ?int, action?: ?string, entite?: ?string, q?: ?string, du?: ?\DateTimeImmutable, au?: ?\DateTimeImmutable} $filtres */
    public function rechercheQb(array $filtres): QueryBuilder
    {
        $qb = $this->createQueryBuilder('j')->orderBy('j.date', 'DESC')->addOrderBy('j.id', 'DESC');
        if (!empty($filtres['utilisateur'])) {
            $qb->andWhere('IDENTITY(j.utilisateur) = :u')->setParameter('u', $filtres['utilisateur']);
        }
        if (!empty($filtres['action'])) {
            $qb->andWhere('j.action = :a')->setParameter('a', $filtres['action']);
        }
        if (!empty($filtres['entite'])) {
            $qb->andWhere('j.entite = :e')->setParameter('e', $filtres['entite']);
        }
        if (!empty($filtres['q'])) {
            $qb->andWhere('j.libelle LIKE :q OR j.utilisateurNom LIKE :q')->setParameter('q', '%'.addcslashes($filtres['q'], '%_').'%');
        }
        if (!empty($filtres['du'])) {
            $qb->andWhere('j.date >= :du')->setParameter('du', $filtres['du']);
        }
        if (!empty($filtres['au'])) {
            $qb->andWhere('j.date < :au')->setParameter('au', $filtres['au']->modify('+1 day'));
        }

        return $qb;
    }

    /** @return list<Journal> */
    public function pourColis(int $colisId, int $limite = 100): array
    {
        return $this->findBy(['colisId' => $colisId], ['date' => 'DESC', 'id' => 'DESC'], $limite);
    }

    /** @return list<Journal> */
    public function pourClient(int $clientId, int $limite = 100): array
    {
        return $this->findBy(['clientId' => $clientId], ['date' => 'DESC', 'id' => 'DESC'], $limite);
    }

    /** @return list<string> */
    public function entites(): array
    {
        return $this->createQueryBuilder('j')->select('DISTINCT j.entite')->where('j.entite IS NOT NULL')->orderBy('j.entite')->getQuery()->getSingleColumnResult();
    }
}
