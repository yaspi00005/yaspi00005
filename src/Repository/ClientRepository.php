<?php

namespace App\Repository;

use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    public function rechercheQb(?string $q): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.nom', 'ASC')->addOrderBy('c.prenom', 'ASC');
        if ($q = trim((string) $q)) {
            $qb->andWhere('LOWER(c.nom) LIKE :q OR LOWER(c.prenom) LIKE :q OR c.telephone LIKE :q OR LOWER(c.email) LIKE :q OR LOWER(c.ville) LIKE :q OR LOWER(c.adresse) LIKE :q OR c.codePostal LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        return $qb;
    }

    /** @return Client[] Résultats pour l'autocomplétion (select2). */
    public function autocomplete(string $q, int $limit = 20): array
    {
        return $this->rechercheQb($q)->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return array{solde: int, clients: int} bonus encore disponible chez les clients */
    public function soldeBonusGlobal(): array
    {
        $r = $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.soldeBonus), 0) AS solde, SUM(CASE WHEN c.soldeBonus > 0 THEN 1 ELSE 0 END) AS clients')
            ->getQuery()->getSingleResult();

        return ['solde' => (int) $r['solde'], 'clients' => (int) $r['clients']];
    }

    /** @return Client[] clients ayant le plus de bonus à utiliser */
    public function meilleursSoldes(int $n = 10): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.soldeBonus > 0')
            ->orderBy('c.soldeBonus', 'DESC')->addOrderBy('c.nom', 'ASC')
            ->setMaxResults($n)
            ->getQuery()->getResult();
    }
}
