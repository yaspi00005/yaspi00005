<?php

namespace App\Repository;

use App\Entity\Nouveaute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Nouveaute>
 */
class NouveauteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Nouveaute::class);
    }

    /** @return Nouveaute[] tous, dans l'ordre d'affichage */
    public function ordonnes(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'id' => 'DESC']);
    }

    /** @return Nouveaute[] annonces affichées sur le site (publiées, non expirées) */
    public function visibles(): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.publiee = true')
            ->andWhere('n.dateFin IS NULL OR n.dateFin >= :aujourdhui')->setParameter('aujourdhui', new \DateTimeImmutable('today'))
            ->orderBy('n.ordre', 'ASC')->addOrderBy('n.id', 'DESC')
            ->getQuery()->getResult();
    }
}
