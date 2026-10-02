<?php

namespace App\Repository;

use App\Entity\PhotoSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhotoSite>
 */
class PhotoSiteRepository extends ServiceEntityRepository
{
    /** Nombre de photos affichées sur le site. */
    public const MAX_SITE = 24;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoSite::class);
    }

    /** @return PhotoSite[] toutes, dans l'ordre d'affichage (les plus récentes d'abord à ordre égal) */
    public function ordonnees(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'id' => 'DESC']);
    }

    /** @return PhotoSite[] celles affichées sur le site */
    public function publiees(int $max = self::MAX_SITE): array
    {
        return $this->findBy(['publie' => true], ['ordre' => 'ASC', 'id' => 'DESC'], $max);
    }
}
