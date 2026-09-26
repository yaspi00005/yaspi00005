<?php

namespace App\Repository;

use App\Entity\Expedition;
use App\Entity\User;
use App\Enum\StatutExpedition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Expedition>
 */
class ExpeditionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expedition::class);
    }

    /**
     * Expéditions visibles par l'utilisateur, filtrées.
     *
     * @param array{statut?: ?string, destination?: ?string, du?: ?string, au?: ?string, q?: ?string} $filtres
     */
    public function filtreQb(?User $user, array $filtres = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')->orderBy('e.dateDepart', 'DESC')->addOrderBy('e.id', 'DESC');

        if ($user && !\in_array(User::ROLE_ADMIN, $user->getRoles(), true) && $user->getPays()) {
            $qb->andWhere('e.destination = :pays')->setParameter('pays', $user->getPays());
        }
        if (!empty($filtres['statut']) && $s = StatutExpedition::tryFrom($filtres['statut'])) {
            $qb->andWhere('e.statut = :statut')->setParameter('statut', $s);
        }
        if (!empty($filtres['destination'])) {
            $qb->andWhere('e.destination = :dest')->setParameter('dest', $filtres['destination']);
        }
        if (!empty($filtres['du'])) {
            $qb->andWhere('e.dateDepart >= :du')->setParameter('du', new \DateTimeImmutable($filtres['du']));
        }
        if (!empty($filtres['au'])) {
            $qb->andWhere('e.dateDepart <= :au')->setParameter('au', new \DateTimeImmutable($filtres['au']));
        }
        if (!empty($filtres['q'])) {
            $qb->andWhere('e.numero LIKE :q OR e.reference LIKE :q')->setParameter('q', '%'.$filtres['q'].'%');
        }

        return $qb;
    }

    /** @return Expedition[] Expéditions qui acceptent encore des colis. */
    public function findOuvertes(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.statut = :s')->setParameter('s', StatutExpedition::EnPreparation)
            ->orderBy('e.dateDepart', 'ASC')
            ->getQuery()->getResult();
    }

    public function compterPourAnnee(int $annee): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.numero LIKE :p')->setParameter('p', 'EXP'.$annee.'-%')
            ->getQuery()->getSingleScalarResult();
    }
}
