<?php

namespace App\Repository;

use App\Entity\Partenaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Partenaire>
 */
class PartenaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partenaire::class);
    }

    /** @return Partenaire[] tous, dans l'ordre d'affichage */
    public function ordonnes(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'id' => 'DESC']);
    }
}
