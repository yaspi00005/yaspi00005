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
            $qb->andWhere('LOWER(c.nom) LIKE :q OR LOWER(c.prenom) LIKE :q OR c.telephone LIKE :q OR LOWER(c.email) LIKE :q OR LOWER(c.ville) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        return $qb;
    }

    /** @return Client[] Résultats pour l'autocomplétion (select2). */
    public function autocomplete(string $q, int $limit = 20): array
    {
        return $this->rechercheQb($q)->setMaxResults($limit)->getQuery()->getResult();
    }

    public function findDoublon(Client $client): ?Client
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.telephone = :tel')->setParameter('tel', $client->getTelephone())
            ->setMaxResults(1);
        if ($client->getId()) {
            $qb->andWhere('c.id != :id')->setParameter('id', $client->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
