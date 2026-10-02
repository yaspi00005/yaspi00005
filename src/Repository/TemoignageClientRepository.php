<?php

namespace App\Repository;

use App\Entity\TemoignageClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TemoignageClient>
 */
class TemoignageClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemoignageClient::class);
    }

    /** @return TemoignageClient[] tous, dans l'ordre d'affichage */
    public function ordonnes(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'id' => 'DESC']);
    }
}
