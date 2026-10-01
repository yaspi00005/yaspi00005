<?php

namespace App\Repository;

use App\Entity\Appel;
use App\Entity\Ramassage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appel>
 */
class AppelRepository extends ServiceEntityRepository
{
    public const VUES = [
        'tous' => 'Tous les appels',
        'non_confirmes' => '🚨 Non confirmés',
        'a_planifier' => '📅 À planifier',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appel::class);
    }

    /**
     * @param array{q?: string, vue?: string, camion?: ?int, chauffeur?: ?int, expedition?: ?int} $filtres
     */
    public function filtreQb(array $filtres): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('cam', 'e', 'u', 'r', 'ch')
            ->leftJoin('a.camion', 'cam')->leftJoin('cam.chauffeur', 'ch')
            ->leftJoin('a.expedition', 'e')->leftJoin('a.utilisateur', 'u')->leftJoin('a.ramassage', 'r')
            ->orderBy('a.dateAppel', 'DESC')->addOrderBy('a.id', 'DESC');

        if ('' !== $q = trim($filtres['q'] ?? '')) {
            $condition = 'a.nom LIKE :q OR a.prenom LIKE :q OR a.telephone LIKE :q OR a.ville LIKE :q OR a.codePostal LIKE :q OR a.commentaire LIKE :q OR a.adresse LIKE :q';
            // Téléphone saisi sans espaces : « 0620985532 » retrouve « 06 20 98 55 32 ».
            if (preg_match('/^\+?[\d .]{6,}$/', $q)) {
                $condition .= ' OR a.telephone LIKE :tel';
                $qb->setParameter('tel', '%'.implode('%', str_split(preg_replace('/\D/', '', $q))).'%');
            }
            $qb->andWhere($condition)->setParameter('q', '%'.addcslashes($q, '%_').'%');
        }
        match ($filtres['vue'] ?? 'tous') {
            'non_confirmes' => $qb->andWhere('a.confirme = false'),
            'a_planifier' => $qb->andWhere('a.confirme = true AND a.ramassage IS NULL'),
            default => null,
        };
        if ($filtres['camion'] ?? null) {
            $qb->andWhere('cam.id = :camion')->setParameter('camion', $filtres['camion']);
        }
        if ($filtres['chauffeur'] ?? null) {
            $qb->andWhere('ch.id = :chauffeur')->setParameter('chauffeur', $filtres['chauffeur']);
        }
        if ($filtres['expedition'] ?? null) {
            $qb->andWhere('e.id = :expedition')->setParameter('expedition', $filtres['expedition']);
        }

        return $qb;
    }

    /** @return array{total: int, confirmes: int, attente: int, aPlanifier: int, estimation: int} */
    public function compteurs(QueryBuilder $qb): array
    {
        $r = (clone $qb)->select('COUNT(a.id) AS total',
            'SUM(CASE WHEN a.confirme = true THEN 1 ELSE 0 END) AS confirmes',
            'SUM(CASE WHEN a.confirme = true AND a.ramassage IS NULL THEN 1 ELSE 0 END) AS aPlanifier',
            'COALESCE(SUM(a.estimation), 0) AS estimation')
            ->resetDQLPart('orderBy')->setFirstResult(0)->setMaxResults(null)
            ->getQuery()->getSingleResult();

        return [
            'total' => (int) $r['total'],
            'confirmes' => (int) $r['confirmes'],
            'attente' => (int) $r['total'] - (int) $r['confirmes'],
            'aPlanifier' => (int) $r['aPlanifier'],
            'estimation' => (int) $r['estimation'],
        ];
    }

    /** @return Appel[] appels confirmés sans date de ramassage, conteneur le plus proche d'abord */
    public function aPlanifier(): array
    {
        return $this->filtreQb(['vue' => 'a_planifier'])
            ->orderBy('a.dateConteneur', 'ASC')->addOrderBy('e.dateDepart', 'ASC')->addOrderBy('a.dateAppel', 'ASC')
            ->getQuery()->getResult();
    }

    public function pourRamassage(Ramassage $ramassage): ?Appel
    {
        return $this->findOneBy(['ramassage' => $ramassage]);
    }
}
