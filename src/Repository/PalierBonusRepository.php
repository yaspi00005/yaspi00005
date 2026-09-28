<?php

namespace App\Repository;

use App\Entity\PalierBonus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PalierBonus>
 */
class PalierBonusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PalierBonus::class);
    }

    /** @return list<PalierBonus> du plus haut seuil au plus bas */
    public function bareme(): array
    {
        return $this->findBy([], ['seuil' => 'DESC']);
    }
}
