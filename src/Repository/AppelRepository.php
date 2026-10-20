<?php

namespace App\Repository;

use App\Entity\Appel;
use App\Entity\Ramassage;
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
     * Onglets. « Tous » : tous les appels en cours (non traités). « Sans camion » : ceux qu'il reste à affecter.
     * « À reprogrammer » : date du ramassage passée sans ramassage effectué. « Archives » : ramassage effectué ou annulé.
     */
    public const VUES = [
        'tous' => '📋 Tous les appels en cours',
        'sans_camion' => '🚚 Sans camion',
        'non_confirmes' => '🚨 Non confirmés',
        'a_planifier' => '📅 À planifier',
        'a_reprogrammer' => '⏰ À reprogrammer',
        'archives' => '🗄️ Archives',
    ];

    /** Colonnes triables du tableau (clic sur le titre) => expression DQL. */
    public const TRIS = [
        'date' => 'a.dateAppel', 'conteneur' => 'dateConteneurTri', 'camion' => 'cam.nom', 'nom' => 'a.nom',
        'telephone' => 'a.telephone', 'cp' => 'a.codePostal', 'ville' => 'a.ville', 'adresse' => 'a.adresse',
        'estimation' => 'a.estimation', 'utilisateur' => 'u.prenom', 'confirme' => 'a.confirme',
    ];

    /** Condition DQL d'un appel archivé (même règle que Appel::isArchive()). */
    /** Archivé : bouton « Traité », ou ramassage effectué ou annulé ; passé sans être fait, il reste à reprogrammer. */
    private const ARCHIVE = '(a.traiteLe IS NOT NULL OR (r.id IS NOT NULL AND r.statut IN (:ramassagesFinis)))';

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
        $qb->setParameter('ramassagesFinis', [StatutRamassage::Effectue, StatutRamassage::Annule]);
        $qb->andWhere('archives' === $vue ? self::ARCHIVE : 'NOT '.self::ARCHIVE);
        match ($vue) {
            'non_confirmes' => $qb->andWhere('a.confirme = false'),
            'a_planifier' => $qb->andWhere('a.confirme = true AND a.ramassage IS NULL'),
            'sans_camion' => $qb->andWhere('a.camion IS NULL'),
            'a_reprogrammer' => $qb->andWhere('r.id IS NOT NULL AND r.datePrevue < :aujourdhui')->setParameter('aujourdhui', new \DateTimeImmutable('today')),
            default => null, // 'tous', 'en_cours' et 'archives'
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
        // Tri choisi en cliquant sur un titre de colonne (remplace l'ordre par défaut, y compris celui de la tournée).
        if (isset(self::TRIS[$tri = $filtres['tri'] ?? ''])) {
            if ('conteneur' === $tri) {
                $qb->addSelect('COALESCE(e.dateDepart, a.dateConteneur) AS HIDDEN dateConteneurTri');
            }
            $qb->orderBy(self::TRIS[$tri], 'desc' === ($filtres['sens'] ?? '') ? 'DESC' : 'ASC')->addOrderBy('a.id', 'DESC');
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
