<?php

namespace App\Repository;

use App\Entity\Colis;
use App\Entity\Expedition;
use App\Entity\User;
use App\Enum\StatutColis;
use App\Enum\StatutPaiement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Colis>
 */
class ColisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Colis::class);
    }

    /**
     * @param array{q?: ?string, statut?: ?string, paiement?: ?string, expedition?: ?int, du?: ?string, au?: ?string} $filtres
     */
    public function filtreQb(?User $user, array $filtres = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('cl', 'e')
            ->join('c.client', 'cl')
            ->leftJoin('c.expedition', 'e')
            ->orderBy('c.dateReception', 'DESC')->addOrderBy('c.id', 'DESC');

        $this->restreindrePourUtilisateur($qb, $user);

        if (!empty($filtres['q'])) {
            $q = '%'.mb_strtolower(trim($filtres['q'])).'%';
            $qb->andWhere('LOWER(c.numeroSuivi) LIKE :q OR LOWER(c.codeRetrait) LIKE :q OR LOWER(cl.nom) LIKE :q OR LOWER(cl.prenom) LIKE :q OR cl.telephone LIKE :q OR LOWER(c.destinataireNom) LIKE :q OR c.destinataireTelephone LIKE :q')
                ->setParameter('q', $q);
        }
        if (!empty($filtres['statut']) && $s = StatutColis::tryFrom($filtres['statut'])) {
            $qb->andWhere('c.statut = :statut')->setParameter('statut', $s);
        }
        if (!empty($filtres['sansRecuperes'])) {
            $qb->andWhere('c.statut <> :recupere')->setParameter('recupere', StatutColis::Recupere);
        }
        if (!empty($filtres['paiement']) && $p = StatutPaiement::tryFrom($filtres['paiement'])) {
            $qb->andWhere('c.statutPaiement = :paiement')->setParameter('paiement', $p);
        }
        if (!empty($filtres['expedition'])) {
            $qb->andWhere('e.id = :exp')->setParameter('exp', (int) $filtres['expedition']);
        }
        if (!empty($filtres['du'])) {
            $qb->andWhere('c.dateReception >= :du')->setParameter('du', new \DateTimeImmutable($filtres['du'].' 00:00:00'));
        }
        if (!empty($filtres['au'])) {
            $qb->andWhere('c.dateReception <= :au')->setParameter('au', new \DateTimeImmutable($filtres['au'].' 23:59:59'));
        }

        return $qb;
    }

    /** Un représentant ne voit que les colis dont l'expédition part vers son pays. */
    public function restreindrePourUtilisateur(QueryBuilder $qb, ?User $user, string $alias = 'c'): void
    {
        if (!$user || \in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return;
        }
        if (\in_array(User::ROLE_REPRESENTANT, $user->getRoles(), true) && $user->getPays()) {
            if (!\in_array('e', $qb->getAllAliases(), true)) {
                $qb->leftJoin($alias.'.expedition', 'e');
            }
            $qb->andWhere('e.destination = :paysUser')->setParameter('paysUser', $user->getPays());
        }
    }

    public function findOneByCode(string $code): ?Colis
    {
        $code = mb_strtoupper(trim($code));

        return $this->createQueryBuilder('c')
            ->andWhere('c.codeRetrait = :code OR c.numeroSuivi = :code')
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Nombre de colis déjà enregistrés sur un mois donné (pour la numérotation). */
    public function compterPourPrefixe(string $prefixe): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.numeroSuivi LIKE :p')->setParameter('p', $prefixe.'%')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array<string, int> nombre de colis par statut */
    public function compterParStatut(?User $user = null, ?Expedition $expedition = null): array
    {
        $qb = $this->createQueryBuilder('c')->select('c.statut AS statut, COUNT(c.id) AS n')->groupBy('c.statut');
        $this->restreindrePourUtilisateur($qb, $user);
        if ($expedition) {
            $qb->andWhere('c.expedition = :exp')->setParameter('exp', $expedition);
        }
        $res = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $statut = $row['statut'] instanceof StatutColis ? $row['statut']->value : $row['statut'];
            $res[$statut] = (int) $row['n'];
        }

        return $res;
    }

    /** @return array{frais: int, remise: int, paye: int} */
    public function totaux(?User $user = null, ?\DateTimeImmutable $depuis = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.frais), 0) AS frais, COALESCE(SUM(c.remise), 0) AS remise, COALESCE(SUM(c.montantPaye), 0) AS paye');
        $this->restreindrePourUtilisateur($qb, $user);
        if ($depuis) {
            $qb->andWhere('c.dateReception >= :depuis')->setParameter('depuis', $depuis);
        }
        $r = $qb->getQuery()->getSingleResult();

        return ['frais' => (int) $r['frais'], 'remise' => (int) $r['remise'], 'paye' => (int) $r['paye']];
    }

    /** @return array<string, int> nombre de colis reçus par mois (clé AAAA-MM) sur N mois */
    public function colisParMois(int $mois = 12, ?User $user = null): array
    {
        $debut = (new \DateTimeImmutable('first day of this month 00:00'))->modify('-'.($mois - 1).' months');
        $qb = $this->createQueryBuilder('c')->select('c.dateReception AS d')
            ->andWhere('c.dateReception >= :debut')->setParameter('debut', $debut);
        $this->restreindrePourUtilisateur($qb, $user);

        $res = [];
        for ($i = 0; $i < $mois; ++$i) {
            $res[$debut->modify("+$i months")->format('Y-m')] = 0;
        }
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $k = $row['d']->format('Y-m');
            if (isset($res[$k])) {
                ++$res[$k];
            }
        }

        return $res;
    }

    /** @return Colis[] */
    public function derniers(?User $user, int $n = 8): array
    {
        return $this->filtreQb($user)->setMaxResults($n)->getQuery()->getResult();
    }

    /** @return Colis[] colis arrivés et non récupérés depuis plus de N jours */
    public function enAttenteRetrait(?User $user, int $jours = 7): array
    {
        $qb = $this->filtreQb($user, ['statut' => StatutColis::Arrive->value])
            ->andWhere('c.updatedAt <= :limite')
            ->setParameter('limite', new \DateTimeImmutable("-$jours days"));

        return $qb->getQuery()->getResult();
    }
}
