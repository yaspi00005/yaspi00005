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

    /**
     * Rentabilité des expéditions parties sur une période : chiffre d'affaires (tarifs − remises),
     * encaissé, charges et marge, calculés en base (pas de chargement de tous les colis).
     *
     * @return list<array{expedition: Expedition, nbColis: int, ca: int, encaisse: int, depenses: int, marge: int, taux: ?float}>
     */
    public function rentabilite(?\DateTimeImmutable $du = null, ?\DateTimeImmutable $au = null): array
    {
        $qb = $this->createQueryBuilder('e')->orderBy('e.dateDepart', 'DESC')->addOrderBy('e.id', 'DESC');
        if ($du) {
            $qb->andWhere('e.dateDepart >= :du')->setParameter('du', $du);
        }
        if ($au) {
            $qb->andWhere('e.dateDepart <= :au')->setParameter('au', $au);
        }
        /** @var Expedition[] $expeditions */
        $expeditions = $qb->getQuery()->getResult();
        if (!$expeditions) {
            return [];
        }
        $em = $this->getEntityManager();
        $colis = [];
        foreach ($em->createQuery('SELECT IDENTITY(c.expedition) AS id, COUNT(c.id) AS nb, COALESCE(SUM(c.frais - c.remise), 0) AS ca, COALESCE(SUM(c.montantPaye), 0) AS paye
                FROM App\Entity\Colis c WHERE c.expedition IN (:e) GROUP BY c.expedition')
            ->setParameter('e', $expeditions)->getArrayResult() as $r) {
            $colis[(int) $r['id']] = $r;
        }
        $depenses = [];
        foreach ($em->createQuery('SELECT IDENTITY(d.expedition) AS id, SUM(d.montant) AS total FROM App\Entity\DepenseExpedition d WHERE d.expedition IN (:e) GROUP BY d.expedition')
            ->setParameter('e', $expeditions)->getArrayResult() as $r) {
            $depenses[(int) $r['id']] = (int) $r['total'];
        }

        return array_map(function (Expedition $e) use ($colis, $depenses) {
            $ca = (int) ($colis[$e->getId()]['ca'] ?? 0);
            $charges = $depenses[$e->getId()] ?? 0;

            return [
                'expedition' => $e,
                'nbColis' => (int) ($colis[$e->getId()]['nb'] ?? 0),
                'ca' => $ca,
                'encaisse' => (int) ($colis[$e->getId()]['paye'] ?? 0),
                'depenses' => $charges,
                'marge' => $ca - $charges,
                'taux' => $ca > 0 ? round(($ca - $charges) / $ca * 100, 1) : null,
            ];
        }, $expeditions);
    }

    /** @return Expedition[] départs annoncés sur le site vitrine, du plus proche au plus lointain */
    public function annoncesPubliees(int $max = 6): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.publieeSurSite = true AND e.statut = :preparation AND e.dateDepart >= :aujourdhui')
            ->setParameter('preparation', StatutExpedition::EnPreparation)
            ->setParameter('aujourdhui', new \DateTimeImmutable('today'))
            ->orderBy('e.dateDepart', 'ASC')
            ->setMaxResults($max)
            ->getQuery()->getResult();
    }
}
