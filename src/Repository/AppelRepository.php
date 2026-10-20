<?php

namespace App\Repository;

use App\Entity\Appel;
use App\Entity\Ramassage;
use App\Enum\StatutExpedition;
use App\Enum\StatutRamassage;
use App\Service\Doublons;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appel>
 */
class AppelRepository extends ServiceEntityRepository
{
    /**
     * « À traiter » : appels en cours sans camion (un appel affecté à un camion sort de cette liste et se retrouve
     * avec le bouton de son camion). « Archives » : ramassage effectué ou annulé, ou conteneur parti.
     */
    public const VUES = [
        'tous' => '📋 À traiter (sans camion)',
        'non_confirmes' => '🚨 Non confirmés',
        'a_planifier' => '📅 À planifier',
        'archives' => '🗄️ Archives',
    ];

    /** Condition DQL d'un appel archivé (même règle que Appel::isArchive()). */
    private const ARCHIVE = '((r.id IS NOT NULL AND r.statut IN (:ramassagesFinis)) OR (e.id IS NOT NULL AND e.statut <> :enPreparation) OR (e.id IS NULL AND a.dateConteneur IS NOT NULL AND a.dateConteneur < :aujourdhui))';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appel::class);
    }

    /**
     * @param array{q?: string, vue?: string, camion?: ?int, chauffeur?: ?int, expedition?: ?int, du?: string, au?: string, utilisateur?: ?int, source?: string} $filtres
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
        $vue = $filtres['vue'] ?? 'tous';
        $qb->setParameter('ramassagesFinis', [StatutRamassage::Effectue, StatutRamassage::Annule])
            ->setParameter('enPreparation', StatutExpedition::EnPreparation)
            ->setParameter('aujourdhui', new \DateTimeImmutable('today'));
        $qb->andWhere('archives' === $vue ? self::ARCHIVE : 'NOT '.self::ARCHIVE);
        match ($vue) {
            'non_confirmes' => $qb->andWhere('a.confirme = false'),
            'a_planifier' => $qb->andWhere('a.confirme = true AND a.ramassage IS NULL'),
            // Liste principale : ce qu'il reste à affecter. Avec un camion ou un chauffeur choisi, on voit ses appels.
            'tous' => ($filtres['camion'] ?? null) || ($filtres['chauffeur'] ?? null) ? null : $qb->andWhere('a.camion IS NULL'),
            default => null, // 'en_cours' (compteurs) et 'archives'
        };
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtres['du'] ?? '')) {
            $qb->andWhere('a.dateAppel >= :du')->setParameter('du', new \DateTimeImmutable($filtres['du']));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtres['au'] ?? '')) {
            $qb->andWhere('a.dateAppel <= :au')->setParameter('au', new \DateTimeImmutable($filtres['au']));
        }
        if ($filtres['utilisateur'] ?? null) {
            $qb->andWhere('u.id = :utilisateur')->setParameter('utilisateur', $filtres['utilisateur']);
        }
        if (\in_array($filtres['source'] ?? '', [Appel::SOURCE_TELEPHONE, Appel::SOURCE_SITE], true)) {
            $qb->andWhere('a.source = :source')->setParameter('source', $filtres['source']);
        }
        if ($filtres['camion'] ?? null) {
            $qb->andWhere('cam.id = :camion')->setParameter('camion', $filtres['camion']);
            // Tournée d'un camion : dans l'ordre de passage choisi, les clients pas encore placés à la fin.
            $qb->addSelect('CASE WHEN a.ordreTournee IS NULL THEN 1 ELSE 0 END AS HIDDEN sansOrdre')
                ->orderBy('sansOrdre', 'ASC')->addOrderBy('a.ordreTournee', 'ASC')->addOrderBy('a.codePostal', 'ASC')->addOrderBy('a.id', 'ASC');
        }
        if ($filtres['chauffeur'] ?? null) {
            $qb->andWhere('ch.id = :chauffeur')->setParameter('chauffeur', $filtres['chauffeur']);
        }
        if ($filtres['expedition'] ?? null) {
            $qb->andWhere('e.id = :expedition')->setParameter('expedition', $filtres['expedition']);
        }

        return $qb;
    }

    /** @return array{total: int, confirmes: int, attente: int, aPlanifier: int, sansCamion: int, estimation: int} */
    public function compteurs(QueryBuilder $qb): array
    {
        $r = (clone $qb)->select('COUNT(a.id) AS total',
            'SUM(CASE WHEN a.confirme = true THEN 1 ELSE 0 END) AS confirmes',
            'SUM(CASE WHEN a.confirme = true AND a.ramassage IS NULL THEN 1 ELSE 0 END) AS aPlanifier',
            'SUM(CASE WHEN a.camion IS NULL THEN 1 ELSE 0 END) AS sansCamion',
            'COALESCE(SUM(a.estimation), 0) AS estimation')
            ->resetDQLPart('orderBy')->setFirstResult(0)->setMaxResults(null)
            ->getQuery()->getSingleResult();

        return [
            'total' => (int) $r['total'],
            'confirmes' => (int) $r['confirmes'],
            'attente' => (int) $r['total'] - (int) $r['confirmes'],
            'aPlanifier' => (int) $r['aPlanifier'],
            'sansCamion' => (int) $r['sansCamion'],
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

    /** Appel en cours (non archivé) au même numéro de téléphone (8 derniers chiffres), sauf $saufId. */
    public function enCoursAuTelephone(?string $telephone, ?int $saufId = null): ?Appel
    {
        $fin = Doublons::finTelephone($telephone);
        if (!$fin) {
            return null;
        }
        $candidats = $this->filtreQb(['vue' => 'en_cours'])
            ->andWhere('a.telephone LIKE :fin')->setParameter('fin', '%'.implode('%', str_split(substr($fin, -4))))
            ->getQuery()->getResult();
        foreach ($candidats as $a) {
            if ($a->getId() !== $saufId && Doublons::finTelephone($a->getTelephone()) === $fin) {
                return $a;
            }
        }

        return null;
    }

    /** @return Appel[] appels en cours d'un camion, dans l'ordre de passage (feuille de tournée) */
    public function enCoursDuCamion(int $camion): array
    {
        return $this->filtreQb(['vue' => 'en_cours', 'camion' => $camion])->getQuery()->getResult();
    }

    public function pourRamassage(Ramassage $ramassage): ?Appel
    {
        return $this->findOneBy(['ramassage' => $ramassage]);
    }
}
